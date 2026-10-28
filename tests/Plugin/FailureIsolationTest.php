<?php

declare(strict_types=1);

namespace Minos\MyBB\Tests\Plugin;

use Minos\MyBB\Applier;
use Minos\MyBB\Status;
use Minos\MyBB\Tests\Support\PluginTestCase;
use Minos\MyBB\Tests\Support\RecordingTransport;

/**
 * One row that throws does not stop the others, and does not lose its verdict: it is logged
 * by step and post id — never by the error's message, which may carry the post's text — and
 * tried again; after three failures it is left to a human.
 *
 * `Moderation` throws for chosen posts as another plugin's `class_moderation_*` hook could
 * (`tests/Stubs/mybb.php`).
 */
final class FailureIsolationTest extends PluginTestCase
{
    /**
     * The audit's probe C2: three overdue rows under fail-open, the first poisonous.
     */
    public function testATimeoutThatThrowsLeavesTheOtherTimeoutsToRun(): void
    {
        $this->forum->set(['minos_failure_mode' => 'fail-open']);
        [$first, $second, $third] = $this->threeOverdue();
        $GLOBALS['minos_test']['throw_on'] = [$first];

        task_minos(['tid' => 1]);

        self::assertSame([1, 1], [(int)$this->forum->post($second)['visible'], (int)$this->forum->post($third)['visible']],
            'the others are decided in the same run');
        self::assertSame([Status::PUBLISHED, Status::PUBLISHED], [$this->forum->row($second)['status'], $this->forum->row($third)['status']]);
        self::assertSame([0, Status::PENDING, '1'],
            [(int)$this->forum->post($first)['visible'], $this->forum->row($first)['status'], (string)$this->forum->row($first)['apply_errors']],
            'back to the status it was claimed from, for the next run');
        $this->assertLoggedOnce('timeout', $first);
    }

    public function testARowThatKeepsThrowingIsLeftToAHumanAfterThreeTries(): void
    {
        $this->forum->set(['minos_failure_mode' => 'fail-open']);
        [$first] = $this->threeOverdue();
        $GLOBALS['minos_test']['throw_on'] = [$first];

        task_minos(['tid' => 1]);
        task_minos(['tid' => 1]);
        self::assertSame(Status::PENDING, $this->forum->row($first)['status']);
        task_minos(['tid' => 1]);

        $row = $this->forum->row($first);
        self::assertSame([Status::HELD, Status::REASON_APPLY_ERROR, '3'], [$row['status'], $row['verdict'], (string)$row['apply_errors']]);
        self::assertSame(0, (int)$this->forum->post($first)['visible'], 'nothing done to the post: fail-open would approve it');
        self::assertSame(Applier::MAX_FAILURES, count(array_filter($this->logEntries(), static function (array $entry): bool {
            return $entry['event'] === 'error';
        })));
        self::assertContains($first, array_map('intval', array_column($this->forum->platform()->attentionRows(50), 'pid')));
        $GLOBALS['minos_test']['throw_on'] = [];
        task_minos(['tid' => 1]);
        self::assertSame(Status::HELD, $this->forum->row($first)['status'], 'a human decides now');
    }

    public function testAWebhookWhoseApplicationThrowsAnswers500AndTheRedeliveryAppliesIt(): void
    {
        $pid = $this->heldReply('Odpowiedź do zatwierdzenia.');
        $GLOBALS['minos_test']['throw_on'] = [$pid];

        self::assertSame(500, $this->forum->deliver(self::payload($pid)), 'the gateway delivers again');
        self::assertSame([0, Status::PENDING], [(int)$this->forum->post($pid)['visible'], $this->forum->row($pid)['status']],
            'not left claimed');
        $this->assertLoggedOnce('verdict', $pid);

        $GLOBALS['minos_test']['throw_on'] = [];
        self::assertSame(200, $this->forum->deliver(self::payload($pid)));
        self::assertSame([1, Status::PUBLISHED], [(int)$this->forum->post($pid)['visible'], $this->forum->row($pid)['status']]);
    }

