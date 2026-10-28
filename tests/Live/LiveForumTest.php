<?php

declare(strict_types=1);

namespace Minos\MyBB\Tests\Live;

use DOMDocument;
use DOMXPath;
use Minos\Client\Signature;
use Minos\MyBB\Tests\Live\Support\Board;
use Minos\MyBB\Tests\Live\Support\Browser;
use Minos\MyBB\Tests\Live\Support\Live;
use PHPUnit\Framework\TestCase;

/**
 * The plugin on a REAL MyBB 1.8.41 forum (K-45), and the seven questions of the live check
 * (K-28), answered by the same run.
 *
 * `bin/verify-mybb-1841.sh` installs MyBB from its `mybb_1841` tag with MyBB's own installer
 * (MySQL or PostgreSQL), copies the built plugin into it, serves it with PHP's built-in
 * server next to the mock gateway of `minos-moderation/client-php`, and runs this class.
 * Every scenario goes through the forum's own pages — the ACP's plugin and settings pages,
 * `member.php`, `newreply.php`, `newthread.php`, `polls.php`, `editpost.php`, `modcp.php`,
 * `showthread.php`, `search.php` — and the real `minos-webhook.php`, with deliveries signed
 * by the mock's worker the way the gateway signs. The database is read to see what
 * happened; outside the pages it is written only to set the stage: the helper inside the
 * forum (`minos-live-cli.php`) changes settings, forums and users through MyBB's own
 * functions and runs the plugin's claim (putting the row back), the subscribers'
 * `lastactive` moves ahead, and the uninstall test drops a column of the kept table.
 *
 * The tests run in order and share the forum. Each answer is recorded and written, at the
 * end, to `$MINOS_LIVE_OUT/live-<engine>.json` (and `reader-view.json`): the fixtures in
 * `tests/fixtures/mybb-1.8.41/` are such files, with the MyBB commit and the PHP version.
 * Test data only: invented users, posts and secrets; the gateway is the mock.
 */
final class LiveForumTest extends TestCase
{
    /** @var Board */
    private static $board;

    /** @var array<string,mixed> The answers, by question. */
    private static $answers = [];

    /** @var array<string,int> */
    private static $uids = [];

    /** @var array<string,string> Passwords by user name, made up for this run. */
    private static $passwords = [];

    /** @var int The thread the replies go to. */
    private static $tid = 0;

    /** @var int A post the mock never answers, kept waiting (`[minos:cisza]`). */
    private static $waiting = 0;

    private const AUTHOR = 'autor_minos';

    private const MAIL_SUBSCRIBER = 'subskrybent_mail';

    private const PM_SUBSCRIBER = 'subskrybent_pw';

    /**
     * What a reader sees of a message: cases for the shared rules F7 (images, videos, links
     * without a label) and S1 (an HTML forum's `<script>`, `<style>` and comments). Rendered
     * by MyBB's own parser with the forum's options, as `build_postbit` does.
     */
    private const READER_VIEW = [
        'html-script'          => [['allowhtml' => 1], 'Miły tekst <script>obelga</script>'],
        'html-style'           => [['allowhtml' => 1], '<style>p { color: red }</style>Zwykły tekst'],
        'html-comment'         => [['allowhtml' => 1], 'Widoczne zdanie <!-- ukryta obelga --> i dalej'],
        'html-comment-open'    => [['allowhtml' => 1], 'Widoczne zdanie <!-- ukryta obelga do końca'],
        'html-comment-in-title' => [['allowhtml' => 1], 'Miły tekst <b title="<!--">obelga</b><b title="-->">'],
        'html-comment-in-alt'  => [['allowhtml' => 1], '<img alt="<!--" src="https://example.com/a.png">obelga -->'],
        'html-img-alt'         => [['allowhtml' => 1], '<img src="https://example.com/a.png" alt="podpis obrazka">'],
        'html-img-alt-text'    => [['allowhtml' => 1], 'Tekst obok <img src="https://example.com/a.png" alt="podpis obrazka" title="tytuł">'],
        'html-empty-link'      => [['allowhtml' => 1], '<a href="https://example.com/"> </a>'],
        'html-off-tags'        => [['allowhtml' => 0], 'Miłego dnia <b obelga> <script>x</script>'],
        'img'                  => [[], '[img]https://example.com/obrazek.png[/img]'],
        'img-size'             => [[], '[img=100x50]https://example.com/obrazek.png[/img]'],
        'img-align'            => [[], '[img align=left]https://example.com/obrazek.png[/img]'],
        'img-with-text'        => [[], 'Tekst przy obrazku [img]https://example.com/obrazek.png[/img]'],
        'img-imgcode-off'      => [['allowimgcode' => 0], '[img]https://example.com/obrazek.png[/img]'],
        'video'                => [[], '[video=youtube]https://www.youtube.com/watch?v=dQw4w9WgXcQ[/video]'],
        'video-unknown-site'   => [[], '[video=nieznany]https://example.com/film[/video]'],
        'video-videocode-off'  => [['allowvideocode' => 0], '[video=youtube]https://www.youtube.com/watch?v=dQw4w9WgXcQ[/video]'],
        'url-blank-label'      => [[], '[url=https://example.com/] [/url]'],
        'url-image-label'      => [[], '[url=https://example.com/][img]https://example.com/obrazek.png[/img][/url]'],
        'url-bold-blank-label' => [[], '[url=https://example.com/][b] [/b][/url]'],
        'url-empty'            => [[], '[url=https://example.com/][/url]'],
        'url-label'            => [[], '[url=https://example.com/]opis linku[/url]'],
        'url-bare-tag'         => [[], '[url]https://example.com/strona[/url]'],
        'url-bare'             => [[], 'https://example.com/strona'],
        'img-leading-space'    => [[], '[img] https://example.com/ obelga obelga[/img]'],
        'img-two-newlines'     => [[], "[img]\n\nhttps://example.com/obrazek.png[/img]"],
        'img-one-newline'      => [[], "[img]\nhttps://example.com/obrazek.png[/img]"],
        'video-newline'        => [[], "[video=youtube]\nhttps://www.youtube.com/watch?v=dQw4w9WgXcQ[/video]"],
        'video-no-id'          => [[], '[video=youtube]https://www.youtube.com/[/video]'],
        'video-facebook-no-id' => [[], '[video=facebook]https://example.com/[/video]'],
        'video-id-other-host'  => [[], '[video=youtube]https://example.com/obelga[/video]'],
        'video-twitch-channel' => [[], '[video=twitch]https://example.com/[/video]'],
        'video-site-case'      => [[], '[video=YouTube]https://www.youtube.com/watch?v=dQw4w9WgXcQ[/video]'],
        'img-zero-width-space' => [[], "[img]https://example.com/obrazek.png[/img]\u{200B}"],
        'img-soft-hyphen-entity' => [[], '[img]https://example.com/obrazek.png[/img]&#173;'],
        'img-shy-html-off'     => [[], '[img]https://example.com/obrazek.png[/img]&shy;'],
        'only-zero-width-space' => [[], "\u{200B}"],
        'html-img-alt-zwsp'    => [['allowhtml' => 1], '<img src="https://example.com/a.png" alt="podpis">&#8203;'],
        'html-title-shy'       => [['allowhtml' => 1], '<a href="https://example.com/" title="tytuł">&shy;</a>'],
    ];

