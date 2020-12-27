<?php

declare(strict_types=1);

namespace Codeception\Module;

use Codeception\Configuration;
use Codeception\Exception\ModuleConfigException;
use Codeception\Exception\ModuleException;
use Codeception\Lib\Connector\Laravel as LaravelConnector;
use Codeception\Lib\Framework;
use Codeception\Lib\Interfaces\ActiveRecord;
use Codeception\Lib\Interfaces\PartedModule;
use Codeception\Lib\ModuleContainer;
use Codeception\Subscriber\ErrorHandler;
use Codeception\TestInterface;
use Codeception\Util\ReflectionHelper;
use Exception;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Database\Connection;
use Illuminate\Database\DatabaseManager;
use Illuminate\Database\Eloquent\Factory;
use Illuminate\Database\Eloquent\FactoryBuilder;
use Illuminate\Database\Eloquent\Model as EloquentModel;
use Illuminate\Foundation\Application;
use Illuminate\Routing\Route;
use Illuminate\Support\Collection;
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
    /**
     * @var Application
     */
    public $app;

    /**
     * @var array
     */
    public $config = [];

    /**
     * Constructor.
     *
     * @param ModuleContainer $container
     * @param array|null $config
     */
    public function __construct(ModuleContainer $container, $config = null)
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
    public function disableEvents()
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
    public function disableModelEvents()
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
     * $I->seeEventTriggered('App\MyEvent', 'App\MyOtherEvent');
     * $I->seeEventTriggered(['App\MyEvent', 'App\MyOtherEvent']);
     * ```
     * @param object|string|array $events
     */
    public function seeEventTriggered($events): void
    {
        $events = is_array($events) ? $events : func_get_args();

        foreach ($events as $event) {
            if (!$this->client->eventTriggered($event)) {
                if (is_object($event)) {
                    $event = get_class($event);
                }

                $this->fail("The '$event' event did not trigger");
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
     * $I->dontSeeEventTriggered('App\MyEvent', 'App\MyOtherEvent');
     * $I->dontSeeEventTriggered(['App\MyEvent', 'App\MyOtherEvent']);
     * ```
     * @param object|string|array $events
     */
    public function dontSeeEventTriggered($events)
    {
        $events = is_array($events) ? $events : func_get_args();

        foreach ($events as $event) {
            if ($this->client->eventTriggered($event)) {
                if (is_object($event)) {
                    $event = get_class($event);
                }

                $this->fail("The '$event' event triggered");
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
    public function callArtisan(string $command, $parameters = [], OutputInterface $output = null): string
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
     * @param array $params
     */
    public function amOnRoute(string $routeName, array $params = []): void
    {
        $route = $this->getRouteByName($routeName);

        $absolute = !is_null($route->domain());
        $url = $this->app['url']->route($routeName, $params, $absolute);
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

        $currentRoute = $this->app->request->route();
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
     * $I->amOnAction('PostsController@index');
     * ```
     *
     * @param string $action
     * @param array $params
     */
    public function amOnAction(string $action, array $params = []): void
    {
        $route = $this->getRouteByAction($action);
        $absolute = !is_null($route->domain());
        $url = $this->app['url']->action($action, $params, $absolute);

        $this->amOnPage($url);
    }

    /**
     * Checks that current url matches action
     *
     * ``` php
     * <?php
     * $I->seeCurrentActionIs('PostsController@index');
     * ```
     *
     * @param string $action
     */
    public function seeCurrentActionIs(string $action): void
    {
        $this->getRouteByAction($action); // Fails if route does not exists
        $currentRoute = $this->app->request->route();
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
        if (!$route = $this->app['routes']->getByName($routeName)) {
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

        if (! $this->app['session']->has($key)) {
            $this->fail("No session variable with key '$key'");
        }

        if (! is_null($value)) {
            $this->assertEquals($value, $this->app['session']->get($key));
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
        $viewErrorBag = $this->app->make('view')->shared('errors');

        $this->assertGreaterThan(0, count($viewErrorBag), 'Expecting that the form has errors, but there were none!');
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
        $viewErrorBag = $this->app->make('view')->shared('errors');

        $this->assertEquals(0, count($viewErrorBag), 'Expecting that the form does not have errors, but there were!');
    }

    /**
     * Assert that specific form error messages are set in the view.
     *
     * This method calls `seeFormErrorMessage` for each entry in the `$bindings` array.
     *
     * ``` php
     * <?php
     * $I->seeFormErrorMessages([
     *     'username' => 'Invalid Username',
     *     'password' => null,
     * ]);
     * ```
     * @param array $bindings
     */
    public function seeFormErrorMessages(array $bindings): void
    {
        foreach ($bindings as $key => $value) {
            $this->seeFormErrorMessage($key, $value);
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
     * @param string $key
     * @param string|null $expectedErrorMessage
     */
    public function seeFormErrorMessage(string $key, $expectedErrorMessage = null): void
    {
        $viewErrorBag = $this->app['view']->shared('errors');

        if (!($viewErrorBag->has($key))) {
            $this->fail("No form error message for key '$key'\n");
        }

        if (! is_null($expectedErrorMessage)) {
            $this->assertStringContainsString($expectedErrorMessage, $viewErrorBag->first($key));
        }
    }

    /**
     * Set the currently logged in user for the application.
     * Takes either an object that implements the User interface or
     * an array of credentials.
     *
     * ``` php
     * <?php
     * // provide array of credentials
     * $I->amLoggedAs(['username' => 'jane@example.com', 'password' => 'password']);
     *
     * // provide User object
     * $I->amLoggedAs( new User );
     *
     * // can be verified with $I->seeAuthentication();
     * ```
     * @param Authenticatable|array $user
     * @param  string|null $driver The authentication driver for Laravel <= 5.1.*, guard name for Laravel >= 5.2
     * @return void
     */
    public function amLoggedAs($user, $driver = null)
    {
        $guard = $auth = $this->app['auth'];

        if (method_exists($auth, 'driver')) {
            $guard = $auth->driver($driver);
        }

        if (method_exists($auth, 'guard')) {
            $guard = $auth->guard($driver);
        }

        if ($user instanceof Authenticatable) {
            $guard->login($user);
            return;
        }

        $this->assertTrue($guard->attempt($user), 'Failed to login with credentials ' . json_encode($user));
    }

    /**
     * Logout user.
     */
    public function logout(): void
    {
        $this->app['auth']->logout();
    }

    /**
     * Checks that a user is authenticated.
     * You can specify the guard that should be use for Laravel >= 5.2.
     *
     * @param string|null $guard
     */
    public function seeAuthentication($guard = null): void
    {
        $auth = $this->app['auth'];

        if (method_exists($auth, 'guard')) {
            $auth = $auth->guard($guard);
        }

        $this->assertTrue($auth->check(), 'There is no authenticated user');
    }

    /**
     * Check that user is not authenticated.
     * You can specify the guard that should be use for Laravel >= 5.2.
     *
     * @param string|null $guard
     */
    public function dontSeeAuthentication($guard = null): void
    {
        $auth = $this->app['auth'];

        if (method_exists($auth, 'guard')) {
            $auth = $auth->guard($guard);
        }

        $this->assertNotTrue($auth->check(), 'There is an user authenticated');
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
     * ``` php
     * <?php
     * $user_id = $I->haveRecord('users', array('name' => 'Davert')); // returns integer
     * $user = $I->haveRecord('App\User', array('name' => 'Davert')); // returns Eloquent model
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
            return $this->app['db']->table($table)->insertGetId($attributes);
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
     * $I->seeRecord('users', array('name' => 'davert'));
     * $I->seeRecord('App\User', array('name' => 'davert'));
     * ```
     *
     * @param string $table
     * @param array $attributes
     * @part orm
     */
    public function seeRecord($table, $attributes = []): void
    {
        if (class_exists($table)) {
            if (! $this->findModel($table, $attributes)) {
                $this->fail("Could not find $table with " . json_encode($attributes));
            }
        } elseif (! $this->findRecord($table, $attributes)) {
            $this->fail("Could not find matching record in table '$table'");
        }

        $this->assertTrue(true);
    }

    /**
     * Checks that record does not exist in database.
     * You can pass the name of a database table or the class name of an Eloquent model as the first argument.
     *
     * ``` php
     * <?php
     * $I->dontSeeRecord('users', array('name' => 'davert'));
     * $I->dontSeeRecord('App\User', array('name' => 'davert'));
     * ```
     *
     * @param string $table
     * @param array $attributes
     * @part orm
     */
    public function dontSeeRecord($table, $attributes = []): void
    {
        if (class_exists($table)) {
            if ($this->findModel($table, $attributes)) {
                $this->fail("Unexpectedly found matching $table with " . json_encode($attributes));
            }
        } elseif ($this->findRecord($table, $attributes)) {
            $this->fail("Unexpectedly found matching record in table '$table'");
        }

        $this->assertTrue(true);
    }

    /**
     * Retrieves record from database
     * If you pass the name of a database table as the first argument, this method returns an array.
     * You can also pass the class name of an Eloquent model, in that case this method returns an Eloquent model.
     *
     * ``` php
     * <?php
     * $record = $I->grabRecord('users', array('name' => 'davert')); // returns array
     * $record = $I->grabRecord('App\User', array('name' => 'davert')); // returns Eloquent model
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
     * $I->seeNumRecords(1, 'users', array('name' => 'davert'));
     * $I->seeNumRecords(1, 'App\User', array('name' => 'davert'));
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
     * $I->grabNumRecords('users', array('name' => 'davert'));
     * $I->grabNumRecords('App\User', array('name' => 'davert'));
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
     * Use Laravel's model factory to create a model.
     * Can only be used with Laravel 5.1 and later.
     *
     * ``` php
     * <?php
     * $I->have('App\User');
     * $I->have('App\User', ['name' => 'John Doe']);
     * $I->have('App\User', [], 'admin');
     * ```
     *
     * @see http://laravel.com/docs/5.1/testing#model-factories
     * @param string $model
     * @param array $attributes
     * @param string $name
     * @return mixed
     * @part orm
     */
    public function have(string $model, array $attributes = [], string $name = 'default')
    {
        try {
            $result = $this->modelFactory($model, $name)->create($attributes);

            // Since Laravel 5.4 the model factory returns a collection instead of a single object
            if ($result instanceof Collection) {
                $result = $result[0];
            }

            return $result;
        } catch (Exception $e) {
            $this->fail("Could not create model: \n\n" . get_class($e) . "\n\n" . $e->getMessage());
        }
    }

    /**
     * Use Laravel's model factory to create multiple models.
     * Can only be used with Laravel 5.1 and later.
     *
     * ``` php
     * <?php
     * $I->haveMultiple('App\User', 10);
     * $I->haveMultiple('App\User', 10, ['name' => 'John Doe']);
     * $I->haveMultiple('App\User', 10, [], 'admin');
     * ```
     *
     * @see http://laravel.com/docs/5.1/testing#model-factories
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
     * Use Laravel's model factory to make a model instance.
     * Can only be used with Laravel 5.1 and later.
     *
     * ``` php
     * <?php
     * $I->make('App\User');
     * $I->make('App\User', ['name' => 'John Doe']);
     * $I->make('App\User', [], 'admin');
     * ```
     *
     * @see http://laravel.com/docs/5.1/testing#model-factories
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
     * Use Laravel's model factory to make multiple model instances.
     * Can only be used with Laravel 5.1 and later.
     *
     * ``` php
     * <?php
     * $I->makeMultiple('App\User', 10);
     * $I->makeMultiple('App\User', 10, ['name' => 'John Doe']);
     * $I->makeMultiple('App\User', 10, [], 'admin');
     * ```
     *
     * @see http://laravel.com/docs/5.1/testing#model-factories
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
     * @return FactoryBuilder
     * @throws ModuleException
     */
    protected function modelFactory(string $model, string $name, $times = 1): FactoryBuilder
    {
        if (! function_exists('factory')) {
            throw new ModuleException($this, 'The factory() method does not exist. ' .
                'This functionality relies on Laravel model factories, which were introduced in Laravel 5.1.');
        }

        if (version_compare(Application::VERSION, '7.0.0', '<')) {
            $factory = factory($model, $name, $times);
        } else {
            $factory = factory($model, $times);
        }

        return $factory;
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
     * @param $abstract
     * @param $concrete
     */
    public function haveBinding($abstract, $concrete): void
    {
        $this->client->haveBinding($abstract, $concrete);
    }

    /**
     * Add a singleton binding to the Laravel service container.
     * (https://laravel.com/docs/master/container)
     *
     * ``` php
     * <?php
     * $I->haveSingleton('My\Interface', 'My\Singleton');
     * ```
     *
     * @param $abstract
     * @param $concrete
     */
    public function haveSingleton($abstract, $concrete): void
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
     * @param $concrete
     * @param $abstract
     * @param $implementation
     */
    public function haveContextualBinding($concrete, $abstract, $implementation): void
    {
        $this->client->haveContextualBinding($concrete, $abstract, $implementation);
    }

    /**
     * Add an instance binding to the Laravel service container.
     * (https://laravel.com/docs/master/container)
     *
     * ``` php
     * <?php
     * $I->haveInstance('My\Class', new My\Class());
     * ```
     *
     * @param $abstract
     * @param $instance
     */
    public function haveInstance($abstract, $instance): void
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
     * @param $handler
     */
    public function haveApplicationHandler($handler): void
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
     *
     */
    public function clearApplicationHandlers()
    {
        $this->client->clearApplicationHandlers();
    }
}
