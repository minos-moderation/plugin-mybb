<?php

declare(strict_types=1);

namespace Minos\MyBB\Tests\Live\Support;

use Minos\Mock\Config;
use Minos\Mock\Queue;
use PDO;

/**
 * The live MyBB 1.8.41 forum of `bin/verify-mybb-1841.sh`: its pages (as an administrator,
 * an author, a subscriber, a guest), its database (read to see what happened), the helper
 * inside it (`minos-live-cli.php`) and the mock gateway beside it.
 */
final class Board
{
    /** The forum MyBB's installer creates ("My Forum"). */
    public const FID = 2;

    /** @var string */
    public $url;

    /** @var string The forum root. */
    public $root;

    /** @var string `mysqli` or `pgsql`. */
    public $engine;

    /** @var PDO|null */
    private $pdo;

    /** @var array<string,Browser> Logged-in browsers by user name. */
    private $browsers = [];

    public function __construct()
    {
        $this->url = rtrim(Live::env('MINOS_LIVE_URL'), '/');
        $this->root = Live::env('MINOS_LIVE_ROOT');
        $this->engine = Live::env('MINOS_LIVE_ENGINE');
        require_once dirname(__DIR__, 3) . '/vendor/minos-moderation/client-php/mock-gateway/autoload.php';
    }

    // ------------------------------------------------------------------ the database

    public function pdo(): PDO
    {
        if ($this->pdo === null) {
            $dsn = $this->engine === 'pgsql'
                ? 'pgsql:host=' . Live::env('MINOS_LIVE_DB_HOST') . ';dbname=' . Live::env('MINOS_LIVE_DB_NAME')
                : 'mysql:host=' . Live::env('MINOS_LIVE_DB_HOST') . ';dbname=' . Live::env('MINOS_LIVE_DB_NAME') . ';charset=utf8mb4';
            $this->pdo = new PDO($dsn, Live::env('MINOS_LIVE_DB_USER'), Live::env('MINOS_LIVE_DB_PASS'),
                [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC]);
        }
        return $this->pdo;
    }

    /**
     * One row, with `{p}` standing for the table prefix.
     *
     * @param array<int|string,mixed> $params
     * @return array<string,mixed>|null
     */
    public function row(string $sql, array $params = []): ?array
    {
        $statement = $this->pdo()->prepare(str_replace('{p}', Live::PREFIX, $sql));
        $statement->execute($params);
        $row = $statement->fetch();
        return is_array($row) ? $row : null;
    }

    /**
     * @param array<int|string,mixed> $params
     * @return mixed The first column of the first row.
     */
    public function value(string $sql, array $params = [])
    {
        $row = $this->row($sql, $params);
        return $row === null ? null : reset($row);
    }

    /**
     * @param array<int|string,mixed> $params
     */
    public function exec(string $sql, array $params = []): void
    {
        $this->pdo()->prepare(str_replace('{p}', Live::PREFIX, $sql))->execute($params);
    }

    /**
     * The plugin's row of a post.
     *
     * @return array<string,mixed>|null
     */
    public function pending(int $pid): ?array
    {
        return $this->row('SELECT * FROM {p}minos_pending WHERE pid=?', [$pid]);
    }

    /**
     * @return array<string,mixed>|null
     */
    public function post(int $pid): ?array
    {
        return $this->row('SELECT * FROM {p}posts WHERE pid=?', [$pid]);
    }

    // ------------------------------------------------------------------ inside MyBB

    /**
     * Runs the helper inside the forum (`minos-live-cli.php`).
     *
     * @param array<string,mixed> $request
     * @return mixed Its JSON answer.
     */
    public function cli(array $request)
    {
        $process = proc_open([PHP_BINARY, $this->root . '/minos-live-cli.php'],
            [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes, $this->root);
        if (!is_resource($process)) {
            throw new \RuntimeException('the helper did not start');
        }
        fwrite($pipes[0], (string)json_encode($request));
        fclose($pipes[0]);
        $out = (string)stream_get_contents($pipes[1]);
        $err = (string)stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);
        $code = proc_close($process);
        $answer = json_decode($out, true);
        if ($code !== 0 || ($answer === null && trim($out) !== 'null')) {
            throw new \RuntimeException('helper ' . $request['op'] . " failed ({$code}): " . substr($out . $err, 0, 2000));
        }
        return $answer;
    }

