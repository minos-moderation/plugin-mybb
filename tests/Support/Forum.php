<?php

declare(strict_types=1);

namespace Minos\MyBB\Tests\Support;

use Minos\Client\Signature;
use Minos\MyBB\Gateway;
use Minos\MyBB\Platform;
use Minos\MyBB\Plugin;
use Minos\MyBB\Submitter;
use PDO;

/**
 * A MyBB forum in miniature for one test: SQLite tables in MyBB's shape, the globals the
 * plugin reads, and {@see write} — a new post that goes through the plugin's REAL hook
 * functions (registered by `inc/plugins/minos.php` on `$plugins`) the way MyBB 1.8.41's
 * `PostDataHandler::insert_post`/`insert_thread` run them, visibility rule included.
 */
final class Forum
{
    /** A key shaped like a real one. Test data only. */
    public const KEY = 'wgb2b_test_forum_key_000000000000';

    /** A webhook secret. Test data only. */
    public const SECRET = 'test-only-webhook-secret-0123456789';

    public const BBURL = 'https://forum.example';

    /** The forum posts go to unless a test says otherwise. */
    public const FID = 2;

    /** When the forum's thread to reply to was written ({@see thread}). */
    public const HOUR_AGO = TIME_NOW - 3600;

    /** @var PDO */
    public $pdo;

    /** @var FakeDb */
    public $db;

    /** @var FakeMyBB */
    public $mybb;

    /** @var FakeCache */
    public $cache;

    /** @var FakeLang */
    public $lang;

    /** @var FakePage */
    public $page;

    /** @var Plugin|null */
    public $plugin;

    /** @var RecordingTransport|null */
    public $transport;

    /**
     * @param string|null $file An SQLite file (shared with another process), or null for memory.
     */
    public static function create(?string $file = null): self
    {
        $forum = new self();
        $fresh = $file === null || !is_file($file) || filesize($file) === 0;
        $forum->pdo = new PDO('sqlite:' . ($file ?? ':memory:'));
        $forum->pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $forum->pdo->setAttribute(PDO::ATTR_TIMEOUT, 10);
        if ($fresh) {
            foreach (self::schema() as $sql) {
                $forum->pdo->exec($sql);
            }
        }
        $forum->db = new FakeDb($forum->pdo, TABLE_PREFIX);
        $forum->mybb = new FakeMyBB();
        $forum->mybb->settings = ['bburl' => self::BBURL, 'bblanguage' => 'polish'];
        $forum->cache = new FakeCache();
        $forum->lang = new FakeLang(MYBB_ROOT . 'inc/languages');
        $forum->page = new FakePage();
        $GLOBALS['mybb'] = $forum->mybb;
        $GLOBALS['db'] = $forum->db;
        $GLOBALS['cache'] = $forum->cache;
        $GLOBALS['lang'] = $forum->lang;
        $GLOBALS['page'] = $forum->page;
        $GLOBALS['minos_test'] = ['moderators' => [], 'forum_permissions' => [], 'user_permissions' => [], 'moderation' => [],
            'task_log' => [], 'throw_on' => [], 'throw_after' => [], 'pms' => [], 'badwords' => []];
        if (!$fresh) {
            rebuild_settings();
        }
        Plugin::reset();
        return $forum;
    }

    /**
     * The adapter over this forum.
     */
    public function platform(): Platform
    {
        return new Platform($this->mybb, $this->db, $this->cache, $this->lang, MYBB_ROOT, TABLE_PREFIX);
    }

    /**
     * The plugin over this forum, with a scripted gateway, as the request's instance.
     *
     * @param callable|null $transport A transport; a {@see RecordingTransport} that accepts by default.
     */
    public function plugin(?callable $transport = null): Plugin
    {
        if ($transport === null) {
            $transport = new RecordingTransport();
        }
        $this->transport = $transport instanceof RecordingTransport ? $transport : null;
        $this->plugin = Plugin::build($this->platform(), new Gateway($transport));
        return $this->plugin;
    }

