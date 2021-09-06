<?php

declare(strict_types=1);

namespace Codeception\Module;

use Closure;
use Codeception\Configuration;
use Codeception\Exception\ModuleConfigException;
use Codeception\Lib\Connector\Laravel as LaravelConnector;
use Codeception\Lib\Framework;
use Codeception\Lib\Interfaces\ActiveRecord;
use Codeception\Lib\Interfaces\PartedModule;
use Codeception\Lib\ModuleContainer;
use Codeception\Module\Laravel\InteractsWithAuthentication;
use Codeception\Subscriber\ErrorHandler;
use Codeception\TestInterface;
use Codeception\Util\ReflectionHelper;
use Exception;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Contracts\Routing\UrlGenerator;
use Illuminate\Contracts\Session\Session;
use Illuminate\Contracts\View\Factory as ViewContract;
use Illuminate\Database\Connection;
use Illuminate\Database\DatabaseManager;
use Illuminate\Database\Eloquent\Factory;
use Illuminate\Database\Eloquent\FactoryBuilder;
use Illuminate\Database\Eloquent\Model as EloquentModel;
use Illuminate\Foundation\Application;
use Illuminate\Http\Request;
use Illuminate\Routing\Route;
use Illuminate\Routing\Router;
use Illuminate\Support\Collection;
use Illuminate\Support\ViewErrorBag;
use ReflectionClass;
use ReflectionException;
use RuntimeException;
use Symfony\Component\Console\Output\OutputInterface;
use function is_array;

/**
 *
 * This module allows you to run functional tests for Laravel 6.0+
 * It should **not** be used for acceptance tests.
 * See the Acceptance tests section below for more details.
 *
 * ## Demo project
 * <https://github.com/Codeception/laravel-module-tests>
 *
 * ## Config
 *
 * * cleanup: `boolean`, default `true` - all database queries will be run in a transaction,
 *   which will be rolled back at the end of each test.
 * * run_database_migrations: `boolean`, default `false` - run database migrations before each test.
 * * database_migrations_path: `string`, default `null` - path to the database migrations, relative to the root of the application.
 * * run_database_seeder: `boolean`, default `false` - run database seeder before each test.
 * * database_seeder_class: `string`, default `` - database seeder class name.
 * * environment_file: `string`, default `.env` - the environment file to load for the tests.
 * * bootstrap: `string`, default `bootstrap/app.php` - relative path to app.php config file.
 * * root: `string`, default `` - root path of the application.
 * * packages: `string`, default `workbench` - root path of application packages (if any).
 * * vendor_dir: `string`, default `vendor` - optional relative path to vendor directory.
 * * disable_exception_handling: `boolean`, default `true` - disable Laravel exception handling.
 * * disable_middleware: `boolean`, default `false` - disable all middleware.
 * * disable_events: `boolean`, default `false` - disable events (does not disable model events).
 * * disable_model_events: `boolean`, default `false` - disable model events.
 * * url: `string`, default `` - the application URL.
 *
 * ### Example #1 (`functional.suite.yml`)
 *
 * Enabling module:
 *
 * ```yml
 * modules:
 *     enabled:
 *         - Laravel
 * ```
 *
 * ### Example #2 (`functional.suite.yml`)
 *
 * Enabling module with custom .env file
 *
 * ```yml
 * modules:
 *     enabled:
 *         - Laravel:
 *             environment_file: .env.testing
 * ```
 *
 * ## API
 *
 * * app - `Illuminate\Foundation\Application`
 * * config - `array`
 *
 * ## Parts
 *
 * * `ORM`: Only include the database methods of this module:
 *     * dontSeeRecord
 *     * grabNumRecords
 *     * grabRecord
 *     * have
 *     * haveMultiple
 *     * haveRecord
 *     * make
 *     * makeMultiple
 *     * seeNumRecords
 *     * seeRecord
 *
 * See [WebDriver module](https://codeception.com/docs/modules/WebDriver#Loading-Parts-from-other-Modules)
 * for general information on how to load parts of a framework module.
 *
 * ## Acceptance tests
 *
 * You should not use this module for acceptance tests.
 * If you want to use Eloquent within your acceptance tests (paired with WebDriver) enable only
 * ORM part of this module:
 *
 * ### Example (`acceptance.suite.yml`)
 *
 * ```yaml
 * modules:
 *     enabled:
 *         - WebDriver:
 *             browser: chrome
 *             url: http://127.0.0.1:8000
 *         - Laravel:
 *             part: ORM
 *             environment_file: .env.testing
 * ```
 */
class Laravel extends Framework implements ActiveRecord, PartedModule
{
    use InteractsWithAuthentication;

