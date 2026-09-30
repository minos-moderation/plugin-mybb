<?php

/*
 * A stand-in for MyBB's `inc/init.php`, copied into the staged forum root of
 * EndToEndTest and run by PHP's built-in server behind the REAL `minos-webhook.php`.
 *
 * It builds the same miniature forum as the tests (`tests/Support`), on the SQLite file the
 * test process shares (MINOS_TEST_DB), then loads the plugin as MyBB loads an active one —
 * so the plugin's classes and the client library come from the STAGED tree, through the
 * plugin's own autoloader, exactly as on a customer's forum. Composer's autoloader is not
 * loaded here on purpose.
 */

if (!defined('IN_MYBB')) {
    die('Direct initialization of this file is not allowed.');
}

$minosRepo = (string)getenv('MINOS_TEST_REPO');
spl_autoload_register(static function (string $class) use ($minosRepo): void {
    $prefix = 'Minos\\MyBB\\Tests\\Support\\';
    if (strncmp($class, $prefix, strlen($prefix)) === 0) {
        require $minosRepo . '/tests/Support/' . substr($class, strlen($prefix)) . '.php';
    }
});

define('MYBB_ROOT', dirname(__DIR__) . '/');
define('TABLE_PREFIX', 'mybb_');
define('TIME_NOW', time());

require $minosRepo . '/tests/Stubs/mybb.php';

$plugins = new Minos\MyBB\Tests\Support\FakePlugins();
$GLOBALS['plugins'] = $plugins;

// The plugin's own loader first, so Forum's references to the plugin's classes resolve
// to the staged copies.
require_once MYBB_ROOT . 'inc/plugins/minos/autoload.php';
Minos\MyBB\Tests\Support\Forum::create((string)getenv('MINOS_TEST_DB'));

// MyBB: $plugins->load() includes every active plugin.
require_once MYBB_ROOT . 'inc/plugins/minos.php';
