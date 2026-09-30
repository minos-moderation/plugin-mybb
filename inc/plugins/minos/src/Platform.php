<?php

declare(strict_types=1);

namespace Minos\MyBB;

/**
 * The ONE place the plugin touches MyBB: its globals (`$mybb`, `$db`, `$cache`, `$lang`,
 * `$page`), its functions (`is_moderator`, `forum_permissions`, `rebuild_settings`,
 * `fetch_next_run`, `add_task_log`, `get_post_link`), its `Moderation` class and its tables.
 * Every other class works through this one, so a MyBB change lands here, and the tests run
 * the real class against hand-written stand-ins for those globals (`tests/Stubs/`).
 *
 * The MyBB 1.8 APIs used here were checked against the MyBB 1.8.41 source; `docs/development.md`
 * lists each one. No PHP 8 syntax.
 */
final class Platform
{
    /** The plugin's codename (`inc/plugins/minos.php`, the language file, the task file). */
    public const CODENAME = 'minos';

    /** One row per post the plugin held (without MyBB's table prefix). */
    public const PENDING_TABLE = 'minos_pending';

    /** Events worth an administrator's look: codes, never content. */
    public const LOG_TABLE = 'minos_log';

    /** The settings group's name. */
    public const SETTING_GROUP = 'minos';

    /** @var object MyBB's `$mybb`. */
    private $mybb;

    /** @var object MyBB's `$db`. */
    private $db;

    /** @var object MyBB's `$cache`. */
    private $cache;

    /** @var object MyBB's `$lang`. */
    private $lang;

    /** @var string MyBB's root directory, with a trailing slash. */
    private $root;

    /** @var string MyBB's table prefix. */
    private $prefix;

    /** @var int|null The current user's `moderateposts` before the plugin raised it. */
    private $savedModeration;

    /** @var bool */
    private $langLoaded = false;

    /**
     * @param object $mybb   `$mybb`.
     * @param object $db     `$db`.
     * @param object $cache  `$cache`.
     * @param object $lang   `$lang`.
     * @param string $root   `MYBB_ROOT`.
     * @param string $prefix `TABLE_PREFIX`.
     */
    public function __construct($mybb, $db, $cache, $lang, string $root, string $prefix)
    {
        $this->mybb = $mybb;
        $this->db = $db;
        $this->cache = $cache;
        $this->lang = $lang;
        $this->root = rtrim($root, '/') . '/';
        $this->prefix = $prefix;
    }

    /**
     * The adapter over the running forum's globals.
     *
     * @return self The adapter.
     */
    public static function fromGlobals(): self
    {
        global $mybb, $db, $cache, $lang;
        return new self($mybb, $db, $cache, $lang, MYBB_ROOT, TABLE_PREFIX);
    }

    // ---------------------------------------------------------------- the forum

    /**
     * MyBB's settings.
     *
     * @return array<string,mixed> `$mybb->settings`.
     */
    public function settings(): array
    {
        return is_array($this->mybb->settings ?? null) ? $this->mybb->settings : [];
    }

    /**
     * The forum's address.
     *
     * @return string `bburl`, without a trailing slash.
     */
    public function boardUrl(): string
    {
        return rtrim((string)($this->settings()['bburl'] ?? ''), '/');
    }

    /**
     * Whether the plugin is activated in the ACP (the webhook runs outside the plugin
     * system's loading and must ask).
     *
     * @return bool True when `minos` is in the `plugins` cache's active list.
     */
    public function isPluginActive(): bool
    {
        $plugins = $this->cache->read('plugins');
        return is_array($plugins) && isset($plugins['active'][self::CODENAME]);
    }

    /**
     * A string of the plugin's language file (Polish in both `polish/` and `english/`).
     *
     * @param string $key  The key without its `$l[...]` wrapper.
     * @param string ...$args Values for `{1}`, `{2}`… (MyBB's placeholder style).
     * @return string The text, or the key itself when it is missing.
     */
    public function lang(string $key, string ...$args): string
    {
        if (!$this->langLoaded) {
            $this->lang->load(self::CODENAME, true, true);
            $this->langLoaded = true;
        }
        $text = isset($this->lang->{$key}) ? (string)$this->lang->{$key} : $key;
        foreach ($args as $i => $arg) {
            $text = str_replace('{' . ($i + 1) . '}', $arg, $text);
        }
        return $text;
    }

