<?php

declare(strict_types=1);

namespace Minos\MyBB\Tests\EndToEnd;

use Minos\Mock\Config;
use Minos\MyBB\Plugin;
use Minos\MyBB\Status;
use Minos\MyBB\Tests\Support\Forum;
use PHPUnit\Framework\TestCase;

/**
 * The plugin against the mock gateway of `minos-moderation/client-php`, over HTTP:
 *
 * - `bin/build-zip.sh --stage-only` stages the distributable (the build itself is tested);
 * - PHP's built-in server serves the STAGED forum root — the real `minos-webhook.php`, with
 *   a fake `inc/init.php` over the SQLite file this test shares;
 * - another serves the mock gateway, told to deliver to that webhook;
 * - `forum-cli.php`, run from the staged root, installs the plugin and writes the posts
 *   through its hooks, sent with cURL — both sides run the staged plugin, not this
 *   repository's classes — and the mock's worker CLI delivers the verdicts, signed like the
 *   gateway signs.
 *
 * The mock chooses verdicts from `[minos:…]` markers in the text (its README).
 */
final class EndToEndTest extends TestCase
{
    private const ROOT = __DIR__ . '/../..';

    private const MOCK = self::ROOT . '/vendor/minos-moderation/client-php/mock-gateway';

    /** @var array<int,array{0:resource,1:int}> Started servers and their ports. */
    private $servers = [];

    /** @var string */
    private $dir;

    protected function setUp(): void
    {
        // The mock's own loader: Composer knows only the library of the client package.
        require_once self::MOCK . '/autoload.php';
        $this->dir = sys_get_temp_dir() . '/minos-mybb-e2e-' . bin2hex(random_bytes(6));
        mkdir($this->dir . '/mock', 0700, true);
    }

    protected function tearDown(): void
    {
        foreach ($this->servers as [$server, $port]) {
            proc_terminate($server);
            proc_close($server);
            // A server that outlives its test is a leak.
            $socket = @fsockopen('127.0.0.1', $port, $errno, $errstr, 0.2);
            self::assertFalse($socket, "the server on port {$port} is still running");
        }
        self::remove($this->dir);
        Plugin::reset();
    }

