<?php

declare(strict_types=1);

namespace Codeception\Module\Laravel;

use Illuminate\Contracts\Routing\Registrar as Router;
use Illuminate\Contracts\Routing\UrlGenerator as Url;
use Illuminate\Routing\Route;
use ReflectionClass;
use ReflectionException;
use Symfony\Component\HttpFoundation\Request as SymfonyRequest;

trait InteractsWithRouting
{
    /**
     * Opens web page by action name
     *
     * ```php
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

        $url = $this->getUrlGenerator()->action($action, $parameters, $absolute);

        $this->amOnPage($url);
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

        $url = $this->getUrlGenerator()->route($routeName, $params, $absolute);
        $this->amOnPage($url);
    }

    /**
     * Checks that current url matches action
     *
     * ```php
     * <?php
     * // Laravel 6 or 7:
     * $I->seeCurrentActionIs('PostsController@index');
     *
     * // Laravel 8+:
     * $I->seeCurrentActionIs(PostsController::class . '@index');
     * ```
     */
    public function seeCurrentActionIs(string $action): void
    {
        $this->getRouteByAction($action);

        $request = $this->getRequestObject();
        $currentRoute = $request->route();
        $currentAction = $currentRoute ? $currentRoute->getActionName() : '';
        $currentAction = ltrim(
            str_replace((string)$this->getAppRootControllerNamespace(), '', $currentAction),
            '\\'
        );

        if ($currentAction !== $action) {
            $this->fail("Current action is '{$currentAction}'");
        }
    }

    /**
     * Checks that current url matches route
     *
     * ```php
     * <?php
     * $I->seeCurrentRouteIs('posts.index');
     * ```
     */
    public function seeCurrentRouteIs(string $routeName): void
    {
        $this->getRouteByName($routeName);

        $request = $this->getRequestObject();
        $currentRoute = $request->route();
        $currentRouteName = $currentRoute ? $currentRoute->getName() : '';

        if ($currentRouteName != $routeName) {
            $message = empty($currentRouteName)
                ? "Current route has no name"
                : "Current route is '{$currentRouteName}'";
            $this->fail($message);
        }
    }

    /**
     * @throws ReflectionException
     */
    protected function getAppRootControllerNamespace(): ?string
    {
        $urlGenerator = $this->getUrlGenerator();
        $reflectionClass = new ReflectionClass($urlGenerator);

        $property = $reflectionClass->getProperty('rootNamespace');
        $property->setAccessible(true);

        return $property->getValue($urlGenerator);
    }

    /**
     * Get route by Action.
     * Fails if route does not exists.
     */
    protected function getRouteByAction(string $action): Route
    {
        $namespacedAction = $this->normalizeActionToFullNamespacedAction($action);

        if (!$route = $this->getRoutes()->getByAction($namespacedAction)) {
            $this->fail("Action '{$action}' does not exist");
        }

        return $route;
    }

    protected function getRouteByName(string $routeName): Route
    {
        $routes = $this->getRouter()->getRoutes();
        if (!$route = $routes->getByName($routeName)) {
            $this->fail("Route with name '{$routeName}' does not exist");
        }

        return $route;
    }

    protected function normalizeActionToFullNamespacedAction(string $action): string
    {
        $rootNamespace = $this->getAppRootControllerNamespace();

        if ($rootNamespace && strpos($action, '\\') !== 0) {
            return $rootNamespace . '\\' . $action;
        }

        return trim($action, '\\');
    }

    /**
     * @return \Illuminate\Routing\UrlGenerator
     */
    protected function getUrlGenerator(): ?Url
    {
        return $this->app['url'] ?? null;
    }

    /**
     * @return \Illuminate\Http\Request
     */
    protected function getRequestObject(): ?SymfonyRequest
    {
        return $this->app['request'] ?? null;
    }

    /**
     * @return \Illuminate\Routing\Router
     */
    protected function getRouter(): ?Router
    {
        return $this->app['router'] ?? null;
    }

    /**
     * @return \Illuminate\Routing\RouteCollectionInterface|\Illuminate\Routing\RouteCollection
     */
    protected function getRoutes()
    {
        return $this->app['routes'] ?? null;
    }
}
