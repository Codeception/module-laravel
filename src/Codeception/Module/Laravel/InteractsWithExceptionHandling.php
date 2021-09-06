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
    public function disableExceptionHandling(): void
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
    public function enableExceptionHandling(): void
    {
        $this->client->enableExceptionHandling();
    }
}
