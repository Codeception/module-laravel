<?php

declare(strict_types=1);

namespace Codeception\Module\Laravel;

trait MakesHttpRequests
{
    /**
     * Disable middleware for the next requests.
     *
     * ```php
     * <?php
     * $I->disableMiddleware();
     * ```
     */
    public function disableMiddleware()
    {
        $this->client->disableMiddleware();
    }
}