    public function testAHalfAppliedVerdictIsNeverAppliedTwice(): void
    {
        // The approval changed the post, then threw (another plugin's hook after the update).
        $pid = $this->heldReply('Odpowiedź do zatwierdzenia.');
        $GLOBALS['minos_test']['throw_after'] = [$pid];

        self::assertSame(500, $this->forum->deliver(self::payload($pid)));
        $GLOBALS['minos_test']['throw_after'] = [];
        self::assertSame(200, $this->forum->deliver(self::payload($pid, ['kwalifikacja' => 'zablokowane'])));

        self::assertSame([['approve_posts', [$pid]]], self::moderation(), 'one action on the post, not two');
        self::assertSame([1, Status::SUPERSEDED], [(int)$this->forum->post($pid)['visible'], $this->forum->row($pid)['status']]);
    }

    public function testALateVerdictThatThrowsAnswers500AndKeepsThePostPublished(): void
    {
        $this->forum->set(['minos_failure_mode' => 'fail-open']);
        $pid = $this->heldReply('Opublikowana bez oceny.');
        $this->forum->platform()->updatePending($pid, ['accepted_at' => TIME_NOW - 21 * 60]);
        task_minos(['tid' => 1]);
        $GLOBALS['minos_test']['throw_on'] = [$pid];

        self::assertSame(500, $this->forum->deliver(self::payload($pid, ['kwalifikacja' => 'zablokowane'])));
        self::assertSame([1, Status::PUBLISHED, '1'],
            [(int)$this->forum->post($pid)['visible'], $this->forum->row($pid)['status'], (string)$this->forum->row($pid)['auto_published']]);

        $GLOBALS['minos_test']['throw_on'] = [];
        self::assertSame(200, $this->forum->deliver(self::payload($pid, ['kwalifikacja' => 'zablokowane'])));
        self::assertSame([0, Status::HELD], [(int)$this->forum->post($pid)['visible'], $this->forum->row($pid)['status']]);
    }

    /**
     * A late verdict for a post held after its timeout (D9.10) whose approval keeps throwing:
     * `500` and the hold given back each time, then — after three tries — left to a human
     * (`apply_error`), so a further delivery applies nothing.
     */
    public function testALateVerdictAfterATimeoutThatKeepsThrowingIsLeftToAHuman(): void
    {
        $pid = $this->heldReply('Wstrzymana po czasie oczekiwania.');
        $this->forum->platform()->updatePending($pid, ['accepted_at' => TIME_NOW - 21 * 60]);
        task_minos(['tid' => 1]);
        $GLOBALS['minos_test']['throw_on'] = [$pid];

        self::assertSame(500, $this->forum->deliver(self::payload($pid)));
        self::assertSame([Status::HELD, Status::REASON_TIMEOUT], [$this->forum->row($pid)['status'], $this->forum->row($pid)['verdict']],
            'the hold is given back for the redelivery');
        self::assertSame(500, $this->forum->deliver(self::payload($pid)));
        self::assertSame(500, $this->forum->deliver(self::payload($pid)));
        self::assertSame([Status::HELD, Status::REASON_APPLY_ERROR], [$this->forum->row($pid)['status'], $this->forum->row($pid)['verdict']]);

        $GLOBALS['minos_test']['throw_on'] = [];
        self::assertSame(200, $this->forum->deliver(self::payload($pid)));
        self::assertSame([0, Status::HELD], [(int)$this->forum->post($pid)['visible'], $this->forum->row($pid)['status']]);
    }

    public function testAResubmissionThatThrowsForOneRowLeavesTheRestOfTheBatch(): void
    {
        $this->forum->plugin(new RecordingTransport([RecordingTransport::refused(503, 'kolejka_niedostepna')]));
        $pids = [];
        for ($i = 0; $i < 3; $i++) {
            $pids[] = $this->heldReply('Wpis numer ' . $i . '.');
            $this->forum->platform()->updatePending($pids[$i], ['retry_at' => TIME_NOW - 1]);
        }
        $this->forum->endPause();
        // Every change of the second row fails, as a database error would.
        $this->forum->pdo->exec('CREATE TRIGGER minos_test_broken BEFORE UPDATE ON mybb_minos_pending WHEN OLD.pid = ' . $pids[1]
            . " BEGIN SELECT RAISE(ABORT, '" . \Moderation::SECRET_TEXT . "'); END");
        $this->forum->plugin(new RecordingTransport());

        task_minos(['tid' => 1]);

        self::assertCount(1, $this->forum->transport->requests, 'one batch');
        self::assertSame(['mybb:' . $pids[0], 'mybb:' . $pids[2]], array_column($this->forum->transport->lastBody()['elementy'], 'id'));
        self::assertSame([Status::PENDING, Status::RETRY, Status::PENDING], array_map(function (int $pid): string {
            return $this->forum->row($pid)['status'];
        }, $pids));
        $this->assertLoggedOnce('resubmit', $pids[1]);
    }