    /**
     * @var Application
     */
    public $app;

    /**
     * @var array
     */
    public $config = [];

    public function __construct(ModuleContainer $container, ?array $config = null)
    {
        $this->config = array_merge(
            [
                'cleanup' => true,
                'run_database_migrations' => false,
                'database_migrations_path' => null,
                'run_database_seeder' => false,
                'database_seeder_class' => '',
                'environment_file' => '.env',
                'bootstrap' => 'bootstrap' . DIRECTORY_SEPARATOR . 'app.php',
                'root' => '',
                'packages' => 'workbench',
                'vendor_dir' => 'vendor',
                'disable_exception_handling' => true,
                'disable_middleware' => false,
                'disable_events' => false,
                'disable_model_events' => false,
            ],
            (array)$config
        );

        $projectDir = explode($this->config['packages'], Configuration::projectDir())[0];
        $projectDir .= $this->config['root'];

        $this->config['project_dir'] = $projectDir;
        $this->config['bootstrap_file'] = $projectDir . $this->config['bootstrap'];

        parent::__construct($container);
    }

    public function _parts(): array
    {
        return ['orm'];
    }

    /**
     * Initialize hook.
     */
    public function _initialize()
    {
        $this->checkBootstrapFileExists();
        $this->registerAutoloaders();
        $this->revertErrorHandler();
    }

    /**
     * Before hook.
     *
     * @param TestInterface $test
     * @throws Exception
     */
    public function _before(TestInterface $test)
    {
        $this->client = new LaravelConnector($this);

        // Database migrations should run before database cleanup transaction starts
        if ($this->config['run_database_migrations']) {
            $this->callArtisan('migrate', ['--path' => $this->config['database_migrations_path']]);
        }

        if ($this->applicationUsesDatabase() && $this->config['cleanup']) {
            $this->app['db']->beginTransaction();
            $this->debugSection('Database', 'Transaction started');
        }

        if ($this->config['run_database_seeder']) {
            $this->callArtisan('db:seed', ['--class' => $this->config['database_seeder_class'], '--force' => true ]);
        }
    }

    /**
     * After hook.
     *
     * @param TestInterface $test
     * @throws Exception
     */
    public function _after(TestInterface $test)
    {
        if ($this->applicationUsesDatabase()) {
            $db = $this->app['db'];

            if ($db instanceof DatabaseManager) {
                if ($this->config['cleanup']) {
                    $db->rollback();
                    $this->debugSection('Database', 'Transaction cancelled; all changes reverted.');
                }

                /**
                 * Close all DB connections in order to prevent "Too many connections" issue
                 *
                 * @var Connection $connection
                 */
                foreach ($db->getConnections() as $connection) {
                    $connection->disconnect();
                }
            }

            // Remove references to Faker in factories to prevent memory leak
            unset($this->app[\Faker\Generator::class]);
            unset($this->app[Factory::class]);
        }
    }

    /**
     * Does the application use the database?
     *
     * @return bool
     */
    private function applicationUsesDatabase(): bool
    {
        return ! empty($this->app['config']['database.default']);
    }

    /**
     * Make sure the Laravel bootstrap file exists.
     *
     * @throws ModuleConfigException
     */
    protected function checkBootstrapFileExists(): void
    {
        $bootstrapFile = $this->config['bootstrap_file'];

        if (!file_exists($bootstrapFile)) {
            throw new ModuleConfigException(
                $this,
                "Laravel bootstrap file not found in $bootstrapFile.\n"
                . "Please provide a valid path by using the 'bootstrap' config param. "
            );
        }
    }

    /**
     * Register Laravel autoloaders.
     */
    protected function registerAutoloaders(): void
    {
        require $this->config['project_dir'] . $this->config['vendor_dir'] . DIRECTORY_SEPARATOR . 'autoload.php';
    }

    /**
     * Revert back to the Codeception error handler,
     * because Laravel registers it's own error handler.
     */
    protected function revertErrorHandler(): void
    {
        $handler = new ErrorHandler();
        set_error_handler([$handler, 'errorHandler']);
    }

    /**
     * Provides access the Laravel application object.
     *
     * @return \Illuminate\Contracts\Foundation\Application
     */
    public function getApplication()
    {
        return $this->app;
    }

    /**
     * @param \Illuminate\Contracts\Foundation\Application $app
     */
    public function setApplication($app): void
    {
        $this->app = $app;
    }

    /**
     * Enable Laravel exception handling.
     *
     * ```php
     * <?php
     * $I->enableExceptionHandling();
     * ```
     */
    public function enableExceptionHandling()
    {
        $this->client->enableExceptionHandling();
    }