    // ---------------------------------------------------------------- the poster

    /**
     * The id of the user making this request.
     *
     * @return int 0 for a guest.
     */
    public function currentUserId(): int
    {
        return (int)($this->mybb->user['uid'] ?? 0);
    }

    /**
     * Whether the current user has never had a post counted — the `author_first_post`
     * spam signal, cheap because MyBB keeps `postnum` on the user.
     *
     * @return bool|null Null for a guest (unknown).
     */
    public function currentUserHasNoPosts(): ?bool
    {
        if ($this->currentUserId() === 0) {
            return null;
        }
        return (int)($this->mybb->user['postnum'] ?? 0) === 0;
    }

    /**
     * Makes MyBB send the current user's next post to its moderation queue.
     *
     * `insert_post`/`insert_thread` decide visibility in a local variable BEFORE their
     * insert hooks run, and count, notify subscribers and update the last post from that
     * variable. Changing `visible` in the insert hook would leave all of that as if the post
     * were public. The one input MyBB reads is `$mybb->user['moderateposts']` (checked for
     * the poster when `$mybb->user['uid'] == uid`), so the plugin raises it in the validate
     * hook, and MyBB takes its own moderated path: `visible = 0`, `unapprovedposts` counted,
     * no subscription e-mail. It is only this request's copy; nothing is saved.
     */
    public function holdCurrentUsersPosts(): void
    {
        if ($this->savedModeration === null) {
            $this->savedModeration = (int)($this->mybb->user['moderateposts'] ?? 0);
        }
        $this->mybb->user['moderateposts'] = 1;
    }

    /**
     * Puts the current user's `moderateposts` back as it was.
     */
    public function releaseCurrentUsersPosts(): void
    {
        if ($this->savedModeration !== null) {
            $this->mybb->user['moderateposts'] = $this->savedModeration;
            $this->savedModeration = null;
        }
    }

    /**
     * Whether a user moderates a forum (super moderators and administrators do everywhere).
     *
     * @param int $fid The forum.
     * @param int $uid The user.
     * @return bool MyBB's `is_moderator`.
     */
    public function isModerator(int $fid, int $uid): bool
    {
        return (bool)is_moderator($fid, '', $uid);
    }

    /**
     * Whether MyBB would send this post to its queue anyway — the forum moderates new
     * posts or threads, or the user is under moderation. The plugin leaves such a post to
     * the humans who asked for it.
     *
     * @param int  $fid    The forum.
     * @param int  $uid    The poster.
     * @param bool $thread A new thread (else a reply).
     * @return bool True when MyBB itself holds it.
     */
    public function forumHoldsAnyway(int $fid, int $uid, bool $thread): bool
    {
        // The user's own flag, not the one the plugin may have raised for an earlier post
        // of this request.
        $moderated = $this->savedModeration ?? (int)($this->mybb->user['moderateposts'] ?? 0);
        if ($uid === $this->currentUserId() && $moderated === 1) {
            return true;
        }
        $permissions = forum_permissions($fid, $uid);
        $key = $thread ? 'modthreads' : 'modposts';
        return (int)($permissions[$key] ?? 0) === 1 && !$this->isModerator($fid, $uid);
    }

    // ---------------------------------------------------------------- posts

    /**
     * A post as it is now, with whether it opens its thread.
     *
     * @param int $pid The post.
     * @return array{pid:int,tid:int,fid:int,visible:int,subject:string,message:string,first:bool}|null
     *     Null when it no longer exists.
     */
    public function post(int $pid): ?array
    {
        $query = $this->db->query(
            'SELECT p.pid, p.tid, p.fid, p.visible, p.subject, p.message, t.firstpost'
            . ' FROM ' . $this->prefix . 'posts p'
            . ' LEFT JOIN ' . $this->prefix . 'threads t ON (t.tid=p.tid)'
            . " WHERE p.pid='" . $pid . "'"
        );
        $row = $this->db->fetch_array($query);
        if (!is_array($row) || !isset($row['pid'])) {
            return null;
        }
        return [
            'pid'     => (int)$row['pid'],
            'tid'     => (int)$row['tid'],
            'fid'     => (int)$row['fid'],
            'visible' => (int)$row['visible'],
            'subject' => (string)$row['subject'],
            'message' => (string)$row['message'],
            'first'   => (int)$row['firstpost'] === (int)$row['pid'],
        ];
    }

