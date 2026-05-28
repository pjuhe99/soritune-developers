<?php
declare(strict_types=1);
require __DIR__ . '/../vendor/autoload.php';
$configPath = __DIR__ . '/../public_html/config.php';
if (file_exists($configPath)) {
    require $configPath;
}
