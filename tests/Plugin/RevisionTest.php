<?php

declare(strict_types=1);

namespace Minos\MyBB\Tests\Plugin;

use Minos\MyBB\Status;
use Minos\MyBB\Tests\Support\PluginTestCase;
use Minos\MyBB\Tests\Support\RecordingTransport;

/**
 * An edit of a post still waiting for its verdict, through MyBB's update hook
 * (`Support\Forum::edit`): by its author, a new revision `mybb:<pid>.<n>` sent by the task;
 * by a moderator, a human's decision, nothing sent.
 */
final class RevisionTest extends PluginTestCase
{
    /**
     * The audit's probe M2 (fail-open): the edit used to end as `superseded` with no request
     * and no failure mode, off the ACP list.
     */
    public function testAnAuthorsEditOfAWaitingPostIsSentAgainAsTheNextRevision(): void
    {
        $this->forum->set(['minos_failure_mode' => 'fail-open']);
        $pid = $this->heldReply('Pierwsza wersja.');
        self::assertSame(Status::PENDING, $this->forum->row($pid)['status']);
        $sent = count($this->forum->transport->requests);

        $this->forum->edit($pid, 'Druga wersja.');

        self::assertCount($sent, $this->forum->transport->requests, 'nothing is sent in the editor\'s request');
        self::assertSame([Status::RETRY, '1'], [$this->forum->row($pid)['status'], (string)$this->forum->row($pid)['rev']]);

        task_minos(['tid' => 1]);

        self::assertCount($sent + 1, $this->forum->transport->requests);
        $item = $this->forum->transport->lastBody()['elementy'][0];
        self::assertSame(['mybb:' . $pid . '.1', 'Druga wersja.'], [$item['id'], $item['tekst']]);
        self::assertSame(Status::PENDING, $this->forum->row($pid)['status']);

        // The verdict about the first text arrives after all: acknowledged, not applied.
        self::assertSame(200, $this->forum->deliver(self::payload($pid, ['kwalifikacja' => 'zablokowane'])));
        self::assertSame([0, Status::PENDING, ''], [(int)$this->forum->post($pid)['visible'], $this->forum->row($pid)['status'],
            $this->forum->row($pid)['verdict']]);
        self::assertSame([], self::moderation());

        self::assertSame(200, $this->forum->deliver(self::payload($pid, ['id' => 'mybb:' . $pid . '.1'])));
        self::assertSame([1, Status::PUBLISHED, 'bezpieczne'], [(int)$this->forum->post($pid)['visible'],
            $this->forum->row($pid)['status'], $this->forum->row($pid)['verdict']]);
    }

    public function testEachEditIsTheNextRevisionAndOnlyTheLastOneApplies(): void
    {
        $pid = $this->heldReply('Wersja zero.');
        $this->forum->edit($pid, 'Wersja pierwsza.');
        task_minos(['tid' => 1]);
        $this->forum->edit($pid, 'Wersja druga.');
        task_minos(['tid' => 1]);

        self::assertSame('mybb:' . $pid . '.2', $this->forum->transport->lastBody()['elementy'][0]['id']);
        self::assertSame(200, $this->forum->deliver(self::payload($pid, ['id' => 'mybb:' . $pid . '.1'])));
        self::assertSame(Status::PENDING, $this->forum->row($pid)['status'], 'revision 1 is no longer the one assessed');
        self::assertSame(200, $this->forum->deliver(self::payload($pid, ['id' => 'mybb:' . $pid . '.3'])));
        self::assertSame(Status::PENDING, $this->forum->row($pid)['status'], 'nor is a revision that was never sent');
        self::assertSame(200, $this->forum->deliver(self::payload($pid, ['id' => 'mybb:' . $pid . '.2', 'kwalifikacja' => 'zablokowane'])));
        self::assertSame([0, Status::HELD, 'zablokowane'], [(int)$this->forum->post($pid)['visible'],
            $this->forum->row($pid)['status'], $this->forum->row($pid)['verdict']]);
    }