    /**
     * Replaces a post's message, directly in `posts`.
     *
     * Not through the post datahandler's update: that validates against the current user
     * (the webhook has none), marks the post as edited by uid 0, and re-runs the
     * `datahandler_post_update` hooks — including the plugin's own, which treats an edit
     * of a held post as superseding its verdict. MyBB keeps no other copy of a message.
     *
     * @param int    $pid     The post.
     * @param string $message The new message.
     */
    public function replaceMessage(int $pid, string $message): void
    {
        $this->db->update_query('posts', ['message' => $this->db->escape_string($message)], "pid='" . $pid . "'");
    }

    /**
     * Approves a held post the way a moderator's "approve" does — `Moderation`, which
     * rebuilds the thread, forum and user counters and the last-post data.
     *
     * @param array{pid:int,tid:int,first:bool} $post The post ({@see post}).
     */
    public function approve(array $post): void
    {
        $moderation = $this->moderation();
        if ($post['first']) {
            $moderation->approve_threads([$post['tid']]);
        } else {
            $moderation->approve_posts([$post['pid']]);
        }
    }

    /**
     * Soft-deletes a post (a thread's first post takes its thread), restorable by a
     * moderator.
     *
     * @param array{pid:int,tid:int,first:bool} $post The post ({@see post}).
     */
    public function softDelete(array $post): void
    {
        $moderation = $this->moderation();
        if ($post['first']) {
            $moderation->soft_delete_threads([$post['tid']]);
        } else {
            $moderation->soft_delete_posts([$post['pid']]);
        }
    }

    /**
     * A post's address, for the ACP page.
     *
     * @param int $pid The post.
     * @param int $tid Its thread.
     * @return string An absolute URL.
     */
    public function postUrl(int $pid, int $tid): string
    {
        return $this->boardUrl() . '/' . get_post_link($pid, $tid) . '#pid' . $pid;
    }

    // ---------------------------------------------------------------- the pending table

    /**
     * Records a held post.
     *
     * @param array<string,int|string> $row Columns of {@see PENDING_TABLE}; the rest default.
     */
    public function addPending(array $row): void
    {
        $this->db->insert_query(self::PENDING_TABLE, $this->escaped($row + [
            'tid' => 0, 'is_thread' => 0, 'status' => Status::PENDING, 'submitted_at' => 0,
            'accepted_at' => 0, 'retry_at' => 0, 'attempts' => 0, 'truncated' => 0,
            'prefix_len' => 0, 'text_len' => 0, 'verdict' => '', 'categories' => '',
            'support' => 0, 'error_code' => '', 'decided_at' => 0, 'original_text' => '',
        ]));
    }

    /**
     * One row.
     *
     * @param int $pid The post.
     * @return array<string,mixed>|null The row, or null.
     */
    public function pending(int $pid): ?array
    {
        $query = $this->db->simple_select(self::PENDING_TABLE, '*', "pid='" . $pid . "'");
        $row = $this->db->fetch_array($query);
        return is_array($row) && isset($row['pid']) ? $row : null;
    }

    /**
     * Takes a row that is still waiting, so exactly one webhook delivery or task run
     * applies it (deliveries repeat, and the task runs beside the webhook).
     *
     * @param int $pid The post.
     * @return bool True for the one caller that changed it from {@see Status::OPEN}.
     */
    public function claimPending(int $pid): bool
    {
        $this->db->update_query(self::PENDING_TABLE, ['status' => Status::APPLYING],
            "pid='" . $pid . "' AND status IN ('" . implode("','", Status::OPEN) . "')");
        return (int)$this->db->affected_rows() === 1;
    }

    /**
     * Changes a row.
     *
     * @param int                      $pid    The post.
     * @param array<string,int|string> $fields Columns to set.
     * @param string|null              $onlyIf Change it only while it has this status — how
     *     a submission records the gateway's answer without overwriting a verdict that a
     *     fast webhook delivery applied in the meantime.
     */
    public function updatePending(int $pid, array $fields, ?string $onlyIf = null): void
    {
        $where = "pid='" . $pid . "'";
        if ($onlyIf !== null) {
            $where .= " AND status='" . $this->db->escape_string($onlyIf) . "'";
        }
        $this->db->update_query(self::PENDING_TABLE, $this->escaped($fields), $where);
    }

