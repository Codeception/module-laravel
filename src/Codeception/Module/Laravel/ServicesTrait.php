<?php

declare(strict_types=1);

namespace Codeception\Module\Laravel;

use Illuminate\Contracts\Auth\Factory as Auth;
use Illuminate\Contracts\Config\Repository as Config;
use Illuminate\Contracts\Console\Kernel as ConsoleKernel;
use Illuminate\Contracts\Debug\ExceptionHandler;
use Illuminate\Contracts\Events\Dispatcher as Events;
use Illuminate\Contracts\Http\Kernel as HttpKernel;
use Illuminate\Contracts\Routing\Registrar as Router;
use Illuminate\Contracts\Routing\UrlGenerator as Url;
use Illuminate\Contracts\View\Factory as View;
use Illuminate\Database\ConnectionResolverInterface as Db;
use Symfony\Component\HttpFoundation\Request as SymfonyRequest;

trait ServicesTrait
{
    /**
     * @return \Illuminate\Auth\AuthManager|\Illuminate\Contracts\Auth\StatefulGuard
     */
    public function getAuth(): ?Auth
    {
        return $this->app['auth'] ?? null;
    }

    /**
     * @return \Illuminate\Config\Repository
     */
    public function getConfig(): ?Config
    {
        return $this->app['config'] ?? null;
    }

    /**
     * @return \Illuminate\Foundation\Console\Kernel
     */
    public function getConsoleKernel(): ?ConsoleKernel
    {
        return $this->app[ConsoleKernel::class] ?? null;
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

    /**
     * @return \Illuminate\Routing\UrlGenerator
     */
    public function getUrlGenerator(): ?Url
    {
        return $this->app['url'] ?? null;
    }

    /**
     * @return \Illuminate\Http\Request
     */
    public function getRequestObject(): ?SymfonyRequest
    {
        return $this->app['request'] ?? null;
    }

    /**
     * @return \Illuminate\Routing\Router
     */
    public function getRouter(): ?Router
    {
        return $this->app['router'] ?? null;
    }

    /**
     * @return \Illuminate\Routing\RouteCollectionInterface|\Illuminate\Routing\RouteCollection
     */
    public function getRoutes()
    {
        return $this->app['routes'] ?? null;
    }

    /**
     * @return \Illuminate\Contracts\Session\Session|\Illuminate\Session\SessionManager
     */
    public function getSession()
    {
        return $this->app['session'] ?? null;
    }

    /**
     * @return \Illuminate\View\Factory
     */
    public function getView(): ?View
    {
        return $this->app['view'] ?? null;
    }
}
