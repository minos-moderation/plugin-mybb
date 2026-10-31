<?php

declare(strict_types=1);

namespace Minos\MyBB\Tests\Plugin;

use Minos\MyBB\Status;
use Minos\MyBB\Tests\Support\Forum;
use Minos\MyBB\Tests\Support\PageEnded;
use Minos\MyBB\Tests\Support\PluginTestCase;

/**
 * Shared rule D9.9 (A7 of client-php's `docs/plugin-behaviour.md`): `wsparcie` gives the
 * forum's moderators an active signal in their own queue, whatever the verdict — here a
 * report in MyBB's report centre (ModCP → reported content), announced by MyBB's header.
 * A post with `wsparcie` is never deleted, rejected or hidden automatically: it stays held,
 * or stays published, until a person decides. Nothing goes to its author. Test data only.
 */
final class SupportTest extends PluginTestCase
{
    /**
     * `bezpieczne` + `wsparcie`: the post is published and exactly one report waits for the
     * moderators — the post, its thread and forum, the board's "Other" reason with the
     * plugin's comment, unread; no private message to anyone.
     */
    public function testASafePostWithSupportIsPublishedAndReportedToTheModerators(): void
    {
        $pid = $this->heldReply('Ostatnio wszystko mnie przerasta.');
        $post = $this->forum->post($pid);

        self::assertSame(200, $this->forum->deliver(self::support($pid)));

        self::assertSame([1, Status::PUBLISHED, '1'], [(int)$this->forum->post($pid)['visible'],
            $this->forum->row($pid)['status'], (string)$this->forum->row($pid)['support']]);
        $reports = $this->forum->reports();
        self::assertCount(1, $reports);
        self::assertSame([$pid, (int)$post['tid'], Forum::FID, 0, 0, 1, 'post', 1], [(int)$reports[0]['id'], (int)$reports[0]['id2'],
            (int)$reports[0]['id3'], (int)$reports[0]['uid'], (int)$reports[0]['reportstatus'], (int)$reports[0]['reasonid'],
            $reports[0]['type'], (int)$reports[0]['reports']]);
        self::assertStringContainsString('wsparcie', $reports[0]['reason']);
        self::assertStringContainsString('bezpieczne', $reports[0]['reason']);
        self::assertSame([0], unserialize($reports[0]['reporters']));
        self::assertSame(1, $this->forum->cache->read('reportedcontent')['unread'], 'MyBB\'s header notice reads it');
        self::assertSame([[], []], [$this->forum->pms(), $this->forum->mails()], 'nothing to the author, nor to anyone');

        self::assertSame(200, $this->forum->deliver(self::support($pid)), 'a repeated delivery');
        self::assertCount(1, $this->forum->reports(), 'reports nothing twice');
    }

    /**
     * The ModCP's report list (`modcp.php`, `action=reports`) fills the "last report" cell
     * only for a reporter with an id, and its template reads it anyway: for the plugin's own
     * report (uid 0) the `modcp_reports_report` hook fills it, escaped. A user's report, and
     * a uid-0 report on a post without `wsparcie`, are left to MyBB.
     */
    public function testTheReportListGetsALastReporterForThePluginsReport(): void
    {
        $pid = $this->heldReply('Potrzebuję pomocy.');
        $this->forum->deliver(self::support($pid));
        $plain = $this->heldReply('Zwykły post.');
        $this->forum->deliver(self::payload($plain));
        $report = $this->forum->reports()[0];

        // As modcp.php has them before the template: no `lastreporter` for uid 0.
        $GLOBALS['report'] = $report + ['lastreporter' => 0];
        $GLOBALS['report_data'] = ['content' => 'x', 'comment' => 'y', 'reports' => '1'];
        $GLOBALS['plugins']->run_hooks('modcp_reports_report');
        self::assertStringContainsString('Minos', $GLOBALS['report_data']['lastreporter']);
        self::assertStringContainsString(date('Y-m-d H:i', (int)$report['lastreport']), $GLOBALS['report_data']['lastreporter']);

        foreach ([['uid' => 5] + $report, ['id' => $plain] + $report] as $other) {
            $GLOBALS['report'] = $other;
            $GLOBALS['report_data'] = ['content' => 'x'];
            $GLOBALS['plugins']->run_hooks('modcp_reports_report');
            self::assertArrayNotHasKey('lastreporter', $GLOBALS['report_data'], 'MyBB\'s own report');
        }
        $GLOBALS['report'] = $report;
        $GLOBALS['report_data'] = ['lastreporter' => 'MyBB'];
        $GLOBALS['plugins']->run_hooks('modcp_reports_report');
        self::assertSame('MyBB', $GLOBALS['report_data']['lastreporter'], 'a cell MyBB filled is kept');
        unset($GLOBALS['report'], $GLOBALS['report_data']);
    }