    /**
     * Disable Laravel exception handling.
     *
     * ```php
     * <?php
     * $I->disableExceptionHandling();
     * ```
     */
    public function disableExceptionHandling()
    {
        $this->client->disableExceptionHandling();
    }

    /**
     * Disable middleware for the next requests.
     *
     * ```php
     * <?php
     * $I->disableMiddleware();
     * ```
     */
    public function disableMiddleware()
    {
        $this->client->disableMiddleware();
    }

    /**
     * Disable events for the next requests.
     * This method does not disable model events.
     * To disable model events you have to use the disableModelEvents() method.
     *
     * ```php
     * <?php
     * $I->disableEvents();
     * ```
     */
    public function disableEvents(): void
    {
        $this->client->disableEvents();
    }

    /**
     * Disable model events for the next requests.
     *
     * ```php
     * <?php
     * $I->disableModelEvents();
     * ```
     */
    public function disableModelEvents(): void
    {
        $this->client->disableModelEvents();
    }

    /**
     * Make sure events fired during the test.
     *
     * ```php
     * <?php
     * $I->seeEventTriggered('App\MyEvent');
     * $I->seeEventTriggered(new App\Events\MyEvent());
     * $I->seeEventTriggered(['App\MyEvent', 'App\MyOtherEvent']);
     * ```
     * @param string|object|string[] $expected
     */
    public function seeEventTriggered($expected): void
    {
        $expected = is_array($expected) ? $expected : [$expected];

        foreach ($expected as $expectedEvent) {
            if (! $this->client->eventTriggered($expectedEvent)) {
                $expectedEvent = is_object($expectedEvent) ? get_class($expectedEvent) : $expectedEvent;

                $this->fail("The '$expectedEvent' event did not trigger");
            }
        }
    }

    /**
     * Make sure events did not fire during the test.
     *
     * ``` php
     * <?php
     * $I->dontSeeEventTriggered('App\MyEvent');
     * $I->dontSeeEventTriggered(new App\Events\MyEvent());
     * $I->dontSeeEventTriggered(['App\MyEvent', 'App\MyOtherEvent']);
     * ```
     * @param string|object|string[] $expected
     */
    public function dontSeeEventTriggered($expected): void
    {
        $expected = is_array($expected) ? $expected : [$expected];

        foreach ($expected as $expectedEvent) {
            $triggered = $this->client->eventTriggered($expectedEvent);
            if ($triggered) {
                $expectedEvent = is_object($expectedEvent) ? get_class($expectedEvent) : $expectedEvent;

                $this->fail("The '$expectedEvent' event triggered");
            }
        }
    }

    /**
     * Call an Artisan command.
     *
     * ``` php
     * <?php
     * $I->callArtisan('command:name');
     * $I->callArtisan('command:name', ['parameter' => 'value']);
     * ```
     * Use 3rd parameter to pass in custom `OutputInterface`
     *
     * @param string $command
     * @param array $parameters
     * @param OutputInterface|null $output
     * @return string|void
     */
    public function callArtisan(string $command, $parameters = [], OutputInterface $output = null)
    {
        $console = $this->app->make(Kernel::class);
        if (!$output) {
            $console->call($command, $parameters);
            $output = trim($console->output());
            $this->debug($output);
            return $output;
        }

        $console->call($command, $parameters, $output);
    }

    /**
     * Opens web page using route name and parameters.
     *
     * ```php
     * <?php
     * $I->amOnRoute('posts.create');
     * ```
     *
     * @param string $routeName
     * @param mixed $params
     */
    public function amOnRoute(string $routeName, $params = []): void
    {
        $route = $this->getRouteByName($routeName);

        $absolute = !is_null($route->domain());
        /** @var UrlGenerator $urlGenerator */
        $urlGenerator = $this->app['url'];
        $url = $urlGenerator->route($routeName, $params, $absolute);
        $this->amOnPage($url);
    }

    /**
     * Checks that current url matches route
     *
     * ``` php
     * <?php
     * $I->seeCurrentRouteIs('posts.index');
     * ```
     * @param string $routeName
     */
    public function seeCurrentRouteIs(string $routeName): void
    {
        $this->getRouteByName($routeName); // Fails if route does not exists

        /** @var Request $request */
        $request = $this->app->request;
        $currentRoute = $request->route();
        $currentRouteName = $currentRoute ? $currentRoute->getName() : '';

        if ($currentRouteName != $routeName) {
            $message = empty($currentRouteName)
                ? "Current route has no name"
                : "Current route is \"$currentRouteName\"";
            $this->fail($message);
        }
    }