    /**
     * Rows whose retry is due.
     *
     * @param int $now   Unix seconds.
     * @param int $limit At most this many, oldest first.
     * @return array<int,array<string,mixed>> Rows.
     */
    public function dueRetries(int $now, int $limit): array
    {
        return $this->rows(self::PENDING_TABLE, "status='" . Status::RETRY . "' AND retry_at<='" . $now . "'",
            ['order_by' => 'retry_at', 'order_dir' => 'ASC', 'limit' => $limit]);
    }

    /**
     * Rows that waited too long: accepted but never answered, or never accepted.
     *
     * @param int $before Unix seconds: accepted (or first sent, when never accepted) before it.
     * @param int $limit  At most this many.
     * @return array<int,array<string,mixed>> Rows.
     */
    public function expiredPending(int $before, int $limit): array
    {
        return $this->rows(self::PENDING_TABLE,
            "(status='" . Status::PENDING . "' AND accepted_at<'" . $before . "')"
            . " OR (status='" . Status::RETRY . "' AND submitted_at<'" . $before . "')",
            ['order_by' => 'submitted_at', 'order_dir' => 'ASC', 'limit' => $limit]);
    }

    /**
     * The newest rows a moderator should look at: held, flagged for support, or waiting.
     *
     * @param int $limit At most this many.
     * @return array<int,array<string,mixed>> Rows, newest first.
     */
    public function attentionRows(int $limit): array
    {
        return $this->rows(self::PENDING_TABLE,
            "support='1' OR status IN ('" . implode("','", [Status::HELD, Status::PENDING, Status::RETRY, Status::APPLYING]) . "')",
            ['order_by' => 'submitted_at', 'order_dir' => 'DESC', 'limit' => $limit]);
    }

    /**
     * Forgets rows decided long ago (the original text of a censored post with them).
     *
     * @param int $before Unix seconds.
     */
    public function pruneDecided(int $before): void
    {
        $this->db->delete_query(self::PENDING_TABLE,
            "decided_at>'0' AND decided_at<'" . $before . "' AND status NOT IN ('"
            . implode("','", array_merge(Status::OPEN, [Status::APPLYING])) . "')");
    }

    // ---------------------------------------------------------------- the log

    /**
     * Records an event: a code and a post id, never content, a key or a secret.
     *
     * @param string $event What happened (`config_error`, `retry`, `recovered`, `timeout`…).
     * @param string $code  The gateway's code, `http_<status>`, `siec`… ('' for none).
     * @param int    $pid   The post, 0 for none.
     * @param int    $now   Unix seconds.
     */
    public function log(string $event, string $code, int $pid, int $now): void
    {
        $this->db->insert_query(self::LOG_TABLE, $this->escaped([
            'dateline' => $now, 'event' => substr($event, 0, 20), 'code' => substr($code, 0, 40), 'pid' => $pid,
        ]));
    }

    /**
     * The newest log entry among some events.
     *
     * @param array<int,string> $events The events.
     * @return array<string,mixed>|null The entry, or null.
     */
    public function lastLog(array $events): ?array
    {
        $in = implode("','", array_map([$this->db, 'escape_string'], $events));
        $rows = $this->rows(self::LOG_TABLE, "event IN ('" . $in . "')",
            ['order_by' => 'lid', 'order_dir' => 'DESC', 'limit' => 1]);
        return $rows[0] ?? null;
    }

    /**
     * The newest log entries.
     *
     * @param int $limit At most this many.
     * @return array<int,array<string,mixed>> Entries, newest first.
     */
    public function recentLog(int $limit): array
    {
        return $this->rows(self::LOG_TABLE, '', ['order_by' => 'lid', 'order_dir' => 'DESC', 'limit' => $limit]);
    }

    /**
     * Forgets old log entries.
     *
     * @param int $before Unix seconds.
     */
    public function pruneLog(int $before): void
    {
        $this->db->delete_query(self::LOG_TABLE, "dateline<'" . $before . "'");
    }

    // ---------------------------------------------------------------- installation

    /**
     * Whether the plugin's tables exist.
     *
     * @return bool True when the pending table does.
     */
    public function tablesExist(): bool
    {
        return (bool)$this->db->table_exists(self::PENDING_TABLE);
    }