    /**
     * Runs one of MyBB's tasks the way a cron line does (`php task.php <tid>`).
     */
    public function task(string $file): void
    {
        $tid = (int)$this->value('SELECT tid FROM {p}tasks WHERE file=?', [$file]);
        $process = proc_open([PHP_BINARY, $this->root . '/task.php', (string)$tid],
            [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes, $this->root);
        stream_get_contents($pipes[1]);
        stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);
        proc_close($process);
    }

    /**
     * Board settings, through MyBB's own `rebuild_settings`.
     *
     * @param array<string,string> $values
     */
    public function settings(array $values): void
    {
        $this->cli(['op' => 'settings', 'values' => $values]);
    }

    /**
     * A registered user (group 2, "Registered").
     */
    public function user(string $name, string $password, int $group = 2): int
    {
        $uid = $this->cli(['op' => 'user', 'username' => $name, 'password' => $password,
            'email' => strtolower($name) . '@example.com', 'usergroup' => $group]);
        if (!is_int($uid)) {
            throw new \RuntimeException('user ' . $name . ': ' . json_encode($uid));
        }
        return $uid;
    }

    // ------------------------------------------------------------------ the pages

    /**
     * A guest's browser.
     */
    public function guest(): Browser
    {
        return new Browser($this->url);
    }

    /**
     * A user's browser, logged in through `member.php` (once per user).
     */
    public function login(string $name, string $password): Browser
    {
        if (!isset($this->browsers[$name])) {
            $browser = new Browser($this->url);
            $form = $browser->get('member.php?action=login');
            $browser->post('member.php', ['action' => 'do_login', 'username' => $name, 'password' => $password,
                'my_post_key' => Browser::postKey($form), 'url' => '', 'remember' => 'yes']);
            if (strpos($browser->get('usercp.php'), 'action=logout') === false) {
                throw new \RuntimeException('login failed for ' . $name);
            }
            $this->browsers[$name] = $browser;
        }
        return $this->browsers[$name];
    }

    /**
     * The administrator's browser, logged in to the ACP.
     */
    public function admin(): Browser
    {
        if (!isset($this->browsers['@acp'])) {
            $browser = $this->login(Live::ADMIN, Live::env('MINOS_LIVE_ADMIN_PASS'));
            $browser->post('admin/index.php', ['do' => 'login', 'username' => Live::ADMIN,
                'password' => Live::env('MINOS_LIVE_ADMIN_PASS')]);
            if (strpos($browser->get('admin/index.php'), 'module=config-plugins') === false) {
                throw new \RuntimeException('ACP login failed');
            }
            $this->browsers['@acp'] = $browser;
        }
        return $this->browsers['@acp'];
    }

    /**
     * An ACP page, GET, with this session's `my_post_key` added.
     */
    public function acp(string $query): string
    {
        $admin = $this->admin();
        $key = Browser::postKey($admin->get('admin/index.php?module=config-plugins'));
        return $admin->get('admin/index.php?' . $query . '&my_post_key=' . $key);
    }

    /**
     * A reply through `newreply.php`, as the browser's user.
     *
     * @return int The new post's pid.
     * @throws \RuntimeException When MyBB refused it (its error in the message).
     */
    public function reply(Browser $as, int $tid, string $message, string $subject = '', int $replyto = 0): int
    {
        $form = $as->get('newreply.php?tid=' . $tid . ($replyto > 0 ? '&replyto=' . $replyto : ''));
        $before = (int)$this->value('SELECT COALESCE(MAX(pid), 0) FROM {p}posts');
        $page = $as->post('newreply.php?tid=' . $tid, [
            'action' => 'do_newreply', 'tid' => $tid, 'replyto' => $replyto,
            'subject' => $subject !== '' ? $subject : Browser::hidden($form, 'subject'),
            'message' => $message, 'my_post_key' => Browser::postKey($form), 'posthash' => Browser::hidden($form, 'posthash'),
            'submit' => 'Post Reply',
        ]);
        $pid = (int)$this->value('SELECT COALESCE(MAX(pid), 0) FROM {p}posts WHERE pid>?', [$before]);
        if ($pid === 0) {
            throw new \RuntimeException('newreply.php refused the reply: ' . self::errorOf($page));
        }
        return $pid;
    }