    /**
     * Opens web page by action name
     *
     * ``` php
     * <?php
     * // Laravel 6 or 7:
     * $I->amOnAction('PostsController@index');
     *
     * // Laravel 8+:
     * $I->amOnAction(PostsController::class . '@index');
     * ```
     *
     * @param string $action
     * @param mixed $parameters
     */
    public function amOnAction(string $action, $parameters = []): void
    {
        $route = $this->getRouteByAction($action);
        $absolute = !is_null($route->domain());
        /** @var UrlGenerator $urlGenerator */
        $urlGenerator = $this->app['url'];
        $url = $urlGenerator->action($action, $parameters, $absolute);

        $this->amOnPage($url);
    }

    /**
     * Checks that current url matches action
     *
     * ``` php
     * <?php
     * // Laravel 6 or 7:
     * $I->seeCurrentActionIs('PostsController@index');
     *
     * // Laravel 8+:
     * $I->seeCurrentActionIs(PostsController::class . '@index');
     * ```
     *
     * @param string $action
     */
    public function seeCurrentActionIs(string $action): void
    {
        $this->getRouteByAction($action); // Fails if route does not exists
        /** @var Request $request */
        $request = $this->app->request;
        $currentRoute = $request->route();
        $currentAction = $currentRoute ? $currentRoute->getActionName() : '';
        $currentAction = ltrim(
            str_replace( (string)$this->getRootControllerNamespace(), '', $currentAction),
            '\\'
        );

        if ($currentAction != $action) {
            $this->fail("Current action is \"$currentAction\"");
        }
    }

    /**
     * @param string $routeName
     * @return mixed
     */
    protected function getRouteByName(string $routeName)
    {
        /** @var Router $router */
        $router = $this->app['router'];
        $routes = $router->getRoutes();
        if (!$route = $routes->getByName($routeName)) {
            $this->fail("Route with name '$routeName' does not exist");
        }

        return $route;
    }

    /**
     * @param string $action
     * @return Route
     */
    protected function getRouteByAction(string $action): Route
    {
        $namespacedAction = $this->actionWithNamespace($action);

        if (!$route = $this->app['routes']->getByAction($namespacedAction)) {
            $this->fail("Action '$action' does not exist");
        }

        return $route;
    }

    /**
     * Normalize an action to full namespaced action.
     *
     * @param string $action
     * @return string
     */
    protected function actionWithNamespace(string $action): string
    {
        $rootNamespace = $this->getRootControllerNamespace();

        if ($rootNamespace && !(strpos($action, '\\') === 0)) {
            return $rootNamespace . '\\' . $action;
        }

        return trim($action, '\\');
    }

    /**
     * Get the root controller namespace for the application.
     *
     * @return string|null
     * @throws ReflectionException
     */
    protected function getRootControllerNamespace(): ?string
    {
        $urlGenerator = $this->app['url'];
        $reflection = new ReflectionClass($urlGenerator);

        $property = $reflection->getProperty('rootNamespace');
        $property->setAccessible(true);

        return $property->getValue($urlGenerator);
    }

    /**
     * Assert that a session variable exists.
     *
     * ``` php
     * <?php
     * $I->seeInSession('key');
     * $I->seeInSession('key', 'value');
     * ```
     *
     * @param string|array $key
     * @param mixed|null $value
     */
    public function seeInSession($key, $value = null): void
    {
        if (is_array($key)) {
            $this->seeSessionHasValues($key);
            return;
        }

        /** @var Session $session */
        $session = $this->app['session'];

        if (!$session->has($key)) {
            $this->fail("No session variable with key '$key'");
        }

        if (! is_null($value)) {
            $this->assertEquals($value, $session->get($key));
        }
    }

    /**
     * Assert that the session has a given list of values.
     *
     * ``` php
     * <?php
     * $I->seeSessionHasValues(['key1', 'key2']);
     * $I->seeSessionHasValues(['key1' => 'value1', 'key2' => 'value2']);
     * ```
     *
     * @param array $bindings
     */
    public function seeSessionHasValues(array $bindings): void
    {
        foreach ($bindings as $key => $value) {
            if (is_int($key)) {
                $this->seeInSession($value);
            } else {
                $this->seeInSession($key, $value);
            }
        }
    }

    /**
     * Assert that form errors are bound to the View.
     *
     * ``` php
     * <?php
     * $I->seeFormHasErrors();
     * ```
     */
    public function seeFormHasErrors(): void
    {
        /** @var ViewContract $view */
        $view = $this->app->make('view');
        /** @var ViewErrorBag $viewErrorBag */
        $viewErrorBag = $view->shared('errors');

        $this->assertGreaterThan(
            0,
            $viewErrorBag->count(),
            'Expecting that the form has errors, but there were none!'
        );
    }

