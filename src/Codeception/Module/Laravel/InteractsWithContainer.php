<?php

declare(strict_types=1);

namespace Codeception\Module\Laravel;

use Illuminate\Contracts\Foundation\Application;

trait InteractsWithContainer
{
    /**
     * Clear the registered application handlers.
     *
     * ```php
     * <?php
     * $I->clearApplicationHandlers();
     * ```
     */
    public function clearApplicationHandlers(): void
    {
        $this->client->clearApplicationHandlers();
    }

    /**
     * Provides access the Laravel application object.
     *
     * ```php
     * <?php
     * $app = $I->getApplication();
     * ```
     */
    public function getApplication(): Application
    {
        return $this->app;
    }

    /**
     * Return an instance of a class from the Laravel service container.
     * (https://laravel.com/docs/7.x/container)
     *
     * ```php
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
     * @return mixed
     */
    public function grabService(string $class)
    {
        return $this->app[$class];
    }

    /**
     * Register a handler than can be used to modify the Laravel application object after it is initialized.
     * The Laravel application object will be passed as an argument to the handler.
     *
     * ```php
     * <?php
     * $I->haveApplicationHandler(function($app) {
     *     $app->make('config')->set(['test_value' => '10']);
     * });
     * ```
     */
    public function haveApplicationHandler(callable $handler): void
    {
        $this->client->haveApplicationHandler($handler);
    }

    /**
     * Add a binding to the Laravel service container.
     * (https://laravel.com/docs/7.x/container)
     *
     * ```php
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
     * Add a contextual binding to the Laravel service container.
     * (https://laravel.com/docs/7.x/container)
     *
     * ```php
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
     * (https://laravel.com/docs/7.x/container)
     *
     * ```php
     * <?php
     * $I->haveInstance('App\MyClass', new App\MyClass());
     * ```
     */
    public function haveInstance(string $abstract, object $instance): void
    {
        $this->client->haveInstance($abstract, $instance);
    }

    /**
     * Add a singleton binding to the Laravel service container.
     * (https://laravel.com/docs/7.x/container)
     *
     * ```php
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

    public function setApplication(Application $app): void
    {
        $this->app = $app;
    }
}
