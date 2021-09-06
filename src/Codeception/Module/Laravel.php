<?php

declare(strict_types=1);

namespace Codeception\Module;

use Codeception\Configuration;
use Codeception\Exception\ModuleConfigException;
use Codeception\Lib\Connector\Laravel as LaravelConnector;
use Codeception\Lib\Framework;
use Codeception\Lib\Interfaces\ActiveRecord;
use Codeception\Lib\Interfaces\PartedModule;
use Codeception\Lib\ModuleContainer;
use Codeception\Module\Laravel\InteractsWithAuthentication;
use Codeception\Module\Laravel\InteractsWithConsole;
use Codeception\Module\Laravel\InteractsWithContainer;
use Codeception\Module\Laravel\InteractsWithEloquent;
use Codeception\Module\Laravel\InteractsWithEvents;
use Codeception\Module\Laravel\InteractsWithExceptionHandling;
use Codeception\Module\Laravel\InteractsWithRouting;
use Codeception\Module\Laravel\InteractsWithSession;
use Codeception\Module\Laravel\InteractsWithViews;
use Codeception\Module\Laravel\MakesHttpRequests;
use Codeception\Subscriber\ErrorHandler;
use Codeception\TestInterface;
use Codeception\Util\ReflectionHelper;
use Exception;
use Illuminate\Database\Connection;
use Illuminate\Database\DatabaseManager;
use Illuminate\Database\Eloquent\Factory;
use Illuminate\Foundation\Application;
use Illuminate\Routing\Route;
use ReflectionException;

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
    use InteractsWithConsole;
    use InteractsWithContainer;
    use InteractsWithEloquent;
    use InteractsWithEvents;
    use InteractsWithExceptionHandling;
    use InteractsWithRouting;
    use InteractsWithSession;
    use InteractsWithViews;
    use MakesHttpRequests;

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
}
