<?php

declare(strict_types=1);

namespace Codeception\Module\Laravel;

use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Contracts\Auth\Factory as AuthContract;

trait InteractsWithAuthentication
{
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
     * @param string|null $guardName The guard name
     */
    public function amLoggedAs($user, ?string $guardName = null): void
    {
        /** @var AuthContract $auth */
        $auth = $this->app['auth'];

        $guard = $auth->guard($guardName);

        if ($user instanceof Authenticatable) {
            $guard->login($user);
            return;
        }

        $this->assertTrue($guard->attempt($user), 'Failed to login with credentials ' . json_encode($user));
    }

    /**
     * Check that user is not authenticated.
     * You can specify the guard that should be use as second parameter.
     *
     * @param string|null $guard
     */
    public function dontSeeAuthentication(?string $guard = null): void
    {
        /** @var AuthContract $auth */
        $auth = $this->app['auth'];

        if (is_string($guard)) {
            $auth = $auth->guard($guard);
        }

        $this->assertNotTrue($auth->check(), 'There is an user authenticated');
    }

    /**
     * Checks that a user is authenticated.
     * You can specify the guard that should be use as second parameter.
     *
     * @param string|null $guard
     */
    public function seeAuthentication($guard = null): void
    {
        /** @var AuthContract $auth */
        $auth = $this->app['auth'];

        $auth = $auth->guard($guard);

        $this->assertTrue($auth->check(), 'There is no authenticated user');
    }

    /**
     * Logout user.
     */
    public function logout(): void
    {
        $this->app['auth']->logout();
    }
}