    /**
     * Installs and activates the plugin, then stores a working configuration.
     *
     * @param array<string,string> $settings Values over the working defaults.
     */
    public function install(array $settings = [], ?callable $transport = null): Plugin
    {
        $plugin = $this->plugin($transport);
        $plugin->installer->install();
        $plugin->installer->activate();
        $this->set($settings + [
            'minos_enabled'        => '1',
            'minos_api_key'        => self::KEY,
            'minos_webhook_secret' => self::SECRET,
        ]);
        return $plugin;
    }

    /**
     * Stores setting values and reloads them.
     *
     * @param array<string,string> $values
     */
    public function set(array $values): void
    {
        foreach ($values as $name => $value) {
            $this->db->update_query('settings', ['value' => $this->db->escape_string($value)],
                "name='" . $this->db->escape_string($name) . "'");
        }
        rebuild_settings();
    }

    /**
     * A user writes a new thread or reply, through MyBB's hooks.
     *
     * @param bool                $thread  A new thread (else a reply to {@see thread}).
     * @param string              $message The message (MyCode source).
     * @param array<string,mixed> $options `subject`, `fid`, `tid`, `uid`, `username`, `savedraft`,
     *     `replyto` (the post whose reply or quote button was used).
     * @return FakePostHandler The handler, with `return_values`.
     */
    public function write(bool $thread, string $message, array $options = []): FakePostHandler
    {
        $uid = (int)($options['uid'] ?? $this->mybb->user['uid']);
        $fid = (int)($options['fid'] ?? self::FID);
        $tid = $thread ? 0 : (int)($options['tid'] ?? $this->thread());
        $subject = (string)($options['subject'] ?? ($thread ? 'Nowy wątek' : 'RE: Wątek'));
        $handler = new FakePostHandler('insert', $thread ? 'thread' : 'post', [
            'uid' => $uid, 'fid' => $fid, 'tid' => $tid, 'subject' => $subject, 'message' => $message,
            'username' => 'Autor Testowy', 'ipaddress' => '203.0.113.77',
            'savedraft' => !empty($options['savedraft']) ? 1 : 0, 'replyto' => (int)($options['replyto'] ?? 0),
        ]);
        $kind = $thread ? 'thread' : 'post';
        $GLOBALS['plugins']->run_hooks('datahandler_post_validate_' . $kind, $handler);

        // MyBB 1.8.41: drafts are -2; else 0 when the forum moderates and the poster is no
        // moderator, or when the current user is the poster and `moderateposts` is 1.
        $permissions = forum_permissions($fid, $uid);
        $visible = 1;
        if (!empty($handler->data['savedraft'])) {
            $visible = -2;
        } else {
            if ((int)$permissions[$thread ? 'modthreads' : 'modposts'] === 1 && !is_moderator($fid, '', $uid)) {
                $visible = 0;
            }
            if ((int)$this->mybb->user['uid'] === $uid && (int)$this->mybb->user['moderateposts'] === 1) {
                $visible = 0;
            }
        }
        $username = (string)($options['username'] ?? 'Autor Testowy');
        if ($thread) {
            $tid = $this->db->insert_query('threads', ['fid' => $fid, 'subject' => $this->db->escape_string($subject),
                'uid' => $uid, 'username' => $this->db->escape_string($username), 'firstpost' => 0, 'visible' => $visible,
                'dateline' => TIME_NOW]);
        }
        $pid = $this->db->insert_query('posts', ['tid' => $tid, 'replyto' => (int)$handler->data['replyto'], 'fid' => $fid, 'uid' => $uid,
            'username' => $this->db->escape_string($username), 'subject' => $this->db->escape_string($subject),
            'message' => $this->db->escape_string($message), 'ipaddress' => '203.0.113.77', 'visible' => $visible,
            'dateline' => TIME_NOW]);
        if ($thread) {
            $this->db->update_query('threads', ['firstpost' => $pid], "tid='" . $tid . "'");
            $handler->return_values = ['pid' => $pid, 'tid' => $tid, 'visible' => $visible];
        } else {
            $handler->return_values = ['pid' => $pid, 'visible' => $visible, 'closed' => 0];
        }
        $GLOBALS['plugins']->run_hooks('datahandler_post_insert_' . $kind . '_end', $handler);
        return $handler;
    }

