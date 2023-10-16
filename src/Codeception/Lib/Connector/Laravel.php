<?php

declare(strict_types=1);

namespace Codeception\Lib\Connector;

use Closure;
use Codeception\Lib\Connector\Laravel\ExceptionHandlerDecorator as LaravelExceptionHandlerDecorator;
use Codeception\Module\Laravel as LaravelModule;
use Codeception\Stub;
use Dotenv\Dotenv;
use Exception;
use Illuminate\Contracts\Config\Repository as Config;
use Illuminate\Contracts\Debug\ExceptionHandler;
use Illuminate\Contracts\Events\Dispatcher;
use Illuminate\Contracts\Events\Dispatcher as Events;
use Illuminate\Contracts\Foundation\Application as AppContract;
use Illuminate\Contracts\Http\Kernel as HttpKernel;
use Illuminate\Database\ConnectionResolverInterface as Db;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Bootstrap\RegisterProviders;
use Illuminate\Http\Request;
use Illuminate\Http\UploadedFile;
use Symfony\Component\HttpFoundation\Request as SymfonyRequest;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\HttpKernelBrowser as Client;

class Laravel extends Client
{
    private array $bindings = [];

    private array $contextualBindings = [];

    /**
     * @var object[]
     */
    private array $instances = [];

    /**
     * @var callable[]
     */
    private array $applicationHandlers = [];

    private ?AppContract $app = null;

    private LaravelModule $module;

    private bool $firstRequest = true;

    private array $triggeredEvents = [];

    private bool $exceptionHandlingDisabled;

    private bool $middlewareDisabled;

    private bool $eventsDisabled;

    private bool $modelEventsDisabled;

    private ?object $oldDb = null;

    /**
     * Constructor.
     *
     * @param LaravelModule $module
     * @throws Exception
     */
    public function __construct($module)
    {
        $this->module = $module;

        $this->exceptionHandlingDisabled = $this->module->config['disable_exception_handling'];
        $this->middlewareDisabled = $this->module->config['disable_middleware'];
        $this->eventsDisabled = $this->module->config['disable_events'];
        $this->modelEventsDisabled = $this->module->config['disable_model_events'];

        $this->initialize();

        $components = parse_url($this->getConfig()->get('app.url', 'http://localhost'));
        if (array_key_exists('url', $this->module->config)) {
            $components = parse_url($this->module->config['url']);
        }

        $host = $components['host'] ?? 'localhost';

        parent::__construct($this->app, ['HTTP_HOST' => $host]);

        // Parent constructor defaults to not following redirects
        $this->followRedirects(true);
    }

    /**
     * Execute a request.
     *
     * @param SymfonyRequest $request
     * @throws Exception
     */
    protected function doRequest($request): Response
    {
        if (!$this->firstRequest) {
            $this->initialize($request);
        }

        $this->firstRequest = false;

        $this->applyBindings();
        $this->applyContextualBindings();
        $this->applyInstances();
        $this->applyApplicationHandlers();

        $request = Request::createFromBase($request);
        $response = $this->kernel->handle($request);
        $this->getHttpKernel()->terminate($this->app['request'], $response);

        return $response;
    }

