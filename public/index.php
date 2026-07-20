<?php
declare(strict_types=1);

chdir(dirname(__DIR__));
require "vendor/autoload.php";

use Laminas\Mvc\Application;

$appConfig = require "config/application.config.php";
Application::init($appConfig)->run();
