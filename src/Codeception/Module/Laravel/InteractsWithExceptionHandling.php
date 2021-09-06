<?php

declare(strict_types=1);

namespace Codeception\Module\Laravel;

trait InteractsWithExceptionHandling
{
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
}
