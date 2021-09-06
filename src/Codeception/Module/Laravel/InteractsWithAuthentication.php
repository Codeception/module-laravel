<?php

declare(strict_types=1);

namespace Codeception\Module\Laravel;

use Illuminate\Contracts\Auth\Authenticatable;

trait InteractsWithAuthentication
{
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
            , 'Failed to login with credentials ' . json_encode($user)
        );
    }

    /**
     * Check that user is not authenticated.
     */
    public function dontSeeAuthentication(string $guardName = null): void
    {
        $this->assertFalse($this->isAuthenticated($guardName), 'The user is authenticated');
    }

    /**
     * Checks that a user is authenticated.
     */
    public function seeAuthentication(string $guardName = null): void
    {
        $this->assertTrue($this->isAuthenticated($guardName), 'The user is not authenticated');
    }

    /**
     * Logout user.
     */
    public function logout(): void
    {
        $this->getAuth()->logout();
    }

    /**
     * Return true if the user is authenticated, false otherwise.
     */
    protected function isAuthenticated(?string $guardName): bool
    {
        return $this->getAuth()->guard($guardName)->check();
    }
}