    /**
     * The error MyBB shows on a page, as text (for a failure's message).
     */
    public static function errorOf(string $html): string
    {
        if (preg_match('#<div class="error">(.*?)</div>#s', $html, $m) || preg_match('#<td class="trow1">(.*?)</td>#s', $html, $m)) {
            return substr(trim((string)preg_replace('/\s+/u', ' ', strip_tags($m[1]))), 0, 300);
        }
        return substr(trim((string)preg_replace('/\s+/u', ' ', strip_tags($html))), 0, 300);
    }

    /**
     * A new thread through `newthread.php`.
     *
     * @return array{tid:int,pid:int,page:string} The page MyBB answered with (a poll redirects).
     * @throws \RuntimeException When MyBB refused it.
     */
    public function thread(Browser $as, string $subject, string $message, bool $poll = false, int $fid = self::FID): array
    {
        $form = $as->get('newthread.php?fid=' . $fid);
        $before = (int)$this->value('SELECT COALESCE(MAX(pid), 0) FROM {p}posts');
        $fields = [
            'action' => 'do_newthread', 'fid' => $fid, 'subject' => $subject, 'message' => $message,
            'my_post_key' => Browser::postKey($form), 'posthash' => Browser::hidden($form, 'posthash'),
            'submit' => 'Post Thread',
        ];
        if ($poll) {
            $fields += ['postpoll' => 1, 'numpolloptions' => 2];
        }
        $page = $as->post('newthread.php?fid=' . $fid, $fields);
        $row = $this->row('SELECT pid, tid FROM {p}posts WHERE pid>? ORDER BY pid DESC', [$before]);
        if ($row === null) {
            throw new \RuntimeException('newthread.php refused the thread: ' . self::errorOf($page));
        }
        return ['tid' => (int)$row['tid'], 'pid' => (int)$row['pid'], 'page' => $page];
    }

    /**
     * A post's body as `showthread.php` shows it to this browser ('' when it is not there).
     */
    public function shownBody(Browser $as, int $tid, int $pid, string $mode = 'linear'): string
    {
        $page = $as->get('showthread.php?tid=' . $tid . '&mode=' . $mode . '&pid=' . $pid);
        if (!preg_match('#<div class="post_body scaleimages" id="pid_' . $pid . '">(.*?)</div>\s*(?:<div class="post_meta|</div>)#s', $page, $m)) {
            return '';
        }
        return $m[1];
    }

    // ------------------------------------------------------------------ the mock gateway

    /**
     * The items the mock gateway holds (accepted and not yet delivered).
     *
     * @return array<int,array<string,mixed>>
     */
    public function mockQueue(): array
    {
        return (new Queue($this->mockConfig()->queuePath()))->all();
    }

    /**
     * One pass of the mock's worker: it delivers what is due, signed like the gateway.
     *
     * @return string The worker's lines.
     */
    public function deliver(): string
    {
        $env = [];
        foreach (['MINOS_MOCK_WEBHOOK_URL', 'MINOS_MOCK_DATA_DIR', 'MINOS_MOCK_DELAY_S'] as $name) {
            $env[$name] = Live::env($name);
        }
        $process = proc_open([PHP_BINARY, dirname(__DIR__, 3) . '/vendor/minos-moderation/client-php/mock-gateway/bin/worker.php', '--once'],
            [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes, null, $env + getenv());
        $out = (string)stream_get_contents($pipes[1]);
        stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);
        proc_close($process);
        return $out;
    }

    public function mockConfig(): Config
    {
        return Config::fromEnv();
    }
}