    /**
     * Creates the plugin's tables (MySQL/MariaDB, PostgreSQL or SQLite, as MyBB runs on).
     */
    public function createTables(): void
    {
        $engine = (string)($this->db->type ?? 'mysqli');
        $tables = [
            self::PENDING_TABLE => [
                'pid' => 'int', 'tid' => 'int', 'is_thread' => 'flag', 'status' => 'varchar(20)',
                'submitted_at' => 'int', 'accepted_at' => 'int', 'retry_at' => 'int',
                'attempts' => 'small', 'truncated' => 'flag', 'prefix_len' => 'small',
                'text_len' => 'small', 'verdict' => 'varchar(20)', 'categories' => 'varchar(255)',
                'support' => 'flag', 'error_code' => 'varchar(40)', 'decided_at' => 'int',
                'original_text' => 'text',
            ],
            self::LOG_TABLE => [
                'lid' => 'serial', 'dateline' => 'int', 'event' => 'varchar(20)',
                'code' => 'varchar(40)', 'pid' => 'int',
            ],
        ];
        $keys = [self::PENDING_TABLE => 'pid', self::LOG_TABLE => 'lid'];
        foreach ($tables as $table => $columns) {
            if (!$this->db->table_exists($table)) {
                $this->db->write_query(self::createTable($engine, $this->prefix . $table, $columns, $keys[$table],
                    (string)$this->db->build_create_table_collation()));
            }
        }
        $index = $this->prefix . self::PENDING_TABLE . '_status';
        if (in_array($engine, ['pgsql', 'pgsql_pdo', 'sqlite'], true)) {
            $this->db->write_query('CREATE INDEX IF NOT EXISTS ' . $index . ' ON '
                . $this->prefix . self::PENDING_TABLE . ' (status, retry_at)');
        }
    }

    /**
     * Drops the plugin's tables.
     */
    public function dropTables(): void
    {
        foreach ([self::PENDING_TABLE, self::LOG_TABLE] as $table) {
            if ($this->db->table_exists($table)) {
                $this->db->drop_table($table);
            }
        }
    }

    /**
     * Whether the settings group exists — what "installed" means for MyBB's plugin list
     * (the tables may be kept after an uninstall).
     *
     * @return bool True when it does.
     */
    public function settingsInstalled(): bool
    {
        $query = $this->db->simple_select('settinggroups', 'gid', "name='" . self::SETTING_GROUP . "'");
        return (int)$this->db->fetch_field($query, 'gid') > 0;
    }

    /**
     * Creates or refreshes the settings group and its settings, keeping stored values,
     * and removes settings the plugin no longer has (the pattern of MyBB's `hello` plugin).
     *
     * @param array{title:string,description:string}                                   $group
     * @param array<string,array{title:string,description:string,optionscode:string,value:string}> $settings
     *     By setting name.
     */
    public function saveSettings(array $group, array $settings): void
    {
        $row = [
            'name'        => self::SETTING_GROUP,
            'title'       => $this->db->escape_string($group['title']),
            'description' => $this->db->escape_string($group['description']),
            'isdefault'   => 0,
        ];
        $query = $this->db->simple_select('settinggroups', 'gid', "name='" . self::SETTING_GROUP . "'");
        $gid = (int)$this->db->fetch_field($query, 'gid');
        if ($gid > 0) {
            $this->db->update_query('settinggroups', $row, "gid='" . $gid . "'");
        } else {
            $query = $this->db->simple_select('settinggroups', 'MAX(disporder) AS disporder');
            $row['disporder'] = (int)$this->db->fetch_field($query, 'disporder') + 1;
            $gid = (int)$this->db->insert_query('settinggroups', $row);
        }

        $order = 0;
        $names = [];
        foreach ($settings as $name => $setting) {
            $names[] = $this->db->escape_string($name);
            $fields = [
                'title'       => $this->db->escape_string($setting['title']),
                'description' => $this->db->escape_string($setting['description']),
                'optionscode' => $this->db->escape_string($setting['optionscode']),
                'disporder'   => ++$order,
                'gid'         => $gid,
            ];
            $query = $this->db->simple_select('settings', 'sid', "name='" . $this->db->escape_string($name) . "'");
            $sid = (int)$this->db->fetch_field($query, 'sid');
            if ($sid > 0) {
                $this->db->update_query('settings', $fields, "sid='" . $sid . "'");
            } else {
                $this->db->insert_query('settings', $fields + [
                    'name'  => $this->db->escape_string($name),
                    'value' => $this->db->escape_string($setting['value']),
                ]);
            }
        }
        $this->db->delete_query('settings', "gid='" . $gid . "' AND name NOT IN ('" . implode("','", $names) . "')");
        rebuild_settings();
    }