    /**
     * The current user edits a post, the way MyBB 1.8.41's `PostDataHandler::update_post`
     * runs: `pid`, `tid` and `fid` in the data, a first post's new subject written to its
     * thread, the `datahandler_post_update` hook, and only then the post's new text.
     *
     * @param int         $pid     The post.
     * @param string      $message The new message.
     * @param string|null $subject A new subject, or null to keep it.
     * @return FakePostHandler The handler.
     */
    public function edit(int $pid, string $message, ?string $subject = null): FakePostHandler
    {
        $post = $this->post($pid);
        $data = ['pid' => $pid, 'tid' => (int)$post['tid'], 'fid' => (int)$post['fid'], 'message' => $message,
            'edit_uid' => (int)$this->mybb->user['uid']];
        if ($subject !== null) {
            $data['subject'] = $subject;
        }
        $handler = new FakePostHandler('update', 'post', $data);
        $fields = ['message' => $this->db->escape_string($message)];
        if ($subject !== null) {
            $fields['subject'] = $this->db->escape_string($subject);
            $this->db->update_query('threads', ['subject' => $fields['subject']],
                "tid='" . (int)$post['tid'] . "' AND firstpost='" . $pid . "'");
        }
        $GLOBALS['plugins']->run_hooks('datahandler_post_update', $handler);
        $this->db->update_query('posts', $fields, "pid='" . $pid . "'");
        return $handler;
    }

    /**
     * The current user adds a poll to a thread, the way MyBB 1.8.41's `polls.php`
     * (`do_newpoll`) does: the poll row, the thread's `poll`, then `polls_do_newpoll_end`
     * with the thread's row (as read before) in the global `$thread`.
     *
     * @param int               $tid      The thread.
     * @param string            $question The question.
     * @param array<int,string> $options  The options, as typed.
     * @return int The poll's id.
     */
    public function addPoll(int $tid, string $question, array $options): int
    {
        $thread = (array)$this->db->fetch_array($this->db->simple_select('threads', '*', "tid='" . $tid . "'"));
        $poll = $this->db->insert_query('polls', ['tid' => $tid, 'question' => $this->db->escape_string($question),
            'options' => $this->db->escape_string(implode('||~|~||', $options)),
            'votes' => implode('||~|~||', array_fill(0, count($options), '0')), 'numoptions' => count($options)]);
        $this->db->update_query('threads', ['poll' => $poll], "tid='" . $tid . "'");
        $GLOBALS['thread'] = $thread;
        $GLOBALS['plugins']->run_hooks('polls_do_newpoll_end');
        return $poll;
    }

    /**
     * A moderator changes a poll, the way `polls.php` (`do_editpoll`) does: the poll row
     * updated, then `polls_do_editpoll_end` with the thread's row in the global `$thread`.
     *
     * @param int               $poll     The poll.
     * @param string            $question The question.
     * @param array<int,string> $options  The options.
     */
    public function editPoll(int $poll, string $question, array $options): void
    {
        $GLOBALS['thread'] = (array)$this->db->fetch_array($this->db->simple_select('threads', '*', "poll='" . $poll . "'"));
        $this->db->update_query('polls', ['question' => $this->db->escape_string($question),
            'options' => $this->db->escape_string(implode('||~|~||', $options)), 'numoptions' => count($options)],
            "pid='" . $poll . "'");
        $GLOBALS['plugins']->run_hooks('polls_do_editpoll_end');
    }

    /**
     * The shared pause a "retry" answer started is over: what the test does next happens
     * after its end. The entry stays, ended now, as it would be found later.
     */
    public function endPause(): void
    {
        $platform = $this->platform();
        $pause = $platform->state(Submitter::PAUSE);
        if (is_array($pause)) {
            $platform->setState(Submitter::PAUSE, ['until' => TIME_NOW] + $pause);
        }
    }