    private function initialize(SymfonyRequest $request = null): void
    {
        // Store a reference to the database object
        // so the database connection can be reused during tests
        $this->oldDb = null;

        $db = $this->getDb();
        if ($db && $db->connection()) {
            $this->oldDb = $db;
        }

        $this->app = $this->loadApplication();
        $this->kernel = $this->app;

        // Set the request instance for the application,
        if (is_null($request)) {
            $appConfig = require $this->module->config['project_dir'] . 'config/app.php';
            $request = SymfonyRequest::create($appConfig['url']);
        }

        $this->app->instance('request', Request::createFromBase($request));

        // Reset the old database after all the service providers are registered.
        if ($this->oldDb) {
            $this->getEvents()->listen('bootstrapped: ' . RegisterProviders::class, function (): void {
                $this->app->singleton('db', fn(): object => $this->oldDb);
            });
        }

        $this->getHttpKernel()->bootstrap();

        $listener = function ($event): void {
            $this->triggeredEvents[] = $this->normalizeEvent($event);
        };

        $this->getEvents()->listen('*', $listener);

        $decorator = new LaravelExceptionHandlerDecorator($this->getExceptionHandler());
        $decorator->exceptionHandlingDisabled($this->exceptionHandlingDisabled);
        $this->app->instance(ExceptionHandler::class, $decorator);

        if ($this->module->config['disable_middleware'] || $this->middlewareDisabled) {
            $this->app->instance('middleware.disable', true);
        }

        if ($this->module->config['disable_events'] || $this->eventsDisabled) {
            $this->mockEventDispatcher();
        }

        if ($this->module->config['disable_model_events'] || $this->modelEventsDisabled) {
            Model::unsetEventDispatcher();
        }

        $this->module->setApplication($this->app);
    }

    /**
     * Boot the Laravel application object.
     */
    private function loadApplication(): AppContract
    {
        /** @var AppContract $app */
        $app = require $this->module->config['bootstrap_file'];
        if ($this->module->config['environment_file'] !== '.env') {
            Dotenv::createMutable(
                $app->basePath(),
                $this->module->config['environment_file']
            )->load();
        }
        $app->instance('request', new Request());

        return $app;
    }

    /**
     * Replace the Laravel event dispatcher with a mock.
     */
    private function mockEventDispatcher(): void
    {
        // Even if events are disabled we still want to record the triggered events.
        // But by mocking the event dispatcher the wildcard listener registered in the initialize method is removed.
        // So to record the triggered events we have to catch the calls to the fire method of the event dispatcher mock.
        $callback = function ($event): array {
            $this->triggeredEvents[] = $this->normalizeEvent($event);

            return [];
        };

        $mock = Stub::makeEmpty(Dispatcher::class, ['dispatch' => $callback]);

        $this->app->instance('events', $mock);
    }

    /**
     * Normalize events to class names.
     *
     * @param object|string $event
     * @return string
     */
    private function normalizeEvent($event): string
    {
        if (is_object($event)) {
            $event = get_class($event);
        }

        if (preg_match('#^bootstrapp(ing|ed): #', $event)) {
            return $event;
        }

        // Events can be formatted as 'event.name: parameters'
        $segments = explode(':', $event);

        return $segments[0];
    }

    /**
     * Apply the registered application handlers.
     */
    private function applyApplicationHandlers(): void
    {
        foreach ($this->applicationHandlers as $handler) {
            call_user_func($handler, $this->app);
        }
    }

    /**
     * Apply the registered Laravel service container bindings.
     */
    private function applyBindings(): void
    {
        foreach ($this->bindings as $abstract => $binding) {
            [$concrete, $shared] = $binding;

            $this->app->bind($abstract, $concrete, $shared);
        }
    }

    /**
     * Apply the registered Laravel service container contextual bindings.
     */
    private function applyContextualBindings(): void
    {
        foreach ($this->contextualBindings as $concrete => $bindings) {
            foreach ($bindings as $abstract => $implementation) {
                $this->app->addContextualBinding($concrete, $abstract, $implementation);
            }
        }
    }

    /**
     * Apply the registered Laravel service container instance bindings.
     */
    private function applyInstances(): void
    {
        foreach ($this->instances as $abstract => $instance) {
            $this->app->instance($abstract, $instance);
        }
    }

    /**
     * Make sure files are \Illuminate\Http\UploadedFile instances with the private $test property set to true.
     * Fixes issue https://github.com/Codeception/Codeception/pull/3417.
     */
    protected function filterFiles(array $files): array
    {
        $files = parent::filterFiles($files);
        return $this->convertToTestFiles($files);
    }