    /**
     * Removes the settings group and its settings.
     */
    public function removeSettings(): void
    {
        $query = $this->db->simple_select('settinggroups', 'gid', "name='" . self::SETTING_GROUP . "'");
        $gid = (int)$this->db->fetch_field($query, 'gid');
        if ($gid > 0) {
            $this->db->delete_query('settings', "gid='" . $gid . "'");
        }
        $this->db->delete_query('settinggroups', "name='" . self::SETTING_GROUP . "'");
        rebuild_settings();
    }

    /**
     * Creates the task (`inc/tasks/minos.php`) or refreshes its texts and schedule.
     *
     * @param array{title:string,description:string,minute:string,enabled:int} $task
     */
    public function saveTask(array $task): void
    {
        $this->requireTaskFunctions();
        $row = [
            'title'       => $this->db->escape_string($task['title']),
            'description' => $this->db->escape_string($task['description']),
            'file'        => self::CODENAME,
            'minute'      => $this->db->escape_string($task['minute']),
            'hour'        => '*',
            'day'         => '*',
            'month'       => '*',
            'weekday'     => '*',
        ];
        $query = $this->db->simple_select('tasks', 'tid', "file='" . self::CODENAME . "'");
        $tid = (int)$this->db->fetch_field($query, 'tid');
        if ($tid > 0) {
            $this->db->update_query('tasks', $row, "tid='" . $tid . "'");
        } else {
            $row += ['enabled' => (int)$task['enabled'], 'logging' => 1, 'locked' => 0];
            $row['nextrun'] = fetch_next_run($row);
            $this->db->insert_query('tasks', $row);
        }
        $this->cache->update_tasks();
    }

    /**
     * Switches the task on or off (activation and deactivation).
     *
     * @param bool $enabled On.
     */
    public function setTaskEnabled(bool $enabled): void
    {
        $fields = ['enabled' => $enabled ? 1 : 0];
        if ($enabled) {
            $this->requireTaskFunctions();
            $query = $this->db->simple_select('tasks', '*', "file='" . self::CODENAME . "'");
            $task = $this->db->fetch_array($query);
            if (is_array($task)) {
                $fields['nextrun'] = fetch_next_run($task);
            }
        }
        $this->db->update_query('tasks', $fields, "file='" . self::CODENAME . "'");
        $this->cache->update_tasks();
    }

    /**
     * Removes the task.
     */
    public function removeTask(): void
    {
        $this->db->delete_query('tasks', "file='" . self::CODENAME . "'");
        $this->cache->update_tasks();
    }

    /**
     * Writes to MyBB's task log (only codes and counts).
     *
     * @param array<string,mixed> $task    The task row MyBB passed to the task.
     * @param string              $message The line.
     */
    public function taskLog(array $task, string $message): void
    {
        $this->requireTaskFunctions();
        add_task_log($task, $message);
    }

    // ---------------------------------------------------------------- the ACP

    /**
     * A request input.
     *
     * @param string $name The input's name.
     * @return mixed `$mybb->input[$name]`, or null.
     */
    public function input(string $name)
    {
        return $this->mybb->input[$name] ?? null;
    }

    /**
     * Sets a setting's submitted value before MyBB's settings page saves `upsetting`.
     *
     * @param string $name  The setting.
     * @param string $value The value to save.
     */
    public function submitSetting(string $name, string $value): void
    {
        if (!isset($this->mybb->input['upsetting']) || !is_array($this->mybb->input['upsetting'])) {
            $this->mybb->input['upsetting'] = [];
        }
        $this->mybb->input['upsetting'][$name] = $value;
    }

    /**
     * The request method.
     *
     * @return string `post` or `get`.
     */
    public function requestMethod(): string
    {
        return strtolower((string)($this->mybb->request_method ?? 'get'));
    }

    /**
     * The ACP page being loaded.
     *
     * @return array{module:string,action:string} `$page->active_module` and `active_action`.
     */
    public function acpLocation(): array
    {
        $page = $GLOBALS['page'] ?? null;
        return [
            'module' => is_object($page) ? (string)($page->active_module ?? '') : '',
            'action' => is_object($page) ? (string)($page->active_action ?? '') : '',
        ];
    }

