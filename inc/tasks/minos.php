<?php

/*
 * Minos — moderacja postów: the task MyBB runs every 5 minutes (registered at install,
 * switched on by activation). It applies the failure mode to held posts that waited too
 * long for a verdict and sends again those the gateway asked to retry. The work is
 * `Minos\MyBB\Task`; this file only hands it MyBB's task row and clock.
 */

if (!defined('IN_MYBB')) {
    die('This file cannot be accessed directly.');
}

require_once MYBB_ROOT . 'inc/plugins/minos/autoload.php';

/**
 * MyBB calls `task_<file>` with the task's row.
 *
 * @param array<string,mixed> $task The row of `mybb_tasks`.
 */
function task_minos($task)
{
    $plugin = \Minos\MyBB\Plugin::instance();
    $done = $plugin->task->run(TIME_NOW);
    $plugin->platform->taskLog($task, $plugin->platform->lang('minos_task_ran',
        (string)$done['timeouts'], (string)$done['resent']));
}