    /**
     * The report says nothing of the post, the key, the secret or the delivery: its comment
     * is the plugin's text with the verdict.
     */
    public function testTheReportCarriesNoContentKeyOrSecret(): void
    {
        $pid = $this->heldReply('Treść, która nie trafia do zgłoszenia.');
        $this->forum->deliver(self::support($pid, ['kwalifikacja' => 'zablokowane']));
        $reason = $this->forum->reports()[0]['reason'];
        foreach (['Treść, która', Forum::KEY, Forum::SECRET, '3f0c9a41d2b7e8c5', 'mybb:' . $pid] as $secret) {
            self::assertStringNotContainsString($secret, $reason);
        }
        self::assertLessThanOrEqual(250, mb_strlen($reason), 'MyBB\'s column');
    }

    /**
     * `zablokowane` + `wsparcie` with the setting "usuń (miękko)": held, never deleted.
     */
    public function testABlockedPostWithSupportIsHeldNotDeleted(): void
    {
        $this->forum->set(['minos_blocked' => 'soft-delete']);
        $supported = $this->heldReply('Zablokowany, ale autor potrzebuje pomocy.');
        $plain = $this->heldReply('Zablokowany bez sygnału.');

        $this->forum->deliver(self::support($supported, ['kwalifikacja' => 'zablokowane']));
        $this->forum->deliver(self::payload($plain, ['kwalifikacja' => 'zablokowane']));

        self::assertSame([0, Status::HELD, 'zablokowane'], [(int)$this->forum->post($supported)['visible'],
            $this->forum->row($supported)['status'], $this->forum->row($supported)['verdict']]);
        self::assertSame([['soft_delete_posts', [$plain]]], self::moderation(), 'only the post without the signal is deleted');
        self::assertSame([$supported], array_map('intval', array_column($this->forum->reports(), 'id')));
    }

    /**
     * A late verdict on a post the failure mode published (fail-open): with `wsparcie`, a
     * `zablokowane` — or an `ocenzurowane` whose mask cannot be published — leaves it
     * published; the moderators get the report. Without the signal it goes back to the queue.
     */
    public function testALateBlockingVerdictWithSupportLeavesThePostPublished(): void
    {
        $this->forum->set(['minos_failure_mode' => 'fail-open']);
        $blocked = $this->autoPublished('Opublikowany bez oceny.');
        $censored = $this->autoPublished('[b]abc[/b] zły');
        $plain = $this->autoPublished('Bez sygnału.');
        $GLOBALS['minos_test']['moderation'] = [];

        $this->forum->deliver(self::support($blocked, ['kwalifikacja' => 'zablokowane']));
        $this->forum->deliver(self::support($censored, ['kwalifikacja' => 'ocenzurowane', 'ocenzurowany' => 'abc ███']));
        $this->forum->deliver(self::payload($plain, ['kwalifikacja' => 'zablokowane']));

        foreach ([$blocked => 'zablokowane', $censored => 'ocenzurowane'] as $pid => $verdict) {
            self::assertSame([1, Status::PUBLISHED, $verdict, '0'], [(int)$this->forum->post($pid)['visible'],
                $this->forum->row($pid)['status'], $this->forum->row($pid)['verdict'], (string)$this->forum->row($pid)['auto_published']],
                "post {$pid} stays published until a person decides");
        }
        self::assertSame([['unapprove_posts', [$plain]]], self::moderation());
        self::assertSame([$blocked, $censored], array_map('intval', array_column($this->forum->reports(), 'id')));
    }

    /**
     * A late verdict for a post held after its timeout (D9.10) follows the same rule: with
     * `wsparcie`, `zablokowane` keeps it held even with "usuń (miękko)".
     */
    public function testALateVerdictAfterATimeoutWithSupportKeepsThePostHeld(): void
    {
        $this->forum->set(['minos_blocked' => 'soft-delete']);
        $pid = $this->heldReply('Po czasie oczekiwania.');
        $this->forum->platform()->updatePending($pid, ['accepted_at' => TIME_NOW - 21 * 60]);
        task_minos(['tid' => 1]);

        $this->forum->deliver(self::support($pid, ['kwalifikacja' => 'zablokowane']));

        self::assertSame([0, Status::HELD, 'zablokowane'], [(int)$this->forum->post($pid)['visible'],
            $this->forum->row($pid)['status'], $this->forum->row($pid)['verdict']]);
        self::assertSame([], self::moderation());
        self::assertCount(1, $this->forum->reports());
    }

