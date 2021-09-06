<?php

declare(strict_types=1);

namespace Codeception\Module\Laravel;

use Illuminate\Contracts\Routing\UrlGenerator;
use Illuminate\Http\Request;
use Illuminate\Routing\Route;
use Illuminate\Routing\Router;
use ReflectionClass;
use ReflectionException;

trait InteractsWithRouting
{
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
}
