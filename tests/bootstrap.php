<?php

/*
 * The test process as MyBB would set it up for a plugin: MyBB's constants, the stand-ins
 * for its functions and `Moderation` (`tests/Stubs/mybb.php`), a `$plugins` object, and
 * the plugin's REAL entry file and task file, included once — so every test that posts
 * goes through the hook functions MyBB would call. `IN_ADMINCP` is defined so the ACP hooks
 * are registered too. Each test builds its own forum (`Support\Forum::create`).
 */

declare(strict_types=1);

require __DIR__ . '/../vendor/autoload.php';

define('IN_MYBB', 1);
define('IN_ADMINCP', 1);
define('MYBB_ROOT', dirname(__DIR__) . '/');
define('TABLE_PREFIX', 'mybb_');
define('TIME_NOW', time());

require __DIR__ . '/Stubs/mybb.php';

$plugins = new Minos\MyBB\Tests\Support\FakePlugins();
$GLOBALS['plugins'] = $plugins;

require MYBB_ROOT . 'inc/plugins/minos.php';
require MYBB_ROOT . 'inc/tasks/minos.php';
