<?php

/*
 * The plugin's own class loader. MyBB has no Composer, so the distributable carries the
 * client library under `inc/plugins/minos/vendor/` (built by `bin/build-zip.sh`) and this
 * file maps both namespaces onto the tree:
 *
 * - `Minos\MyBB\`   → `inc/plugins/minos/src/`
 * - `Minos\Client\` → `inc/plugins/minos/vendor/minos-moderation/client-php/src/`
 *
 * It does not use Composer's generated autoloader, whose paths are relative to the
 * directory `composer install` ran in, not to where the zip puts `vendor/`. In development
 * Composer's autoloader (loaded first by the tests) already knows both namespaces, and a
 * class it has loaded is never looked up here.
 */

if (!defined('IN_MYBB')) {
    die('This file cannot be accessed directly.');
}

spl_autoload_register(static function (string $class): void {
    $roots = [
        'Minos\\MyBB\\'   => __DIR__ . '/src/',
        'Minos\\Client\\' => __DIR__ . '/vendor/minos-moderation/client-php/src/',
    ];
    foreach ($roots as $prefix => $dir) {
        if (strncmp($class, $prefix, strlen($prefix)) === 0) {
            $file = $dir . str_replace('\\', '/', substr($class, strlen($prefix))) . '.php';
            if (is_file($file)) {
                require $file;
            }
            return;
        }
    }
});