    /**
     * Assert that there are no form errors bound to the View.
     *
     * ``` php
     * <?php
     * $I->dontSeeFormErrors();
     * ```
     */
    public function dontSeeFormErrors(): void
    {
        /** @var ViewContract $view */
        $view = $this->app->make('view');
        /** @var ViewErrorBag $viewErrorBag */
        $viewErrorBag = $view->shared('errors');

        $this->assertEquals(
            0,
            $viewErrorBag->count(),
            'Expecting that the form does not have errors, but there were!'
        );
    }

    /**
     * Verifies that multiple fields on a form have errors.
     *
     * This method will validate that the expected error message
     * is contained in the actual error message, that is,
     * you can specify either the entire error message or just a part of it:
     *
     * ``` php
     * <?php
     * $I->seeFormErrorMessages([
     *     'address'   => 'The address is too long',
     *     'telephone' => 'too short' // the full error message is 'The telephone is too short'
     * ]);
     * ```
     *
     * If you don't want to specify the error message for some fields,
     * you can pass `null` as value instead of the message string.
     * If that is the case, it will be validated that
     * that field has at least one error of any type:
     *
     * ``` php
     * <?php
     * $I->seeFormErrorMessages([
     *     'telephone' => 'too short',
     *     'address'   => null
     * ]);
     * ```
     *
     * @param array $expectedErrors
     */
    public function seeFormErrorMessages(array $expectedErrors): void
    {
        foreach ($expectedErrors as $field => $message) {
            $this->seeFormErrorMessage($field, $message);
        }
    }

    /**
     * Assert that a specific form error message is set in the view.
     *
     * If you want to assert that there is a form error message for a specific key
     * but don't care about the actual error message you can omit `$expectedErrorMessage`.
     *
     * If you do pass `$expectedErrorMessage`, this method checks if the actual error message for a key
     * contains `$expectedErrorMessage`.
     *
     * ``` php
     * <?php
     * $I->seeFormErrorMessage('username');
     * $I->seeFormErrorMessage('username', 'Invalid Username');
     * ```
     * @param string $field
     * @param string|null $errorMessage
     */
    public function seeFormErrorMessage(string $field, $errorMessage = null): void
    {
        /** @var ViewContract $view */
        $view =  $this->app['view'];
        /** @var ViewErrorBag $viewErrorBag */
        $viewErrorBag = $view->shared('errors');

        if (!($viewErrorBag->has($field))) {
            $this->fail("No form error message for key '$field'\n");
        }

        if (! is_null($errorMessage)) {
            $this->assertStringContainsString($errorMessage, $viewErrorBag->first($field));
        }
    }

    /**
     * Return an instance of a class from the Laravel service container.
     * (https://laravel.com/docs/master/container)
     *
     * ``` php
     * <?php
     * // In Laravel
     * App::bind('foo', function($app) {
     *     return new FooBar;
     * });
     *
     * // Then in test
     * $service = $I->grabService('foo');
     *
     * // Will return an instance of FooBar, also works for singletons.
     * ```
     *
     * @param string $class
     * @return mixed
     */
    public function grabService(string $class)
    {
        return $this->app[$class];
    }


    /**
     * Inserts record into the database.
     * If you pass the name of a database table as the first argument, this method returns an integer ID.
     * You can also pass the class name of an Eloquent model, in that case this method returns an Eloquent model.
     *
     * ```php
     * <?php
     * $user_id = $I->haveRecord('users', ['name' => 'Davert']); // returns integer
     * $user = $I->haveRecord('App\Models\User', ['name' => 'Davert']); // returns Eloquent model
     * ```
     *
     * @param string $table
     * @param array  $attributes
     * @return EloquentModel|int
     * @throws RuntimeException
     * @part orm
     */
    public function haveRecord($table, $attributes = [])
    {
        if (class_exists($table)) {
            $model = new $table;

            if (! $model instanceof EloquentModel) {
                throw new RuntimeException("Class $table is not an Eloquent model");
            }

            $model->fill($attributes)->save();

            return $model;
        }

        try {
            /** @var DatabaseManager $dbManager */
            $dbManager = $this->app['db'];
            return $dbManager->table($table)->insertGetId($attributes);
        } catch (Exception $e) {
            $this->fail("Could not insert record into table '$table':\n\n" . $e->getMessage());
        }
    }

