<?php declare(strict_types = 1);

require_once __DIR__ . '/../vendor/autoload.php';

require_once __DIR__ . '/TestDaemon.php';

use Mikk3lRo\atomix\Tests\TestDaemon;

$daemon = new TestDaemon(__FILE__, null, false, in_array('noIpc', $argv));

$daemon->handleCliInvocation();
