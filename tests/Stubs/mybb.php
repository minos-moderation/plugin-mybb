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
     * MyBB: a user's permissions in a forum. Here: as the test set them for the user
     * (`user_permissions`) and the forum (`forum_permissions`), over a registered user's
     * defaults: may view the forum and its threads, sees others' threads, not moderated.
     *
     * @return array<string,int>
     */
    function forum_permissions($fid = 0, $uid = 0, $gid = 0)
    {
        return ($GLOBALS['minos_test']['user_permissions'][(int)$uid] ?? [])
            + ($GLOBALS['minos_test']['forum_permissions'][(int)$fid] ?? [])
            + ['modposts' => 0, 'modthreads' => 0, 'canview' => 1, 'canviewthreads' => 1, 'canonlyviewownthreads' => 0];
    }

    /**
     * MyBB: a substring that counts characters (`mb_substr` when it has it).
     *
     * @return string
     */
    function my_substr($string, $start, $length = null, $handle_entities = false)
    {
        return mb_substr((string)$string, (int)$start, $length === null ? null : (int)$length, 'UTF-8');
    }

    /**
     * MyBB: a thread's relative link (`THREAD_URL`, `THREAD_URL_ACTION`), escaped as MyBB
     * escapes it.
     *
     * @return string
     */
    function get_thread_link($tid, $page = 0, $action = '')
    {
        return 'showthread.php?tid=' . (int)$tid . ($action !== '' ? '&amp;action=' . $action : '');
    }

    /**
     * MyBB: a forum's row. Here: its parsing options as the test set them, over MyBB's
     * defaults for a new forum (HTML off, MyCode on).
     *
     * @return array<string,mixed>
     */
    function get_forum($fid, $active_override = 0)
    {
        return ($GLOBALS['minos_test']['forums'][(int)$fid] ?? []) + [
            'fid' => (int)$fid, 'allowhtml' => 0, 'allowmycode' => 1, 'allowimgcode' => 1, 'allowvideocode' => 1,
        ];
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
     * MyBB's `Moderation`: the six methods the plugin calls. Visibility changes as MyBB's
     * do (`1` approved, `-1` soft-deleted; a thread's first post follows its thread); the
     * counters MyBB rebuilds are not modelled — the calls are recorded instead.
     *
     * A test makes a call throw, as another plugin's `class_moderation_*` hook could, for the
     * ids in `$GLOBALS['minos_test']['throw_on']` (before any change) or `['throw_after']`
     * (after the visibility changed: a half-applied call). The message is {@see SECRET_TEXT},
     * which must reach no log.
     */
    class Moderation
    {
        /** What a thrown error says: a stand-in for post text in an error message. */
        const SECRET_TEXT = 'TAJNA_TRESC_POSTU_W_KOMUNIKACIE_BLEDU';

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
        public function unapprove_posts($pids)
        {
            $this->record('unapprove_posts', $pids);
            $this->posts($pids, 0, 1);
            return true;
        }

        /** @param array<int,int> $tids */
        public function unapprove_threads($tids)
        {
            $this->record('unapprove_threads', $tids);
            $this->threads($tids, 0, 1);
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
            $this->throwFor('throw_on', $ids);
            $GLOBALS['minos_test']['moderation'][] = [$method, array_map('intval', $ids)];
        }

        /** @param array<int,int> $ids */
        private function throwFor(string $when, array $ids): void
        {
            if (array_intersect(array_map('intval', $ids), $GLOBALS['minos_test'][$when] ?? []) !== []) {
                throw new \RuntimeException(self::SECRET_TEXT);
            }
        }

        private function posts(array $pids, int $visible, ?int $from): void
        {
            global $db;
            $where = 'pid IN (' . implode(',', array_map('intval', $pids)) . ')';
            $db->update_query('posts', ['visible' => $visible], $where . ($from === null ? '' : " AND visible='" . $from . "'"));
            $this->lastPosts();
            $this->throwFor('throw_after', $pids);
        }

        private function threads(array $tids, int $visible, ?int $from): void
        {
            global $db;
            $list = implode(',', array_map('intval', $tids));
            $only = $from === null ? '' : " AND visible='" . $from . "'";
            $db->update_query('threads', ['visible' => $visible], 'tid IN (' . $list . ')' . $only);
            $db->write_query('UPDATE ' . TABLE_PREFIX . "posts SET visible='" . $visible . "' WHERE pid IN (SELECT firstpost FROM "
                . TABLE_PREFIX . 'threads WHERE tid IN (' . $list . '))' . $only);
            $this->lastPosts();
            $this->throwFor('throw_after', $tids);
        }

        /**
         * MyBB's `update_last_post` and `update_forum_lastpost`, which `Moderation` runs: the
         * newest visible post's moment becomes the thread's and the forum's last post.
         */
        private function lastPosts(): void
        {
            global $db;
            $p = TABLE_PREFIX;
            $db->write_query("UPDATE {$p}threads SET lastpost=COALESCE((SELECT MAX(dateline) FROM {$p}posts"
                . " WHERE {$p}posts.tid={$p}threads.tid AND visible='1'), lastpost)");
            $db->write_query("UPDATE {$p}forums SET lastpost=COALESCE((SELECT MAX(lastpost) FROM {$p}threads"
                . " WHERE {$p}threads.fid={$p}forums.fid AND visible='1'), lastpost)");
        }
    }
}

if (!class_exists('postParser', false)) {
    /**
     * MyBB's `postParser`, the two methods the subscription notifications call: bad words
     * replaced from the test's list (`badwords`: word → replacement), and the text of a
     * message — here its MyCode tags dropped, enough for an excerpt.
     */
    class postParser
    {
        /** @return string */
        public function parse_badwords($message, $options = [])
        {
            return strtr((string)$message, $GLOBALS['minos_test']['badwords'] ?? []);
        }

        /** @return string */
        public function text_parse_message($message, $options = [])
        {
            if (!empty($options['filter_badwords'])) {
                $message = $this->parse_badwords($message);
            }
            return (string)preg_replace('#\\[/?[a-z]+(?:=[^\\]]*)?\\]#i', '', (string)$message);
        }
    }
}

if (!class_exists('MyLanguage', false)) {
    /**
     * MyBB's `MyLanguage`, for a recipient's language: {@see \Minos\MyBB\Tests\Support\FakeLang}.
     */
    class MyLanguage extends \Minos\MyBB\Tests\Support\FakeLang
    {
    }
}

if (!class_exists('PMDataHandler', false)) {
    /**
     * MyBB's `PMDataHandler`: validates the recipients (`toid` must name users) and inserts
     * one `privatemessages` row per recipient into the folder "Inbox" (1), as `insert_pm`
     * does. Records each message in `$GLOBALS['minos_test']['pms']`.
     */
    class PMDataHandler
    {
        /** @var bool|int */
        public $admin_override = false;

        /** @var array<string,mixed> */
        public $data = [];

        public function set_data($data)
        {
            $this->data = (array)$data;
        }

        /** @return bool */
        public function validate_pm()
        {
            global $db;
            $uids = array_map('intval', (array)($this->data['toid'] ?? []));
            if ($uids === [] || trim((string)($this->data['subject'] ?? '')) === '' || trim((string)($this->data['message'] ?? '')) === '') {
                return false;
            }
            $query = $db->simple_select('users', 'COUNT(*) AS n', 'uid IN (' . implode(',', $uids) . ')');
            return (int)$db->fetch_field($query, 'n') === count(array_unique($uids));
        }

        public function insert_pm()
        {
            global $db;
            if (!empty($GLOBALS['minos_test']['pm_throws'])) {
                throw new \RuntimeException(\Moderation::SECRET_TEXT); // another plugin's `datahandler_pm_insert` hook
            }
            foreach (array_map('intval', (array)$this->data['toid']) as $uid) {
                $db->insert_query('privatemessages', ['uid' => $uid, 'toid' => $uid, 'fromid' => (int)$this->data['fromid'],
                    'folder' => 1, 'subject' => $db->escape_string((string)$this->data['subject']),
                    'message' => $db->escape_string((string)$this->data['message'])]);
                $GLOBALS['minos_test']['pms'][] = ['to' => $uid, 'from' => (int)$this->data['fromid'],
                    'override' => (bool)$this->admin_override, 'subject' => (string)$this->data['subject'],
                    'message' => (string)$this->data['message']];
            }
        }
    }
}
