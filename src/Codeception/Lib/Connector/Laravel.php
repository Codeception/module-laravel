<?php

declare(strict_types=1);

namespace Codeception\Lib\Connector;

use Closure;
use Codeception\Lib\Connector\Laravel\ExceptionHandlerDecorator as LaravelExceptionHandlerDecorator;
use Codeception\Lib\Connector\Laravel6\ExceptionHandlerDecorator as Laravel6ExceptionHandlerDecorator;
use Codeception\Stub;
use Exception;
use Illuminate\Contracts\Debug\ExceptionHandler;
use Illuminate\Contracts\Events\Dispatcher;
use Illuminate\Contracts\Http\Kernel;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Bootstrap\RegisterProviders;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Request as SymfonyRequest;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\HttpKernelBrowser as Client;
use Symfony\Component\HttpKernel\Kernel as SymfonyKernel;
use function class_alias;

if (SymfonyKernel::VERSION_ID < 40300) {
    class_alias('Symfony\Component\HttpKernel\Client', 'Symfony\Component\HttpKernel\HttpKernelBrowser');
}

class Laravel extends Client
{
    /**
     * @var array
     */
    private $bindings = [];

    /**
     * @var array
     */
    private $contextualBindings = [];

    /**
     * @var array
     */
    private $instances = [];

    /**
     * @var array
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

        $components = parse_url($this->app['config']->get('app.url', 'http://localhost'));
        if (array_key_exists('url', $this->module->config)) {
            $components = parse_url($this->module->config['url']);
        }
        $host = isset($components['host']) ? $components['host'] : 'localhost';

        parent::__construct($this->app, ['HTTP_HOST' => $host]);

        // Parent constructor defaults to not following redirects
        $this->followRedirects(true);
    }

    /**
     * Execute a request.
     *
     * @param SymfonyRequest $request
     * @return Response
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
        $this->app->make(Kernel::class)->terminate($request, $response);

        return $response;
    }

    /**
     * @param SymfonyRequest|null $request
     * @throws Exception
     */
    private function initialize(SymfonyRequest $request = null): void
    {
        // Store a reference to the database object
        // so the database connection can be reused during tests
        $this->oldDb = null;
        if (isset($this->app['db']) && $this->app['db']->connection()) {
            $this->oldDb = $this->app['db'];
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
            $this->app['events']->listen('bootstrapped: ' . RegisterProviders::class, function () {
                $this->app->singleton('db', function () {
                    return $this->oldDb;
                });
            });
        }

        $this->app->make(Kernel::class)->bootstrap();

        // Record all triggered events by adding a wildcard event listener
        // Since Laravel 5.4 wildcard event handlers receive the event name as the first argument,
        // but for earlier Laravel versions the firing() method of the event dispatcher should be used
        // to determine the event name.
        if (method_exists($this->app['events'], 'firing')) {
            $listener = function () {
                $this->triggeredEvents[] = $this->normalizeEvent($this->app['events']->firing());
            };
        } else {
            $listener = function ($event) {
                $this->triggeredEvents[] = $this->normalizeEvent($event);
            };
        }
        $this->app['events']->listen('*', $listener);

        // Replace the Laravel exception handler with our decorated exception handler,
        // so exceptions can be intercepted for the disable_exception_handling functionality.
        if (version_compare(Application::VERSION, '7.0.0', '<')) {
            $decorator = new Laravel6ExceptionHandlerDecorator($this->app[ExceptionHandler::class]);
        } else {
            $decorator = new LaravelExceptionHandlerDecorator($this->app[ExceptionHandler::class]);
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
     *
     * @return Application
     */
    private function loadApplication(): Application
    {
        $app = require $this->module->config['bootstrap_file'];
        $app->loadEnvironmentFrom($this->module->config['environment_file']);
        $app->instance('request', new Request());

        return $app;
    }

    /**
     * Replace the Laravel event dispatcher with a mock.
     *
     * @throws Exception
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

        // In Laravel 5.4 the Illuminate\Contracts\Events\Dispatcher interface was changed,
        // the 'fire' method was renamed to 'dispatch'. This code determines the correct method to mock.
        $method = method_exists($this->app['events'], 'dispatch') ? 'dispatch' : 'fire';

        $mock = Stub::makeEmpty(Dispatcher::class, [
           $method => $callback
        ]);

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
            list($concrete, $shared) = $binding;

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

    //======================================================================
    // Public methods called by module
    //======================================================================

    /**
     * Register a Laravel service container binding that should be applied
     * after initializing the Laravel Application object.
     *
     * @param string $abstract
     * @param Closure|string|null $concrete
     * @param bool $shared
     */
    public function haveBinding(string $abstract, $concrete, bool $shared = false): void
    {
        $this->bindings[$abstract] = [$concrete, $shared];
    }

    /**
     * Register a Laravel service container contextual binding that should be applied
     * after initializing the Laravel Application object.
     *
     * @param string $concrete
     * @param string $abstract
     * @param Closure|string $implementation
     */
    public function haveContextualBinding(string $concrete, string $abstract, $implementation): void
    {
        if (! isset($this->contextualBindings[$concrete])) {
            $this->contextualBindings[$concrete] = [];
        }

        $this->contextualBindings[$concrete][$abstract] = $implementation;
    }

    /**
     * Register a Laravel service container instance binding that should be applied
     * after initializing the Laravel Application object.
     *
     * @param string $abstract
     * @param mixed $instance
     */
    public function haveInstance(string $abstract, $instance): void
    {
        $this->instances[$abstract] = $instance;
    }

    /**
     * Register a handler than can be used to modify the Laravel application object after it is initialized.
     * The Laravel application object will be passed as an argument to the handler.
     *
     * @param callable $handler
     */
    public function haveApplicationHandler(callable $handler): void
    {
        $this->applicationHandlers[] = $handler;
    }

    /**
     * Clear the registered application handlers.
     */
    public function clearApplicationHandlers(): void
    {
        $this->applicationHandlers = [];
    }
    
    /**
     * Make sure files are \Illuminate\Http\UploadedFile instances with the private $test property set to true.
     * Fixes issue https://github.com/Codeception/Codeception/pull/3417.
     *
     * @param array $files
     * @return array
     */
    protected function filterFiles(array $files): array
    {
        $files = parent::filterFiles($files);
        return $this->convertToTestFiles($files);
    }

    private function convertToTestFiles(array $files): array
    {
        $filtered = [];

        foreach ($files as $key => $value) {
            if (is_array($value)) {
                $filtered[$key] = $this->convertToTestFiles($value);
            } else {
                $filtered[$key] = UploadedFile::createFromBase($value, true);
            }
        }

        return $filtered;
    }
}
