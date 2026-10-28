<?php

declare(strict_types=1);

namespace Minos\MyBB\Tests\Plugin;

use Minos\MyBB\Status;
use Minos\MyBB\Tests\Support\PluginTestCase;
use Minos\MyBB\Tests\Support\RecordingTransport;

/**
 * The task, run as MyBB runs it: `task_minos($task)` from `inc/tasks/minos.php`.
 */
final class TaskTest extends PluginTestCase
{
    public function testAPostWithoutAVerdictInTimeGetsTheFailureMode(): void
    {
        $late = $this->heldReply();
        $recent = $this->heldReply();
        $this->age($late, 21 * 60);
        $this->age($recent, 19 * 60);

        task_minos(['tid' => 1]);

        self::assertSame([Status::HELD, Status::REASON_TIMEOUT], [$this->forum->row($late)['status'], $this->forum->row($late)['verdict']]);
        self::assertSame(Status::PENDING, $this->forum->row($recent)['status'], 'the timeout is 20 minutes by default');
        self::assertSame(['timeout'], array_column($this->logEntries(), 'event'));
        self::assertCount(1, $GLOBALS['minos_test']['task_log']);
    }

    public function testFailOpenPublishesAPostWithoutAVerdict(): void
    {
        $this->forum->set(['minos_failure_mode' => 'fail-open', 'minos_timeout' => '30']);
        $late = $this->heldReply();
        $notYet = $this->heldReply();
        $this->age($late, 31 * 60);
        $this->age($notYet, 21 * 60);

        task_minos(['tid' => 1]);

        self::assertSame(1, (int)$this->forum->post($late)['visible']);
        self::assertSame(0, (int)$this->forum->post($notYet)['visible'], 'the administrator\'s timeout, not the default');
    }

    public function testADueRetryIsSentAgainAndAcceptedOnce(): void
    {
        $this->forum->plugin(new RecordingTransport([
            RecordingTransport::refused(429, 'kolejka_pelna', 60),
            RecordingTransport::refused(429, 'kolejka_pelna', 60),
            RecordingTransport::accepted(),
        ]));
        $pid = $this->heldReply('Tekst do ponowienia.');
        $notDue = $this->heldReply();
        $this->forum->platform()->updatePending($pid, ['retry_at' => TIME_NOW - 1]);

        task_minos(['tid' => 1]);

        self::assertCount(3, $this->forum->transport->requests);
        $item = $this->forum->transport->lastBody()['elementy'][0];
        self::assertSame(['mybb:' . $pid, 'Tekst do ponowienia.'], [$item['id'], $item['tekst']]);
        self::assertSame([Status::PENDING, '2'], [$this->forum->row($pid)['status'], (string)$this->forum->row($pid)['attempts']]);
        self::assertSame(Status::RETRY, $this->forum->row($notDue)['status']);
    }

    public function testRetriesGoInBatchesOfTwenty(): void
    {
        $this->forum->plugin(new RecordingTransport([RecordingTransport::refused(503, 'kolejka_niedostepna')]));
        $pids = [];
        for ($i = 0; $i < 25; $i++) {
            $pids[] = $this->heldReply('Post ' . $i);
        }
        foreach ($pids as $pid) {
            $this->forum->platform()->updatePending($pid, ['retry_at' => TIME_NOW - 1]);
        }
        $this->forum->plugin(new RecordingTransport());

        task_minos(['tid' => 1]);

        self::assertSame([20, 5], array_map(static function (array $request): int {
            return count(json_decode($request['body'], true)['elementy']);
        }, $this->forum->transport->requests));
        foreach ($pids as $pid) {
            self::assertSame(Status::PENDING, $this->forum->row($pid)['status']);
        }
    }

    public function testARefusedItemInABatchIsTheOnlyOneFailed(): void
    {
        $this->forum->plugin(new RecordingTransport([RecordingTransport::refused(503, 'kolejka_niedostepna')]));
        $first = $this->heldReply();
        $second = $this->heldReply();
        foreach ([$first, $second] as $pid) {
            $this->forum->platform()->updatePending($pid, ['retry_at' => TIME_NOW - 1]);
        }
        $this->forum->plugin(new RecordingTransport([RecordingTransport::refused(413, 'limit_dlugosci', null, 1)]));

        task_minos(['tid' => 1]);

        self::assertSame([Status::RETRY, Status::HELD], [$this->forum->row($first)['status'], $this->forum->row($second)['status']]);
        self::assertSame(TIME_NOW, (int)$this->forum->row($first)['retry_at'], 'the other item goes again next run');
    }

