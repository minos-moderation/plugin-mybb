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

// Shared rule F10: a `2xx` only for a stored verdict. Whatever throws before the receiver
// answers — MyBB's start included, whose queries raise `mysqli_sql_exception` on PHP 8.1+
// with mysqli, and MyBB sets no exception handler — answers `500`, which the gateway
// delivers again; PHP's own page for an uncaught exception would be a `200` with
// `display_errors` on. Nothing of the error is shown or logged here.
try {
    require_once __DIR__ . '/inc/init.php';
    require_once MYBB_ROOT . 'inc/plugins/minos/autoload.php';

    $minosStatus = \Minos\MyBB\Plugin::instance()->receiver->handle(
        (string)($_SERVER['REQUEST_METHOD'] ?? 'GET'),
        isset($_SERVER['HTTP_X_WERGILIUSZ_PODPIS']) ? (string)$_SERVER['HTTP_X_WERGILIUSZ_PODPIS'] : null,
        $minosBody,
        time()
    );
} catch (\Throwable $minosError) {
    $minosStatus = 500;
}

http_response_code($minosStatus);
header('Content-Type: text/plain; charset=utf-8');
header('Cache-Control: no-store');
exit;