    /**
     * The receiver reads the row, then claims it; an edit in between starts a new revision,
     * and the claim, which names the revision it read, then takes nothing.
     */
    public function testAClaimForARevisionAnEditReplacedTakesNothing(): void
    {
        $pid = $this->heldReply('Wersja zero.');
        $this->forum->edit($pid, 'Wersja pierwsza.');
        $platform = $this->forum->platform();

        self::assertFalse($platform->claimPending($pid, TIME_NOW, Status::OPEN, false, 0));
        self::assertSame(Status::RETRY, $this->forum->row($pid)['status']);
        self::assertTrue($platform->claimPending($pid, TIME_NOW, Status::OPEN, false, 1));
    }

    /**
     * The author saves another edit while the task's request for the previous revision is on
     * its way: whatever the gateway answers about revision 1 — accepted, a retry, a
     * configuration error — is not recorded on revision 2, which goes out on a later run. A
     * verdict about revision 1 is not applied.
     *
     * @dataProvider answersInFlight
     * @param array{status:int,body:string} $answer
     */
    public function testAnEditWhileItsRequestIsOnItsWayIsSentNext(array $answer): void
    {
        $pid = $this->heldReply('Wersja zero.');
        $this->forum->edit($pid, 'Wersja pierwsza.');
        $forum = $this->forum;
        $edited = false;
        $sent = [];
        $this->forum->plugin(static function (string $url, array $headers, string $body) use ($forum, $pid, $answer, &$edited, &$sent): array {
            $sent[] = array_column(json_decode($body, true)['elementy'], 'tekst', 'id');
            if (!$edited) {
                $edited = true;
                $forum->edit($pid, 'Wersja druga.');
                return $answer;
            }
            return RecordingTransport::accepted();
        });

        $this->forum->plugin->task->run(TIME_NOW);

        $row = $this->forum->row($pid);
        self::assertSame([Status::RETRY, '2', 0, '0'], [$row['status'], (string)$row['rev'], (int)$row['accepted_at'],
            (string)$row['auto_published']], 'revision 2 waits to be sent, untouched by the answer about revision 1');
        self::assertSame(200, $this->forum->deliver(self::payload($pid, ['id' => 'mybb:' . $pid . '.1'])));
        self::assertSame([0, Status::RETRY], [(int)$this->forum->post($pid)['visible'], $this->forum->row($pid)['status']]);

        $this->forum->plugin->task->run(TIME_NOW + 300);

        self::assertSame(['mybb:' . $pid . '.2' => 'Wersja druga.'], end($sent));
        self::assertSame([Status::PENDING, '2'], [$this->forum->row($pid)['status'], (string)$this->forum->row($pid)['rev']]);
    }

    /** @return array<string,array{0:array{status:int,body:string}}> */
    public function answersInFlight(): array
    {
        return [
            'accepted'            => [RecordingTransport::accepted()],
            'a retry'             => [RecordingTransport::refused(429, 'kolejka_pelna', 60)],
            'a configuration error' => [RecordingTransport::refused(403, 'brak_webhooka')],
        ];
    }

    /**
     * An author's edit after which the text to send is the one already sent — saved as it
     * was, twice — starts no revision: no request, and the verdict on its way applies.
     */
    public function testAnEditThatLeavesTheSentTextAsItWasStartsNothing(): void
    {
        $pid = $this->heldReply('Ten sam tekst.');
        $sent = count($this->forum->transport->requests);

        $this->forum->edit($pid, 'Ten sam tekst.');
        task_minos(['tid' => 1]);
        $this->forum->edit($pid, "Ten  sam tekst.\r\n");
        task_minos(['tid' => 1]);

        self::assertCount($sent, $this->forum->transport->requests, 'nothing sent again');
        self::assertSame([Status::PENDING, '0'], [$this->forum->row($pid)['status'], (string)$this->forum->row($pid)['rev']]);
        self::assertSame(200, $this->forum->deliver(self::payload($pid)));
        self::assertSame([1, Status::PUBLISHED], [(int)$this->forum->post($pid)['visible'], $this->forum->row($pid)['status']],
            'the verdict on its way still judged this text');
    }

