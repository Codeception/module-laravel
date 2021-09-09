<?php

declare(strict_types=1);

namespace Codeception\Module\Laravel;

trait InteractsWithSession
{
    /**
     * Flush all of the current session data.
     */
    public function flushSession(): void
    {
        $this->startSession();
        $this->getSession()->flush();
    }

    /**
     * Set the session to the given array.
     */
    public function haveInSession(array $data): void
    {
        $this->startSession();

        foreach ($data as $key => $value) {
            $this->getSession()->put($key, $value);
        }
    }

    /**
     * Assert that a session variable exists.
     *
     * ```php
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

        $session = $this->getSession();

        if (!$session->has($key)) {
            $this->fail("No session variable with key '{$key}'");
        }

        if (! is_null($value)) {
            $this->assertSame($value, $session->get($key));
        }
    }

    /**
     * Assert that the session has a given list of values.
     *
     * ```php
     * <?php
     * $I->seeSessionHasValues(['key1', 'key2']);
     * $I->seeSessionHasValues(['key1' => 'value1', 'key2' => 'value2']);
     * ```
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
     * Start the session for the application.
     */
    protected function startSession(): void
    {
        if (! $this->getSession()->isStarted()) {
            $this->getSession()->start();
        }
    }

    /**
     * @return \Illuminate\Contracts\Session\Session|\Illuminate\Session\SessionManager
     */
    protected function getSession()
    {
        return $this->app['session'] ?? null;
    }
}
