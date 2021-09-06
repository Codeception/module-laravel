<?php

declare(strict_types=1);

namespace Codeception\Module\Laravel;

use Illuminate\Contracts\View\Factory as ViewContract;
use Illuminate\Support\ViewErrorBag;

trait InteractsWithViews
{
    /**
     * Assert that there are no form errors bound to the View.
     *
     * ``` php
     * <?php
     * $I->dontSeeFormErrors();
     * ```
     */
    public function dontSeeFormErrors(): void
    {
        /** @var ViewContract $view */
        $view = $this->app->make('view');
        /** @var ViewErrorBag $viewErrorBag */
        $viewErrorBag = $view->shared('errors');

        $this->assertEquals(
            0,
            $viewErrorBag->count(),
            'Expecting that the form does not have errors, but there were!'
        );
    }

    /**
     * Assert that a specific form error message is set in the view.
     *
     * If you want to assert that there is a form error message for a specific key
     * but don't care about the actual error message you can omit `$expectedErrorMessage`.
     *
     * If you do pass `$expectedErrorMessage`, this method checks if the actual error message for a key
     * contains `$expectedErrorMessage`.
     *
     * ``` php
     * <?php
     * $I->seeFormErrorMessage('username');
     * $I->seeFormErrorMessage('username', 'Invalid Username');
     * ```
     * @param string $field
     * @param string|null $errorMessage
     */
    public function seeFormErrorMessage(string $field, $errorMessage = null): void
    {
        /** @var ViewContract $view */
        $view =  $this->app['view'];
        /** @var ViewErrorBag $viewErrorBag */
        $viewErrorBag = $view->shared('errors');

        if (!($viewErrorBag->has($field))) {
            $this->fail("No form error message for key '$field'\n");
        }

        if (! is_null($errorMessage)) {
            $this->assertStringContainsString($errorMessage, $viewErrorBag->first($field));
        }
    }

    /**
     * Verifies that multiple fields on a form have errors.
     *
     * This method will validate that the expected error message
     * is contained in the actual error message, that is,
     * you can specify either the entire error message or just a part of it:
     *
     * ``` php
     * <?php
     * $I->seeFormErrorMessages([
     *     'address'   => 'The address is too long',
     *     'telephone' => 'too short' // the full error message is 'The telephone is too short'
     * ]);
     * ```
     *
     * If you don't want to specify the error message for some fields,
     * you can pass `null` as value instead of the message string.
     * If that is the case, it will be validated that
     * that field has at least one error of any type:
     *
     * ``` php
     * <?php
     * $I->seeFormErrorMessages([
     *     'telephone' => 'too short',
     *     'address'   => null
     * ]);
     * ```
     *
     * @param array $expectedErrors
     */
    public function seeFormErrorMessages(array $expectedErrors): void
    {
        foreach ($expectedErrors as $field => $message) {
            $this->seeFormErrorMessage($field, $message);
        }
    }

    /**
     * Assert that form errors are bound to the View.
     *
     * ``` php
     * <?php
     * $I->seeFormHasErrors();
     * ```
     */
    public function seeFormHasErrors(): void
    {
        /** @var ViewContract $view */
        $view = $this->app->make('view');
        /** @var ViewErrorBag $viewErrorBag */
        $viewErrorBag = $view->shared('errors');

        $this->assertGreaterThan(
            0,
            $viewErrorBag->count(),
            'Expecting that the form has errors, but there were none!'
        );
    }
}
