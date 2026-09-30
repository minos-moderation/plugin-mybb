<?php

/*
 * Minos — moderacja postów: the address the Wergiliusz gateway delivers verdicts to,
 * `https://<forum>/minos-webhook.php` (register it with the forum's key).
 *
 * It sits in the forum's root and starts MyBB the way `task.php` does — `inc/init.php`
 * only: the database, the settings, the cache and the plugins, but no session, no page and
 * no "who is online" entry. The raw body is read FIRST, before anything could parse it:
 * the signature covers its exact bytes. `Minos\MyBB\Receiver` decides the status; the
 * answer carries nothing else.
 */

define('IN_MYBB', 1);
define('NO_ONLINE', 1);
define('THIS_SCRIPT', 'minos-webhook.php');

$minosBody = (string)file_get_contents('php://input');

require_once __DIR__ . '/inc/init.php';
require_once MYBB_ROOT . 'inc/plugins/minos/autoload.php';

$minosStatus = \Minos\MyBB\Plugin::instance()->receiver->handle(
    (string)($_SERVER['REQUEST_METHOD'] ?? 'GET'),
    isset($_SERVER['HTTP_X_WERGILIUSZ_PODPIS']) ? (string)$_SERVER['HTTP_X_WERGILIUSZ_PODPIS'] : null,
    $minosBody,
    time()
);

http_response_code($minosStatus);
header('Content-Type: text/plain; charset=utf-8');
header('Cache-Control: no-store');
exit;
