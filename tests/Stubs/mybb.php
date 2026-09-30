<?php

/*
 * Hand-written stand-ins for the MyBB 1.8 functions and the `Moderation` class the plugin
 * calls (through `Minos\MyBB\Platform` only). No MyBB checkout: each one does what the
 * plugin relies on, on the tables `Minos\MyBB\Tests\Support\Forum` creates, and records its
 * calls in `$GLOBALS['minos_test']` so a test can ask what happened.
 *
 * Signatures follow MyBB 1.8.41. Loaded by `tests/bootstrap.php` and by the end-to-end
 * test's fake `inc/init.php`. No PHP 8 syntax: CI runs it on PHP 7.4 too.
 */

if (!function_exists('is_moderator')) {
    /**
     * MyBB: whether a user moderates a forum. Here: whether the test named the user.
     *
     * @param int    $fid    The forum.
     * @param string $action A permission ('' for any).
     * @param int    $uid    The user.
     * @return bool
     */
    function is_moderator($fid = 0, $action = '', $uid = 0)
    {
        return in_array((int)$uid, $GLOBALS['minos_test']['moderators'] ?? [], true);
    }

    /**
     * MyBB: a user's permissions in a forum. Here: `modposts`/`modthreads` as the test set them.
     *
     * @return array<string,int>
     */
    function forum_permissions($fid = 0, $uid = 0, $gid = 0)
    {
        return ($GLOBALS['minos_test']['forum_permissions'][(int)$fid] ?? []) + ['modposts' => 0, 'modthreads' => 0];
    }

    /**
     * MyBB: writes `inc/settings.php` from the settings table. Here: reloads `$mybb->settings`.
     */
    function rebuild_settings()
    {
        global $db, $mybb;
        $GLOBALS['minos_test']['rebuilt'] = ($GLOBALS['minos_test']['rebuilt'] ?? 0) + 1;
        $query = $db->simple_select('settings', 'name,value');
        while ($row = $db->fetch_array($query)) {
            $mybb->settings[$row['name']] = $row['value'];
        }
    }

    /**
     * MyBB: the next run of a task.
     *
     * @param array<string,mixed> $task
     * @return int
     */
    function fetch_next_run($task)
    {
        return TIME_NOW + 300;
    }

    /**
     * MyBB: a line in the task log.
     *
     * @param array<string,mixed> $task
     * @param string              $message
     */
    function add_task_log($task, $message)
    {
        $GLOBALS['minos_test']['task_log'][] = $message;
    }

    /**
     * MyBB: a post's relative link.
     *
     * @return string
     */
    function get_post_link($pid, $tid = 0)
    {
        return 'showthread.php?tid=' . (int)$tid . '&pid=' . (int)$pid;
    }
}

if (!class_exists('Moderation', false)) {
    /**
     * MyBB's `Moderation`: the four methods the plugin calls. Visibility changes as MyBB's
     * do (`1` approved, `-1` soft-deleted; a thread's first post follows its thread); the
     * counters MyBB rebuilds are not modelled — the calls are recorded instead.
     */
    class Moderation
    {
        /** @param array<int,int> $pids */
        public function approve_posts($pids)
        {
            $this->record('approve_posts', $pids);
            $this->posts($pids, 1, 0);
            return true;
        }

        /** @param array<int,int> $tids */
        public function approve_threads($tids)
        {
            $this->record('approve_threads', $tids);
            $this->threads($tids, 1, 0);
            return true;
        }

        /** @param array<int,int> $pids */
        public function soft_delete_posts($pids)
        {
            $this->record('soft_delete_posts', $pids);
            $this->posts($pids, -1, null);
            return true;
        }

        /** @param array<int,int> $tids */
        public function soft_delete_threads($tids)
        {
            $this->record('soft_delete_threads', $tids);
            $this->threads($tids, -1, null);
            return true;
        }

        private function record(string $method, array $ids): void
        {
            $GLOBALS['minos_test']['moderation'][] = [$method, array_map('intval', $ids)];
        }

        private function posts(array $pids, int $visible, ?int $from): void
        {
            global $db;
            $where = 'pid IN (' . implode(',', array_map('intval', $pids)) . ')';
            $db->update_query('posts', ['visible' => $visible], $where . ($from === null ? '' : " AND visible='" . $from . "'"));
        }

        private function threads(array $tids, int $visible, ?int $from): void
        {
            global $db;
            $list = implode(',', array_map('intval', $tids));
            $only = $from === null ? '' : " AND visible='" . $from . "'";
            $db->update_query('threads', ['visible' => $visible], 'tid IN (' . $list . ')' . $only);
            $db->write_query('UPDATE ' . TABLE_PREFIX . "posts SET visible='" . $visible . "' WHERE pid IN (SELECT firstpost FROM "
                . TABLE_PREFIX . 'threads WHERE tid IN (' . $list . '))' . $only);
        }
    }
}
