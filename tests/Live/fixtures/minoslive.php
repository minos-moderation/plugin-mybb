<?php

/*
 * A watcher for the live run, installed next to Minos on the test forum only (never
 * shipped): it records which of MyBB 1.8.41's hooks ran, and in which script, one line
 * each in `cache/minoslive-hooks.log`. It changes nothing and returns nothing.
 */

if (!defined('IN_MYBB')) {
    die('Direct initialization of this file is not allowed.');
}

const MINOSLIVE_HOOKS = [
    'polls_newpoll_start', 'polls_do_newpoll_start', 'polls_do_newpoll_process', 'polls_do_newpoll_end',
    'polls_editpoll_start', 'polls_do_editpoll_end',
    'newthread_do_newthread_start', 'newthread_do_newthread_end', 'newreply_do_newreply_end',
    'datahandler_post_validate_thread', 'datahandler_post_validate_post',
    'datahandler_post_insert_thread_end', 'datahandler_post_insert_post_end', 'datahandler_post_update',
    'editpost_start', 'editpost_do_editpost_start', 'editpost_do_editpost_end',
    'class_moderation_approve_posts', 'class_moderation_approve_threads',
    'modcp_do_modqueue_start', 'modcp_do_modqueue_end',
];

/**
 * One method per hook, by name: MyBB calls `[$watcher, '<hook>']`.
 */
final class MinosLiveWatcher
{
    public function __call($hook, $arguments)
    {
        minoslive_record($hook);
        return null;
    }
}

$minosliveWatcher = new MinosLiveWatcher();
foreach (MINOSLIVE_HOOKS as $minosliveHook) {
    $plugins->add_hook($minosliveHook, [$minosliveWatcher, $minosliveHook]);
}

function minoslive_info()
{
    return [
        'name' => 'Minos live watcher (test forum only)', 'description' => 'Records the hooks that ran.',
        'website' => '', 'author' => 'Minos', 'authorsite' => '', 'version' => '1', 'compatibility' => '18*',
        'codename' => 'minoslive',
    ];
}

function minoslive_activate()
{
}

function minoslive_deactivate()
{
}

function minoslive_record($hook)
{
    file_put_contents(MYBB_ROOT . 'cache/minoslive-hooks.log',
        (defined('THIS_SCRIPT') ? THIS_SCRIPT : '?') . ' ' . $hook . "\n", FILE_APPEND | LOCK_EX);
}
