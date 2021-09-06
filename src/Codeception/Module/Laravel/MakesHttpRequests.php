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
     *
     * @param string|array|null $middleware
     */
    public function disableMiddleware($middleware = null): void
    {
        $this->client->disableMiddleware($middleware);
    }
}
