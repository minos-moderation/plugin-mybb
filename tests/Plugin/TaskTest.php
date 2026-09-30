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

    public function testNothingIsResentWhileThePluginIsOffButTimeoutsStillApply(): void
    {
        $this->forum->plugin(new RecordingTransport([RecordingTransport::refused(503, 'kolejka_niedostepna')]));
        $due = $this->heldReply();
        $late = $this->heldReply();
        $this->forum->platform()->updatePending($due, ['retry_at' => TIME_NOW - 1]);
        $this->forum->platform()->updatePending($late, ['submitted_at' => TIME_NOW - 21 * 60]);
        $this->forum->set(['minos_enabled' => '0']);
        $this->forum->plugin(new RecordingTransport());

        task_minos(['tid' => 1]);

        self::assertSame([], $this->forum->transport->requests);
        self::assertSame(Status::RETRY, $this->forum->row($due)['status']);
        self::assertSame(Status::HELD, $this->forum->row($late)['status'], 'never accepted, and waited too long');
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

    public function testOldLogEntriesAndDecisionsAreForgotten(): void
    {
        $platform = $this->forum->platform();
        $platform->log('retry', 'kolejka_pelna', 0, TIME_NOW - 31 * 86400);
        $platform->log('retry', 'kolejka_pelna', 0, TIME_NOW - 86400);
        $old = $this->heldReply();
        $this->forum->deliver(self::payload($old));
        $platform->updatePending($old, ['decided_at' => TIME_NOW - 91 * 86400]);

        task_minos(['tid' => 1]);

        self::assertCount(1, $this->logEntries());
        self::assertNull($this->forum->row($old));
    }

    private function age(int $pid, int $seconds): void
    {
        $this->forum->platform()->updatePending($pid, ['submitted_at' => TIME_NOW - $seconds, 'accepted_at' => TIME_NOW - $seconds]);
    }
}
