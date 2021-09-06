<?php

declare(strict_types=1);

namespace Codeception\Module\Laravel;

trait InteractsWithEvents
{
    /**
     * Disable events for the next requests.
     * This method does not disable model events.
     * To disable model events you have to use the disableModelEvents() method.
     *
     * ```php
     * <?php
     * $I->disableEvents();
     * ```
     */
    public function disableEvents(): void
    {
        $this->client->disableEvents();
    }

    /**
     * Disable model events for the next requests.
     *
     * ```php
     * <?php
     * $I->disableModelEvents();
     * ```
     */
    public function disableModelEvents(): void
    {
        $this->client->disableModelEvents();
    }

    /**
     * Make sure events did not fire during the test.
     *
     * ```php
     * <?php
     * $I->dontSeeEventTriggered('App\MyEvent');
     * $I->dontSeeEventTriggered(new App\Events\MyEvent());
     * $I->dontSeeEventTriggered(['App\MyEvent', 'App\MyOtherEvent']);
     * ```
     * @param string|object|string[] $expected
     */
    public function dontSeeEventTriggered($expected): void
    {
        $expected = is_array($expected) ? $expected : [$expected];

        foreach ($expected as $expectedEvent) {
            $triggered = $this->client->eventTriggered($expectedEvent);
            if ($triggered) {
                $expectedEvent = is_object($expectedEvent) ? get_class($expectedEvent) : $expectedEvent;

                $this->fail("The '{$expectedEvent}' event triggered");
            }
        }
    }

    /**
     * Make sure events fired during the test.
     *
     * ```php
     * <?php
     * $I->seeEventTriggered('App\MyEvent');
     * $I->seeEventTriggered(new App\Events\MyEvent());
     * $I->seeEventTriggered(['App\MyEvent', 'App\MyOtherEvent']);
     * ```
     * @param string|object|string[] $expected
     */
    public function seeEventTriggered($expected): void
    {
        $expected = is_array($expected) ? $expected : [$expected];

        foreach ($expected as $expectedEvent) {
            if (! $this->client->eventTriggered($expectedEvent)) {
                $expectedEvent = is_object($expectedEvent) ? get_class($expectedEvent) : $expectedEvent;

                $this->fail("The '{$expectedEvent}' event did not trigger");
            }
        }
    }
}