    /**
     * Checks that record exists in database.
     * You can pass the name of a database table or the class name of an Eloquent model as the first argument.
     *
     * ``` php
     * <?php
     * $I->seeRecord('users', ['name' => 'davert']);
     * $I->seeRecord('App\Models\User', ['name' => 'davert']);
     * ```
     *
     * @param string $table
     * @param array $attributes
     * @part orm
     */
    public function seeRecord($table, $attributes = []): void
    {
        if (class_exists($table)) {
            if (! $foundMatchingRecord = (bool)$this->findModel($table, $attributes)) {
                $this->fail("Could not find $table with " . json_encode($attributes));
            }
        } elseif (! $foundMatchingRecord = (bool)$this->findRecord($table, $attributes)) {
            $this->fail("Could not find matching record in table '$table'");
        }

        $this->assertTrue($foundMatchingRecord);
    }

    /**
     * Checks that record does not exist in database.
     * You can pass the name of a database table or the class name of an Eloquent model as the first argument.
     *
     * ```php
     * <?php
     * $I->dontSeeRecord('users', ['name' => 'davert']);
     * $I->dontSeeRecord('App\Models\User', ['name' => 'davert']);
     * ```
     *
     * @param string $table
     * @param array $attributes
     * @part orm
     */
    public function dontSeeRecord($table, $attributes = []): void
    {
        if (class_exists($table)) {
            if ($foundMatchingRecord = (bool)$this->findModel($table, $attributes)) {
                $this->fail("Unexpectedly found matching $table with " . json_encode($attributes));
            }
        } elseif ($foundMatchingRecord = (bool)$this->findRecord($table, $attributes)) {
            $this->fail("Unexpectedly found matching record in table '$table'");
        }

        $this->assertFalse($foundMatchingRecord);
    }

    /**
     * Retrieves record from database
     * If you pass the name of a database table as the first argument, this method returns an array.
     * You can also pass the class name of an Eloquent model, in that case this method returns an Eloquent model.
     *
     * ``` php
     * <?php
     * $record = $I->grabRecord('users', ['name' => 'davert']); // returns array
     * $record = $I->grabRecord('App\Models\User', ['name' => 'davert']); // returns Eloquent model
     * ```
     *
     * @param string $table
     * @param array $attributes
     * @return array|EloquentModel
     * @part orm
     */
    public function grabRecord($table, $attributes = [])
    {
        if (class_exists($table)) {
            if (! $model = $this->findModel($table, $attributes)) {
                $this->fail("Could not find $table with " . json_encode($attributes));
            }

            return $model;
        }

        if (! $record = $this->findRecord($table, $attributes)) {
            $this->fail("Could not find matching record in table '$table'");
        }

        return $record;
    }

    /**
     * Checks that number of given records were found in database.
     * You can pass the name of a database table or the class name of an Eloquent model as the first argument.
     *
     * ``` php
     * <?php
     * $I->seeNumRecords(1, 'users', ['name' => 'davert']);
     * $I->seeNumRecords(1, 'App\Models\User', ['name' => 'davert']);
     * ```
     *
     * @param int $expectedNum
     * @param string $table
     * @param array $attributes
     * @part orm
     */
    public function seeNumRecords(int $expectedNum, string $table, array $attributes = []): void
    {
        if (class_exists($table)) {
            $currentNum = $this->countModels($table, $attributes);
            $this->assertEquals(
                $expectedNum,
                $currentNum,
                "The number of found {$table} ({$currentNum}) does not match expected number {$expectedNum} with " . json_encode($attributes)
            );
        } else {
            $currentNum = $this->countRecords($table, $attributes);
            $this->assertEquals(
                $expectedNum,
                $currentNum,
                "The number of found records in table {$table} ({$currentNum}) does not match expected number $expectedNum with " . json_encode($attributes)
            );
        }
    }

    /**
     * Retrieves number of records from database
     * You can pass the name of a database table or the class name of an Eloquent model as the first argument.
     *
     * ``` php
     * <?php
     * $I->grabNumRecords('users', ['name' => 'davert']);
     * $I->grabNumRecords('App\Models\User', ['name' => 'davert']);
     * ```
     *
     * @param string $table
     * @param array $attributes
     * @return int
     * @part orm
     */
    public function grabNumRecords(string $table, array $attributes = []): int
    {
        return class_exists($table) ? $this->countModels($table, $attributes) : $this->countRecords($table, $attributes);
    }

    /**
     * @param string $modelClass
     * @param array $attributes
     *
     * @return EloquentModel
     */
    protected function findModel(string $modelClass, array $attributes = [])
    {
        $query = $this->buildQuery($modelClass, $attributes);

        return $query->first();
    }

    protected function findRecord(string $table, array $attributes = []): array
    {
        $query = $this->buildQuery($table, $attributes);
        return (array) $query->first();
    }