    /**
     * Shows a message at the top of the ACP page being built.
     *
     * @param string $html The message (already escaped).
     */
    public function acpMessage(string $html): void
    {
        $page = $GLOBALS['page'] ?? null;
        if (is_object($page)) {
            $page->extra_messages[] = ['type' => 'error', 'message' => $html];
        }
    }

    /**
     * Outputs a whole ACP page and ends the request (`output_footer` exits).
     *
     * @param string $title The title.
     * @param string $html  The body (already escaped).
     */
    public function acpOutput(string $title, string $html): void
    {
        $page = $GLOBALS['page'];
        $page->add_breadcrumb_item($title);
        $page->output_header($title);
        echo $html;
        $page->output_footer();
    }

    /**
     * Asks a yes/no question in the ACP and ends the request.
     *
     * @param string $url     Where the answer is posted (`no` is set for "No").
     * @param string $message The question (already escaped).
     * @param string $title   The title.
     */
    public function acpConfirm(string $url, string $message, string $title): void
    {
        $GLOBALS['page']->output_confirm_action($url, $message, $title);
    }

    // ---------------------------------------------------------------- internals

    /**
     * @return object MyBB's `Moderation`.
     */
    private function moderation()
    {
        if (!class_exists('Moderation', false)) {
            require_once $this->root . 'inc/class_moderation.php';
        }
        return new \Moderation();
    }

    private function requireTaskFunctions(): void
    {
        if (!function_exists('fetch_next_run')) {
            require_once $this->root . 'inc/functions_task.php';
        }
    }

    /**
     * @param array<string,int|string> $row
     * @return array<string,int|string> Strings escaped, integers kept.
     */
    private function escaped(array $row): array
    {
        foreach ($row as $column => $value) {
            $row[$column] = is_int($value) ? $value : $this->db->escape_string((string)$value);
        }
        return $row;
    }

    /**
     * @param array<string,mixed> $options `simple_select` options.
     * @return array<int,array<string,mixed>>
     */
    private function rows(string $table, string $where, array $options): array
    {
        $query = $this->db->simple_select($table, '*', $where, $options);
        $rows = [];
        while (($row = $this->db->fetch_array($query)) && is_array($row)) {
            $rows[] = $row;
        }
        return $rows;
    }

    /**
     * `CREATE TABLE` for one engine.
     *
     * @param array<string,string> $columns Name → `int`, `small`, `flag`, `serial`, `text`, `varchar(n)`.
     */
    private static function createTable(string $engine, string $table, array $columns, string $key, string $collation): string
    {
        $pg = in_array($engine, ['pgsql', 'pgsql_pdo'], true);
        $sqlite = $engine === 'sqlite';
        $lines = [];
        foreach ($columns as $name => $type) {
            if ($type === 'serial') {
                $lines[] = $name . ($pg ? ' serial' : ($sqlite ? ' INTEGER PRIMARY KEY' : ' int unsigned NOT NULL auto_increment'));
                continue;
            }
            if ($type === 'text') {
                $lines[] = $name . ' text' . ($pg || $sqlite ? " NOT NULL default ''" : ' NOT NULL');
                continue;
            }
            if (strncmp($type, 'varchar', 7) === 0) {
                $lines[] = $name . ' ' . $type . " NOT NULL default ''";
                continue;
            }
            $sql = ['int' => 'int unsigned', 'small' => 'smallint unsigned', 'flag' => 'tinyint(1)'][$type];
            if ($pg || $sqlite) {
                $sql = ['int' => 'int', 'small' => 'smallint', 'flag' => 'smallint'][$type];
            }
            $lines[] = $name . ' ' . $sql . " NOT NULL default '0'";
        }
        if (!$sqlite || $columns[$key] !== 'serial') {
            $lines[] = 'PRIMARY KEY (' . $key . ')';
        }
        if (!$pg && !$sqlite && isset($columns['retry_at'])) {
            $lines[] = 'KEY status (status, retry_at)';
        }
        return 'CREATE TABLE ' . $table . " (\n  " . implode(",\n  ", $lines) . "\n)"
            . ($pg || $sqlite ? '' : ' ENGINE=MyISAM' . $collation);
    }
}