    /**
     * A published thread to reply to (made directly, without the plugin).
     *
     * @return int Its tid.
     */
    public function thread(): int
    {
        // An hour old: its last post is what a subscriber's last visit is compared with.
        $tid = $this->db->insert_query('threads', ['fid' => self::FID, 'subject' => 'Wątek', 'uid' => 1,
            'username' => 'Założyciel', 'firstpost' => 0, 'visible' => 1, 'dateline' => self::HOUR_AGO,
            'lastpost' => self::HOUR_AGO]);
        $pid = $this->db->insert_query('posts', ['tid' => $tid, 'fid' => self::FID, 'uid' => 1, 'username' => 'Założyciel',
            'subject' => 'Wątek', 'message' => 'Pierwszy post.', 'ipaddress' => '', 'visible' => 1, 'dateline' => self::HOUR_AGO]);
        $this->db->update_query('threads', ['firstpost' => $pid], "tid='" . $tid . "'");
        $this->db->update_query('forums', ['lastpost' => self::HOUR_AGO], "fid='" . self::FID . "'");
        return $tid;
    }

    /**
     * A registered user (group 2, "Registered").
     *
     * @param array<string,mixed> $fields Columns over the defaults: `language` '' (the
     *     board's), `lastactive` now (visited after the last post), `usergroup` 2.
     */
    public function user(int $uid, string $name, array $fields = []): void
    {
        $this->db->insert_query('users', $this->escapedRow($fields + ['uid' => $uid, 'username' => $name,
            'email' => strtolower($name) . '@example.com', 'language' => '', 'lastactive' => TIME_NOW, 'usergroup' => 2,
            'receivepms' => 1, 'pmnotify' => 0]));
    }

    /**
     * A user subscribes to a thread: `notification` 1 by e-mail, 2 by private message, 0 none.
     */
    public function subscribe(int $uid, int $tid, int $notification): void
    {
        $this->db->insert_query('threadsubscriptions', ['uid' => $uid, 'tid' => $tid, 'notification' => $notification]);
    }

    /**
     * A user subscribes to a forum.
     */
    public function subscribeForum(int $uid, int $fid = self::FID): void
    {
        $this->db->insert_query('forumsubscriptions', ['uid' => $uid, 'fid' => $fid]);
    }

    /**
     * @return array<int,array<string,mixed>> The e-mails MyBB's mail queue holds, oldest first.
     */
    public function mails(): array
    {
        return $this->all('SELECT * FROM ' . TABLE_PREFIX . 'mailqueue ORDER BY mid');
    }

    /**
     * @return array<int,array<string,mixed>> The reports in the ModCP's report centre, oldest first.
     */
    public function reports(): array
    {
        return $this->all('SELECT * FROM ' . TABLE_PREFIX . 'reportedcontent ORDER BY rid');
    }

    /**
     * @return array<int,array<string,mixed>> The private messages in users' inboxes, oldest first.
     */
    public function pms(): array
    {
        return $this->all('SELECT * FROM ' . TABLE_PREFIX . 'privatemessages ORDER BY pmid');
    }

    /**
     * @return array<int,array<string,mixed>>
     */
    private function all(string $sql): array
    {
        $rows = [];
        $query = $this->db->query($sql);
        while ($row = $this->db->fetch_array($query)) {
            $rows[] = $row;
        }
        return $rows;
    }

    /**
     * @param array<string,mixed> $row
     * @return array<string,mixed>
     */
    private function escapedRow(array $row): array
    {
        foreach ($row as $column => $value) {
            $row[$column] = is_int($value) ? $value : $this->db->escape_string((string)$value);
        }
        return $row;
    }

    /**
     * The gateway delivers a payload, signed as it signs.
     *
     * @param array<string,mixed>|string $payload A payload, or an exact body.
     * @param string|null                $secret  The signing secret ({@see SECRET} by default).
     * @param int|null                   $signedAt The signature's timestamp (now by default).
     * @return int The receiver's HTTP status.
     */
    public function deliver($payload, ?string $secret = null, ?int $signedAt = null, ?int $now = null): int
    {
        $body = is_string($payload) ? $payload
            : (string)json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        $now = $now ?? time();
        $header = Signature::sign($secret ?? self::SECRET, $body, $signedAt ?? $now);
        return $this->plugin->receiver->handle('POST', $header, $body, $now);
    }

