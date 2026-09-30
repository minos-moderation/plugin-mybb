<?php

declare(strict_types=1);

namespace Minos\MyBB\Tests\Support;

use Minos\Client\Signature;
use Minos\MyBB\Gateway;
use Minos\MyBB\Platform;
use Minos\MyBB\Plugin;
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
        $GLOBALS['minos_test'] = ['moderators' => [], 'forum_permissions' => [], 'moderation' => [], 'task_log' => []];
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
     * @param array<string,mixed> $options `subject`, `fid`, `tid`, `uid`, `savedraft`.
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
            'savedraft' => !empty($options['savedraft']) ? 1 : 0,
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
        if ($thread) {
            $tid = $this->db->insert_query('threads', ['fid' => $fid, 'subject' => $this->db->escape_string($subject),
                'uid' => $uid, 'firstpost' => 0, 'visible' => $visible]);
        }
        $pid = $this->db->insert_query('posts', ['tid' => $tid, 'fid' => $fid, 'uid' => $uid,
            'subject' => $this->db->escape_string($subject), 'message' => $this->db->escape_string($message),
            'ipaddress' => '203.0.113.77', 'visible' => $visible]);
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
     * A published thread to reply to (made directly, without the plugin).
     *
     * @return int Its tid.
     */
    public function thread(): int
    {
        $tid = $this->db->insert_query('threads', ['fid' => self::FID, 'subject' => 'Wątek', 'uid' => 1,
            'firstpost' => 0, 'visible' => 1]);
        $pid = $this->db->insert_query('posts', ['tid' => $tid, 'fid' => self::FID, 'uid' => 1,
            'subject' => 'Wątek', 'message' => 'Pierwszy post.', 'ipaddress' => '', 'visible' => 1]);
        $this->db->update_query('threads', ['firstpost' => $pid], "tid='" . $tid . "'");
        return $tid;
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
                . " uid INTEGER NOT NULL DEFAULT 0, firstpost INTEGER NOT NULL DEFAULT 0, visible INTEGER NOT NULL DEFAULT 1)",
            "CREATE TABLE {$p}posts (pid INTEGER PRIMARY KEY, tid INTEGER NOT NULL DEFAULT 0, fid INTEGER NOT NULL DEFAULT 0,"
                . " uid INTEGER NOT NULL DEFAULT 0, subject TEXT NOT NULL DEFAULT '', message TEXT NOT NULL DEFAULT '',"
                . " ipaddress TEXT NOT NULL DEFAULT '', visible INTEGER NOT NULL DEFAULT 1)",
        ];
    }
}
