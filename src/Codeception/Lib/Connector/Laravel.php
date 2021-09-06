<?php

declare(strict_types=1);

namespace Codeception\Lib\Connector;

use Codeception\Lib\Connector\Laravel\ExceptionHandlerDecorator as LaravelExceptionHandlerDecorator;
use Codeception\Lib\Connector\Laravel6\ExceptionHandlerDecorator as Laravel6ExceptionHandlerDecorator;
use Codeception\Module\Laravel\ServicesTrait;
use Codeception\Stub;
use Exception;
use Illuminate\Contracts\Debug\ExceptionHandler;
use Illuminate\Contracts\Events\Dispatcher;
use Illuminate\Contracts\Foundation\Application as AppContract;
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
    use ServicesTrait;

    /**
     * @var array
     */
    private $bindings = [];

    /**
     * @var array
     */
    private $contextualBindings = [];

    /**
     * @var object[]
     */
    private $instances = [];

    /**
     * @var callable[]
     */
    private $applicationHandlers = [];

    /**
     * @var Application
     */
    private $app;

    /**
     * @var \Codeception\Module\Laravel
     */
    private $module;

    /**
     * @var bool
     */
    private $firstRequest = true;

    /**
     * @var array
     */
    private $triggeredEvents = [];

    /**
     * @var bool
     */
    private $exceptionHandlingDisabled;

    /**
     * @var bool
     */
    private $middlewareDisabled;

    /**
     * @var bool
     */
    private $eventsDisabled;

    /**
     * @var bool
     */
    private $modelEventsDisabled;

    /**
     * @var object
     */
    private $oldDb;

    /**
     * Constructor.
     *
     * @param \Codeception\Module\Laravel $module
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
        $this->getHttpKernel()->terminate($request, $response);

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

        $this->app = $this->kernel = $this->loadApplication();

        // Set the request instance for the application,
        if (is_null($request)) {
            $appConfig = require $this->module->config['project_dir'] . 'config/app.php';
            $request = SymfonyRequest::create($appConfig['url']);
        }
        $this->app->instance('request', Request::createFromBase($request));

        // Reset the old database after all the service providers are registered.
        if ($this->oldDb) {
            $this->getEvents()->listen('bootstrapped: ' . RegisterProviders::class, function () {
                $this->app->singleton('db', function () {
                    return $this->oldDb;
                });
            });
        }

        $this->getHttpKernel()->bootstrap();

        $listener = function ($event) {
            $this->triggeredEvents[] = $this->normalizeEvent($event);
        };

        $this->getEvents()->listen('*', $listener);

        // Replace the Laravel exception handler with our decorated exception handler,
        // so exceptions can be intercepted for the disable_exception_handling functionality.
        if (version_compare(Application::VERSION, '7.0.0', '<')) {
            $decorator = new Laravel6ExceptionHandlerDecorator($this->getExceptionHandler());
        } else {
            $decorator = new LaravelExceptionHandlerDecorator($this->getExceptionHandler());
        }

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
        $app->loadEnvironmentFrom($this->module->config['environment_file']);
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
        $callback = function ($event) {
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

        if (preg_match('/^bootstrapp(ing|ed): /', $event)) {
            return $event;
        }

        // Events can be formatted as 'event.name: parameters'
        $segments = explode(':', $event);

        return $segments[0];
    }

    //======================================================================
    // Public methods called by module
    //======================================================================

    /**
     * Did an event trigger?
     *
     * @param $event
     * @return bool
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

    /**
     * Disable Laravel exception handling.
     */
    public function disableExceptionHandling(): void
    {
        $this->exceptionHandlingDisabled = true;
        $this->app[ExceptionHandler::class]->exceptionHandlingDisabled(true);
    }

    /**
     * Enable Laravel exception handling.
     */
    public function enableExceptionHandling(): void
    {
        $this->exceptionHandlingDisabled = false;
        $this->app[ExceptionHandler::class]->exceptionHandlingDisabled(false);
    }

    /**
     * Disable events.
     *
     * @throws Exception
     */
    public function disableEvents(): void
    {
        $this->eventsDisabled = true;
        $this->mockEventDispatcher();
    }

    /**
     * Disable model events.
     */
    public function disableModelEvents(): void
    {
        $this->modelEventsDisabled = true;
        Model::unsetEventDispatcher();
    }

    /*
     * Disable middleware.
     */
    public function disableMiddleware(): void
    {
        $this->middlewareDisabled = true;
        $this->app->instance('middleware.disable', true);
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
}
