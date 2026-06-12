<?php

declare(strict_types=1);

namespace App;

use Nette\Bootstrap\Configurator;
use Nette\DI\Container;

final class Bootstrap
{
    private Configurator $configurator;
    private string $rootDir;

    public function __construct()
    {
        $this->rootDir = dirname(__DIR__);
        $this->configurator = new Configurator();
        $this->configurator->setTempDirectory($this->rootDir . '/temp');
    }

    public function bootConsoleApplication(): Container
    {
        $this->initializeEnvironment();
        $this->setupContainer();

        return $this->configurator->createContainer();
    }

    private function initializeEnvironment(): void
    {
        // For debugging during development, uncomment:
        // $this->configurator->setDebugMode(true);
        $this->configurator->enableTracy($this->rootDir . '/log');

        $this->configurator->createRobotLoader()
            ->addDirectory(__DIR__)
            ->register();
    }

    private function setupContainer(): void
    {
        $configDir = $this->rootDir . '/config';
        $this->configurator->addConfig($configDir . '/common.neon');

        // Local overrides for the tool itself (optional).
        if (is_file($configDir . '/local.neon')) {
            $this->configurator->addConfig($configDir . '/local.neon');
        }
    }
}
