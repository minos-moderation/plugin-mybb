<?php

/*
 * The POSTING side of EndToEndTest, run with PHP's CLI from the staged forum root (copied
 * there next to the fake `inc/init.php`): it boots the forum like a page of MyBB would, so
 * the plugin's entry file, its classes and the client library all load from the STAGED
 * tree, and the real cURL transport talks to the mock gateway.
 *
 * Reads a JSON job on stdin — `install` (settings to store after installing and
 * activating), `user`, `posts` (`[thread, message, subject]`) — and prints the pids as JSON.
 */

define('IN_MYBB', 1);
define('THIS_SCRIPT', 'forum-cli.php');

require __DIR__ . '/inc/init.php';

$minosJob = (array)json_decode((string)stream_get_contents(STDIN), true);

if (isset($minosJob['install'])) {
    $minosPlugin = \Minos\MyBB\Plugin::instance();
    $minosPlugin->installer->install();
    $minosPlugin->installer->activate();
    $minosForum->set((array)$minosJob['install']);
}
if (isset($minosJob['user'])) {
    $minosForum->mybb->user = (array)$minosJob['user'];
}
$minosPids = [];
foreach ((array)($minosJob['posts'] ?? []) as $name => $minosPost) {
    $minosPids[$name] = (int)$minosForum->write((bool)$minosPost[0], (string)$minosPost[1],
        isset($minosPost[2]) ? ['subject' => (string)$minosPost[2]] : [])->return_values['pid'];
}
echo json_encode(['pids' => $minosPids, 'plugin' => (new ReflectionClass(\Minos\MyBB\Plugin::class))->getFileName()]);