    private function convertToTestFiles(array &$files): array
    {
        $filtered = [];

        foreach ($files as $key => $value) {
            if (is_array($value)) {
                $filtered[$key] = $this->convertToTestFiles($value);

                $files[$key] = $value;
            } else {
                $filtered[$key] = UploadedFile::createFromBase($value, true);

                unset($files[$key]);
            }
        }

        return $filtered;
    }

    // Public methods called by module

    public function clearApplicationHandlers(): void
    {
        $this->applicationHandlers = [];
    }

    public function disableEvents(): void
    {
        $this->eventsDisabled = true;
        $this->mockEventDispatcher();
    }

    public function disableExceptionHandling(): void
    {
        $this->exceptionHandlingDisabled = true;
        $this->getExceptionHandler()->exceptionHandlingDisabled(true);
    }

    public function disableMiddleware($middleware = null): void
    {
        if (is_null($middleware)) {
            $this->middlewareDisabled = true;

            $this->app->instance('middleware.disable', true);
            return;
        }

        foreach ((array) $middleware as $abstract) {
            $this->app->instance($abstract, new class
            {
                public function handle($request, $next)
                {
                    return $next($request);
                }
            });
        }
    }

    public function disableModelEvents(): void
    {
        $this->modelEventsDisabled = true;
        Model::unsetEventDispatcher();
    }

    public function enableExceptionHandling(): void
    {
        $this->exceptionHandlingDisabled = false;
        $this->getExceptionHandler()->exceptionHandlingDisabled(false);
    }

    public function enableMiddleware($middleware = null): void
    {
        if (is_null($middleware)) {
            $this->middlewareDisabled = false;

            unset($this->app['middleware.disable']);
            return;
        }

        foreach ((array) $middleware as $abstract) {
            unset($this->app[$abstract]);
        }
    }

    /**
     * Did an event trigger?
     *
     * @param object|string $event
     */
    public function eventTriggered($event): bool
    {
        $event = $this->normalizeEvent($event);

        foreach ($this->triggeredEvents as $triggeredEvent) {
            if ($event == $triggeredEvent || is_subclass_of($event, $triggeredEvent)) {
                return true;
            }
        }

        return false;
    }

    public function haveApplicationHandler(callable $handler): void
    {
        $this->applicationHandlers[] = $handler;
    }

    /**
     * @param Closure|string|null $concrete
     */
    public function haveBinding(string $abstract, $concrete, bool $shared = false): void
    {
        $this->bindings[$abstract] = [$concrete, $shared];
    }

    /**
     * @param Closure|string $implementation
     */
    public function haveContextualBinding(string $concrete, string $abstract, $implementation): void
    {
        if (! isset($this->contextualBindings[$concrete])) {
            $this->contextualBindings[$concrete] = [];
        }

        $this->contextualBindings[$concrete][$abstract] = $implementation;
    }

    public function haveInstance(string $abstract, object $instance): void
    {
        $this->instances[$abstract] = $instance;
    }

    /**
     * @return \Illuminate\Config\Repository
     */
    public function getConfig(): ?Config
    {
        return $this->app['config'] ?? null;
    }

    /**
     * @return \Illuminate\Database\DatabaseManager
     */
    public function getDb(): ?Db
    {
        return $this->app['db'] ?? null;
    }

    /**
     * @return \Illuminate\Events\Dispatcher
     */
    public function getEvents(): ?Events
    {
        return $this->app['events'] ?? null;
    }

    /**
     * @return \Illuminate\Foundation\Exceptions\Handler
     */
    public function getExceptionHandler(): ?ExceptionHandler
    {
        return $this->app[ExceptionHandler::class] ?? null;
    }

    /**
     * @return \Illuminate\Foundation\Http\Kernel
     */
    public function getHttpKernel(): ?HttpKernel
    {
        return $this->app[HttpKernel::class] ?? null;
    }
}