    /**
     * @dataProvider failureModes
     */
    public function testASwitchedOffPluginSweepsNothing(string $mode): void
    {
        // Under fail-open a sweep would publish the held posts: switching off must release nothing.
        $this->forum->set(['minos_failure_mode' => $mode]);
        $this->forum->plugin(new RecordingTransport([RecordingTransport::refused(503, 'kolejka_niedostepna')]));
        $due = $this->heldReply();
        $neverAccepted = $this->heldReply();
        $this->forum->plugin(new RecordingTransport());
        $accepted = $this->heldReply();
        $claimed = $this->heldReply();
        $platform = $this->forum->platform();
        $platform->updatePending($due, ['retry_at' => TIME_NOW - 1]);
        $platform->updatePending($neverAccepted, ['submitted_at' => TIME_NOW - 21 * 60]);
        $this->age($accepted, 21 * 60);
        self::assertTrue($platform->claimPending($claimed, TIME_NOW - 6 * 60));
        $this->forum->set(['minos_enabled' => '0']);
        $this->forum->plugin(new RecordingTransport());
        $logged = $this->logEntries();

        task_minos(['tid' => 1]);

        self::assertSame([], $this->forum->transport->requests, 'nothing is sent');
        self::assertSame([], self::moderation(), 'no post is touched');
        foreach ([$due => Status::RETRY, $neverAccepted => Status::RETRY, $accepted => Status::PENDING, $claimed => Status::APPLYING] as $pid => $status) {
            self::assertSame([0, $status], [(int)$this->forum->post($pid)['visible'], $this->forum->row($pid)['status']], "post {$pid}");
        }
        self::assertSame($logged, $this->logEntries(), 'no timeout, no error');
    }

    /** @return array<string,array{0:string}> */
    public function failureModes(): array
    {
        return ['fail-closed' => ['fail-closed'], 'fail-open' => ['fail-open']];
    }

    public function testASwitchedOffPluginStillForgetsOldData(): void
    {
        $platform = $this->forum->platform();
        $platform->log('retry', 'kolejka_pelna', 0, TIME_NOW - 31 * 86400);
        $this->forum->set(['minos_enabled' => '0']);

        task_minos(['tid' => 1]);

        self::assertSame([], $this->logEntries());
    }

    /**
     * Switching the plugin on again sends what was waiting when it was switched off, with a
     * fresh timeout — instead of the failure mode for the whole backlog at once (under
     * fail-open: publishing every one of them unassessed).
     */
    public function testSwitchingBackOnSendsAgainWhatWasWaitingWithAFreshTimeout(): void
    {
        $this->forum->set(['minos_failure_mode' => 'fail-open']);
        $this->forum->plugin(new RecordingTransport([RecordingTransport::refused(503, 'kolejka_niedostepna'), RecordingTransport::accepted()]));
        $retry = $this->heldReply('Czekał na ponowną wysyłkę.');
        $pending = $this->heldReply('Czekał na werdykt.');
        self::assertSame([Status::RETRY, Status::PENDING], [$this->forum->row($retry)['status'], $this->forum->row($pending)['status']]);
        $this->forum->set(['minos_enabled' => '0']);
        task_minos(['tid' => 1]);
        // Three hours pass while it is off.
        $this->age($pending, 3 * 3600);
        $this->forum->platform()->updatePending($retry, ['submitted_at' => TIME_NOW - 3 * 3600, 'retry_at' => TIME_NOW - 3 * 3600]);
        $this->forum->set(['minos_enabled' => '1']);
        $this->forum->plugin(new RecordingTransport());

        task_minos(['tid' => 1]);
        task_minos(['tid' => 1]);

        self::assertCount(1, $this->forum->transport->requests, 'sent again once, in one batch');
        self::assertSame(['mybb:' . $retry, 'mybb:' . $pending], array_column($this->forum->transport->lastBody()['elementy'], 'id'));
        foreach ([$retry, $pending] as $pid) {
            $row = $this->forum->row($pid);
            self::assertSame([0, Status::PENDING, TIME_NOW, TIME_NOW],
                [(int)$this->forum->post($pid)['visible'], $row['status'], (int)$row['submitted_at'], (int)$row['accepted_at']],
                'waiting again, with a fresh timeout');
        }
        self::assertContains('resumed', array_column($this->logEntries(), 'event'));
        self::assertSame([], self::moderation(), 'nothing published unassessed');
    }