    public function testVerdictsFromTheMockGatewayAreAppliedThroughTheWebhook(): void
    {
        // The distributable, staged by the real build script from this repository's vendor/.
        $this->runCommand([self::ROOT . '/bin/build-zip.sh', '--stage-only'],
            ['MINOS_VENDOR_DIR' => self::ROOT . '/vendor', 'MINOS_BUILD_DIR' => $this->dir . '/build']);
        $forumRoot = $this->dir . '/build/stage/Upload';
        self::assertFileExists($forumRoot . '/inc/plugins/minos/vendor/minos-moderation/client-php/src/Signature.php');
        self::assertDirectoryDoesNotExist($forumRoot . '/inc/plugins/minos/vendor/minos-moderation/client-php/mock-gateway');
        copy(__DIR__ . '/fixtures/init.php', $forumRoot . '/inc/init.php');
        copy(__DIR__ . '/fixtures/forum-cli.php', $forumRoot . '/forum-cli.php');

        $database = $this->dir . '/forum.sqlite';
        Forum::create($database); // the schema, in this process; the forum itself runs staged
        $receiverPort = $this->freePort();
        $forumEnv = [
            'MINOS_TEST_DB'     => $database,
            'MINOS_TEST_REPO'   => (string)realpath(self::ROOT),
            'MINOS_TEST_BBURL'  => "http://127.0.0.1:{$receiverPort}",
        ];
        $this->serve($forumRoot, $forumEnv, $receiverPort);
        $mockEnv = [
            'MINOS_MOCK_WEBHOOK_URL' => "http://127.0.0.1:{$receiverPort}/minos-webhook.php",
            'MINOS_MOCK_DATA_DIR'    => $this->dir . '/mock',
            'MINOS_MOCK_DELAY_S'     => '0',
        ];
        $mockPort = $this->serve(self::MOCK . '/public', $mockEnv);

        // Install and post from the STAGED tree, as a forum would.
        $posted = json_decode($this->runCommand([PHP_BINARY, $forumRoot . '/forum-cli.php'], $forumEnv, (string)json_encode([
            'install' => [
                'minos_enabled'        => '1',
                'minos_gateway_url'    => "http://127.0.0.1:{$mockPort}",
                'minos_api_key'        => Config::DEFAULT_KEY,
                'minos_webhook_secret' => Config::DEFAULT_SECRET,
            ],
            'user'  => ['uid' => 7, 'postnum' => 1, 'moderateposts' => 0],
            'posts' => [
                'safe'     => [false, 'Świetny wpis!'],
                'blocked'  => [false, 'spadaj [minos:blokuj] [minos:kategoria=nekanie]'],
                'censored' => [false, 'to jest [minos:cenzuruj] [[głupi]] pomysł'],
                'none'     => [false, 'coś [minos:nieocenione]'],
                'support'  => [false, 'Jest mi bardzo źle [minos:kategoria=samookaleczenie]'],
                'twice'    => [false, 'Dwa razy [minos:dwa-razy]'],
                'forged'   => [false, 'Podrobiony [minos:zly-podpis]'],
                'thread'   => [true, 'Pierwszy post wątku.', 'Wątek z bramy'],
                'edited'   => [false, 'Pierwsza wersja [minos:blokuj]'],
            ],
        ])), true);
        self::assertStringStartsWith(realpath($forumRoot), (string)$posted['plugin'], 'the posting side ran the staged plugin');
        $pids = array_map('intval', (array)$posted['pids']);
        self::assertCount(9, $pids);

        $forum = Forum::create($database);
        foreach ($pids as $name => $pid) {
            self::assertSame(Status::PENDING, $forum->row($pid)['status'], "{$name}: the mock accepted it (202)");
            self::assertSame(0, (int)$forum->post($pid)['visible'], "{$name}: held until its verdict");
        }

        // Its author edits a post while it waits; the task sends the edit as revision 1. The
        // mock then delivers both verdicts, the stale one about the first text first.
        $this->runCommand([PHP_BINARY, $forumRoot . '/forum-cli.php'], $forumEnv, (string)json_encode([
            'user'  => ['uid' => 7, 'postnum' => 1, 'moderateposts' => 0],
            'edits' => [$pids['edited'] => 'Poprawiona wersja.'],
            'task'  => true,
        ]));
        self::assertSame([Status::PENDING, '1'], [$forum->row($pids['edited'])['status'], (string)$forum->row($pids['edited'])['rev']],
            'revision 1 accepted (202)');

        // Two passes: `[minos:dwa-razy]` is delivered again on the second.
        $first = $this->runCommand([PHP_BINARY, self::MOCK . '/bin/worker.php', '--once'], $mockEnv);
        $second = $this->runCommand([PHP_BINARY, self::MOCK . '/bin/worker.php', '--once'], $mockEnv);
        self::assertStringContainsString('mybb:' . $pids['forged'] . ' ponowienie 401', $first, 'a forged delivery is refused');
        self::assertMatchesRegularExpression('/mybb:' . $pids['twice'] . ' dostarczony 200/', $second, 'the repeat reached the webhook');

        $state = function (string $name) use ($forum, $pids): array {
            $row = $forum->row($pids[$name]);
            return [(int)$forum->post($pids[$name])['visible'], $row['status'], $row['verdict']];
        };
        self::assertSame([1, Status::PUBLISHED, 'bezpieczne'], $state('safe'));
        self::assertSame([0, Status::HELD, 'zablokowane'], $state('blocked'));
        self::assertSame('nekanie', $forum->row($pids['blocked'])['categories']);
        self::assertSame([0, Status::HELD, 'nieocenione'], $state('none'), 'fail-closed by default');
        self::assertSame([1, Status::PUBLISHED, 'bezpieczne'], $state('support'));
        self::assertSame('1', (string)$forum->row($pids['support'])['support']);
        self::assertSame([1, Status::PUBLISHED, 'bezpieczne'], $state('twice'));
        self::assertSame([0, Status::PENDING, ''], $state('forged'));
        self::assertSame([1, Status::PUBLISHED, 'bezpieczne'], $state('thread'));
        self::assertMatchesRegularExpression('/mybb:' . $pids['edited'] . '\.1 dostarczony 200/', $first, 'revision 1 was delivered');
        self::assertSame([1, Status::PUBLISHED, 'bezpieczne'], $state('edited'), 'the stale verdict was not applied');
        // The mock masks one character per character, as the contract does.
        self::assertSame([1, Status::CENSORED, 'ocenzurowane'], $state('censored'));
        self::assertSame('to jest [minos:cenzuruj] [[█████]] pomysł', $forum->post($pids['censored'])['message']);
        self::assertSame('to jest [minos:cenzuruj] [[głupi]] pomysł', $forum->row($pids['censored'])['original_text']);
    }