    /**
     * Whatever the verdict: a censored post kept in the queue is reported too; a post a
     * moderator already decided keeps the decision and is reported; a post that is gone is
     * not. (`nieocenione` carries no `wsparcie`: the client library reads none from a payload
     * without a verdict.)
     */
    public function testTheSignalIsRaisedWhateverTheVerdict(): void
    {
        $none = $this->heldReply('[b]abc[/b] zły');
        $decided = $this->heldReply('Zatwierdzony przez moderatora.');
        $this->forum->db->update_query('posts', ['visible' => 1], "pid='" . $decided . "'");
        $gone = $this->heldReply('Usunięty.');
        $this->forum->db->delete_query('posts', "pid='" . $gone . "'");

        $this->forum->deliver(self::support($none, ['kwalifikacja' => 'ocenzurowane', 'ocenzurowany' => 'abc ███']));
        $this->forum->deliver(self::support($decided));
        $this->forum->deliver(self::support($gone));

        self::assertSame([Status::HELD, 'ocenzurowane'], [$this->forum->row($none)['status'], $this->forum->row($none)['verdict']]);
        self::assertSame(Status::SUPERSEDED, $this->forum->row($decided)['status']);
        self::assertSame([$none, $decided], array_map('intval', array_column($this->forum->reports(), 'id')));
        self::assertStringContainsString('ocenzurowane', $this->forum->reports()[0]['reason']);
    }

    /**
     * A report that cannot be written is logged by step and post id — never the error's
     * text — and the verdict stands.
     */
    public function testAReportThatFailsLeavesTheVerdictApplied(): void
    {
        $GLOBALS['minos_test']['report_throws'] = true;
        $pid = $this->heldReply('Zgłoszenie z błędem.');

        self::assertSame(200, $this->forum->deliver(self::support($pid)));

        self::assertSame([1, Status::PUBLISHED], [(int)$this->forum->post($pid)['visible'], $this->forum->row($pid)['status']]);
        $errors = array_values(array_filter($this->logEntries(), static function (array $entry): bool {
            return $entry['event'] === 'error';
        }));
        self::assertSame([['support', $pid]], array_map(static function (array $entry): array {
            return [$entry['code'], (int)$entry['pid']];
        }, $errors));
        self::assertStringNotContainsString(\Moderation::SECRET_TEXT, (string)json_encode($this->logEntries()));
    }

    /**
     * The ACP page lists `wsparcie` posts on a list of their own, which fifty newer posts
     * needing a look do not push out.
     */
    public function testTheACPListsSupportOnItsOwnList(): void
    {
        $supported = $this->heldReply('Starszy post z sygnałem.');
        $this->forum->deliver(self::support($supported));
        $this->forum->platform()->updatePending($supported, ['submitted_at' => TIME_NOW - 3600]);
        for ($i = 0; $i < 55; $i++) {
            $this->heldReply('Czeka ' . $i . '.');
        }
        $this->forum->page->active_module = 'tools';
        $this->forum->page->active_action = 'minos';
        ob_start();
        try {
            minos_hook_admin_load();
            self::fail('the page ends the request');
        } catch (PageEnded $ended) {
            $html = (string)ob_get_clean();
        }
        [$attention, $support] = explode('Posty z sygnałem wsparcia</div>', $html, 2) + [1 => ''];
        self::assertSame(50, substr_count($attention, 'W kolejce moderacji') + substr_count($attention, 'Czeka na werdykt'),
            'the guard: the list of posts needing a look is full');
        self::assertStringNotContainsString('>#' . $supported . '</a>', $attention);
        self::assertStringContainsString('>#' . $supported . '</a>', explode('<div class="title">Dziennik', $support)[0]);
    }

    /**
     * @param array<string,mixed> $fields Fields over a `bezpieczne` verdict with `wsparcie`.
     * @return array<string,mixed>
     */
    private static function support(int $pid, array $fields = []): array
    {
        return self::payload($pid, $fields + ['kategorie' => ['samookaleczenie'], 'wsparcie' => true]);
    }

    private function autoPublished(string $message): int
    {
        $pid = $this->heldReply($message);
        $this->forum->platform()->updatePending($pid, ['accepted_at' => TIME_NOW - 21 * 60]);
        $this->forum->plugin->task->run(TIME_NOW);
        self::assertSame([1, '1'], [(int)$this->forum->post($pid)['visible'], (string)$this->forum->row($pid)['auto_published']]);
        return $pid;
    }
}
