<?php

declare(strict_types=1);

namespace Minos\MyBB\Tests\EndToEnd;

use Minos\Mock\Config;
use Minos\MyBB\Gateway;
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
 * - posts are written through the plugin's hooks in this process, sent with cURL, and the
 *   mock's worker CLI delivers the verdicts, signed like the gateway signs.
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

        $database = $this->dir . '/forum.sqlite';
        $forum = Forum::create($database);
        $receiverPort = $this->serve($forumRoot, ['MINOS_TEST_DB' => $database, 'MINOS_TEST_REPO' => realpath(self::ROOT)]);
        $mockEnv = [
            'MINOS_MOCK_WEBHOOK_URL' => "http://127.0.0.1:{$receiverPort}/minos-webhook.php",
            'MINOS_MOCK_DATA_DIR'    => $this->dir . '/mock',
            'MINOS_MOCK_DELAY_S'     => '0',
        ];
        $mockPort = $this->serve(self::MOCK . '/public', $mockEnv);

        $forum->mybb->settings['bburl'] = "http://127.0.0.1:{$receiverPort}";
        $plugin = Plugin::build($forum->platform(), new Gateway());
        $plugin->installer->install();
        $plugin->installer->activate();
        $forum->set([
            'minos_enabled'        => '1',
            'minos_gateway_url'    => "http://127.0.0.1:{$mockPort}",
            'minos_api_key'        => Config::DEFAULT_KEY,
            'minos_webhook_secret' => Config::DEFAULT_SECRET,
        ]);
        $forum->mybb->user = ['uid' => 7, 'postnum' => 1, 'moderateposts' => 0];

        $pids = [];
        foreach ([
            'safe'      => 'Świetny wpis!',
            'blocked'   => 'spadaj [minos:blokuj] [minos:kategoria=nekanie]',
            'censored'  => 'to jest [minos:cenzuruj] [[głupi]] pomysł',
            'none'      => 'coś [minos:nieocenione]',
            'support'   => 'Jest mi bardzo źle [minos:kategoria=samookaleczenie]',
            'twice'     => 'Dwa razy [minos:dwa-razy]',
            'forged'    => 'Podrobiony [minos:zly-podpis]',
        ] as $name => $message) {
            $pids[$name] = (int)$forum->write(false, $message)->return_values['pid'];
        }
        $thread = $forum->write(true, 'Pierwszy post wątku.', ['subject' => 'Wątek z bramy']);
        $pids['thread'] = (int)$thread->return_values['pid'];

        foreach ($pids as $name => $pid) {
            self::assertSame(Status::PENDING, $forum->row($pid)['status'], "{$name}: the mock accepted it (202)");
            self::assertSame(0, (int)$forum->post($pid)['visible'], "{$name}: held until its verdict");
        }

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
        // The mock masks `[[głupi]]` as five characters, dropping the brackets, so its text
        // is shorter than what was sent; the contract masks one character per character.
        // The plugin does not publish a masked text of another length: it holds the post.
        self::assertSame([0, Status::HELD, 'ocenzurowane'], $state('censored'));
    }

    /**
     * Starts PHP's built-in server and waits until it answers.
     *
     * @param array<string,string> $env
     * @return int The port.
     */
    private function serve(string $docroot, array $env): int
    {
        for ($try = 0; $try < 5; $try++) {
            $port = random_int(20000, 40000);
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
     * @return string Its output.
     */
    private function runCommand(array $command, array $env): string
    {
        $process = proc_open($command, [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes, null, $env + getenv());
        self::assertIsResource($process);
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
