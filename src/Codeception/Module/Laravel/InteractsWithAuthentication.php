<?php

declare(strict_types=1);

namespace Codeception\Module\Laravel;

use Illuminate\Auth\GuardHelpers;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Contracts\Auth\Factory as Auth;

trait InteractsWithAuthentication
{
    /**
     * Set the given user object to the current or specified Guard.
     *
     * ```php
     * <?php
     * $I->amActingAs($user);
     * ```
     */
    public function amActingAs(Authenticatable $user, string $guardName = null): void
    {
        if (property_exists($user, 'wasRecentlyCreated') && $user->wasRecentlyCreated) {
            $user->wasRecentlyCreated = false;
        }

        $this->getAuth()->guard($guardName)->setUser($user);

        $this->getAuth()->shouldUse($guardName);
    }

    /**
     * Set the currently logged in user for the application.
     * Unlike 'amActingAs', this method does update the session, fire the login events
     * and remember the user as it assigns the corresponding Cookie.
     *
     * ```php
     * <?php
     * // provide array of credentials
     * $I->amLoggedAs(['username' => 'jane@example.com', 'password' => 'password']);
     *
     * // provide User object that implements the User interface
     * $I->amLoggedAs( new User );
     *
     * // can be verified with $I->seeAuthentication();
     * ```
     * @param Authenticatable|array $user
     * @param string|null $guardName
     */
    public function amLoggedAs($user, string $guardName = null): void
    {
        if ($user instanceof Authenticatable) {
            $this->getAuth()->login($user);
            return;
        }

        $guard = $this->getAuth()->guard($guardName);
        $this->assertTrue(
            $guard->attempt($user)
            , 'Failed to login with credentials ' . json_encode($user, JSON_THROW_ON_ERROR)
        );
    }

    /**
     * Assert that the user is authenticated as the given user.
     *
     * ```php
     * <?php
     * $I->assertAuthenticatedAs($user);
     * ```
     */
    public function assertAuthenticatedAs(Authenticatable $user, string $guardName = null): void
    {
        $expected = $this->getAuth()->guard($guardName)->user();

        $this->assertNotNull($expected, 'The current user is not authenticated.');

        $this->assertInstanceOf(
            get_class($expected), $user,
            'The currently authenticated user is not who was expected'
        );

        $this->assertSame(
            $expected->getAuthIdentifier(), $user->getAuthIdentifier(),
            'The currently authenticated user is not who was expected'
        );
    }

    /**
     * Assert that the given credentials are valid.
     *
     * ```php
     * <?php
     * $I->assertCredentials([
     *     'email' => 'john_doe@gmail.com',
     *     'password' => '123456'
     * ]);
     * ```
     */
    public function assertCredentials(array $credentials, string $guardName = null): void
    {
        $this->assertTrue(
            $this->hasCredentials($credentials, $guardName), 'The given credentials are invalid.'
        );
    }

    /**
     * Assert that the given credentials are invalid.
     *
     * ```php
     * <?php
     * $I->assertInvalidCredentials([
     *     'email' => 'john_doe@gmail.com',
     *     'password' => 'wrong_password'
     * ]);
     * ```
     */
    public function assertInvalidCredentials(array $credentials, string $guardName = null): void
    {
        $this->assertFalse(
            $this->hasCredentials($credentials, $guardName), 'The given credentials are valid.'
        );
    }

    /**
     * Check that user is not authenticated.
     *
     * ```php
     * <?php
     * $I->dontSeeAuthentication();
     * ```
     */
    public function dontSeeAuthentication(string $guardName = null): void
    {
        $this->assertFalse($this->isAuthenticated($guardName), 'The user is authenticated');
    }

    /**
     * Logout user.
     *
     * ```php
     * <?php
     * $I->logout();
     * ```
     */
    public function logout(): void
    {
        $this->getAuth()->logout();
    }

    /**
     * Checks that a user is authenticated.
     *
     * ```php
     * <?php
     * $I->seeAuthentication();
     * ```
     */
    public function seeAuthentication(string $guardName = null): void
    {
        $this->assertTrue($this->isAuthenticated($guardName), 'The user is not authenticated');
    }

    /**
     * Return true if the credentials are valid, false otherwise.
     */
    protected function hasCredentials(array $credentials, string $guardName = null): bool
    {
        /** @var GuardHelpers $guard */
        $guard = $this->getAuth()->guard($guardName);
        $provider = $guard->getProvider();

        $user = $provider->retrieveByCredentials($credentials);

        return $user && $provider->validateCredentials($user, $credentials);
    }

    /**
     * Return true if the user is authenticated, false otherwise.
     */
    protected function isAuthenticated(?string $guardName): bool
    {
        return $this->getAuth()->guard($guardName)->check();
    }

    /**
     * @return \Illuminate\Auth\AuthManager|\Illuminate\Contracts\Auth\StatefulGuard
     */
    protected function getAuth(): ?Auth
    {
        return $this->app['auth'] ?? null;
    }
}