    protected function countModels(string $modelClass, $attributes = []): int
    {
        $query = $this->buildQuery($modelClass, $attributes);
        return $query->count();
    }

    protected function countRecords(string $table, array $attributes = []): int
    {
        $query = $this->buildQuery($table, $attributes);
        return $query->count();
    }

    /**
     * @param string $modelClass
     *
     * @return EloquentModel
     * @throws RuntimeException
     */
    protected function getQueryBuilderFromModel(string $modelClass)
    {
        $model = new $modelClass;

        if (!$model instanceof EloquentModel) {
            throw new RuntimeException("Class $modelClass is not an Eloquent model");
        }

        return $model->newQuery();
    }

    /**
     * @param string $table
     *
     * @return EloquentModel
     */
    protected function getQueryBuilderFromTable(string $table)
    {
        return $this->app['db']->table($table);
    }

    /**
     * Use Laravel model factory to create a model.
     *
     * ``` php
     * <?php
     * $I->have('App\Models\User');
     * $I->have('App\Models\User', ['name' => 'John Doe']);
     * $I->have('App\Models\User', [], 'admin');
     * ```
     *
     * @see https://laravel.com/docs/6.x/database-testing#using-factories
     * @param string $model
     * @param array $attributes
     * @param string $name
     * @return mixed
     * @part orm
     */
    public function have(string $model, array $attributes = [], string $name = 'default')
    {
        try {
            $model = $this->modelFactory($model, $name)->create($attributes);

            // In Laravel 6 the model factory returns a collection instead of a single object
            if ($model instanceof Collection) {
                $model = $model[0];
            }

            return $model;
        } catch (Exception $e) {
            $this->fail('Could not create model: \n\n' . get_class($e) . '\n\n' . $e->getMessage());
        }
    }

    /**
     * Use Laravel model factory to create multiple models.
     *
     * ``` php
     * <?php
     * $I->haveMultiple('App\Models\User', 10);
     * $I->haveMultiple('App\Models\User', 10, ['name' => 'John Doe']);
     * $I->haveMultiple('App\Models\User', 10, [], 'admin');
     * ```
     *
     * @see https://laravel.com/docs/6.x/database-testing#using-factories
     * @param string $model
     * @param int $times
     * @param array $attributes
     * @param string $name
     * @return mixed
     * @part orm
     */
    public function haveMultiple(string $model, int $times, array $attributes = [], string $name = 'default')
    {
        try {
            return $this->modelFactory($model, $name, $times)->create($attributes);
        } catch (Exception $e) {
            $this->fail("Could not create model: \n\n" . get_class($e) . "\n\n" . $e->getMessage());
        }
    }

    /**
     * Use Laravel model factory to make a model instance.
     *
     * ``` php
     * <?php
     * $I->make('App\Models\User');
     * $I->make('App\Models\User', ['name' => 'John Doe']);
     * $I->make('App\Models\User', [], 'admin');
     * ```
     *
     * @see https://laravel.com/docs/6.x/database-testing#using-factories
     * @param string $model
     * @param array $attributes
     * @param string $name
     * @return mixed
     * @part orm
     */
    public function make(string $model, array $attributes = [], string $name = 'default')
    {
        try {
            return $this->modelFactory($model, $name)->make($attributes);
        } catch (Exception $e) {
            $this->fail("Could not make model: \n\n" . get_class($e) . "\n\n" . $e->getMessage());
        }
    }

    /**
     * Use Laravel model factory to make multiple model instances.
     *
     * ``` php
     * <?php
     * $I->makeMultiple('App\Models\User', 10);
     * $I->makeMultiple('App\Models\User', 10, ['name' => 'John Doe']);
     * $I->makeMultiple('App\Models\User', 10, [], 'admin');
     * ```
     *
     * @see https://laravel.com/docs/6.x/database-testing#using-factories
     * @param string $model
     * @param int $times
     * @param array $attributes
     * @param string $name
     * @return mixed
     * @part orm
     */
    public function makeMultiple(string $model, int $times, array $attributes = [], string $name = 'default')
    {
        try {
            return $this->modelFactory($model, $name, $times)->make($attributes);
        } catch (Exception $e) {
            $this->fail("Could not make model: \n\n" . get_class($e) . "\n\n" . $e->getMessage());
        }
    }

    /**
     * @param string $model
     * @param string $name
     * @param int $times
     * @return FactoryBuilder|\Illuminate\Database\Eloquent\Factories\Factory
     */
    protected function modelFactory(string $model, string $name, $times = 1)
    {
        if (version_compare(Application::VERSION, '7.0.0', '<')) {
            return factory($model, $name, $times);
        }

        return $model::factory()->count($times);
    }