    /**
     * @return array<string,mixed>|null The plugin's row of a post.
     */
    public function row(int $pid): ?array
    {
        return $this->platform()->pending($pid);
    }

    /**
     * @return array<string,mixed> A post's row in `posts`.
     */
    public function post(int $pid): array
    {
        $query = $this->db->simple_select('posts', '*', "pid='" . $pid . "'");
        return (array)$this->db->fetch_array($query);
    }

    /**
     * @return array<int,string> MyBB's tables for the plugin, in SQLite.
     */
    private static function schema(): array
    {
        $p = TABLE_PREFIX;
        return [
            "CREATE TABLE {$p}settinggroups (gid INTEGER PRIMARY KEY, name TEXT NOT NULL DEFAULT '', title TEXT NOT NULL DEFAULT '',"
                . " description TEXT NOT NULL DEFAULT '', disporder INTEGER NOT NULL DEFAULT 0, isdefault INTEGER NOT NULL DEFAULT 0)",
            "CREATE TABLE {$p}settings (sid INTEGER PRIMARY KEY, name TEXT NOT NULL DEFAULT '', title TEXT NOT NULL DEFAULT '',"
                . " description TEXT NOT NULL DEFAULT '', optionscode TEXT NOT NULL DEFAULT '', value TEXT NOT NULL DEFAULT '',"
                . " disporder INTEGER NOT NULL DEFAULT 0, gid INTEGER NOT NULL DEFAULT 0, isdefault INTEGER NOT NULL DEFAULT 0)",
            "CREATE TABLE {$p}tasks (tid INTEGER PRIMARY KEY, title TEXT NOT NULL DEFAULT '', description TEXT NOT NULL DEFAULT '',"
                . " file TEXT NOT NULL DEFAULT '', minute TEXT NOT NULL DEFAULT '', hour TEXT NOT NULL DEFAULT '',"
                . " day TEXT NOT NULL DEFAULT '', month TEXT NOT NULL DEFAULT '', weekday TEXT NOT NULL DEFAULT '',"
                . " nextrun INTEGER NOT NULL DEFAULT 0, lastrun INTEGER NOT NULL DEFAULT 0, enabled INTEGER NOT NULL DEFAULT 1,"
                . " logging INTEGER NOT NULL DEFAULT 0, locked INTEGER NOT NULL DEFAULT 0)",
            "CREATE TABLE {$p}threads (tid INTEGER PRIMARY KEY, fid INTEGER NOT NULL DEFAULT 0, subject TEXT NOT NULL DEFAULT '',"
                . " uid INTEGER NOT NULL DEFAULT 0, username TEXT NOT NULL DEFAULT '', firstpost INTEGER NOT NULL DEFAULT 0,"
                . " visible INTEGER NOT NULL DEFAULT 1, poll INTEGER NOT NULL DEFAULT 0, dateline INTEGER NOT NULL DEFAULT 0,"
                . " lastpost INTEGER NOT NULL DEFAULT 0)",
            "CREATE TABLE {$p}forums (fid INTEGER PRIMARY KEY, name TEXT NOT NULL DEFAULT '', lastpost INTEGER NOT NULL DEFAULT 0)",
            "INSERT INTO {$p}forums (fid, name) VALUES (" . self::FID . ", 'Forum testowe')",
            "CREATE TABLE {$p}users (uid INTEGER PRIMARY KEY, username TEXT NOT NULL DEFAULT '', email TEXT NOT NULL DEFAULT '',"
                . " language TEXT NOT NULL DEFAULT '', lastactive INTEGER NOT NULL DEFAULT 0, usergroup INTEGER NOT NULL DEFAULT 2,"
                . " receivepms INTEGER NOT NULL DEFAULT 1, pmnotify INTEGER NOT NULL DEFAULT 0)",
            "CREATE TABLE {$p}usergroups (gid INTEGER PRIMARY KEY, title TEXT NOT NULL DEFAULT '', isbannedgroup INTEGER NOT NULL DEFAULT 0)",
            "INSERT INTO {$p}usergroups (gid, title, isbannedgroup) VALUES (2, 'Registered', 0), (7, 'Banned', 1)",
            "CREATE TABLE {$p}threadsubscriptions (sid INTEGER PRIMARY KEY, uid INTEGER NOT NULL DEFAULT 0, tid INTEGER NOT NULL DEFAULT 0,"
                . " notification INTEGER NOT NULL DEFAULT 0)",
            "CREATE TABLE {$p}forumsubscriptions (fsid INTEGER PRIMARY KEY, fid INTEGER NOT NULL DEFAULT 0, uid INTEGER NOT NULL DEFAULT 0)",
            "CREATE TABLE {$p}mailqueue (mid INTEGER PRIMARY KEY, mailto TEXT NOT NULL DEFAULT '', mailfrom TEXT NOT NULL DEFAULT '',"
                . " subject TEXT NOT NULL DEFAULT '', message TEXT NOT NULL DEFAULT '', headers TEXT NOT NULL DEFAULT '')",
            "CREATE TABLE {$p}reportreasons (rid INTEGER PRIMARY KEY, title TEXT NOT NULL DEFAULT '', appliesto TEXT NOT NULL DEFAULT '',"
                . " extra INTEGER NOT NULL DEFAULT 0, disporder INTEGER NOT NULL DEFAULT 0)",
            // MyBB 1.8.41's install data (`install/resources/mysql_db_inserts.php`).
            "INSERT INTO {$p}reportreasons (rid, title, appliesto, extra, disporder) VALUES (1, '<lang:report_reason_other>', 'all', 1, 99),"
                . " (2, '<lang:report_reason_rules>', 'all', 0, 1), (3, '<lang:report_reason_bad>', 'all', 0, 2),"
                . " (4, '<lang:report_reason_spam>', 'all', 0, 3), (5, '<lang:report_reason_wrong>', 'post', 0, 4)",
            "CREATE TABLE {$p}reportedcontent (rid INTEGER PRIMARY KEY, id INTEGER NOT NULL DEFAULT 0, id2 INTEGER NOT NULL DEFAULT 0,"
                . " id3 INTEGER NOT NULL DEFAULT 0, uid INTEGER NOT NULL DEFAULT 0, reportstatus INTEGER NOT NULL DEFAULT 0,"
                . " reasonid INTEGER NOT NULL DEFAULT 0, reason TEXT NOT NULL DEFAULT '', type TEXT NOT NULL DEFAULT '',"
                . " reports INTEGER NOT NULL DEFAULT 0, reporters TEXT NOT NULL DEFAULT '', dateline INTEGER NOT NULL DEFAULT 0,"
                . " lastreport INTEGER NOT NULL DEFAULT 0)",
            "CREATE TABLE {$p}privatemessages (pmid INTEGER PRIMARY KEY, uid INTEGER NOT NULL DEFAULT 0, toid INTEGER NOT NULL DEFAULT 0,"
                . " fromid INTEGER NOT NULL DEFAULT 0, folder INTEGER NOT NULL DEFAULT 1, subject TEXT NOT NULL DEFAULT '',"
                . " message TEXT NOT NULL DEFAULT '')",
            "CREATE TABLE {$p}polls (pid INTEGER PRIMARY KEY, tid INTEGER NOT NULL DEFAULT 0, question TEXT NOT NULL DEFAULT '',"
                . " options TEXT NOT NULL DEFAULT '', votes TEXT NOT NULL DEFAULT '', numoptions INTEGER NOT NULL DEFAULT 0)",
            "CREATE TABLE {$p}posts (pid INTEGER PRIMARY KEY, tid INTEGER NOT NULL DEFAULT 0, replyto INTEGER NOT NULL DEFAULT 0,"
                . " fid INTEGER NOT NULL DEFAULT 0,"
                . " uid INTEGER NOT NULL DEFAULT 0, username TEXT NOT NULL DEFAULT '', subject TEXT NOT NULL DEFAULT '',"
                . " message TEXT NOT NULL DEFAULT '', ipaddress TEXT NOT NULL DEFAULT '', visible INTEGER NOT NULL DEFAULT 1,"
                . " dateline INTEGER NOT NULL DEFAULT 0)",
        ];
    }
}