    /**
     * Shared rule F5a over HTTP, with the real cURL transport of the staged plugin: at the
     * gateway's address something else answers (`foreign-router.php`) — a `200`, a `201`
     * and a `204`, each naming the post in a body like the gateway's acceptance, then a
     * `408`. None is an acceptance or a configuration error: the post stays held and
     * waits, retried after a backoff that doubles, and the sending pauses; the ACP is told
     * that something else answers there. Once the address is the mock gateway's, its `202`
     * accepts the post and its verdict is applied through the webhook.
     */
    public function testOnlyTheGatewaysAcceptanceAcceptsAPostOverHttp(): void
    {
        [$forumRoot, $forumEnv, $mockEnv, $mockPort] = $this->stage();
        $count = $this->dir . '/foreign.count';
        $foreignPort = $this->serveRouter(__DIR__ . '/fixtures/foreign-router.php',
            ['MINOS_E2E_STATUSES' => '200,201,204,408', 'MINOS_E2E_COUNT' => $count]);
        $job = function (array $job) use ($forumRoot, $forumEnv): array {
            return (array)json_decode($this->runCommand([PHP_BINARY, $forumRoot . '/forum-cli.php'], $forumEnv,
                (string)json_encode($job + ['user' => ['uid' => 7, 'postnum' => 1, 'moderateposts' => 0]])), true);
        };
        $posted = $job([
            'install' => [
                'minos_enabled'        => '1',
                'minos_gateway_url'    => "http://127.0.0.1:{$foreignPort}",
                'minos_api_key'        => Config::DEFAULT_KEY,
                'minos_webhook_secret' => Config::DEFAULT_SECRET,
            ],
            'posts' => ['post' => [false, 'Wpis wysłany pod adres, pod którym nie ma bramy.']],
        ]);
        $pid = (int)$posted['pids']['post'];
        $t0 = (int)$posted['now'];
        $forum = Forum::create($this->dir . '/forum.sqlite');
        $seen = function () use ($forum, $pid): array {
            $row = $forum->row($pid);
            return [$row['status'], $row['error_code'], (int)$row['attempts'], (int)$forum->post($pid)['visible']];
        };
        self::assertSame([Status::RETRY, 'http_200', 1, 0], $seen(), 'a 200 naming the post is no acceptance');
        self::assertSame('http_200', $posted['state']['pause']['kod'], 'and pauses the sending');
        self::assertSame('http_200', $posted['state']['foreign_answer']['kod'], 'for the ACP notice');
        self::assertNull($forum->platform()->lastLog(['config_error']), 'nor a configuration error');

        // The task sends it again after each backoff: 60 s, 120 s, 240 s.
        $at = $t0 + 61;
        $job(['task_at' => $at]);
        self::assertSame([Status::RETRY, 'http_201', 2, 0], $seen(), 'a 201 neither');
        $at += 121;
        $job(['task_at' => $at]);
        self::assertSame([Status::RETRY, 'http_204', 3, 0], $seen(), 'a 204 neither');
        $at += 241;
        $ran = $job(['task_at' => $at]);
        self::assertSame([Status::RETRY, 'http_408', 4, 0], $seen(), 'a 408 is a network failure: retried');
        self::assertSame('http_408', $ran['state']['pause']['kod']);
        self::assertSame('4', (string)file_get_contents($count), 'four requests reached the foreign address');

        // The administrator corrects the address: the mock's 202 accepts it, its verdict applies.
        $at += 481;
        $accepted = $job(['set' => ['minos_gateway_url' => "http://127.0.0.1:{$mockPort}"], 'task_at' => $at]);
        self::assertSame([Status::PENDING, '', 5, 0], $seen(), 'the gateway\'s 202 accepts it');
        self::assertNull($accepted['state']['foreign_answer'], 'and clears the notice');
        $delivered = $this->runCommand([PHP_BINARY, self::MOCK . '/bin/worker.php', '--once'], $mockEnv);
        self::assertMatchesRegularExpression('/mybb:' . $pid . ' dostarczony 200/', $delivered);
        self::assertSame([Status::PUBLISHED, 1], [$forum->row($pid)['status'], (int)$forum->post($pid)['visible']]);
        self::assertSame('4', (string)file_get_contents($count), 'nothing more went to the foreign address');
    }