    /**
     * A post held as `no_text` and edited, still without text: nothing starts, nothing loops.
     */
    public function testAnEmptyPostEditedStillEmptyStartsNothing(): void
    {
        $pid = (int)$this->forum->write(true, '[b] [/b]', ['subject' => 'Tytuł'])->return_values['pid'];
        $this->forum->edit($pid, '[i] [/i]');
        task_minos(['tid' => 1]);

        self::assertSame([], $this->forum->transport->requests);
        self::assertSame([Status::HELD, Status::REASON_NO_TEXT, '0'],
            [$this->forum->row($pid)['status'], $this->forum->row($pid)['verdict'], (string)$this->forum->row($pid)['rev']]);
    }

    /**
     * The author's edit lands between the webhook's read of the row and its claim: the claim
     * names the revision it read, so the verdict about the old text is not applied.
     */
    public function testAnEditBetweenTheWebhooksReadAndItsClaimWins(): void
    {
        $pid = $this->heldReply('Wersja zero.');
        $forum = $this->forum;
        $this->forum->db->beforeNextWrite("SET status='" . Status::APPLYING . "'", static function () use ($forum, $pid): void {
            $forum->edit($pid, 'Wersja pierwsza.');
        });

        self::assertSame(200, $this->forum->deliver(self::payload($pid, ['kwalifikacja' => 'zablokowane'])));

        self::assertSame([0, Status::RETRY, '1', ''], [(int)$this->forum->post($pid)['visible'], $this->forum->row($pid)['status'],
            (string)$this->forum->row($pid)['rev'], $this->forum->row($pid)['verdict']]);
        self::assertSame([], self::moderation());
    }

    public function testAnIdThatIsNoRevisionIsNotAccepted(): void
    {
        $pid = $this->heldReply('Wersja zero.');
        $this->forum->edit($pid, 'Wersja pierwsza.');
        task_minos(['tid' => 1]);

        foreach (['.01', '.0', '.', '.1.1', '.x'] as $suffix) {
            self::assertSame(200, $this->forum->deliver(self::payload($pid, ['id' => 'mybb:' . $pid . $suffix])), $suffix);
            self::assertSame(Status::PENDING, $this->forum->row($pid)['status'], $suffix);
        }
    }

    public function testAModeratorsEditOfAWaitingPostIsLeftToThemAndListed(): void
    {
        $pid = $this->heldReply('Pierwsza wersja.');
        $sent = count($this->forum->transport->requests);
        $GLOBALS['minos_test']['moderators'] = [self::UID];

        $this->forum->edit($pid, 'Wersja moderatora.');
        task_minos(['tid' => 1]);

        self::assertCount($sent, $this->forum->transport->requests, 'nothing is sent');
        self::assertSame([Status::SUPERSEDED, Status::REASON_EDITED, '0'],
            [$this->forum->row($pid)['status'], $this->forum->row($pid)['verdict'], (string)$this->forum->row($pid)['rev']]);
        self::assertContains($pid, array_map('intval', array_column($this->forum->platform()->attentionRows(50), 'pid')),
            'the ACP page lists it');
        self::assertSame(200, $this->forum->deliver(self::payload($pid)));
        self::assertSame([0, Status::SUPERSEDED], [(int)$this->forum->post($pid)['visible'], $this->forum->row($pid)['status']]);
        self::assertSame([], self::moderation());
    }