    /**
     * Returns a list of recognized domain names.
     * This elements of this list are regular expressions.
     *
     * @return array
     * @throws ReflectionException
     */
    protected function getInternalDomains(): array
    {
        $internalDomains = [$this->getApplicationDomainRegex()];

        foreach ($this->app['routes'] as $route) {
            if (!is_null($route->domain())) {
                $internalDomains[] = $this->getDomainRegex($route);
            }
        }

        return array_unique($internalDomains);
    }

    /**
     * @return string
     * @throws ReflectionException
     */
    private function getApplicationDomainRegex(): string
    {
        $server = ReflectionHelper::readPrivateProperty($this->client, 'server');
        $domain = $server['HTTP_HOST'];

        return '/^' . str_replace('.', '\.', $domain) . '$/';
    }

    /**
     * Get the regex for matching the domain part of this route.
     *
     * @param Route $route
     * @return string
     * @throws ReflectionException
     */
    private function getDomainRegex(Route $route)
    {
        ReflectionHelper::invokePrivateMethod($route, 'compileRoute');
        $compiledRoute = ReflectionHelper::readPrivateProperty($route, 'compiled');

        return $compiledRoute->getHostRegex();
    }

    /**
     * Build Eloquent query with attributes
     *
     * @param string $table
     * @param array $attributes
     * @return EloquentModel
     * @part orm
     */
    private function buildQuery(string $table, $attributes = [])
    {
        if (class_exists($table)) {
            $query = $this->getQueryBuilderFromModel($table);
        } else {
            $query = $this->getQueryBuilderFromTable($table);
        }

        foreach ($attributes as $key => $value) {
            if (is_array($value)) {
                call_user_func_array(array($query, 'where'), $value);
            } elseif (is_null($value)) {
                $query->whereNull($key);
            } else {
                $query->where($key, $value);
            }
        }
        return $query;
    }

    /**
     * Add a binding to the Laravel service container.
     * (https://laravel.com/docs/master/container)
     *
     * ``` php
     * <?php
     * $I->haveBinding('My\Interface', 'My\Implementation');
     * ```
     *
     * @param string $abstract
     * @param Closure|string|null $concrete
     * @param bool $shared
     */
    public function haveBinding(string $abstract, $concrete = null, bool $shared = false): void
    {
        $this->client->haveBinding($abstract, $concrete, $shared);
    }

    /**
     * Add a singleton binding to the Laravel service container.
     * (https://laravel.com/docs/master/container)
     *
     * ``` php
     * <?php
     * $I->haveSingleton('App\MyInterface', 'App\MySingleton');
     * ```
     *
     * @param string $abstract
     * @param Closure|string|null $concrete
     */
    public function haveSingleton(string $abstract, $concrete): void
    {
        $this->client->haveBinding($abstract, $concrete, true);
    }

    /**
     * Add a contextual binding to the Laravel service container.
     * (https://laravel.com/docs/master/container)
     *
     * ``` php
     * <?php
     * $I->haveContextualBinding('My\Class', '$variable', 'value');
     *
     * // This is similar to the following in your Laravel application
     * $app->when('My\Class')
     *     ->needs('$variable')
     *     ->give('value');
     * ```
     *
     * @param string $concrete
     * @param string $abstract
     * @param Closure|string $implementation
     */
    public function haveContextualBinding(string $concrete, string $abstract, $implementation): void
    {
        $this->client->haveContextualBinding($concrete, $abstract, $implementation);
    }

    /**
     * Add an instance binding to the Laravel service container.
     * (https://laravel.com/docs/master/container)
     *
     * ``` php
     * <?php
     * $I->haveInstance('App\MyClass', new App\MyClass());
     * ```
     *
     * @param string $abstract
     * @param mixed $instance
     */
    public function haveInstance(string $abstract, $instance): void
    {
        $this->client->haveInstance($abstract, $instance);
    }

    /**
     * Register a handler than can be used to modify the Laravel application object after it is initialized.
     * The Laravel application object will be passed as an argument to the handler.
     *
     * ``` php
     * <?php
     * $I->haveApplicationHandler(function($app) {
     *     $app->make('config')->set(['test_value' => '10']);
     * });
     * ```
     *
     * @param callable $handler
     */
    public function haveApplicationHandler(callable $handler): void
    {
        $this->client->haveApplicationHandler($handler);
    }

    /**
     * Clear the registered application handlers.
     *
     * ``` php
     * <?php
     * $I->clearApplicationHandlers();
     * ```
     */
    public function clearApplicationHandlers(): void
    {
        $this->client->clearApplicationHandlers();
    }
}
