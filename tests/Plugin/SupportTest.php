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

        // A repeat after a moderator closed the report: the row remembers the signal.
        $closed = $this->heldReply('Ostatnio wszystko mnie przerasta, drugi raz.');
        $this->forum->deliver(self::support($closed));
        $this->forum->db->update_query('reportedcontent', ['reportstatus' => 1], "id='" . $closed . "'");
        self::assertSame(200, $this->forum->deliver(self::support($closed)));
        self::assertSame([$pid, $closed], array_map('intval', array_column($this->forum->reports(), 'id')));
    }

    /**
     * A delivery that comes while another delivery of the same revision is being applied
     * (the row claimed, `applying`) raises nothing itself and is not acknowledged: the
     * gateway delivers it again, and the redelivery meets the outcome.
     */
    public function testADeliveryBesideOneBeingAppliedIsDeliveredAgain(): void
    {
        $pid = $this->heldReply('Dostarczony dwa razy naraz.');
        self::assertTrue($this->forum->platform()->claimPending($pid, TIME_NOW), 'the guard: the other delivery claimed it');

        $code = $this->forum->deliver(self::support($pid));

        self::assertGreaterThanOrEqual(500, $code, 'not acknowledged: the gateway delivers again');
        self::assertSame([], $this->forum->reports());
        self::assertSame(Status::APPLYING, $this->forum->row($pid)['status']);
    }

    /**
     * A repeated delivery that comes at any moment after the verdict's outcome is recorded —
     * here before the very next write of the delivery that applies it — raises nothing:
     * the outcome and the revision's signal are one write. The one report says the verdict
     * was applied, not that it came after a decision. On time, and late on a post the
     * failure mode published whose text changed past the edit hook (nothing applied).
     *
     * @dataProvider outcomes
     */
    public function testARepeatRightAfterTheOutcomeIsRecordedRaisesNothingMore(string $case): void
    {
        if ($case === 'late') {
            $this->forum->set(['minos_failure_mode' => 'fail-open']);
            $pid = $this->autoPublished('Powtórka tuż po zapisaniu późnego werdyktu.');
            $this->forum->db->update_query('posts', ['message' => 'Zmieniony obok edycji.'], "pid='" . $pid . "'");
        } else {
            $pid = $this->heldReply('Powtórka tuż po zapisaniu werdyktu.');
        }
        $forum = $this->forum;
        $claimed = false;
        $repeat = null;
        $watch = static function () use (&$watch, $forum, $pid, &$claimed, &$repeat): void {
            if ($forum->row($pid)['status'] === Status::APPLYING) {
                $claimed = true;
            } elseif ($claimed) {
                $repeat = $forum->deliver(self::support($pid)); // the first write after the outcome
                return;
            }
            $forum->db->beforeNextWrite('mybb_', $watch);
        };
        $this->forum->db->beforeNextWrite('mybb_', $watch);

        self::assertSame(200, $this->forum->deliver(self::support($pid)));

        self::assertSame(200, $repeat, 'the guard: the repeat came after the outcome, and was acknowledged');
        self::assertSame([1, Status::PUBLISHED, '1'], [(int)$this->forum->post($pid)['visible'], $this->forum->row($pid)['status'],
            (string)$this->forum->row($pid)['support_rev']]);
        $reports = $this->forum->reports();
        self::assertSame([$pid], array_map('intval', array_column($reports, 'id')));
        self::assertSame($this->forum->platform()->lang('minos_support_report', 'bezpieczne'), $reports[0]['reason'],
            'the verdict was applied: no note of a decision');
    }

    /**
     * A post the failure mode published stays open to a late verdict after a `bezpieczne`
     * on its cut text (still unassessed as a whole): a repeat of that verdict is claimed
     * again, and raises nothing more, even after a moderator closed the report.
     */
    public function testARepeatedLateVerdictOnACutTextRaisesTheSignalOnce(): void
    {
        $this->forum->set(['minos_failure_mode' => 'fail-open']);
        $pid = $this->autoPublished(str_repeat('a', 3000) . ' dalej niż wysłany początek');
        self::assertSame('1', (string)$this->forum->row($pid)['truncated'], 'the guard: its text was cut');

        self::assertSame(200, $this->forum->deliver(self::support($pid)));
        $this->forum->db->update_query('reportedcontent', ['reportstatus' => 1], "id='" . $pid . "'");
        self::assertSame(['1', '1'], [(string)$this->forum->row($pid)['auto_published'], (string)$this->forum->row($pid)['support_rev']],
            'the guard: still open to a late verdict, its signal recorded');
        self::assertSame(200, $this->forum->deliver(self::support($pid)));

        self::assertSame([$pid], array_map('intval', array_column($this->forum->reports(), 'id')));
        self::assertSame([1, Status::PUBLISHED], [(int)$this->forum->post($pid)['visible'], $this->forum->row($pid)['status']]);
    }

    /**
     * @return array<string,array{0:string}>
     */
    public function outcomes(): array
    {
        return ['on time' => ['on time'], 'late, text changed' => ['late']];
    }

    /**
     * A verdict with `wsparcie` that comes while the task times its post out (the row
     * claimed by the task) is not acknowledged; delivered again after the task's step, it
     * is applied as a late verdict — under fail-open and under fail-closed — and its signal
     * reaches the moderators.
     *
     * @dataProvider failureModes
     */
    public function testASignalThatComesWhileTheTaskTimesThePostOutReachesTheModerators(string $mode, int $visible): void
    {
        $this->forum->set(['minos_failure_mode' => $mode]);
        $pid = $this->heldReply('Czeka, a zadanie właśnie kończy czas oczekiwania.');
        $this->forum->platform()->updatePending($pid, ['accepted_at' => TIME_NOW - 21 * 60]);
        $forum = $this->forum;
        $during = null;
        $this->forum->db->beforeNextWrite("verdict='" . Status::REASON_TIMEOUT . "'",
            static function () use ($forum, $pid, &$during): void {
                $during = [$forum->row($pid)['status'], $forum->deliver(self::support($pid, ['kwalifikacja' => 'zablokowane'])),
                    count($forum->reports())];
            });

        $this->forum->plugin->task->run(TIME_NOW);

        self::assertNotNull($during, 'the guard: the delivery came during the task\'s step');
        self::assertSame(Status::APPLYING, $during[0], 'the guard: the task held the row');
        self::assertGreaterThanOrEqual(500, $during[1], 'not acknowledged while the task applied the row');
        self::assertSame(0, $during[2]);
        self::assertSame(Status::REASON_TIMEOUT, $this->forum->row($pid)['verdict'], 'the task\'s step was applied');

        self::assertSame(200, $this->forum->deliver(self::support($pid, ['kwalifikacja' => 'zablokowane'])));

        $reports = $this->forum->reports();
        self::assertSame([$pid], array_map('intval', array_column($reports, 'id')));
        self::assertSame($this->forum->platform()->lang('minos_support_report', 'zablokowane'), $reports[0]['reason'],
            'the verdict was applied, late');
        self::assertSame([$visible, 'zablokowane'], [(int)$this->forum->post($pid)['visible'], $this->forum->row($pid)['verdict']]);
        self::assertSame(200, $this->forum->deliver(self::support($pid, ['kwalifikacja' => 'zablokowane'])));
        self::assertCount(1, $this->forum->reports(), 'once');
    }

    /**
     * @return array<string,array{0:string,1:int}> The failure mode, and the post's
     *     visibility after the late `zablokowane` with `wsparcie` (never hidden: D9.9).
     */
    public function failureModes(): array
    {
        return ['fail-open' => ['fail-open', 1], 'fail-closed' => ['fail-closed', 0]];
    }

    /**
     * A verdict with `wsparcie` that comes while a moderator's edit holds the row is not
     * acknowledged; delivered again after the edit, it raises the report with the note that
     * the verdict came after the moderator's decision, and changes nothing else.
     */
    public function testASignalThatComesDuringAModeratorsEditReachesTheModerators(): void
    {
        $pid = $this->heldReply('Moderator właśnie poprawia ten post.');
        $GLOBALS['minos_test']['moderators'] = [self::UID];
        $forum = $this->forum;
        $during = null;
        $this->forum->db->beforeNextWrite("verdict='" . Status::REASON_EDITED . "'",
            static function () use ($forum, $pid, &$during): void {
                $during = [$forum->row($pid)['status'], $forum->deliver(self::support($pid)), count($forum->reports())];
            });

        $this->forum->edit($pid, 'Wersja moderatora.');

        self::assertNotNull($during, 'the guard: the delivery came during the edit');
        self::assertSame(Status::APPLYING, $during[0], 'the guard: the edit held the row');
        self::assertGreaterThanOrEqual(500, $during[1], 'not acknowledged while the edit applied the row');
        self::assertSame(0, $during[2]);
        self::assertSame(Status::SUPERSEDED, $this->forum->row($pid)['status'], 'the edit was applied');

        self::assertSame(200, $this->forum->deliver(self::support($pid)));

        $reports = $this->forum->reports();
        self::assertSame([$pid], array_map('intval', array_column($reports, 'id')));
        self::assertSame($this->forum->platform()->lang('minos_support_report_after_decision', 'bezpieczne'), $reports[0]['reason']);
        self::assertSame([0, Status::SUPERSEDED], [(int)$this->forum->post($pid)['visible'], $this->forum->row($pid)['status']]);
        self::assertSame([], self::moderation(), 'the moderator\'s decision stands');
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
        self::assertSame($this->forum->platform()->lang('minos_support_report_after_decision', 'bezpieczne'),
            $this->forum->reports()[1]['reason'], 'the decided post\'s report notes the decision');
    }

    /**
     * A report of a verdict this delivery applies that cannot be written (the report centre
     * refuses the insert): the verdict stands, the error is logged by step and post id —
     * never its text — and the delivery is not acknowledged (`500`), because nothing else
     * would raise the signal. The redelivery finds the outcome recorded and raises the
     * report, once, without the note of a person's decision. On time, late after a fail-open
     * publication, and late after a fail-closed hold for the timeout.
     *
     * @dataProvider appliedVerdicts
     */
    public function testAReportOfAnAppliedVerdictThatFailsIsRaisedByTheRedelivery(string $case): void
    {
        $verdict = 'bezpieczne';
        if ($case === 'on time') {
            $pid = $this->heldReply('Zgłoszenie z błędem, na czas.');
            $expected = [1, Status::PUBLISHED];
        } elseif ($case === 'after a fail-open publication') {
            $this->forum->set(['minos_failure_mode' => 'fail-open']);
            $pid = $this->autoPublished('Zgłoszenie z błędem, po publikacji bez oceny.');
            $verdict = 'zablokowane';
            $expected = [1, Status::PUBLISHED];
        } else {
            $pid = $this->heldReply('Zgłoszenie z błędem, po czasie oczekiwania.');
            $this->forum->platform()->updatePending($pid, ['accepted_at' => TIME_NOW - 21 * 60]);
            task_minos(['tid' => 1]);
            self::assertSame([Status::HELD, Status::REASON_TIMEOUT], [$this->forum->row($pid)['status'], $this->forum->row($pid)['verdict']],
                'the guard: held for its timeout');
            $verdict = 'zablokowane';
            $expected = [0, Status::HELD];
        }
        $payload = self::support($pid, ['kwalifikacja' => $verdict]);
        $this->forum->pdo->exec('CREATE TRIGGER minos_test_no_report BEFORE INSERT ON mybb_reportedcontent'
            . " BEGIN SELECT RAISE(ABORT, '" . \Moderation::SECRET_TEXT . "'); END");

        self::assertSame(500, $this->forum->deliver($payload), 'not acknowledged: the gateway delivers again');

        $state = function () use ($pid): array {
            return [(int)$this->forum->post($pid)['visible'], $this->forum->row($pid)['status']];
        };
        self::assertSame($expected, $state(), 'the verdict stands');
        self::assertSame($verdict, $this->forum->row($pid)['verdict']);
        self::assertSame([], $this->forum->reports());
        self::assertSame([['support', $pid]], array_map(static function (array $entry): array {
            return [$entry['code'], (int)$entry['pid']];
        }, array_values(array_filter($this->logEntries(), static function (array $entry): bool {
            return $entry['event'] === 'error';
        }))), 'logged as the signal\'s failure, not as a failed verdict');
        self::assertSame('0', (string)$this->forum->row($pid)['apply_errors']);
        self::assertStringNotContainsString(\Moderation::SECRET_TEXT, (string)json_encode($this->logEntries()));

        $this->forum->pdo->exec('DROP TRIGGER minos_test_no_report');
        self::assertSame(200, $this->forum->deliver($payload));
        self::assertSame(200, $this->forum->deliver($payload), 'a repeat');

        self::assertSame($expected, $state(), 'applied once');
        $reports = $this->forum->reports();
        self::assertSame([$pid], array_map('intval', array_column($reports, 'id')));
        self::assertSame($this->forum->platform()->lang('minos_support_report', $verdict), $reports[0]['reason'],
            'no person decided: the plain note');
    }

    /**
     * @return array<string,array{0:string}>
     */
    public function appliedVerdicts(): array
    {
        return [
            'on time'                       => ['on time'],
            'after a fail-open publication' => ['after a fail-open publication'],
            'after a timeout hold'          => ['after a timeout hold'],
        ];
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
     * A7, also after a person's decision (the owner's decision of 2026-11-01): a reply the
     * failure mode held for its timeout, which a moderator then approved in the ModCP queue,
     * gets a late `zablokowane` with `wsparcie` — the moderators get one report, noting that
     * the verdict came after the decision, and the post stays as the moderator left it
     * (published, not deleted even with "usuń (miękko)"). A repeated delivery raises
     * nothing more, even once a moderator closed the report; the same verdict without
     * `wsparcie` raises nothing.
     */
    public function testSupportAfterAModeratorApprovedTheHeldPostIsReportedOnce(): void
    {
        $this->forum->set(['minos_blocked' => 'soft-delete']);
        $supported = $this->heldReply('Zatwierdzony po czasie, z sygnałem.');
        $plain = $this->heldReply('Zatwierdzony po czasie, bez sygnału.');
        foreach ([$supported, $plain] as $pid) {
            $this->forum->platform()->updatePending($pid, ['accepted_at' => TIME_NOW - 21 * 60]);
        }
        task_minos(['tid' => 1]);
        (new \Moderation())->approve_posts([$supported, $plain]); // the ModCP's queue
        $GLOBALS['minos_test']['moderation'] = [];
        $before = [$this->forum->row($supported)['status'], $this->forum->row($supported)['verdict']];
        self::assertSame([Status::HELD, Status::REASON_TIMEOUT], $before, 'the guard: held for its timeout');

        self::assertSame(200, $this->forum->deliver(self::support($supported, ['kwalifikacja' => 'zablokowane'])));
        self::assertSame(200, $this->forum->deliver(self::payload($plain, ['kwalifikacja' => 'zablokowane'])));

        $reports = $this->forum->reports();
        self::assertSame([$supported], array_map('intval', array_column($reports, 'id')));
        self::assertSame($this->forum->platform()->lang('minos_support_report_after_decision', 'zablokowane'),
            $reports[0]['reason'], 'the note says the verdict came after the decision');
        foreach ([$supported, $plain] as $pid) {
            self::assertSame([1, Status::HELD, Status::REASON_TIMEOUT], [(int)$this->forum->post($pid)['visible'],
                $this->forum->row($pid)['status'], $this->forum->row($pid)['verdict']], "post {$pid} as the moderator left it");
        }
        self::assertSame([], self::moderation(), 'nothing unapproved or deleted');
        self::assertSame(['1', '0'], [(string)$this->forum->row($supported)['support'], (string)$this->forum->row($plain)['support']],
            'listed with the ACP\'s support posts');
        self::assertSame([[], []], [$this->forum->pms(), $this->forum->mails()], 'nothing to the author');

        $this->forum->db->update_query('reportedcontent', ['reportstatus' => 1], "id='" . $supported . "'");
        self::assertSame(200, $this->forum->deliver(self::support($supported, ['kwalifikacja' => 'zablokowane'])), 'a repeat');
        self::assertCount(1, $this->forum->reports(), 'a repeated delivery raises nothing, the report closed or not');
    }

    /**
     * A report after a person's decision that cannot be written (the report centre refuses
     * the insert) is logged by step and post id, and not acknowledged: the redelivery
     * raises it, once. After a moderator's approval of a post held for its timeout, and on
     * a revision its author has replaced.
     *
     * @dataProvider decisions
     */
    public function testAReportAfterADecisionThatFailsIsRaisedByTheRedelivery(string $decision): void
    {
        $pid = $this->heldReply('Zgłoszenie po decyzji, które się nie zapisuje.');
        $payload = self::support($pid, ['kwalifikacja' => 'zablokowane']);
        if ($decision === 'approved') {
            $this->forum->platform()->updatePending($pid, ['accepted_at' => TIME_NOW - 21 * 60]);
            task_minos(['tid' => 1]);
            (new \Moderation())->approve_posts([$pid]); // the ModCP's queue
        } else {
            $this->forum->edit($pid, 'Druga wersja, inna.');
            task_minos(['tid' => 1]);
            self::assertSame('1', (string)$this->forum->row($pid)['rev'], 'the guard: a new revision');
        }
        $this->forum->pdo->exec('CREATE TRIGGER minos_test_no_report BEFORE INSERT ON mybb_reportedcontent'
            . " BEGIN SELECT RAISE(ABORT, '" . \Moderation::SECRET_TEXT . "'); END");

        $code = $this->forum->deliver($payload);

        self::assertGreaterThanOrEqual(500, $code, 'not acknowledged: the gateway delivers again');
        self::assertSame([], $this->forum->reports());
        self::assertSame([['support', $pid]], array_map(static function (array $entry): array {
            return [$entry['code'], (int)$entry['pid']];
        }, array_values(array_filter($this->logEntries(), static function (array $entry): bool {
            return $entry['event'] === 'error';
        }))));
        self::assertStringNotContainsString(\Moderation::SECRET_TEXT, (string)json_encode($this->logEntries()));

        $this->forum->pdo->exec('DROP TRIGGER minos_test_no_report');
        self::assertSame(200, $this->forum->deliver($payload));
        self::assertSame(200, $this->forum->deliver($payload), 'a repeat');

        $reports = $this->forum->reports();
        self::assertSame([$pid], array_map('intval', array_column($reports, 'id')));
        self::assertSame($this->forum->platform()->lang('minos_support_report_after_decision', 'zablokowane'), $reports[0]['reason']);
    }

    /**
     * @return array<string,array{0:string}>
     */
    public function decisions(): array
    {
        return ['a moderator approved it' => ['approved'], 'its author edited it' => ['edited']];
    }

    /**
     * A7 while the post waited for its verdict: a moderator approved it, soft-deleted it or
     * edited it in the queue before the verdict came. Each gets the report noting the
     * decision; each stays visible, deleted or held as the moderator left it.
     */
    public function testSupportAfterAModeratorsDecisionWhileThePostWaitedIsReported(): void
    {
        $approved = $this->heldReply('Zatwierdzony, zanim przyszedł werdykt.');
        $deleted = $this->heldReply('Usunięty miękko, zanim przyszedł werdykt.');
        $edited = $this->heldReply('Poprawiony przez moderatora.');
        (new \Moderation())->approve_posts([$approved]);
        (new \Moderation())->soft_delete_posts([$deleted]);
        $GLOBALS['minos_test']['moderators'] = [self::UID];
        $this->forum->edit($edited, 'Poprawiony przez moderatora, inną treścią.');
        $GLOBALS['minos_test']['moderators'] = [];
        $GLOBALS['minos_test']['moderation'] = [];
        self::assertSame([Status::SUPERSEDED, Status::REASON_EDITED],
            [$this->forum->row($edited)['status'], $this->forum->row($edited)['verdict']], 'the guard: the edit decided it');

        foreach ([$approved, $deleted, $edited] as $pid) {
            self::assertSame(200, $this->forum->deliver(self::support($pid, ['kwalifikacja' => 'zablokowane'])));
            self::assertSame(200, $this->forum->deliver(self::support($pid, ['kwalifikacja' => 'zablokowane'])), 'a repeat');
        }

        self::assertSame([$approved, $deleted, $edited], array_map('intval', array_column($this->forum->reports(), 'id')));
        foreach ($this->forum->reports() as $report) {
            self::assertSame($this->forum->platform()->lang('minos_support_report_after_decision', 'zablokowane'), $report['reason']);
        }
        self::assertSame([1, -1, 0], [(int)$this->forum->post($approved)['visible'], (int)$this->forum->post($deleted)['visible'],
            (int)$this->forum->post($edited)['visible']]);
        self::assertSame([], self::moderation());
    }

    /**
     * A7 on an older revision (S3): its author edited the waiting post, so the verdict on
     * the first text is not applied — but its `wsparcie` is reported, once, and the post
     * keeps waiting for the verdict on its new text, which then applies as usual; the mark
     * stays on the ACP's support list.
     */
    public function testSupportOnARevisionTheAuthorReplacedIsReported(): void
    {
        $pid = $this->heldReply('Pierwsza wersja z prośbą o pomoc.');
        $this->forum->edit($pid, 'Druga wersja, inną treścią.');
        task_minos(['tid' => 1]);
        self::assertSame([Status::PENDING, '1'], [$this->forum->row($pid)['status'], (string)$this->forum->row($pid)['rev']],
            'the guard: revision 1 waits');

        self::assertSame(200, $this->forum->deliver(self::support($pid, ['kwalifikacja' => 'zablokowane'])));
        self::assertSame(200, $this->forum->deliver(self::support($pid, ['kwalifikacja' => 'zablokowane'])), 'a repeat');
        self::assertSame([0, Status::PENDING], [(int)$this->forum->post($pid)['visible'], $this->forum->row($pid)['status']],
            'still waiting for the verdict on the new text');
        self::assertCount(1, $this->forum->reports());

        self::assertSame(200, $this->forum->deliver(self::payload($pid, ['id' => 'mybb:' . $pid . '.1'])));
        self::assertSame([1, Status::PUBLISHED, '1'], [(int)$this->forum->post($pid)['visible'], $this->forum->row($pid)['status'],
            (string)$this->forum->row($pid)['support']]);
        self::assertCount(1, $this->forum->reports());
    }

    /**
     * A7 on a post the failure mode published (fail-open) and its author edited since: the
     * late `zablokowane` judged a text that is gone and is not applied — the post stays
     * published — but its `wsparcie` is reported; without it nothing happens.
     */
    public function testSupportOnAPublishedPostEditedSinceIsReported(): void
    {
        $this->forum->set(['minos_failure_mode' => 'fail-open']);
        $supported = $this->autoPublished('Opublikowany bez oceny, potem poprawiony.');
        $plain = $this->autoPublished('Opublikowany bez oceny, poprawiony, bez sygnału.');
        $this->forum->edit($supported, 'Poprawiona treść.');
        $this->forum->edit($plain, 'Poprawiona treść bez sygnału.');
        $GLOBALS['minos_test']['moderation'] = [];

        $this->forum->deliver(self::support($supported, ['kwalifikacja' => 'zablokowane']));
        $this->forum->deliver(self::payload($plain, ['kwalifikacja' => 'zablokowane']));

        self::assertSame([$supported], array_map('intval', array_column($this->forum->reports(), 'id')));
        self::assertSame([1, 1], [(int)$this->forum->post($supported)['visible'], (int)$this->forum->post($plain)['visible']]);
        self::assertSame([], self::moderation(), 'nothing sent back to the queue');
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