    /**
     * A post the failure mode held because no verdict came: the author's edit gets it
     * assessed after all.
     *
     * @dataProvider heldWithoutAVerdict
     */
    public function testAnAuthorsEditOfAPostHeldWithoutAVerdictIsSentAgain(callable $hold, string $reason): void
    {
        $pid = $hold($this);
        self::assertSame([Status::HELD, $reason], [$this->forum->row($pid)['status'], $this->forum->row($pid)['verdict']]);
        $this->forum->plugin(new RecordingTransport());

        $this->forum->edit($pid, 'Poprawiona treść.');
        task_minos(['tid' => 1]);

        $item = $this->forum->transport->lastBody()['elementy'][0] ?? [];
        self::assertSame(['mybb:' . $pid . '.1', 'Poprawiona treść.'], [$item['id'] ?? null, $item['tekst'] ?? null]);
        self::assertSame([0, Status::PENDING, ''], [(int)$this->forum->post($pid)['visible'], $this->forum->row($pid)['status'],
            $this->forum->row($pid)['verdict']]);
        self::assertSame(200, $this->forum->deliver(self::payload($pid, ['id' => 'mybb:' . $pid . '.1'])));
        self::assertSame([1, Status::PUBLISHED], [(int)$this->forum->post($pid)['visible'], $this->forum->row($pid)['status']]);
    }

    /** @return array<string,array{0:callable,1:string}> */
    public function heldWithoutAVerdict(): array
    {
        return [
            'timeout' => [static function (self $test): int {
                $pid = $test->heldReply('Pierwsza wersja.');
                $test->forum->platform()->updatePending($pid, ['accepted_at' => TIME_NOW - 21 * 60]);
                task_minos(['tid' => 1]);
                return $pid;
            }, Status::REASON_TIMEOUT],
            'config_error' => [static function (self $test): int {
                $test->forum->plugin(new RecordingTransport([RecordingTransport::refused(403, 'brak_webhooka')]));
                return $test->heldReply('Pierwsza wersja.');
            }, Status::REASON_CONFIG_ERROR],
            'text_error' => [static function (self $test): int {
                return $test->heldReply('Widoczne SLOWO ' . str_repeat('[url=http://a.pl]x', 340));
            }, Status::REASON_TEXT_ERROR],
        ];
    }

    /**
     * A verdict the gateway gave stays: an edit of a post it held is the moderators' to judge.
     */
    public function testAnAuthorsEditOfAPostHeldByAVerdictSendsNothing(): void
    {
        $pid = $this->heldReply('Pierwsza wersja.');
        $this->forum->deliver(self::payload($pid, ['kwalifikacja' => 'zablokowane']));
        $sent = count($this->forum->transport->requests);

        $this->forum->edit($pid, 'Poprawiona treść.');
        task_minos(['tid' => 1]);

        self::assertCount($sent, $this->forum->transport->requests);
        self::assertSame([Status::HELD, 'zablokowane', '0'],
            [$this->forum->row($pid)['status'], $this->forum->row($pid)['verdict'], (string)$this->forum->row($pid)['rev']]);
    }

    /**
     * The revision starts a fresh wait: the time-out counts from the edit, and the row keeps
     * nothing of the attempt it replaces.
     */
    public function testANewRevisionWaitsAfreshAndForgetsTheEarlierAttempt(): void
    {
        $pid = $this->heldReply('Pierwsza wersja.');
        $this->forum->platform()->updatePending($pid, ['submitted_at' => TIME_NOW - 19 * 60, 'accepted_at' => TIME_NOW - 19 * 60]);

        $this->forum->edit($pid, 'Druga wersja.');

        $row = $this->forum->row($pid);
        self::assertSame([TIME_NOW, 0, TIME_NOW, 0, ''],
            [(int)$row['submitted_at'], (int)$row['accepted_at'], (int)$row['retry_at'], (int)$row['attempts'], $row['sent_sha256']]);
        $this->forum->plugin->task->run(TIME_NOW + 2 * 60);
        self::assertSame(Status::PENDING, $this->forum->row($pid)['status'], 'not timed out 21 minutes after the first send');
    }
}