    /**
     * While the shared pause lasts the task only moves due rows to its end: one row that
     * throws there leaves the others.
     */
    public function testARowThatThrowsDuringThePauseLeavesTheOthers(): void
    {
        $this->forum->plugin(new RecordingTransport([RecordingTransport::refused(503, 'kolejka_niedostepna', 120)]));
        $pids = [];
        for ($i = 0; $i < 3; $i++) {
            $pids[] = $this->heldReply('Wpis numer ' . $i . '.');
            $this->forum->platform()->updatePending($pids[$i], ['retry_at' => TIME_NOW - 1]);
        }
        $this->forum->pdo->exec('CREATE TRIGGER minos_test_broken BEFORE UPDATE ON mybb_minos_pending WHEN OLD.pid = ' . $pids[1]
            . " BEGIN SELECT RAISE(ABORT, '" . \Moderation::SECRET_TEXT . "'); END");

        task_minos(['tid' => 1]);

        self::assertSame([TIME_NOW + 120, TIME_NOW - 1, TIME_NOW + 120], array_map(function (int $pid): int {
            return (int)$this->forum->row($pid)['retry_at'];
        }, $pids));
        $this->assertLoggedOnce('resubmit', $pids[1]);
    }

    public function testABatchThatThrowsLeavesTheOtherBatches(): void
    {
        $this->forum->plugin(new RecordingTransport([RecordingTransport::refused(503, 'kolejka_niedostepna')]));
        $pids = [];
        for ($i = 0; $i < 25; $i++) {
            $pids[] = $this->heldReply('Wpis ' . $i . '.');
            $this->forum->platform()->updatePending($pids[$i], ['retry_at' => TIME_NOW - 1]);
        }
        $this->forum->endPause();
        $calls = 0;
        $this->forum->plugin(function (string $url, array $headers, string $body) use (&$calls): array {
            if (++$calls === 1) {
                throw new \RuntimeException(\Moderation::SECRET_TEXT);
            }
            return RecordingTransport::answerTo($body);
        });

        task_minos(['tid' => 1]);

        self::assertSame(2, $calls, 'the second batch was still sent');
        self::assertSame(Status::RETRY, $this->forum->row($pids[0])['status'], 'the first batch waits for the next run');
        self::assertSame(Status::PENDING, $this->forum->row($pids[24])['status']);
        $this->assertLoggedOnce('send', $pids[0]);
    }

    /**
     * Three held replies whose verdict is overdue.
     *
     * @return array<int,int> Their pids, oldest first.
     */
    private function threeOverdue(): array
    {
        $pids = [];
        foreach ([3000, 2900, 2800] as $age) {
            $pid = $this->heldReply('Wpis sprzed ' . $age . ' s.');
            $this->forum->platform()->updatePending($pid, ['submitted_at' => TIME_NOW - $age, 'accepted_at' => TIME_NOW - $age]);
            $pids[] = $pid;
        }
        return $pids;
    }

    private function assertLoggedOnce(string $step, int $pid): void
    {
        $errors = array_values(array_filter($this->logEntries(), static function (array $entry): bool {
            return $entry['event'] === 'error';
        }));
        self::assertCount(1, $errors);
        self::assertSame([$step, $pid], [$errors[0]['code'], (int)$errors[0]['pid']]);
        $stored = (string)json_encode([$this->logEntries(), $this->forum->platform()->attentionRows(100)], JSON_UNESCAPED_UNICODE);
        self::assertStringNotContainsString(\Moderation::SECRET_TEXT, $stored, 'never the error\'s message');
    }
}