    public function testActivationAfterADeactivationSendsAgainWhatWasWaiting(): void
    {
        $this->forum->set(['minos_failure_mode' => 'fail-open']);
        $pid = $this->heldReply('Czekał podczas dezaktywacji.');
        minos_deactivate();
        $this->age($pid, 3 * 3600);
        minos_activate();
        $this->forum->plugin(new RecordingTransport());

        task_minos(['tid' => 1]);

        self::assertSame(['mybb:' . $pid], array_column($this->forum->transport->lastBody()['elementy'] ?? [], 'id'));
        self::assertSame([0, Status::PENDING], [(int)$this->forum->post($pid)['visible'], $this->forum->row($pid)['status']]);
    }

    public function testAResendOfAPostAHumanDecidedIsDropped(): void
    {
        $this->forum->plugin(new RecordingTransport([RecordingTransport::refused(503, 'kolejka_niedostepna')]));
        $pid = $this->heldReply();
        $this->forum->platform()->updatePending($pid, ['retry_at' => TIME_NOW - 1]);
        $this->forum->db->update_query('posts', ['visible' => 1], "pid='" . $pid . "'");
        $this->forum->plugin(new RecordingTransport());

        task_minos(['tid' => 1]);

        self::assertSame([], $this->forum->transport->requests);
        self::assertSame(Status::SUPERSEDED, $this->forum->row($pid)['status']);
    }

    public function testOldLogEntriesAndDecisionsAreForgottenAfterThirtyDays(): void
    {
        $platform = $this->forum->platform();
        $platform->log('retry', 'kolejka_pelna', 0, TIME_NOW - 31 * 86400);
        $platform->log('retry', 'kolejka_pelna', 0, TIME_NOW - 86400);
        $this->forum->set(['minos_failure_mode' => 'fail-open']);
        $old = $this->heldReply('abc zły');
        $this->forum->deliver(self::payload($old, ['kwalifikacja' => 'ocenzurowane', 'ocenzurowany' => 'abc ███']));
        self::assertSame('abc zły', $this->forum->row($old)['original_text'], 'an original the row keeps');
        $recent = $this->heldReply();
        $this->forum->deliver(self::payload($recent));
        $waiting = $this->heldReply();
        $platform->updatePending($old, ['decided_at' => TIME_NOW - 31 * 86400]);
        $platform->updatePending($recent, ['decided_at' => TIME_NOW - 29 * 86400]);
        $platform->updatePending($waiting, ['submitted_at' => TIME_NOW - 60, 'accepted_at' => TIME_NOW - 60]);

        task_minos(['tid' => 1]);

        self::assertCount(1, $this->logEntries());
        self::assertNull($this->forum->row($old), 'a decision older than 30 days is forgotten, its original with it');
        self::assertNotNull($this->forum->row($recent));
        self::assertNotNull($this->forum->row($waiting), 'an undecided row is never forgotten');
    }

    public function testATimeoutBelowTwentyMinutesIsRaisedToTwenty(): void
    {
        // The review's probe: a one-minute timeout, the task at 61 s, the verdict at 5 min.
        $this->forum->set(['minos_failure_mode' => 'fail-open', 'minos_timeout' => '1']);
        $pid = $this->heldReply();
        $this->age($pid, 61);

        task_minos(['tid' => 1]);
        self::assertSame([0, Status::PENDING], [(int)$this->forum->post($pid)['visible'], $this->forum->row($pid)['status']],
            'the verdict is still on its way');

        $this->forum->deliver(self::payload($pid, ['kwalifikacja' => 'zablokowane']), null, null, TIME_NOW + 5 * 60 - 61);
        self::assertSame([0, Status::HELD], [(int)$this->forum->post($pid)['visible'], $this->forum->row($pid)['status']]);
    }

    public function testAClaimWhoseHolderDiedIsGivenBack(): void
    {
        $stale = $this->heldReply();
        $fresh = $this->heldReply();
        $platform = $this->forum->platform();
        self::assertTrue($platform->claimPending($stale, TIME_NOW - 6 * 60));
        self::assertTrue($platform->claimPending($fresh, TIME_NOW - 60));

        task_minos(['tid' => 1]);

        self::assertSame(Status::PENDING, $this->forum->row($stale)['status']);
        self::assertSame(Status::APPLYING, $this->forum->row($fresh)['status']);
        self::assertSame(200, $this->forum->deliver(self::payload($stale)));
        self::assertSame(Status::PUBLISHED, $this->forum->row($stale)['status'], 'a released row takes its verdict again');
    }

    private function age(int $pid, int $seconds): void
    {
        $this->forum->platform()->updatePending($pid, ['submitted_at' => TIME_NOW - $seconds, 'accepted_at' => TIME_NOW - $seconds]);
    }
}
