<?php

declare(strict_types=1);

namespace Minos\MyBB;

/**
 * The ONE place the plugin touches MyBB: its globals (`$mybb`, `$db`, `$cache`, `$lang`,
 * `$page`), its functions (`is_moderator`, `forum_permissions`, `get_forum`,
 * `rebuild_settings`, `fetch_next_run`, `add_task_log`, `get_post_link`,
 * `get_thread_link`, `my_substr`), its classes (`Moderation`, `postParser`, `MyLanguage`,
 * `PMDataHandler`) and its tables.
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

    /** Between a poll's options in MyBB's `polls.options` column. */
    private const POLL_OPTIONS_SEPARATOR = '||~|~||';

    /**
     * The prefix of the plugin's entries in MyBB's datacache (`$cache`): one entry per value
     * of state (`minos_switched_off`, `minos_refusal`, `minos_pause`), so requests that
     * change different values never overwrite each other's.
     */
    public const STATE_PREFIX = 'minos_';

    /**
     * The pending table's columns, name → type ({@see columnSql}). A column added in a later
     * version goes at the end: activation adds it to a table that lacks it.
     */
    private const PENDING_COLUMNS = [
        'pid' => 'int', 'tid' => 'int', 'is_thread' => 'flag', 'status' => 'varchar(20)',
        'submitted_at' => 'int', 'accepted_at' => 'int', 'retry_at' => 'int',
        'attempts' => 'small', 'truncated' => 'flag', 'prefix_len' => 'small',
        'text_len' => 'small', 'verdict' => 'varchar(20)', 'categories' => 'varchar(255)',
        'support' => 'flag', 'error_code' => 'varchar(40)', 'decided_at' => 'int',
        'auto_published' => 'flag', 'claimed_at' => 'int', 'claimed_from' => 'varchar(20)',
        'original_text' => 'text',
        // The SHA-256 of the text sent (never the text itself) and the masked text of a post
        // the plugin kept in the queue.
        'sent_sha256' => 'varchar(64)', 'masked_text' => 'text',
        // Steps that threw for the row ({@see Applier::failedStep}).
        'apply_errors' => 'small',
        // `author_first_post` as first sent, for a resubmission: '1', '0', or '' (a guest).
        'first_post' => 'varchar(1)',
        // The revision being assessed: 0 is sent as `mybb:<pid>`, n as `mybb:<pid>.<n>`
        // ({@see Submitter::itemId}); an author's edit of a waiting post adds one.
        'rev' => 'small',
        // The gateway's answer as delivered (a wire value: `bezpieczne`, `ocenzurowane`,
        // `zablokowane` or `nieocenione`, whatever the plugin then did) and when it came:
        // with `accepted_at` (the 202), the ACP's time-to-verdict figures.
        'answer' => 'varchar(20)', 'answered_at' => 'int',
        // Whether a reply's own subject was sent with it (`Text::ownSubject`), as decided
        // at its first send or by the edit that started its revision.
        'own_subject' => 'flag',
    ];

    /** The log table's columns. */
    private const LOG_COLUMNS = [
        'lid' => 'serial', 'dateline' => 'int', 'event' => 'varchar(20)',
        'code' => 'varchar(40)', 'pid' => 'int',
    ];

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

    /** @var array<string,object> MyBB's `MyLanguage` per language, with `messages` and `global` loaded. */
    private $languages = [];

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
     * One value of the plugin's state kept between requests, read afresh from MyBB's
     * datacache (`read($name, true)`: not the copy loaded when this request began, which
     * another request may have changed since).
     *
     * @param string $key The value's name.
     * @return mixed The value; null when none is kept.
     */
    public function state(string $key)
    {
        $value = $this->cache->read(self::STATE_PREFIX . $key, true);
        return $value === false ? null : $value;
    }

    /**
     * Keeps one value of the plugin's state, or forgets it. Each value is its own datacache
     * entry, written whole: a request that keeps one value never writes back a stale copy
     * of another.
     *
     * @param string $key   The value's name.
     * @param mixed  $value The value (not false); null forgets it.
     */
    public function setState(string $key, $value): void
    {
        if ($value === null) {
            $this->cache->delete(self::STATE_PREFIX . $key);
            return;
        }
        $this->cache->update(self::STATE_PREFIX . $key, $value);
    }

    /**
     * Removes the plugin's datacache entries (uninstall).
     *
     * @param array<int,string> $keys The values' names.
     */
    public function removeState(array $keys): void
    {
        foreach ($keys as $key) {
            $this->cache->delete(self::STATE_PREFIX . $key);
        }
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
     * How a forum shows posts: whether it parses HTML, MyCode, `[img]` and `[video]`.
     *
     * @param int $fid The forum.
     * @return array<string,int> `allowhtml`, `allowmycode`, `allowimgcode`,
     *     `allowvideocode`, as MyBB's `get_forum` has them (missing ones left out).
     */
    public function forumParsing(int $fid): array
    {
        $forum = get_forum($fid);
        $options = [];
        foreach (array_keys(Text::DEFAULT_FORUM) as $key) {
            if (is_array($forum) && isset($forum[$key])) {
                $options[$key] = (int)$forum[$key];
            }
        }
        return $options;
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
     * A post as it is now, with whether it opens its thread, its thread's title, the subject
     * of the post it replies to (`posts.replyto`, '' for none), and — for the first post of
     * a thread with a poll — the poll as `showthread.php` reads it (`threads.poll` →
     * `polls`; the options are one string, `||~|~||` between them, of which `numoptions`
     * are shown).
     *
     * @param int $pid The post.
     * @return array{pid:int,tid:int,fid:int,visible:int,subject:string,message:string,first:bool,thread_subject:string,replyto_subject:string,poll:array{question:string,options:array<int,string>}|null}|null
     *     Null when it no longer exists.
     */
    public function post(int $pid): ?array
    {
        $query = $this->db->query(
            'SELECT p.pid, p.tid, p.fid, p.visible, p.subject, p.message, t.firstpost, t.subject AS thread_subject,'
            . ' r.subject AS replyto_subject,'
            . ' pl.pid AS poll_id, pl.question AS poll_question, pl.options AS poll_options, pl.numoptions AS poll_numoptions'
            . ' FROM ' . $this->prefix . 'posts p'
            . ' LEFT JOIN ' . $this->prefix . 'threads t ON (t.tid=p.tid)'
            . ' LEFT JOIN ' . $this->prefix . "posts r ON (r.pid=p.replyto AND p.replyto>'0')"
            . ' LEFT JOIN ' . $this->prefix . "polls pl ON (pl.pid=t.poll AND t.poll>'0')"
            . " WHERE p.pid='" . $pid . "'"
        );
        $row = $this->db->fetch_array($query);
        if (!is_array($row) || !isset($row['pid'])) {
            return null;
        }
        $first = (int)$row['firstpost'] === (int)$row['pid'];
        $poll = null;
        if ($first && (int)($row['poll_id'] ?? 0) > 0) {
            $poll = [
                'question' => (string)$row['poll_question'],
                'options'  => array_slice(explode(self::POLL_OPTIONS_SEPARATOR, (string)$row['poll_options']), 0,
                    max(0, (int)$row['poll_numoptions'])),
            ];
        }
        return [
            'pid'            => (int)$row['pid'],
            'tid'            => (int)$row['tid'],
            'fid'            => (int)$row['fid'],
            'visible'        => (int)$row['visible'],
            'subject'        => (string)$row['subject'],
            'message'        => (string)$row['message'],
            'first'          => $first,
            'thread_subject' => (string)($row['thread_subject'] ?? ''),
            'replyto_subject' => (string)($row['replyto_subject'] ?? ''),
            'poll'           => $poll,
        ];
    }

    /**
     * The first post of the thread whose poll `polls.php` has just created or changed: its
     * `polls_do_newpoll_end` and `polls_do_editpoll_end` hooks run in the script's global
     * scope with the thread's row in `$thread`, and pass no arguments.
     *
     * @return int The post id; 0 when there is no such thread row.
     */
    public function pollThreadFirstPost(): int
    {
        $thread = $GLOBALS['thread'] ?? null;
        return is_array($thread) ? (int)($thread['firstpost'] ?? 0) : 0;
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
     * @return bool Whether the post now holds it — read back, not trusted.
     */
    public function replaceMessage(int $pid, string $message): bool
    {
        $this->db->update_query('posts', ['message' => $this->db->escape_string($message)], "pid='" . $pid . "'");
        $query = $this->db->simple_select('posts', 'message', "pid='" . $pid . "'");
        return (string)$this->db->fetch_field($query, 'message') === $message;
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
     * Sends a published post back to the moderation queue, the way a moderator's
     * "unapprove" does (`Moderation` rebuilds the counters).
     *
     * @param array{pid:int,tid:int,first:bool} $post The post ({@see post}).
     */
    public function unapprove(array $post): void
    {
        $moderation = $this->moderation();
        if ($post['first']) {
            $moderation->unapprove_threads([$post['tid']]);
        } else {
            $moderation->unapprove_posts([$post['pid']]);
        }
    }

    /**
     * What MyBB reads before it inserts a post visible at once, and its subscription
     * notifications then compare with: the thread's last post and visibility
     * (`insert_post`: `get_thread`) and the forum's last post (`insert_thread`: the forum's
     * row). Read before the plugin publishes a post ({@see notifySubscribers}): the approval
     * moves both last posts on.
     *
     * @param array{tid:int,fid:int} $post The post ({@see post}).
     * @return array{thread_lastpost:int,thread_visible:int,forum_lastpost:int}
     */
    public function lastPosts(array $post): array
    {
        $query = $this->db->simple_select('threads', 'lastpost, visible', "tid='" . (int)$post['tid'] . "'");
        $thread = $this->db->fetch_array($query);
        $query = $this->db->simple_select('forums', 'lastpost', "fid='" . (int)$post['fid'] . "'");
        $forum = $this->db->fetch_array($query);
        return [
            'thread_lastpost' => is_array($thread) ? (int)$thread['lastpost'] : 0,
            'thread_visible'  => is_array($thread) ? (int)$thread['visible'] : 0,
            'forum_lastpost'  => is_array($forum) ? (int)$forum['lastpost'] : 0,
        ];
    }

    /**
     * Queues the subscription notifications MyBB queues for a post visible at once, for a
     * post the plugin has just published: MyBB 1.8.41 sends them only from `insert_post` and
     * `insert_thread` (`inc/datahandlers/post.php`, the blocks after "Fetch any users
     * subscribed to this thread" and "Queue up any forum subscription notices", only when
     * `$visible == 1`), never from `Moderation::approve_*` (shared rule D9.8, A4 of
     * client-php's `docs/plugin-behaviour.md`). The conditions are MyBB's, copied:
     *
     * - a reply, in a thread that is visible: the thread's subscribers with instant
     *   notification (`threadsubscriptions.notification` 1 = e-mail into `mailqueue`, 2 = a
     *   private message), but its author; a new thread: the forum's subscribers (e-mail),
     *   but its author and members of a banned group;
     * - "do not notify again before the visit": only a subscriber active after the thread's
     *   (the forum's) last post before this one (`u.lastactive > lastpost`, read before the
     *   approval: {@see lastPosts});
     * - the right to view: `forum_permissions()` `canview` and `canviewthreads`, and in a
     *   forum where users see only their own threads (`canonlyviewownthreads`) a subscriber
     *   who is neither the thread's starter (replies) nor a moderator;
     * - the recipient's language: their own when installed, else the board's
     *   (`orig_bblanguage` for replies, `bblanguage` for threads), else `english`;
     * - the text: MyBB's `emailsubject_subscription`/`email_subscription`,
     *   `pmsubject_subscription`/`pm_subscription` and `emailsubject_forumsubscription`/
     *   `email_forumsubscription` with the excerpt `text_parse_message` makes of the post as
     *   it is now (the masked text of a censored post), cut to `subscribeexcerpt`; a guest
     *   without a name is "Guest" in the recipient's language;
     * - a private message as `send_pm($pm, -1, true)` sends it: from "MyBB Engine" (uid 0),
     *   the administrator's override, nothing when the board has private messages off —
     *   through MyBB's `PMDataHandler`, with the strings already in the recipient's language
     *   and no IP address (the webhook and the task have no session);
     * - `$cache->update_mailqueue()` when an e-mail was queued.
     *
     * Not run: the `datahandler_post_insert_subscribed` hook (it passes the post datahandler,
     * which a publication by the plugin does not have). The block is MyBB 1.8.41's: its
     * upkeep follows MyBB's releases, and the live run compares the counts on a real forum.
     *
     * @param array{pid:int,tid:int,fid:int,first:bool} $post   The post ({@see post}), now visible.
     * @param array{thread_lastpost:int,thread_visible:int,forum_lastpost:int} $before {@see lastPosts}, read before the approval.
     * @return int How many notifications were queued.
     */
    public function notifySubscribers(array $post, array $before): int
    {
        $query = $this->db->query(
            'SELECT p.pid, p.uid, p.username, p.message, p.visible, t.tid, t.fid, t.uid AS thread_uid,'
            . ' t.subject AS thread_subject, f.name AS forum_name'
            . ' FROM ' . $this->prefix . 'posts p'
            . ' LEFT JOIN ' . $this->prefix . 'threads t ON (t.tid=p.tid)'
            . ' LEFT JOIN ' . $this->prefix . 'forums f ON (f.fid=t.fid)'
            . " WHERE p.pid='" . (int)$post['pid'] . "'"
        );
        $row = $this->db->fetch_array($query);
        if (!is_array($row) || (int)$row['visible'] !== 1 || (!$post['first'] && (int)$before['thread_visible'] !== 1)) {
            return 0; // not published, or a reply in a thread still in the queue: MyBB notifies nobody
        }
        $this->boardLanguage();
        if (!class_exists('postParser', false)) {
            require_once $this->root . 'inc/class_parser.php';
        }
        // The webhook and the task have no session user: MyBB's permission functions compare
        // with `$mybb->user['uid']`, which is then a guest's 0, for this call only.
        $sessionless = !isset($this->mybb->user['uid']);
        if ($sessionless) {
            $this->mybb->user['uid'] = 0;
        }
        try {
            return $post['first']
                ? $this->notifyForum($row, new \postParser(), (int)$before['forum_lastpost'])
                : $this->notifyThread($row, new \postParser(), (int)$before['thread_lastpost']);
        } finally {
            if ($sessionless) {
                unset($this->mybb->user['uid']);
            }
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
            'support' => 0, 'error_code' => '', 'decided_at' => 0, 'auto_published' => 0,
            'claimed_at' => 0, 'claimed_from' => '', 'original_text' => '', 'sent_sha256' => '',
            'masked_text' => '', 'apply_errors' => 0, 'first_post' => '', 'rev' => 0,
            'answer' => '', 'answered_at' => 0, 'own_subject' => 0,
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
     * Takes a row, so exactly one webhook delivery or task run applies it (deliveries
     * repeat, and the task runs beside the webhook). The status it had and the moment are
     * recorded, so {@see releaseStaleClaims} can give back a claim whose holder died.
     *
     * @param int               $pid              The post.
     * @param int               $now              Unix seconds.
     * @param array<int,string> $from             The statuses it may be taken from.
     * @param bool              $autoPublishedOnly Only a row the plugin itself published
     *     in fail-open mode (`auto_published`).
     * @param int|null          $rev              Only while the row is at this revision — how a
     *     verdict for a revision an edit has since replaced is kept from being applied.
     * @return bool True for the one caller that changed it.
     */
    public function claimPending(int $pid, int $now, array $from = Status::OPEN, bool $autoPublishedOnly = false,
        ?int $rev = null): bool
    {
        foreach ($from as $status) {
            $this->db->update_query(self::PENDING_TABLE, [
                'status' => Status::APPLYING, 'claimed_from' => $this->db->escape_string($status), 'claimed_at' => $now,
            ], "pid='" . $pid . "' AND status='" . $this->db->escape_string($status) . "'"
                . ($autoPublishedOnly ? " AND auto_published='1'" : '')
                . ($rev !== null ? " AND rev='" . $rev . "'" : ''));
            if ((int)$this->db->affected_rows() === 1) {
                return true;
            }
        }
        return false;
    }

    /**
     * Takes a row the failure mode held because its verdict did not come in time (`held`
     * with `timeout`), for a verdict that comes late after all (shared rule D9.10, A6 of
     * client-php's `docs/plugin-behaviour.md`): one conditional `UPDATE`, as
     * {@see claimPending}, recording `held` as the status it was claimed from.
     *
     * @param int      $pid The post.
     * @param int      $now Unix seconds.
     * @param int|null $rev Only while the row is at this revision.
     * @return bool True for the one caller that changed it.
     */
    public function claimTimedOut(int $pid, int $now, ?int $rev = null): bool
    {
        $this->db->update_query(self::PENDING_TABLE, [
            'status' => Status::APPLYING, 'claimed_from' => Status::HELD, 'claimed_at' => $now,
        ], "pid='" . $pid . "' AND status='" . Status::HELD . "' AND verdict='" . Status::REASON_TIMEOUT . "'"
            . ($rev !== null ? " AND rev='" . $rev . "'" : ''));
        return (int)$this->db->affected_rows() === 1;
    }

    /**
     * Gives back claims older than a moment: the process that took them died between the
     * claim and the outcome. The row returns to the status it was claimed from.
     *
     * @param int $before Unix seconds.
     */
    public function releaseStaleClaims(int $before): void
    {
        $this->db->write_query('UPDATE ' . $this->prefix . self::PENDING_TABLE
            . " SET status=claimed_from, claimed_at='0' WHERE status='" . Status::APPLYING
            . "' AND claimed_from<>'' AND claimed_at<'" . $before . "'");
    }

    /**
     * Counts a step that threw for a row, and gives back its claim: a claimed row returns to
     * the status it was claimed from, an unclaimed one keeps its status.
     *
     * @param int $pid The post.
     * @return int The row's failed steps so far; 0 when there is no row.
     */
    public function countFailure(int $pid): int
    {
        $this->db->write_query('UPDATE ' . $this->prefix . self::PENDING_TABLE
            . " SET apply_errors=apply_errors+1, claimed_at='0', status=CASE WHEN status='" . Status::APPLYING
            . "' AND claimed_from<>'' THEN claimed_from ELSE status END WHERE pid='" . $pid . "'");
        $row = $this->pending($pid);
        return $row === null ? 0 : (int)$row['apply_errors'];
    }

    /**
     * Changes a row.
     *
     * @param int                      $pid    The post.
     * @param array<string,int|string> $fields Columns to set.
     * @param string|null              $onlyIf Change it only while it has this status — how
     *     a submission records the gateway's answer without overwriting a verdict that a
     *     fast webhook delivery applied in the meantime.
     * @param int|null                 $rev    Change it only while it is at this revision — how
     *     the answer to a request records nothing on a revision an edit started while the
     *     request was on its way.
     */
    public function updatePending(int $pid, array $fields, ?string $onlyIf = null, ?int $rev = null): void
    {
        $where = "pid='" . $pid . "'";
        if ($onlyIf !== null) {
            $where .= " AND status='" . $this->db->escape_string($onlyIf) . "'";
        }
        if ($rev !== null) {
            $where .= " AND rev='" . $rev . "'";
        }
        $this->db->update_query(self::PENDING_TABLE, $this->escaped($fields), $where);
    }

    /**
     * Starts a new revision of a post an author edited while the plugin was waiting for its
     * verdict (`pending`, `retry`) or after the failure mode held it because no verdict came
     * ({@see Status::REVISED_REASONS}): one conditional `UPDATE`, so a verdict being applied
     * at the same moment and the edit never both win. The row waits again as `retry`, due
     * now, with a fresh receive timeout and nothing of the earlier attempt (its digest, its
     * outcome); the task sends it under the next revision's id.
     *
     * @param int      $pid        The post.
     * @param int      $now        Unix seconds.
     * @param int|null $ownSubject For a reply: whether its subject goes with the new
     *     revision (1 or 0); null keeps what the row has.
     * @return bool True when the row now waits as a new revision.
     */
    public function revise(int $pid, int $now, ?int $ownSubject = null): bool
    {
        $this->db->write_query('UPDATE ' . $this->prefix . self::PENDING_TABLE
            . " SET rev=rev+1, status='" . Status::RETRY . "', submitted_at='" . $now . "', accepted_at='0',"
            . ($ownSubject !== null ? " own_subject='" . ($ownSubject === 1 ? 1 : 0) . "'," : '')
            . " retry_at='" . $now . "', attempts='0', error_code='', verdict='', categories='', support='0',"
            . " decided_at='0', auto_published='0', claimed_at='0', claimed_from='', sent_sha256='',"
            . " masked_text='', apply_errors='0', truncated='0', prefix_len='0', text_len='0'"
            . " WHERE pid='" . $pid . "' AND (status IN ('" . implode("','", Status::OPEN) . "')"
            . " OR (status='" . Status::HELD . "' AND verdict IN ('" . implode("','", Status::REVISED_REASONS) . "')))");
        return (int)$this->db->affected_rows() === 1;
    }

    /**
     * Makes every row still waiting for a verdict wait afresh: it is sent again at once and
     * gets a new receive timeout (the plugin was switched off while it waited, and its
     * verdict may have been refused and dropped meanwhile).
     *
     * @param int $now Unix seconds.
     * @return int How many rows.
     */
    public function rearmWaiting(int $now): int
    {
        $this->db->update_query(self::PENDING_TABLE, [
            'status' => Status::RETRY, 'submitted_at' => $now, 'accepted_at' => 0, 'retry_at' => $now,
        ], "status IN ('" . implode("','", Status::OPEN) . "')");
        return (int)$this->db->affected_rows();
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
     * The newest rows a moderator should look at: held, flagged for support, waiting, decided
     * by a human before the verdict came (a moderator's edit, say), or left to a human after a
     * step kept failing.
     *
     * @param int $limit At most this many.
     * @return array<int,array<string,mixed>> Rows, newest first.
     */
    public function attentionRows(int $limit): array
    {
        return $this->rows(self::PENDING_TABLE,
            "support='1' OR auto_published='1' OR verdict='" . Status::REASON_APPLY_ERROR . "' OR status IN ('"
                . implode("','", [Status::HELD, Status::PENDING, Status::RETRY, Status::APPLYING, Status::SUPERSEDED]) . "')",
            ['order_by' => 'submitted_at', 'order_dir' => 'DESC', 'limit' => $limit]);
    }

    /**
     * The verdicts the gateway delivered since a moment: for each, its answer and, when the
     * row kept the moment of the gateway's `202`, the seconds from it to the delivery.
     * Codes and times only — never a text.
     *
     * @param int $since Unix seconds.
     * @return array<int,array{answer:string,seconds:int|null}> One per delivered verdict.
     */
    public function answersSince(int $since): array
    {
        $answers = [];
        foreach ($this->rows(self::PENDING_TABLE, "answered_at>='" . $since . "' AND answer<>''", []) as $row) {
            $accepted = (int)$row['accepted_at'];
            $answered = (int)$row['answered_at'];
            $answers[] = [
                'answer'  => (string)$row['answer'],
                'seconds' => $accepted > 0 && $answered >= $accepted ? $answered - $accepted : null,
            ];
        }
        return $answers;
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
     * Creates the plugin's tables (MySQL/MariaDB, PostgreSQL or SQLite, as MyBB runs on),
     * and brings a table kept from an earlier version (an uninstall that kept the data, an
     * upgrade: deactivate, replace the files, activate) up to date: every column it lacks is
     * added with `ALTER TABLE … ADD`, its rows kept.
     */
    public function createTables(): void
    {
        $engine = (string)($this->db->type ?? 'mysqli');
        $tables = [self::PENDING_TABLE => self::PENDING_COLUMNS, self::LOG_TABLE => self::LOG_COLUMNS];
        $keys = [self::PENDING_TABLE => 'pid', self::LOG_TABLE => 'lid'];
        foreach ($tables as $table => $columns) {
            if (!$this->db->table_exists($table)) {
                $this->db->write_query(self::createTable($engine, $this->prefix . $table, $columns, $keys[$table],
                    (string)$this->db->build_create_table_collation()));
                continue;
            }
            foreach ($columns as $name => $type) {
                if ($type !== 'serial' && !$this->db->field_exists($name, $table)) {
                    $this->db->add_column($table, $name, self::columnSql($engine, $type));
                }
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
     * Changes how one setting is rendered (its `optionscode`), keeping its value.
     *
     * @param string $name        The setting.
     * @param string $optionscode The new `optionscode`.
     */
    public function setOptionscode(string $name, string $optionscode): void
    {
        $this->db->update_query('settings', ['optionscode' => $this->db->escape_string($optionscode)],
            "name='" . $this->db->escape_string($name) . "'");
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
     * `insert_post`'s thread subscription block, for a published reply.
     *
     * @param array<string,mixed> $row      The post with its thread and forum.
     * @param object              $parser   MyBB's `postParser`.
     * @param int                 $lastpost The thread's last post before this one.
     * @return int Notifications queued.
     */
    private function notifyThread(array $row, $parser, int $lastpost): int
    {
        $settings = $this->settings();
        $subject = $parser->parse_badwords((string)$row['thread_subject']);
        $excerpt = $parser->text_parse_message((string)$row['message'],
            ['me_username' => (string)$row['username'], 'filter_badwords' => 1]);
        $excerpt = my_substr($excerpt, 0, (int)($settings['subscribeexcerpt'] ?? 100)) . (string)$this->lang->emailbit_viewthread;
        $link = str_replace('&amp;', '&', get_thread_link((int)$row['tid'], 0, 'newpost'));
        $query = $this->db->query(
            'SELECT u.username, u.email, u.uid, u.language, s.notification'
            . ' FROM ' . $this->prefix . 'threadsubscriptions s'
            . ' LEFT JOIN ' . $this->prefix . 'users u ON (u.uid=s.uid)'
            . " WHERE (s.notification='1' OR s.notification='2') AND s.tid='" . (int)$row['tid'] . "'"
            . " AND s.uid != '" . (int)$row['uid'] . "'"
            . " AND u.lastactive>'" . $lastpost . "'"
        );
        $members = [];
        while (($member = $this->db->fetch_array($query)) && is_array($member)) {
            $members[] = $member;
        }
        $done = [];
        $queued = 0;
        $mailed = false;
        foreach ($members as $member) {
            $uid = (int)$member['uid'];
            if (isset($done[$uid])) {
                continue;
            }
            $done[$uid] = true;
            if (!$this->mayReadThread((int)$row['fid'], $uid, (int)$row['thread_uid'] !== $uid)) {
                continue;
            }
            $lang = $this->languageFor((string)$member['language'], (string)($settings['orig_bblanguage'] ?? ''));
            $author = (int)$row['uid'] === 0 && (string)$row['username'] === '' ? (string)$lang->guest : (string)$row['username'];
            if ((int)$member['notification'] === 1) {
                $this->queueMail((string)$member['email'], $lang->sprintf((string)$lang->emailsubject_subscription, $subject),
                    $lang->sprintf((string)$lang->email_subscription, (string)$member['username'], $author,
                        (string)($settings['bbname'] ?? ''), $subject, $excerpt, (string)($settings['bburl'] ?? ''), $link,
                        (string)(int)$row['tid']));
                $mailed = true;
            } else {
                $this->sendPm($uid, $lang->sprintf((string)$lang->pmsubject_subscription, $subject),
                    $lang->sprintf((string)$lang->pm_subscription, (string)$member['username'], $author, $subject, $excerpt,
                        (string)($settings['bburl'] ?? ''), $link, (string)(int)$row['tid']));
            }
            $queued++;
        }
        if ($mailed) {
            $this->cache->update_mailqueue();
        }
        return $queued;
    }

    /**
     * `insert_thread`'s forum subscription block, for a published thread.
     *
     * @param array<string,mixed> $row      The first post with its thread and forum.
     * @param object              $parser   MyBB's `postParser`.
     * @param int                 $lastpost The forum's last post before this thread.
     * @return int E-mails queued.
     */
    private function notifyForum(array $row, $parser, int $lastpost): int
    {
        $settings = $this->settings();
        $excerpt = $parser->text_parse_message($parser->parse_badwords((string)$row['message']));
        $limit = (int)($settings['subscribeexcerpt'] ?? 100);
        if (strlen($excerpt) > $limit) {
            $excerpt = my_substr($excerpt, 0, $limit) . (string)$this->lang->emailbit_viewthread;
        }
        $query = $this->db->query(
            'SELECT u.username, u.email, u.uid, u.language'
            . ' FROM ' . $this->prefix . 'forumsubscriptions fs'
            . ' LEFT JOIN ' . $this->prefix . 'users u ON (u.uid=fs.uid)'
            . ' LEFT JOIN ' . $this->prefix . 'usergroups g ON (g.gid=u.usergroup)'
            . " WHERE fs.fid='" . (int)$row['fid'] . "'"
            . " AND fs.uid != '" . (int)$row['uid'] . "'"
            . " AND u.lastactive > '" . $lastpost . "'"
            . ' AND g.isbannedgroup != 1'
        );
        $members = [];
        while (($member = $this->db->fetch_array($query)) && is_array($member)) {
            $members[] = $member;
        }
        $done = [];
        $queued = 0;
        foreach ($members as $member) {
            $uid = (int)$member['uid'];
            if (isset($done[$uid])) {
                continue;
            }
            $done[$uid] = true;
            if (!$this->mayReadThread((int)$row['fid'], $uid, true)) {
                continue;
            }
            $lang = $this->languageFor((string)$member['language'], (string)($settings['bblanguage'] ?? ''));
            $author = (int)$row['uid'] === 0 && (string)$row['username'] === '' ? (string)$lang->guest : (string)$row['username'];
            $this->queueMail((string)$member['email'], $lang->sprintf((string)$lang->emailsubject_forumsubscription, (string)$row['forum_name']),
                $lang->sprintf((string)$lang->email_forumsubscription, (string)$member['username'], $author,
                    (string)$row['forum_name'], (string)($settings['bbname'] ?? ''), (string)$row['thread_subject'], $excerpt,
                    (string)($settings['bburl'] ?? ''), get_thread_link((int)$row['tid']), (string)(int)$row['fid']));
            $queued++;
        }
        if ($queued > 0) {
            $this->cache->update_mailqueue();
        }
        return $queued;
    }

    /**
     * MyBB's view checks in its subscription blocks: `canview` and `canviewthreads`, and in a
     * forum where users see only their own threads, a moderator (or, for replies, the
     * thread's starter: `$others` false).
     *
     * @param bool $others The thread is someone else's.
     */
    private function mayReadThread(int $fid, int $uid, bool $others): bool
    {
        $permissions = forum_permissions($fid, $uid);
        if ((int)($permissions['canview'] ?? 0) === 0 || (int)($permissions['canviewthreads'] ?? 0) === 0) {
            return false;
        }
        return !($others && (int)($permissions['canonlyviewownthreads'] ?? 0) === 1 && !is_moderator($fid, '', $uid));
    }

    /**
     * The board's language on `$lang`, with `global` and `messages` loaded, as `task.php`
     * loads them: the webhook starts MyBB with `inc/init.php` only, and MyBB's parser and
     * its private-message handler read `$lang` (`quote`, `wrote`, `mybb_engine`…). A page
     * request already has its user's language and is left as it is.
     */
    private function boardLanguage(): void
    {
        if ((string)($this->lang->language ?? '') === '') {
            $this->lang->set_language((string)($this->settings()['bblanguage'] ?? ''));
        }
        if (!isset($this->lang->wrote)) {
            $this->lang->load('global');
        }
        if (!isset($this->lang->emailbit_viewthread)) {
            $this->lang->load('messages');
        }
    }

    /**
     * MyBB's strings in a recipient's language: their own when installed, else the board's,
     * else `english`.
     *
     * @param string $own   The recipient's `language` ('' for the board's).
     * @param string $board The board's.
     * @return object A `MyLanguage` with `messages` and `global` loaded.
     */
    private function languageFor(string $own, string $board)
    {
        if ($own !== '' && $this->lang->language_exists($own)) {
            $language = $own;
        } elseif ($board !== '') {
            $language = $board;
        } else {
            $language = 'english';
        }
        if (!isset($this->languages[$language])) {
            $strings = new \MyLanguage();
            $strings->set_path($this->root . 'inc/languages');
            $strings->set_language($language);
            $strings->load('messages');
            $strings->load('global');
            $this->languages[$language] = $strings;
        }
        return $this->languages[$language];
    }

    /**
     * One e-mail into MyBB's `mailqueue`, as the subscription blocks queue it.
     */
    private function queueMail(string $to, string $subject, string $message): void
    {
        $this->db->insert_query('mailqueue', [
            'mailto'   => $this->db->escape_string($to),
            'mailfrom' => '',
            'subject'  => $this->db->escape_string($subject),
            'message'  => $this->db->escape_string($message),
            'headers'  => '',
        ]);
    }

    /**
     * One private message from "MyBB Engine", as `send_pm($pm, -1, true)` sends it
     * (`inc/functions.php`): nothing when the board has private messages off; the
     * administrator's override (the recipient's own settings do not refuse it).
     */
    private function sendPm(int $uid, string $subject, string $message): void
    {
        if ((string)($this->settings()['enablepms'] ?? '1') === '0') {
            return;
        }
        if (!class_exists('PMDataHandler', false)) {
            require_once $this->root . 'inc/datahandlers/pm.php';
        }
        $handler = new \PMDataHandler();
        $handler->admin_override = 1;
        $handler->set_data([
            'subject' => $subject, 'message' => $message, 'icon' => -1, 'fromid' => 0, 'toid' => [$uid],
            'bccid' => [], 'do' => '', 'pmid' => '', 'sender' => ['uid' => 0, 'username' => ''], 'ipaddress' => '',
            'options' => ['disablesmilies' => 0, 'savecopy' => 0, 'readreceipt' => 0], 'saveasdraft' => 0,
        ]);
        if ($handler->validate_pm()) {
            $handler->insert_pm();
        }
    }

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
            $lines[] = $name . ' ' . self::columnSql($engine, $type);
        }
        if (!$sqlite || $columns[$key] !== 'serial') {
            $lines[] = 'PRIMARY KEY (' . $key . ')';
        }
        if (!$pg && !$sqlite && isset($columns['retry_at'])) {
            $lines[] = 'KEY status (status, retry_at)';
        }
        // No ENGINE clause: the server's default (InnoDB on current MySQL and MariaDB).
        return 'CREATE TABLE ' . $table . " (\n  " . implode(",\n  ", $lines) . "\n)"
            . ($pg || $sqlite ? '' : $collation);
    }

    /**
     * One column's definition for one engine, for `CREATE TABLE` and for `ALTER TABLE … ADD`
     * (every one is NOT NULL with a default, so rows that exist when it is added get it).
     *
     * @param string $type `int`, `small`, `flag`, `text` or `varchar(n)`.
     */
    private static function columnSql(string $engine, string $type): string
    {
        $pg = in_array($engine, ['pgsql', 'pgsql_pdo'], true);
        $sqlite = $engine === 'sqlite';
        if ($type === 'text') {
            // MySQL gives TEXT no default; it fills existing rows with ''.
            return 'text' . ($pg || $sqlite ? " NOT NULL default ''" : ' NOT NULL');
        }
        if (strncmp($type, 'varchar', 7) === 0) {
            return $type . " NOT NULL default ''";
        }
        $sql = ['int' => 'int unsigned', 'small' => 'smallint unsigned', 'flag' => 'tinyint(1)'][$type];
        if ($pg || $sqlite) {
            $sql = ['int' => 'int', 'small' => 'smallint', 'flag' => 'smallint'][$type];
        }
        return $sql . " NOT NULL default '0'";
    }
}
