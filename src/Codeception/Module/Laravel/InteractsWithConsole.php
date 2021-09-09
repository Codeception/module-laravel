<?php

declare(strict_types=1);

namespace Codeception\Module\Laravel;

use Illuminate\Contracts\Console\Kernel as ConsoleKernel;
use Symfony\Component\Console\Output\OutputInterface;

trait InteractsWithConsole
{
    /**
     * Call an Artisan command.
     *
     * ```php
     * <?php
     * $I->callArtisan('command:name');
     * $I->callArtisan('command:name', ['parameter' => 'value']);
     * ```
     * Use 3rd parameter to pass in custom `OutputInterface`
     *
     * @return string|void
     */
    public function callArtisan(string $command, array $parameters = [], OutputInterface $output = null)
    {
        $console = $this->getConsoleKernel();
        if (!$output) {
            $console->call($command, $parameters);
            $output = trim($console->output());
            $this->debug($output);
            return $output;
        }

        $console->call($command, $parameters, $output);
    }

    /**
     * @return \Illuminate\Foundation\Console\Kernel
     */
    protected function getConsoleKernel(): ?ConsoleKernel
    {
        return $this->app[ConsoleKernel::class] ?? null;
    }
}
