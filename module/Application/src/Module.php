<?php
declare(strict_types=1);

namespace Application;

use Laminas\Mvc\MvcEvent;

class Module
{
    public function getConfig(): array
    {
        return include __DIR__ . "/../config/module.config.php";
    }

    public function onBootstrap(MvcEvent $event): void
    {
        $config = $event->getApplication()->getServiceManager()->get('config');
        $timezone = (string) ($config['app']['timezone'] ?? 'Asia/Bangkok');
        date_default_timezone_set($timezone);
    }
}
