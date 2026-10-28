<?php

declare(strict_types=1);

namespace Minos\MyBB\Tests\Plugin;

use Minos\MyBB\Gateway;
use Minos\MyBB\Status;
use Minos\MyBB\Submitter;
use Minos\MyBB\Tests\Support\PluginTestCase;
use Minos\MyBB\Tests\Support\RecordingTransport;

/**
 * The shared pause: after the gateway asks to retry (a 429 of any kind, a 503, no answer),
 * nothing is sent before the moment it named — neither in an author's request nor by the
 * task — and a task run stops at the first batch the gateway refuses.
 */
final class PauseTest extends PluginTestCase
{
    /**
     * Three posts in a minute after a "retry" answer cost one request: the authors of the
     * other two do not wait for the gateway, and their posts wait for the task.
     *
     * @dataProvider retryAnswers
     * @param array{status:int,body:string} $answer
     */
    public function testThreePostsInAMinuteAfterARetryAnswerCostOneRequest(array $answer, int $pauseS): void
    {
        $this->forum->plugin(new RecordingTransport([$answer, RecordingTransport::accepted()]));
        $first = $this->heldReply('Pierwszy.');
        $second = $this->heldReply('Drugi.');
        $third = $this->heldReply('Trzeci.');

        self::assertCount(1, $this->forum->transport->requests, 'one refused request');
        foreach ([$first, $second, $third] as $pid) {
            self::assertSame([0, Status::RETRY, TIME_NOW + $pauseS],
                [(int)$this->forum->post($pid)['visible'], $this->forum->row($pid)['status'], (int)$this->forum->row($pid)['retry_at']],
                "post {$pid} waits until the pause ends");
        }

        $this->forum->plugin->task->run(TIME_NOW + $pauseS);

        self::assertCount(2, $this->forum->transport->requests, 'after the pause, one batch');
        self::assertSame(['mybb:' . $first, 'mybb:' . $second, 'mybb:' . $third],
            array_column($this->forum->transport->lastBody()['elementy'], 'id'));
    }

    /** @return array<string,array{0:array{status:int,body:string},1:int}> */
    public function retryAnswers(): array
    {
        return [
            'the key\'s minute limit' => [RecordingTransport::refused(429, 'limit_minutowy_klucza', 45), 45],
            'the key\'s requests in flight' => [RecordingTransport::refused(429, 'limit_w_locie_klucza', 30), 30],
            'the global limit'        => [RecordingTransport::refused(429, 'limit_globalny_b2b', 300), 300],
            'a full queue'            => [RecordingTransport::refused(429, 'kolejka_pelna', 60), 60],
            '503 with ponow_za_s'     => [RecordingTransport::refused(503, 'kolejka_niedostepna', 90), 90],
            '503 without'             => [RecordingTransport::refused(503, 'kolejka_niedostepna'), 60],
            'a 502 from a proxy'      => [['status' => 502, 'body' => '<html>'], 60],
            'no answer'               => [['status' => 0, 'body' => ''], 60],
            'a 408'                   => [['status' => 408, 'body' => ''], 60],
        ];
    }

    /**
     * The audit's probe M4 in the author's request: while the key's daily limit lasts, a new
     * post costs no request at all and gets the failure mode at once.
     */
    public function testDuringTheDailyLimitANewPostIsNotSentAndGetsTheFailureModeAtOnce(): void
    {
        $this->forum->plugin(new RecordingTransport([RecordingTransport::refused(429, 'limit_dobowy_klucza', 36000)]));
        $this->heldReply('Pierwszy.');
        $later = $this->heldReply('Później.');

        self::assertCount(1, $this->forum->transport->requests);
        self::assertSame([Status::HELD, Status::REASON_GATEWAY_LIMIT], [$this->forum->row($later)['status'], $this->forum->row($later)['verdict']]);
    }

