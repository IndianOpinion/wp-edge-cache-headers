<?php

declare(strict_types=1);

require dirname(__DIR__) . '/vendor/autoload.php';
require __DIR__ . '/stubs/WpStub.php';
require __DIR__ . '/stubs/classes.php';
require __DIR__ . '/stubs/functions.php';
require __DIR__ . '/stubs/wp-cli-utils.php';

if (!defined('ABSPATH')) {
    define('ABSPATH', __DIR__ . '/');
}
