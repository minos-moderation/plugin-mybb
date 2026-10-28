<?php

/*
 * Installs MyBB 1.8.41 through ITS OWN installer, over HTTP, the way an administrator
 * clicks through `install/index.php`: create_tables → populate_tables → templates →
 * configuration → adminuser → final. Run by `bin/verify-mybb-1841.sh` against a forum
 * root served by PHP's built-in server.
 *
 * Environment: MINOS_LIVE_URL (the forum), MINOS_LIVE_ENGINE (`mysqli` or `pgsql`),
 * MINOS_LIVE_DB_HOST, MINOS_LIVE_DB_NAME, MINOS_LIVE_DB_USER, MINOS_LIVE_DB_PASS,
 * MINOS_LIVE_ADMIN_PASS. Test data only: an invented board, administrator and password.
 */

declare(strict_types=1);

require __DIR__ . '/../../vendor/autoload.php';

use Minos\MyBB\Tests\Live\Support\Browser;
use Minos\MyBB\Tests\Live\Support\Live;

$url = Live::env('MINOS_LIVE_URL');
$engine = Live::env('MINOS_LIVE_ENGINE');
$browser = new Browser($url);

$config = [
    'dbhost'      => Live::env('MINOS_LIVE_DB_HOST'),
    'dbuser'      => Live::env('MINOS_LIVE_DB_USER'),
    'dbpass'      => Live::env('MINOS_LIVE_DB_PASS'),
    'dbname'      => Live::env('MINOS_LIVE_DB_NAME'),
    'tableprefix' => Live::PREFIX,
];
if ($engine === 'mysqli') {
    $config['encoding'] = 'utf8mb4';
}

$steps = [
    ['action' => 'create_tables', 'dbengine' => $engine, 'config' => [$engine => $config]],
    ['action' => 'populate_tables'],
    ['action' => 'templates'],
    ['action' => 'configuration'],
    [
        'action' => 'adminuser', 'bbname' => 'Forum testowe Minos', 'bburl' => $url,
        'websitename' => 'Forum testowe Minos', 'websiteurl' => $url, 'cookiedomain' => '',
        'cookiepath' => '/', 'contactemail' => 'admin@example.com', 'pin' => '',
        'allow_anonymous_info' => '0',
    ],
    [
        'action' => 'final', 'adminuser' => Live::ADMIN, 'adminpass' => Live::env('MINOS_LIVE_ADMIN_PASS'),
        'adminpass2' => Live::env('MINOS_LIVE_ADMIN_PASS'), 'adminemail' => 'admin@example.com',
    ],
];
foreach ($steps as $fields) {
    $html = $browser->post('install/index.php', $fields);
    // The installer's own error box (`*_step_error_config`): `<div class="error">` and an
    // "Error" heading; the last page carries another `error`-styled box, an advertisement.
    if ($browser->status !== 200 || preg_match('#<div class="error">\s*<h3>(.*?)</div>#s', $html, $m)) {
        fwrite(STDERR, "install step {$fields['action']} failed (HTTP {$browser->status}): "
            . trim(strip_tags($m[1] ?? substr($html, 0, 2000))) . "\n");
        exit(1);
    }
}
$root = Live::env('MINOS_LIVE_ROOT');
if (!is_file($root . '/install/lock')) {
    fwrite(STDERR, "the installer did not finish: no install/lock\n");
    exit(1);
}
echo "MyBB installed on {$engine}\n";