    /**
     * The task while the pause lasts: no request; a row the pause outlasts gets the failure
     * mode in the same run (the audit's probe M4: no longer `retry` after 19 minutes), the
     * others wait until it ends.
     */
    public function testTheTaskSendsNothingDuringThePauseAndFailsWhatItOutlasts(): void
    {
        $this->forum->plugin(new RecordingTransport([RecordingTransport::refused(503, 'kolejka_niedostepna')]));
        $old = $this->heldReply('Wysłany 19 minut temu.');
        $fresh = $this->heldReply('Świeży.');
        $this->forum->platform()->updatePending($old, ['submitted_at' => TIME_NOW - 19 * 60, 'retry_at' => TIME_NOW - 1]);
        $this->forum->platform()->updatePending($fresh, ['retry_at' => TIME_NOW - 1]);
        $this->forum->endPause();
        $this->forum->plugin(new RecordingTransport([RecordingTransport::refused(429, 'limit_globalny_b2b', 120)]));
        $this->heldReply('Ten dostaje odmowę.');
        $sent = count($this->forum->transport->requests);

        task_minos(['tid' => 1]);

        self::assertCount($sent, $this->forum->transport->requests, 'nothing sent during the pause');
        self::assertSame([Status::HELD, Status::REASON_GATEWAY_LIMIT], [$this->forum->row($old)['status'], $this->forum->row($old)['verdict']]);
        self::assertSame([Status::RETRY, TIME_NOW + 120], [$this->forum->row($fresh)['status'], (int)$this->forum->row($fresh)['retry_at']]);
    }

    /**
     * Three batches due, the first refused: one request. The rows of the other two wait until
     * the pause ends — after a 503 in the task no further batch goes out.
     */
    public function testATaskRunStopsAtTheFirstBatchTheGatewayRefuses(): void
    {
        $this->forum->plugin(new RecordingTransport([RecordingTransport::refused(503, 'kolejka_niedostepna')]));
        $pids = [];
        for ($i = 0; $i < 2 * Gateway::MAX_ITEMS + 5; $i++) {
            $pids[] = $this->heldReply('Wpis ' . $i . '.');
        }
        self::assertCount(1, $this->forum->transport->requests);
        $this->forum->plugin(new RecordingTransport([RecordingTransport::refused(503, 'kolejka_niedostepna'), RecordingTransport::accepted()]));

        $this->forum->plugin->task->run(TIME_NOW + 60);

        self::assertCount(1, $this->forum->transport->requests, 'the first batch only');
        self::assertCount(Gateway::MAX_ITEMS, $this->forum->transport->lastBody()['elementy']);
        foreach ($pids as $i => $pid) {
            self::assertSame(Status::RETRY, $this->forum->row($pid)['status']);
            if ($i >= Gateway::MAX_ITEMS) {
                self::assertSame(TIME_NOW + 120, (int)$this->forum->row($pid)['retry_at'], 'unsent, it waits for the pause\'s end');
            }
        }

        $this->forum->plugin->task->run(TIME_NOW + 180);
        self::assertCount(4, $this->forum->transport->requests, 'all three batches once the gateway accepts');
        foreach ($pids as $pid) {
            self::assertSame(Status::PENDING, $this->forum->row($pid)['status']);
        }
    }

    public function testTheFirstAcceptanceEndsThePause(): void
    {
        $this->forum->plugin(new RecordingTransport([RecordingTransport::refused(429, 'kolejka_pelna', 60), RecordingTransport::accepted()]));
        $this->heldReply('Odrzucony.');
        $this->forum->plugin->task->run(TIME_NOW + 60);
        self::assertCount(2, $this->forum->transport->requests);

        $next = $this->heldReply('Po przyjęciu.');

        self::assertCount(3, $this->forum->transport->requests, 'sent in its author\'s request again');
        self::assertSame(Status::PENDING, $this->forum->row($next)['status']);
    }

    /**
     * Two requests sent before either was refused: the one refused for a minute does not end
     * the ten-minute pause the other was given meanwhile.
     */
    public function testAShorterPauseNeverEndsALongerOne(): void
    {
        $other = $this->forum->platform();
        $this->forum->plugin(static function () use ($other): array {
            $other->setState(Submitter::PAUSE, ['until' => TIME_NOW + 600, 'kod' => 'limit_globalny_b2b']);
            return RecordingTransport::refused(429, 'limit_minutowy_klucza', 30);
        });
        $this->heldReply('W tej samej chwili.');
        $this->forum->plugin(new RecordingTransport());

        $this->forum->plugin->task->run(TIME_NOW + 31);

        self::assertSame([], $this->forum->transport->requests, 'the ten-minute pause still holds');
    }
}