    /**
     * The staged forum served over HTTP next to the mock gateway.
     *
     * @return array{0:string,1:array<string,string>,2:array<string,string>,3:int} The forum
     *     root, its environment, the mock's environment and its port.
     */
    private function stage(): array
    {
        $this->runCommand([self::ROOT . '/bin/build-zip.sh', '--stage-only'],
            ['MINOS_VENDOR_DIR' => self::ROOT . '/vendor', 'MINOS_BUILD_DIR' => $this->dir . '/build']);
        $forumRoot = $this->dir . '/build/stage/Upload';
        copy(__DIR__ . '/fixtures/init.php', $forumRoot . '/inc/init.php');
        copy(__DIR__ . '/fixtures/forum-cli.php', $forumRoot . '/forum-cli.php');
        $database = $this->dir . '/forum.sqlite';
        Forum::create($database);
        $receiverPort = $this->freePort();
        $forumEnv = [
            'MINOS_TEST_DB'     => $database,
            'MINOS_TEST_REPO'   => (string)realpath(self::ROOT),
            'MINOS_TEST_BBURL'  => "http://127.0.0.1:{$receiverPort}",
        ];
        $this->serve($forumRoot, $forumEnv, $receiverPort);
        $mockEnv = [
            'MINOS_MOCK_WEBHOOK_URL' => "http://127.0.0.1:{$receiverPort}/minos-webhook.php",
            'MINOS_MOCK_DATA_DIR'    => $this->dir . '/mock',
            'MINOS_MOCK_DELAY_S'     => '0',
        ];
        return [$forumRoot, $forumEnv, $mockEnv, $this->serve(self::MOCK . '/public', $mockEnv)];
    }

    /**
     * Starts PHP's built-in server with a router script and waits until it answers.
     *
     * @param array<string,string> $env
     * @return int The port.
     */
    private function serveRouter(string $router, array $env): int
    {
        for ($try = 0; $try < 5; $try++) {
            $port = random_int(20000, 40000);
            $server = proc_open([PHP_BINARY, '-S', "127.0.0.1:{$port}", $router],
                [1 => ['file', '/dev/null', 'w'], 2 => ['file', '/dev/null', 'w']], $pipes, null, $env + getenv());
            self::assertIsResource($server);
            for ($wait = 0; $wait < 50; $wait++) {
                $socket = @fsockopen('127.0.0.1', $port, $errno, $errstr, 0.1);
                if ($socket !== false) {
                    fclose($socket);
                    $this->servers[] = [$server, $port];
                    return $port;
                }
                usleep(100000);
            }
            proc_terminate($server);
            proc_close($server);
        }
        self::fail('the router did not start');
    }

    /**
     * A port nothing listens on now.
     */
    private function freePort(): int
    {
        for ($try = 0; $try < 20; $try++) {
            $port = random_int(20000, 40000);
            $socket = @fsockopen('127.0.0.1', $port, $errno, $errstr, 0.1);
            if ($socket === false) {
                return $port;
            }
            fclose($socket);
        }
        self::fail('no free port');
    }

    /**
     * Starts PHP's built-in server and waits until it answers.
     *
     * @param array<string,string> $env
     * @param int|null             $port A port to try first.
     * @return int The port.
     */
    private function serve(string $docroot, array $env, ?int $port = null): int
    {
        for ($try = 0; $try < 5; $try++) {
            $port = $port !== null && $try === 0 ? $port : random_int(20000, 40000);
            // An array, not a string: a string runs through `sh -c`, and terminating the
            // shell would leave PHP's server running.
            $server = proc_open([PHP_BINARY, '-S', "127.0.0.1:{$port}", '-t', $docroot],
                [1 => ['file', '/dev/null', 'w'], 2 => ['file', '/dev/null', 'w']], $pipes, null, $env + getenv());
            self::assertIsResource($server);
            for ($wait = 0; $wait < 50; $wait++) {
                $socket = @fsockopen('127.0.0.1', $port, $errno, $errstr, 0.1);
                if ($socket !== false) {
                    fclose($socket);
                    $this->servers[] = [$server, $port];
                    return $port;
                }
                usleep(100000);
            }
            proc_terminate($server);
            proc_close($server);
        }
        self::fail('the built-in server did not start');
    }

    /**
     * Runs a command to its end.
     *
     * @param array<int,string>    $command
     * @param array<string,string> $env
     * @param string               $input Its standard input.
     * @return string Its output.
     */
    private function runCommand(array $command, array $env, string $input = ''): string
    {
        $process = proc_open($command, [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes, null, $env + getenv());
        self::assertIsResource($process);
        fwrite($pipes[0], $input);
        fclose($pipes[0]);
        $output = (string)stream_get_contents($pipes[1]) . (string)stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);
        self::assertSame(0, proc_close($process), implode(' ', $command) . ":\n" . $output);
        return $output;
    }

    private static function remove(string $path): void
    {
        if (is_link($path) || is_file($path)) {
            unlink($path);
            return;
        }
        if (is_dir($path)) {
            foreach (scandir($path) ?: [] as $entry) {
                if ($entry !== '.' && $entry !== '..') {
                    self::remove($path . '/' . $entry);
                }
            }
            rmdir($path);
        }
    }
}