    public static function setUpBeforeClass(): void
    {
        self::$board = new Board();
        foreach ([self::AUTHOR, self::MAIL_SUBSCRIBER, self::PM_SUBSCRIBER] as $name) {
            self::$passwords[$name] = 'atrapa-' . bin2hex(random_bytes(6));
        }
    }

    public static function tearDownAfterClass(): void
    {
        $out = getenv('MINOS_LIVE_OUT');
        if (!is_string($out) || $out === '' || self::$answers === []) {
            return;
        }
        if (!is_dir($out)) {
            mkdir($out, 0755, true);
        }
        $readerView = self::$answers['reader_view'] ?? null;
        unset(self::$answers['reader_view']);
        $environment = self::environment();
        file_put_contents($out . '/live-' . self::$board->engine . '.json',
            json_encode($environment + ['answers' => self::$answers], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . "\n");
        if ($readerView !== null) {
            file_put_contents($out . '/reader-view.json',
                json_encode($environment + ['cases' => $readerView], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . "\n");
        }
    }

    // ------------------------------------------------------------------ the scenarios

    public function testTheForumIsMyBB1841(): void
    {
        $core = (string)file_get_contents(self::$board->root . '/inc/class_core.php');
        self::assertStringContainsString('public $version = "' . Live::MYBB_VERSION . '";', $core);
        self::assertStringContainsString('public $version_code = 1841;', $core);
        self::assertFileExists(self::$board->root . '/install/lock', 'installed by its own installer');
        self::assertGreaterThan(100, (int)self::$board->value('SELECT COUNT(*) FROM {p}settings'), 'MyBB\'s settings are in the database');
        self::assertSame(self::$board->url, (string)self::$board->value("SELECT value FROM {p}settings WHERE name='bburl'"));
    }

    /**
     * Install and activate through the ACP's plugin page, then the plugin's settings page:
     * the key and the secret go through the plugin's own form fields (the ACP hook moves them
     * into MyBB's `upsetting`). Question 7, first half: the tables as each engine made them.
     */
    public function testInstallAndActivateThroughTheACP(): void
    {
        $board = self::$board;
        $board->settings(['postfloodcheck' => '0', 'postfloodsecs' => '0', 'searchfloodtime' => '0', 'maxposts' => '0',
            'postmergemins' => '0']);
        foreach (array_keys(self::$passwords) as $name) {
            self::$uids[$name] = $board->user($name, self::$passwords[$name]);
        }

        $plugins = $board->acp('module=config-plugins');
        self::assertStringContainsString('plugin=minos', $plugins, 'the plugin is listed');
        $board->acp('module=config-plugins&action=activate&plugin=minoslive');
        $board->acp('module=config-plugins&action=activate&plugin=minos');

        $active = (string)$board->value("SELECT cache FROM {p}datacache WHERE title='plugins'");
        self::assertStringContainsString('"minos"', $active, 'active in MyBB\'s plugin cache');
        foreach (['minos_pending', 'minos_log'] as $table) {
            self::assertNotNull($board->row('SELECT COUNT(*) AS n FROM {p}' . $table), $table . ' exists');
        }
        self::assertSame(1, (int)$board->value("SELECT enabled FROM {p}tasks WHERE file='minos'"), 'the task is on');
        $gid = (int)$board->value("SELECT gid FROM {p}settinggroups WHERE name='minos'");
        self::assertGreaterThan(0, $gid);

        $mock = $board->mockConfig();
        $admin = $board->admin();
        $form = $admin->get('admin/index.php?module=config-settings&action=change&gid=' . $gid);
        $admin->post('admin/index.php?module=config-settings&action=change', [
            'my_post_key' => Browser::postKey($form), 'gid' => $gid,
            'upsetting' => [
                'minos_enabled' => '1', 'minos_gateway_url' => Live::env('MINOS_LIVE_MOCK_URL'),
                'minos_profile' => 'forum_adult', 'minos_failure_mode' => 'fail-closed',
            ],
            'minos_secret_input' => ['minos_api_key' => $mock->key, 'minos_webhook_secret' => $mock->webhookSecret],
        ]);
        $settings = [];
        foreach (['minos_enabled', 'minos_gateway_url', 'minos_api_key', 'minos_webhook_secret'] as $name) {
            $settings[$name] = (string)$board->value('SELECT value FROM {p}settings WHERE name=?', [$name]);
        }
        self::assertSame(['minos_enabled' => '1', 'minos_gateway_url' => Live::env('MINOS_LIVE_MOCK_URL'),
            'minos_api_key' => $mock->key, 'minos_webhook_secret' => $mock->webhookSecret], $settings,
            'saved through the ACP form, the secrets through the plugin\'s own fields');
        $page = $admin->get('admin/index.php?module=config-settings&action=change&gid=' . $gid);
        self::assertStringNotContainsString($mock->webhookSecret, $page, 'the settings page shows a prefix only');
        self::assertStringNotContainsString($mock->key, $page);

        self::$answers['q7_schema'] = $board->cli(['op' => 'schema']);
    }

    /**
     * The smoke test: an author's reply is held, sent once to the gateway, and published by
     * a signed verdict over HTTP. Question 6: the counters before, while held and after.
     */
    public function testAHeldReplyIsSentOnceAndPublishedByASignedVerdict(): void
    {
        $board = self::$board;
        $topic = $board->thread($board->admin(), 'Wątek testowy Minos', 'Pierwszy post administratora.');
        self::$tid = $topic['tid'];
        self::assertSame(1, (int)$board->post($topic['pid'])['visible'], 'an administrator\'s thread skips the plugin');

        $author = $board->login(self::AUTHOR, self::$passwords[self::AUTHOR]);
        $before = $this->counters();
        $pid = $board->reply($author, self::$tid, 'Zwykła odpowiedź testowa.');
        self::assertGreaterThan(0, $pid);
        self::assertSame(0, (int)$board->post($pid)['visible'], 'held in MyBB\'s moderation queue');
        self::assertSame('pending', $board->pending($pid)['status'], 'accepted by the gateway (202)');
        $queued = array_values(array_filter($board->mockQueue(), static function (array $entry) use ($pid): bool {
            return $entry['item_id'] === 'mybb:' . $pid;
        }));
        self::assertCount(1, $queued, 'one request for the post');
        self::assertSame('Zwykła odpowiedź testowa.', $queued[0]['text']);
        $held = $this->counters();

        $lines = $board->deliver();
        self::assertMatchesRegularExpression('/mybb:' . $pid . ' dostarczony 200/', $lines);
        self::assertSame(1, (int)$board->post($pid)['visible'], 'published by the verdict');
        self::assertSame(['published', 'bezpieczne'], [$board->pending($pid)['status'], $board->pending($pid)['verdict']]);
        $approved = $this->counters();

        // The same for a reply a moderator approves in the ModCP queue (the mock never
        // answers this one): the plugin's path must leave the counters as MyBB's own does.
        $manualBefore = $this->counters();
        $manual = $board->reply($author, self::$tid, 'Odpowiedź do porównania liczników [minos:cisza].');
        $manualHeld = $this->counters();
        $this->approveInModcp($manual);
        $manualApproved = $this->counters();

        self::$answers['q6_counters'] = [
            'approved_by_webhook' => ['before' => $before, 'held' => $held, 'approved' => $approved],
            'approved_in_modcp'   => ['before' => $manualBefore, 'held' => $manualHeld, 'approved' => $manualApproved],
        ];
        foreach (['forum_posts', 'thread_replies', 'author_postnum'] as $counter) {
            self::assertSame($before[$counter], $held[$counter], $counter . ': a held reply does not count');
            self::assertSame($before[$counter] + 1, $approved[$counter], $counter . ': counted once approved');
        }
        self::assertSame(self::delta($manualBefore, $manualHeld), self::delta($before, $held), 'held: as MyBB holds');
        self::assertSame(self::delta($manualBefore, $manualApproved), self::delta($before, $approved), 'approved: as a moderator approves');

        // The approval's time inside the webhook's request (audit measurement C-39): one
        // delivery signed here, timed from the outside.
        $pid = $board->reply($author, self::$tid, 'Druga odpowiedź do pomiaru.');
        $body = (string)json_encode(['id' => 'mybb:' . $pid, 'status' => 'ocenione', 'kwalifikacja' => 'bezpieczne',
            'kategorie' => [], 'wsparcie' => false, 'wersja' => '3f0c9a41d2b7e8c5']);
        $webhook = $board->guest();
        $webhook->postRaw('minos-webhook.php', $body, ['Content-Type: application/json',
            Signature::HEADER . ': ' . Signature::sign($board->mockConfig()->webhookSecret, $body, time())]);
        self::assertSame(200, $webhook->status);
        self::assertSame(1, (int)$board->post($pid)['visible']);
        self::$answers['c39_webhook_request_ms'] = (int)round($webhook->seconds * 1000);
        $board->deliver(); // the mock's own delivery of it: already applied, 200
    }

    /**
     * The mask (K-30): a censored plain-text reply is published with the gateway's masked
     * text, one `█` per character; the original stays in the plugin's row.
     */
    public function testACensoredReplyIsPublishedMasked(): void
    {
        $board = self::$board;
        $pid = $board->reply($board->login(self::AUTHOR, self::$passwords[self::AUTHOR]), self::$tid,
            'to jest [minos:cenzuruj] [[głupi]] pomysł');
        $board->deliver();
        self::assertSame('to jest [minos:cenzuruj] [[█████]] pomysł', $board->post($pid)['message']);
        self::assertSame(1, (int)$board->post($pid)['visible']);
        self::assertSame(['censored', 'to jest [minos:cenzuruj] [[głupi]] pomysł'],
            [$board->pending($pid)['status'], $board->pending($pid)['original_text']]);
    }

    /**
     * A late verdict for a post the failure mode held after its timeout (K-43, shared rule
     * D9.10): fail-closed, the gateway accepted the reply, its verdict did not come in time
     * and MyBB's task (`task.php`) held it; the mock then delivers it through the real
     * webhook and it is applied as on time. A reply a moderator approved in the ModCP queue
     * meanwhile is left as the moderator decided.
     */
    public function testALateVerdictIsAppliedToAPostHeldForItsTimeout(): void
    {
        $board = self::$board;
        $author = $board->login(self::AUTHOR, self::$passwords[self::AUTHOR]);
        // A thread of its own, so the other scenarios' thread keeps its pages.
        $tid = $board->thread($board->admin(), 'Wątek spóźnionych werdyktów', 'Pierwszy post administratora.')['tid'];
        $late = $board->reply($author, $tid, 'Odpowiedź, której werdykt się spóźni.');
        $decided = $board->reply($author, $tid, 'Odpowiedź, o której zdecyduje moderator.');
        // The verdicts are late: the gateway accepted both 21 minutes ago (the timeout is 20).
        $board->exec('UPDATE {p}minos_pending SET accepted_at=? WHERE pid IN (?, ?)', [time() - 21 * 60, $late, $decided]);
        $board->task('minos');
        $held = [];
        foreach (['late' => $late, 'decided' => $decided] as $name => $pid) {
            $held[$name] = [$board->pending($pid)['status'], $board->pending($pid)['verdict'], (int)$board->post($pid)['visible']];
        }
        $this->approveInModcp($decided);

        $lines = $board->deliver();
        self::assertMatchesRegularExpression('/mybb:' . $late . ' dostarczony 200/', $lines);
        self::assertMatchesRegularExpression('/mybb:' . $decided . ' dostarczony 200/', $lines);
        $after = [];
        foreach (['late' => $late, 'decided' => $decided] as $name => $pid) {
            $after[$name] = [$board->pending($pid)['status'], $board->pending($pid)['verdict'], (int)$board->post($pid)['visible']];
        }
        self::$answers['k43_late_after_timeout'] = ['held_by_the_task' => $held, 'after_the_late_verdict' => $after];
        self::assertSame(['held', 'timeout', 0], $held['late'], 'held by the failure mode');
        self::assertSame(['published', 'bezpieczne', 1], $after['late'], 'applied as on time');
        self::assertSame(['held', 'timeout', 1], $after['decided'], 'the moderator\'s decision stays');
    }

    /**
     * Question 2: subscribers of the thread (e-mail, private message) and of the forum, and
     * what each kind of publication queues for them — a post visible at once (the plugin
     * off: the control), a post the plugin publishes through the webhook, and a post a
     * moderator approves in the ModCP queue.
     */
    public function testWhatSubscribersGetWhenAHeldPostIsApproved(): void
    {
        $board = self::$board;
        $mail = $board->login(self::MAIL_SUBSCRIBER, self::$passwords[self::MAIL_SUBSCRIBER]);
        $pm = $board->login(self::PM_SUBSCRIBER, self::$passwords[self::PM_SUBSCRIBER]);
        $page = $mail->get('showthread.php?tid=' . self::$tid);
        $mail->post('usercp.php', ['action' => 'do_addsubscription', 'tid' => self::$tid, 'notification' => 1,
            'my_post_key' => Browser::postKey($page)]);
        $mail->get('usercp.php?action=addsubscription&type=forum&fid=' . Board::FID . '&my_post_key=' . Browser::postKey($page));
        $page = $pm->get('showthread.php?tid=' . self::$tid);
        $pm->post('usercp.php', ['action' => 'do_addsubscription', 'tid' => self::$tid, 'notification' => 2,
            'my_post_key' => Browser::postKey($page)]);
        self::assertSame(1, (int)$board->value('SELECT notification FROM {p}threadsubscriptions WHERE uid=? AND tid=?',
            [self::$uids[self::MAIL_SUBSCRIBER], self::$tid]));
        self::assertSame(2, (int)$board->value('SELECT notification FROM {p}threadsubscriptions WHERE uid=? AND tid=?',
            [self::$uids[self::PM_SUBSCRIBER], self::$tid]));
        self::assertSame(1, (int)$board->value('SELECT COUNT(*) FROM {p}forumsubscriptions WHERE uid=? AND fid=?',
            [self::$uids[self::MAIL_SUBSCRIBER], Board::FID]));

        $author = $board->login(self::AUTHOR, self::$passwords[self::AUTHOR]);
        $answers = [];

        // The control: the plugin switched off, the post is public at once.
        $board->settings(['minos_enabled' => '0']);
        $answers['visible_at_once'] = $this->notificationsFor(function () use ($board, $author): void {
            $pid = $board->reply($author, self::$tid, 'Odpowiedź widoczna od razu.');
            self::assertSame(1, (int)$board->post($pid)['visible']);
            $topic = $board->thread($author, 'Wątek widoczny od razu', 'Treść wątku widocznego od razu.');
            self::assertSame(1, (int)$board->post($topic['pid'])['visible']);
        });
        $board->settings(['minos_enabled' => '1']);
        self::assertGreaterThan(0, $answers['visible_at_once']['thread_mail'], 'the control reaches the e-mail subscriber');
        self::assertGreaterThan(0, $answers['visible_at_once']['thread_pm'], 'the control reaches the PM subscriber');
        self::assertGreaterThan(0, $answers['visible_at_once']['forum_mail'], 'the control reaches the forum subscriber');

        // Published by the plugin through the webhook.
        $answers['approved_by_webhook'] = $this->notificationsFor(function () use ($board, $author): void {
            $pid = $board->reply($author, self::$tid, 'Odpowiedź zatwierdzona przez webhook.');
            $topic = $board->thread($author, 'Wątek zatwierdzony przez webhook', 'Treść wątku zatwierdzonego przez webhook.');
            self::assertSame([0, 0], [(int)$board->post($pid)['visible'], (int)$board->post($topic['pid'])['visible']]);
            $board->deliver();
            self::assertSame([1, 1], [(int)$board->post($pid)['visible'], (int)$board->post($topic['pid'])['visible']]);
        });

        // Approved by a moderator in the ModCP queue (the mock never answers this one).
        $answers['approved_in_modcp'] = $this->notificationsFor(function () use ($board, $author): void {
            $pid = $board->reply($author, self::$tid, 'Odpowiedź dla moderatora [minos:cisza].');
            self::assertSame(0, (int)$board->post($pid)['visible']);
            $this->approveInModcp($pid);
        });
        self::$answers['q2_notifications'] = $answers;
        self::assertSame(['thread_mail' => 0, 'forum_mail' => 0, 'thread_pm' => 0], $answers['approved_by_webhook']);
    }

    /**
     * Question 3: a poll on a thread that waits for its verdict — the form, a direct post of
     * it, the hooks that ran, and what the plugin sends.
     */
    public function testAPollOnAWaitingThread(): void
    {
        $board = self::$board;
        $author = $board->login(self::AUTHOR, self::$passwords[self::AUTHOR]);
        $log = $board->root . '/cache/minoslive-hooks.log';
        @unlink($log);
        $topic = $board->thread($author, 'Wątek z ankietą', 'Treść wątku z ankietą [minos:cisza].', true);
        $pid = $topic['pid'];
        self::assertSame(0, (int)$board->post($pid)['visible']);
        $answers = ['newthread_with_poll_answer' => self::pageKind($topic['page'])];

        $form = $author->get('polls.php?action=newpoll&tid=' . $topic['tid'] . '&polloptions=2');
        $answers['newpoll_form_for_the_author'] = strpos($form, 'name="question"') !== false ? 'form' : self::pageKind($form);
        $author->post('polls.php', [
            'action' => 'do_newpoll', 'tid' => $topic['tid'], 'question' => 'Pytanie ankiety testowej?',
            'options' => [1 => 'Opcja pierwsza', 2 => 'Opcja druga'], 'polloptions' => 2, 'timeout' => 0,
            'my_post_key' => Browser::postKey($author->get('showthread.php?tid=' . self::$tid)),
        ]);
        $poll = (int)$board->value('SELECT poll FROM {p}threads WHERE tid=?', [$topic['tid']]);
        $answers['direct_do_newpoll_created_a_poll'] = $poll > 0;
        $answers['hooks'] = is_file($log) ? array_values(array_unique(array_map('trim', file($log)))) : [];
        $row = $board->pending($pid);
        $answers['plugin_row_after_the_poll'] = ['status' => $row['status'], 'rev' => (int)$row['rev']];
        if ($poll > 0) {
            $board->task('minos');
            $sent = array_values(array_filter($board->mockQueue(), static function (array $entry) use ($pid): bool {
                return strpos($entry['item_id'], 'mybb:' . $pid . '.') === 0;
            }));
            $answers['sent_again_with_the_poll'] = $sent !== [] && strpos($sent[0]['text'], 'Pytanie ankiety testowej?') !== false;
        }
        self::$answers['q3_polls'] = $answers;
        self::assertStringNotContainsString('name="question"', $form, 'the form refuses a thread held for moderation');
    }

    /**
     * Question 4: may an author of the "Registered" group edit their own post while it waits
     * in the moderation queue — with MyBB's default `showownunapproved` (off), and with it on.
     */
    public function testWhoMayEditAnUnapprovedPost(): void
    {
        $board = self::$board;
        $author = $board->login(self::AUTHOR, self::$passwords[self::AUTHOR]);
        $pid = $board->reply($author, self::$tid, 'Do poprawy [minos:cisza].');
        self::$waiting = $pid;
        self::assertSame('pending', $board->pending($pid)['status']);
        $answers = [];
        $page = $author->get('editpost.php?pid=' . $pid);
        $answers['showownunapproved_off'] = strpos($page, 'name="message"') !== false ? 'form' : self::pageKind($page);

        $board->settings(['showownunapproved' => '1']);
        $page = $author->get('editpost.php?pid=' . $pid);
        $answers['showownunapproved_on'] = strpos($page, 'name="message"') !== false ? 'form' : self::pageKind($page);
        if ($answers['showownunapproved_on'] === 'form') {
            $author->post('editpost.php', ['action' => 'do_editpost', 'pid' => $pid, 'subject' => Browser::hidden($page, 'subject'),
                'message' => 'Poprawiona treść [minos:cisza].', 'my_post_key' => Browser::postKey($page),
                'posthash' => self::optionalHidden($page, 'posthash')]);
            $answers['edit_saved'] = $board->post($pid)['message'] === 'Poprawiona treść [minos:cisza].';
            $answers['plugin_row_after_the_edit'] = ['status' => $board->pending($pid)['status'], 'rev' => (int)$board->pending($pid)['rev']];
        }
        $board->settings(['showownunapproved' => '0']);
        self::$answers['q4_edit_own_unapproved'] = $answers;
        self::assertNotSame('form', $answers['showownunapproved_off'], 'MyBB\'s default refuses the author');
    }

    /**
     * Question 5: where a reply's own subject shows — the linear view, the threaded view,
     * the printable version, post search results.
     */
    public function testWhereAReplysOwnSubjectShows(): void
    {
        $board = self::$board;
        $subject = 'Temat własny odpowiedzi';
        $pid = $board->reply($board->login(self::AUTHOR, self::$passwords[self::AUTHOR]), self::$tid,
            'Treść odpowiedzi z własnym tematem zielonykot.', $subject);
        $sent = array_values(array_filter($board->mockQueue(), static function (array $entry) use ($pid): bool {
            return $entry['item_id'] === 'mybb:' . $pid;
        }));
        $board->deliver();
        self::assertSame(1, (int)$board->post($pid)['visible']);
        $guest = $board->guest();
        $admin = $board->admin();
        $search = $admin->get('search.php');
        $results = $admin->followRefresh($admin->post('search.php', ['action' => 'do_search', 'keywords' => 'zielonykot',
            'postthread' => 1, 'showresults' => 'posts', 'forums' => ['all'], 'my_post_key' => self::optionalPostKey($search)]));
        self::assertStringContainsString('zielonykot', strip_tags($results), 'the guard: the search found the post');
        self::$answers['q5_reply_subject'] = [
            'sent_with_the_subject' => $sent !== [] && strpos($sent[0]['text'], $subject) === 0,
            'linear'    => strpos($guest->get('showthread.php?tid=' . self::$tid . '&mode=linear'), $subject) !== false,
            'threaded'  => strpos($guest->get('showthread.php?tid=' . self::$tid . '&mode=threaded&pid=' . $pid), $subject) !== false,
            'printable' => strpos($guest->get('printthread.php?tid=' . self::$tid), $subject) !== false,
            'search'    => strpos($results, $subject) !== false,
        ];
        self::assertTrue(self::$answers['q5_reply_subject']['threaded'], 'the threaded view shows it');
    }

    /**
     * Question 1: replies and threads whose body is `Widoczne SLOWO` and n unclosed
     * `[url=http://a.pl]x` tags (n = 340, 1000) at PHP's default `pcre.backtrack_limit`:
     * does MyBB take them, what does a reader see, what does PCRE say in MyBB's parser — and
     * what the plugin does.
     */
    public function testUnclosedUrlTagsAgainstPcre(): void
    {
        $board = self::$board;
        $author = $board->login(self::AUTHOR, self::$passwords[self::AUTHOR]);
        $answers = [];
        foreach ([340, 1000] as $n) {
            $message = 'Widoczne SLOWO ' . str_repeat('[url=http://a.pl]x', $n);
            foreach (['reply', 'thread'] as $kind) {
                $refusal = '';
                try {
                    if ($kind === 'reply') {
                        $pid = $board->reply($author, self::$tid, $message);
                        $tid = self::$tid;
                    } else {
                        $topic = $board->thread($author, 'Wątek PCRE ' . $n, $message);
                        $pid = $topic['pid'];
                        $tid = $topic['tid'];
                    }
                } catch (\RuntimeException $e) {
                    [$pid, $tid, $refusal] = [0, 0, $e->getMessage()];
                }
                $stored = $pid > 0 ? $board->post($pid) : null;
                $parsed = $board->cli(['op' => 'parse', 'fid' => Board::FID, 'message' => $message]);
                $shown = $pid > 0 ? $board->shownBody($board->admin(), $tid, $pid) : '';
                $row = $pid > 0 ? $board->pending($pid) : null;
                $answers[$kind . '_' . $n] = [
                    'bytes' => strlen($message),
                    'accepted_by_mybb' => $stored !== null && $stored['message'] === $message,
                    'mybb_refusal' => $refusal,
                    'reader_sees_the_word' => strpos(self::visible($shown)['text'], 'SLOWO') !== false,
                    'reader_body_chars' => mb_strlen(self::visible($shown)['text']),
                    'parser_output_chars' => mb_strlen(self::visible((string)$parsed['html'])['text']),
                    'parser_sees_the_word' => strpos(self::visible((string)$parsed['html'])['text'], 'SLOWO') !== false,
                    'parser_preg_last_error' => $parsed['preg_last_error'],
                    'parser_pattern_failures' => array_map(static function (array $f): array {
                        return ['pattern' => (string)$f['pattern'], 'error' => (int)$f['error']];
                    }, $parsed['pattern_failures']),
                    'parser_output_chars_without_its_output_check' => $parsed['html_without_output_check'] === null
                        ? null : mb_strlen(self::visible((string)$parsed['html_without_output_check'])['text']),
                    'pcre' => ['backtrack_limit' => $parsed['backtrack_limit'], 'jit' => $parsed['jit']],
                    'plugin' => $row === null ? null : ['status' => $row['status'], 'verdict' => $row['verdict']],
                ];
            }
        }
        self::$answers['q1_pcre'] = $answers;
        self::assertTrue($answers['reply_340']['accepted_by_mybb']);
    }

    /**
     * What a reader sees of images, videos, links and HTML ({@see READER_VIEW}), by MyBB's
     * own parser; one HTML case also through `showthread.php`, to tie the parser to the page.
     */
    public function testWhatAReaderSeesOfImagesLinksAndHtml(): void
    {
        $board = self::$board;
        $defaults = $board->row('SELECT allowhtml, allowmycode, allowimgcode, allowvideocode FROM {p}forums WHERE fid=?', [Board::FID]);
        $cases = [];
        foreach (self::READER_VIEW as $id => [$options, $message]) {
            $forum = array_map('intval', $options + $defaults);
            $board->cli(['op' => 'forum', 'fid' => Board::FID, 'values' => $forum]);
            $parsed = $board->cli(['op' => 'parse', 'fid' => Board::FID, 'message' => $message]);
            $cases[$id] = ['forum' => $forum, 'message' => $message, 'html' => $parsed['html']] + self::visible((string)$parsed['html']);
        }
        $board->cli(['op' => 'forum', 'fid' => Board::FID, 'values' => ['allowhtml' => 1] + array_map('intval', $defaults)]);
        $pid = $board->reply($board->login(self::AUTHOR, self::$passwords[self::AUTHOR]), self::$tid,
            'Miły tekst <script>obelga</script> <!-- ukryta obelga --> [minos:cisza]');
        $shown = self::visible($board->shownBody($board->admin(), self::$tid, $pid));
        $sent = array_values(array_filter($board->mockQueue(), static function (array $entry) use ($pid): bool {
            return $entry['item_id'] === 'mybb:' . $pid;
        }));
        $board->cli(['op' => 'forum', 'fid' => Board::FID, 'values' => array_map('intval', $defaults)]);
        self::assertCount(1, $sent);
        self::assertSame('Miły tekst <script>obelga</script> [minos:cisza]', $sent[0]['text'],
            'the plugin sends what the page shows: the escaped tags, not the comment');
        self::$answers['reader_view'] = $cases;
        self::$answers['html_forum_page'] = ['text' => $shown['text']];
        self::assertStringContainsString('<script>obelga</script>', $shown['text'], 'MyBB shows <script> as text');
        self::assertStringNotContainsString('ukryta', $shown['text'], 'an HTML comment is not shown');
        self::assertSame('', $cases['img']['text']);
        self::assertSame('https://example.com/strona', $cases['url-bare']['text']);
    }

    /**
     * Shared rule F7 on the real forum: an image alone is held unsent (`no_text`), a bare
     * address is sent as text.
     */
    public function testAnImageAloneIsNotSentAndABareAddressIs(): void
    {
        $board = self::$board;
        $author = $board->login(self::AUTHOR, self::$passwords[self::AUTHOR]);
        $queued = count($board->mockQueue());
        $image = $board->reply($author, self::$tid, '[img]https://example.com/obrazek.png[/img]');
        self::assertSame(['held', 'no_text'], [$board->pending($image)['status'], $board->pending($image)['verdict']]);
        self::assertCount($queued, $board->mockQueue(), 'nothing sent for the image');
        self::assertSame(0, (int)$board->post($image)['visible'], 'fail-closed');

        $address = $board->reply($author, self::$tid, 'https://example.com/strona');
        $sent = array_values(array_filter($board->mockQueue(), static function (array $entry) use ($address): bool {
            return $entry['item_id'] === 'mybb:' . $address;
        }));
        self::assertCount(1, $sent);
        self::assertSame(['https://example.com/strona', 1], [$sent[0]['text'], $sent[0]['meta']['links']]);
        $board->deliver();
    }

    /**
     * Question 7, second half: the claim's conditional UPDATE on this engine — the first
     * claim of a waiting row changes one row, the second none.
     */
    public function testTheClaimCountsOneChangedRow(): void
    {
        $claim = self::$board->cli(['op' => 'claim', 'pid' => self::$waiting]);
        self::$answers['q7_claim'] = $claim;
        self::assertSame(['first' => true, 'second' => false, 'affected_first' => 1, 'affected_second' => 0], $claim);
    }

    /**
     * A delivery with a wrong signature (K-60): `401`, the refusal counted without content,
     * the ACP says what it points at; a verified delivery clears it.
     */
    public function testARefusedSignatureIsNamedInTheACP(): void
    {
        $board = self::$board;
        $body = (string)json_encode(['id' => 'mybb:' . self::$waiting, 'status' => 'ocenione', 'kwalifikacja' => 'bezpieczne',
            'kategorie' => [], 'wsparcie' => false, 'wersja' => '3f0c9a41d2b7e8c5']);
        $webhook = $board->guest();
        $webhook->postRaw('minos-webhook.php', $body, ['Content-Type: application/json',
            Signature::HEADER . ': ' . Signature::sign('nie-ten-sekret-tylko-do-testow', $body, time())]);
        self::assertSame(401, $webhook->status);
        self::assertSame('', trim($webhook->postRaw('minos-webhook.php', $body, ['Content-Type: application/json'])));
        $trail = $board->cli(['op' => 'state', 'name' => 'minos_signature_rejected']);
        self::assertSame(['count' => 2, 'reason' => Signature::MALFORMED], ['count' => $trail['count'], 'reason' => $trail['reason']]);
        self::assertStringContainsString('Webhook odrzucił doręczenia z nieprawidłowym podpisem',
            html_entity_decode($board->admin()->get('admin/index.php'), ENT_QUOTES, 'UTF-8'));
        self::assertSame(0, (int)$board->post(self::$waiting)['visible'], 'nothing applied');

        $other = (string)json_encode(['id' => 'k-1', 'status' => 'ocenione', 'kwalifikacja' => 'bezpieczne',
            'kategorie' => [], 'wsparcie' => false, 'wersja' => '3f0c9a41d2b7e8c5']);
        $webhook->postRaw('minos-webhook.php', $other, ['Content-Type: application/json',
            Signature::HEADER . ': ' . Signature::sign($board->mockConfig()->webhookSecret, $other, time())]);
        self::assertSame(200, $webhook->status);
        self::assertNull($board->cli(['op' => 'state', 'name' => 'minos_signature_rejected']));
    }

    /**
     * Switched off ("Włączona = nie") and deactivated: the webhook answers `404` and applies
     * nothing; deactivated, a new post is not held and nothing is sent.
     */
    public function testSwitchedOffAndDeactivatedTheWebhookAnswers404(): void
    {
        $board = self::$board;
        $body = (string)json_encode(['id' => 'mybb:' . self::$waiting, 'status' => 'ocenione', 'kwalifikacja' => 'bezpieczne',
            'kategorie' => [], 'wsparcie' => false, 'wersja' => '3f0c9a41d2b7e8c5']);
        $signed = function () use ($board, $body): int {
            $webhook = $board->guest();
            $webhook->postRaw('minos-webhook.php', $body, ['Content-Type: application/json',
                Signature::HEADER . ': ' . Signature::sign($board->mockConfig()->webhookSecret, $body, time())]);
            return $webhook->status;
        };
        $board->settings(['minos_enabled' => '0']);
        self::assertSame(404, $signed(), 'switched off');
        $board->settings(['minos_enabled' => '1']);

        $board->acp('module=config-plugins&action=deactivate&plugin=minos');
        self::assertSame(404, $signed(), 'deactivated');
        self::assertSame(0, (int)$board->post(self::$waiting)['visible']);
        $queued = count($board->mockQueue());
        $pid = $board->reply($board->login(self::AUTHOR, self::$passwords[self::AUTHOR]), self::$tid, 'Odpowiedź przy wyłączonej wtyczce.');
        self::assertSame(1, (int)$board->post($pid)['visible'], 'not held');
        self::assertCount($queued, $board->mockQueue(), 'nothing sent');
        self::assertNull($board->pending($pid));
        $board->acp('module=config-plugins&action=activate&plugin=minos');
        self::assertSame(1, (int)$board->value("SELECT enabled FROM {p}tasks WHERE file='minos'"));
    }

    /**
     * Uninstall through the ACP: "No" keeps the tables; a kept table missing a column (an
     * older version's) gets it back on the next activation, rows kept — `add_column` on this
     * engine; "Yes" drops them.
     */
    public function testUninstallKeepsOrDropsTheTables(): void
    {
        $board = self::$board;
        $rows = (int)$board->value('SELECT COUNT(*) FROM {p}minos_pending');
        self::assertGreaterThan(0, $rows);
        $confirm = $board->acp('module=config-plugins&action=deactivate&uninstall=1&plugin=minos');
        self::assertStringContainsString('Czy usunąć także tabele wtyczki', html_entity_decode($confirm, ENT_QUOTES, 'UTF-8'));
        $board->admin()->post('admin/index.php?module=config-plugins&action=deactivate&uninstall=1&plugin=minos',
            ['my_post_key' => Browser::postKey($confirm), 'no' => 'No']);
        self::assertSame(0, (int)$board->value("SELECT COUNT(*) FROM {p}settinggroups WHERE name='minos'"), 'settings removed');
        self::assertSame(0, (int)$board->value("SELECT COUNT(*) FROM {p}tasks WHERE file='minos'"), 'task removed');
        self::assertSame($rows, (int)$board->value('SELECT COUNT(*) FROM {p}minos_pending'), '"No" keeps the rows');

        $board->exec('ALTER TABLE {p}minos_pending DROP COLUMN own_subject');
        $board->acp('module=config-plugins&action=activate&plugin=minos');
        $column = $board->row('SELECT own_subject FROM {p}minos_pending');
        self::assertNotNull($column, 'the column is back');
        self::assertSame($rows, (int)$board->value('SELECT COUNT(*) FROM {p}minos_pending'), 'and the rows kept');
        self::$answers['activation_adds_a_missing_column'] = true;

        $confirm = $board->acp('module=config-plugins&action=deactivate&uninstall=1&plugin=minos');
        $board->admin()->post('admin/index.php?module=config-plugins&action=deactivate&uninstall=1&plugin=minos',
            ['my_post_key' => Browser::postKey($confirm), 'yes' => 'Yes']);
        $tables = $board->engine === 'pgsql'
            ? (int)$board->value("SELECT COUNT(*) FROM information_schema.tables WHERE table_name LIKE 'mybb_minos_%'")
            : (int)$board->value("SELECT COUNT(*) FROM information_schema.tables WHERE table_schema=DATABASE() AND table_name LIKE 'mybb\\_minos\\_%'");
        self::assertSame(0, $tables, '"Yes" drops them');
    }

    // ------------------------------------------------------------------ helpers

    /**
     * A moderator (the administrator) approves a reply in the ModCP's moderation queue.
     */
    private function approveInModcp(int $pid): void
    {
        $admin = self::$board->admin();
        $queue = $admin->get('modcp.php?action=modqueue&type=posts');
        $admin->post('modcp.php', ['action' => 'do_modqueue', 'posts' => [$pid => 'approve'],
            'my_post_key' => Browser::postKey($queue)]);
        self::assertSame(1, (int)self::$board->post($pid)['visible'], 'approved in the ModCP');
    }

    /**
     * @param array<string,int> $from
     * @param array<string,int> $to
     * @return array<string,int> Each counter's change.
     */
    private static function delta(array $from, array $to): array
    {
        $delta = [];
        foreach ($from as $name => $value) {
            $delta[$name] = $to[$name] - $value;
        }
        return $delta;
    }

    /**
     * The forum's, the thread's and the author's counters.
     *
     * @return array<string,int>
     */
    private function counters(): array
    {
        $board = self::$board;
        $forum = $board->row('SELECT posts, threads, unapprovedposts, unapprovedthreads FROM {p}forums WHERE fid=?', [Board::FID]);
        $thread = $board->row('SELECT replies, unapprovedposts FROM {p}threads WHERE tid=?', [self::$tid]);
        return [
            'forum_posts' => (int)$forum['posts'], 'forum_threads' => (int)$forum['threads'],
            'forum_unapproved_posts' => (int)$forum['unapprovedposts'], 'forum_unapproved_threads' => (int)$forum['unapprovedthreads'],
            'thread_replies' => (int)$thread['replies'], 'thread_unapproved' => (int)$thread['unapprovedposts'],
            'author_postnum' => (int)$board->value('SELECT postnum FROM {p}users WHERE uid=?', [self::$uids[self::AUTHOR]]),
        ];
    }

    /**
     * What a step queued for the subscribers: e-mails to the thread's and the forum's
     * e-mail subscriber, private messages to the PM subscriber. MyBB notifies a subscriber
     * once until their next visit (`lastactive` after the thread's or forum's last post), so
     * both are marked active first.
     *
     * @return array<string,int> `thread_mail`, `forum_mail`, `thread_pm`: what the step added.
     */
    private function notificationsFor(callable $step): array
    {
        $board = self::$board;
        $board->exec('UPDATE {p}users SET lastactive=? WHERE uid IN (?, ?)',
            [time() + 86400, self::$uids[self::MAIL_SUBSCRIBER], self::$uids[self::PM_SUBSCRIBER]]);
        $email = strtolower(self::MAIL_SUBSCRIBER) . '@example.com';
        // The board's language is English: MyBB's `emailsubject_subscription` ("New Reply
        // to …") and `emailsubject_forumsubscription` ("New Thread in …").
        $count = function () use ($board, $email): array {
            return [
                'thread_mail' => (int)$board->value("SELECT COUNT(*) FROM {p}mailqueue WHERE mailto=? AND subject LIKE 'New Reply to %'", [$email]),
                'forum_mail' => (int)$board->value("SELECT COUNT(*) FROM {p}mailqueue WHERE mailto=? AND subject LIKE 'New Thread in %'", [$email]),
                'thread_pm' => (int)$board->value('SELECT COUNT(*) FROM {p}privatemessages WHERE toid=? AND folder=1', [self::$uids[self::PM_SUBSCRIBER]]),
            ];
        };
        $before = $count();
        $step();
        $after = $count();
        return self::delta($before, $after);
    }

    /**
     * The text a reader sees of some HTML, and the hints (`alt`, `title`) beside it.
     *
     * @return array{text:string,hints:array<int,string>,media:int,has_text:bool}
     */
    private static function visible(string $html): array
    {
        $doc = new DOMDocument();
        $doc->loadHTML('<?xml encoding="UTF-8"><div id="minos-root">' . $html . '</div>', LIBXML_NOERROR | LIBXML_NOWARNING);
        $xpath = new DOMXPath($doc);
        $hidden = [];
        foreach ($xpath->query('//script|//style|//template|//noscript|//comment()') as $node) {
            $hidden[] = $node;
        }
        foreach ($hidden as $node) {
            $node->parentNode->removeChild($node);
        }
        $hints = [];
        foreach ($xpath->query('//@alt|//@title') as $attribute) {
            $hints[] = trim($attribute->nodeValue);
        }
        $root = $doc->getElementById('minos-root');
        $text = $root === null ? '' : trim((string)preg_replace('/\s+/u', ' ', (string)$root->textContent));
        return [
            'text' => $text,
            'hints' => array_values(array_filter($hints, 'strlen')),
            'media' => $xpath->query('//img|//iframe|//video|//object|//embed')->length,
            // White space, separators and format characters (a zero-width space, a soft
            // hyphen) take no visible place: a text of only those shows nothing.
            'has_text' => preg_replace('/[\s\p{Z}\p{Cf}\p{Cc}\p{M}\x{115F}\x{1160}\x{3164}\x{FFA0}\x{2800}]+/u', '', $text) !== '',
        ];
    }

    /**
     * What kind of page MyBB answered with: its error box, a redirect notice, or another page.
     */
    private static function pageKind(string $html): string
    {
        if (preg_match('#<td class="trow1">\s*(.*?)\s*</td>#s', $html, $m) && strpos($html, 'class="error"') !== false) {
            return 'error: ' . trim(strip_tags($m[1]));
        }
        if (preg_match('#<div class="error">(.*?)</div>#s', $html, $m) || preg_match('#<td class="trow1">(.*?)</td>#s', $html, $m)) {
            return 'message: ' . substr(trim((string)preg_replace('/\s+/u', ' ', strip_tags($m[1]))), 0, 200);
        }
        if (preg_match('#<title>(.*?)</title>#s', $html, $m)) {
            return 'page: ' . trim($m[1]);
        }
        return 'other';
    }

    private static function optionalHidden(string $html, string $name): string
    {
        try {
            return Browser::hidden($html, $name);
        } catch (\RuntimeException $e) {
            return '';
        }
    }

    private static function optionalPostKey(string $html): string
    {
        try {
            return Browser::postKey($html);
        } catch (\RuntimeException $e) {
            return '';
        }
    }

    /**
     * What the fixtures record about the run.
     *
     * @return array<string,mixed>
     */
    private static function environment(): array
    {
        $commit = getenv('MINOS_LIVE_MYBB_COMMIT');
        $schema = self::$answers['q7_schema'] ?? null;
        return [
            'mybb' => ['version' => Live::MYBB_VERSION, 'tag' => Live::MYBB_TAG, 'commit' => is_string($commit) ? $commit : ''],
            'php' => PHP_VERSION,
            'database' => ['engine' => self::$board->engine, 'version' => is_array($schema) ? (string)$schema['version'] : ''],
            'recorded' => gmdate('Y-m-d'),
            'source' => 'tests/Live/LiveForumTest.php through bin/verify-mybb-1841.sh',
        ];
    }
}
