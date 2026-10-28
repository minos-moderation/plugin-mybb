<?php

declare(strict_types=1);

namespace Minos\MyBB\Tests\Plugin;

use Minos\Client\Signature;
use Minos\MyBB\Status;
use Minos\MyBB\Tests\Support\FakePostHandler;
use Minos\MyBB\Tests\Support\Forum;
use Minos\MyBB\Tests\Support\PluginTestCase;
use Minos\MyBB\Tests\Support\RecordingTransport;

/**
 * The webhook, with deliveries signed the way the gateway signs (`Signature::sign`).
 */
final class ReceiverTest extends PluginTestCase
{
    public function testAGoodDeliveryIsApplied(): void
    {
        $pid = $this->heldReply();
        self::assertSame(200, $this->forum->deliver(self::payload($pid)));
        self::assertSame(1, (int)$this->forum->post($pid)['visible']);
        self::assertSame([Status::PUBLISHED, 'bezpieczne'], [$this->forum->row($pid)['status'], $this->forum->row($pid)['verdict']]);
        self::assertSame([['approve_posts', [$pid]]], self::moderation());
    }

    public function testAWrongSecretIs401AndChangesNothing(): void
    {
        $pid = $this->heldReply();
        self::assertSame(401, $this->forum->deliver(self::payload($pid), 'nie-ten-sekret'));
        $this->assertStillWaiting($pid);
    }

    public function testAStaleSignatureIs401(): void
    {
        $pid = $this->heldReply();
        self::assertSame(401, $this->forum->deliver(self::payload($pid), null, time() - 600));
        $this->assertStillWaiting($pid);
    }

    public function testATamperedBodyIs401(): void
    {
        $pid = $this->heldReply();
        $body = (string)json_encode(self::payload($pid, ['kwalifikacja' => 'zablokowane']));
        $header = Signature::sign(Forum::SECRET, $body, time());
        $tampered = str_replace('zablokowane', 'bezpieczne', $body);
        self::assertSame(401, $this->forum->plugin->receiver->handle('POST', $header, $tampered, time()));
        self::assertSame(401, $this->forum->plugin->receiver->handle('POST', null, $body, time()));
        $this->assertStillWaiting($pid);
    }

    public function testASignedBodyThatIsNoPayloadIs400(): void
    {
        self::assertSame(400, $this->forum->deliver('nie json'));
        self::assertSame(400, $this->forum->deliver('{"status":"ocenione"}'));
    }

    public function testAnUnknownOrForeignIdIs200AndNothingElse(): void
    {
        $pid = $this->heldReply();
        self::assertSame(200, $this->forum->deliver(self::payload(99999)));
        self::assertSame(200, $this->forum->deliver(['id' => 'k-1027'] + self::payload($pid)));
        self::assertSame(200, $this->forum->deliver(['id' => 'mybb:0' . $pid] + self::payload($pid)));
        $this->assertStillWaiting($pid);
    }

    public function testARepeatedDeliveryIs200AndAppliesNothingTwice(): void
    {
        $pid = $this->heldReply();
        self::assertSame(200, $this->forum->deliver(self::payload($pid)));
        self::assertSame(200, $this->forum->deliver(self::payload($pid)));
        self::assertSame(200, $this->forum->deliver(self::payload($pid, ['kwalifikacja' => 'zablokowane'])));
        self::assertCount(1, self::moderation());
        self::assertSame(Status::PUBLISHED, $this->forum->row($pid)['status']);
    }

    public function testOnlyPostIsAccepted(): void
    {
        $pid = $this->heldReply();
        self::assertSame(405, $this->forum->plugin->receiver->handle('GET', null, '', time()));
        $this->assertStillWaiting($pid);
    }

    public function testADeactivatedPluginAnswers404AndChangesNothing(): void
    {
        $pid = $this->heldReply();
        $this->forum->cache->data['plugins'] = ['active' => []];
        self::assertSame(404, $this->forum->deliver(self::payload($pid)));
        $this->assertStillWaiting($pid);
    }

    /**
     * @dataProvider verdicts
     * @param array<string,mixed> $fields
     */
    public function testASwitchedOffPluginAnswers404AndChangesNothing(array $fields): void
    {
        $this->forum->set(['minos_failure_mode' => 'fail-open', 'minos_blocked' => 'soft-delete']);
        $pid = $this->heldReply('abc zły');
        $this->forum->set(['minos_enabled' => '0']);
        self::assertSame(404, $this->forum->deliver(self::payload($pid, $fields)));
        $this->assertStillWaiting($pid);
    }

    /** @return array<string,array{0:array<string,mixed>}> */
    public function verdicts(): array
    {
        return [
            'bezpieczne'   => [[]],
            'zablokowane'  => [['kwalifikacja' => 'zablokowane']],
            'ocenzurowane' => [['kwalifikacja' => 'ocenzurowane', 'ocenzurowany' => 'abc ███']],
            'nieocenione'  => [['status' => 'nieocenione']],
        ];
    }

    public function testWithoutAUsableSecretADeliveryIs401(): void
    {
        $pid = $this->heldReply();
        $this->forum->set(['minos_webhook_secret' => '   ']);
        self::assertSame(401, $this->forum->deliver(self::payload($pid), '   '), 'a blank secret verifies nothing');
        self::assertSame(401, $this->forum->deliver(self::payload($pid), ''));
        $this->assertStillWaiting($pid);
    }

    /**
     * The audit's probe M5: switched off under fail-open, a delivery and the task leave both
     * held posts held (today: 200, and both published unassessed).
     */
    public function testSwitchedOffNeitherAVerdictNorTheTaskPublishesHeldPosts(): void
    {
        $this->forum->set(['minos_failure_mode' => 'fail-open']);
        $a = $this->heldReply('Post A.');
        $b = $this->heldReply('Post B.');
        $this->forum->set(['minos_enabled' => '0']);
        $this->forum->plugin();

        self::assertSame(404, $this->forum->deliver(self::payload($a)));
        $this->forum->platform()->updatePending($b, ['accepted_at' => TIME_NOW - 21 * 60]);
        task_minos(['tid' => 1]);

        foreach ([$a, $b] as $pid) {
            self::assertSame([0, Status::PENDING, '0'],
                [(int)$this->forum->post($pid)['visible'], $this->forum->row($pid)['status'], (string)$this->forum->row($pid)['auto_published']]);
        }
        self::assertSame([], self::moderation());
    }

    /**
     * The audit's probe C3: fail-closed with soft-delete, switched off, a `zablokowane`
     * delivery (today: the post is soft-deleted).
     */
    public function testSwitchedOffABlockedVerdictDeletesNothing(): void
    {
        $this->forum->set(['minos_blocked' => 'soft-delete']);
        $pid = $this->heldReply('Post A.');
        $this->forum->set(['minos_enabled' => '0']);
        $this->forum->plugin();

        self::assertSame(404, $this->forum->deliver(self::payload($pid, ['kwalifikacja' => 'zablokowane'])));
        self::assertSame([0, Status::PENDING], [(int)$this->forum->post($pid)['visible'], $this->forum->row($pid)['status']]);
        self::assertSame([], self::moderation());
    }

    public function testAThreadIsApprovedAsAThread(): void
    {
        $handler = $this->forum->write(true, 'Pierwszy post.', ['subject' => 'Wątek']);
        $pid = (int)$handler->return_values['pid'];
        $this->forum->deliver(self::payload($pid));
        self::assertSame([['approve_threads', [(int)$handler->return_values['tid']]]], self::moderation());
        self::assertSame(1, (int)$this->forum->post($pid)['visible']);
    }

    public function testCensoredIsPublishedWithTheMaskedTextAndTheOriginalKept(): void
    {
        $pid = $this->heldReply("No to jest głupi\r\npomysł");
        $this->forum->deliver(self::payload($pid, [
            'kwalifikacja' => 'ocenzurowane', 'kategorie' => ['wulgaryzmy'], 'ocenzurowany' => "No to jest █████\npomysł",
        ]));
        self::assertSame(["No to jest █████\npomysł", 1], [$this->forum->post($pid)['message'], (int)$this->forum->post($pid)['visible']]);
        $row = $this->forum->row($pid);
        self::assertSame([Status::CENSORED, "No to jest głupi\r\npomysł", 'wulgaryzmy', ''],
            [$row['status'], $row['original_text'], $row['categories'], $row['masked_text']]);
    }

    /**
     * A post with MyCode renders to another text than it stores: its mask cannot stand for
     * it, so it stays in the queue, untouched, with the mask kept for the ACP page.
     */
    public function testACensoredPostWithFormattingStaysHeldWithItsMaskKept(): void
    {
        $pid = $this->heldReply('No to jest [b]głupi[/b] pomysł');
        $this->forum->deliver(self::payload($pid, [
            'kwalifikacja' => 'ocenzurowane', 'kategorie' => ['wulgaryzmy'], 'ocenzurowany' => 'No to jest █████ pomysł',
        ]));
        self::assertSame([0, 'No to jest [b]głupi[/b] pomysł'], [(int)$this->forum->post($pid)['visible'], $this->forum->post($pid)['message']]);
        $row = $this->forum->row($pid);
        self::assertSame([Status::HELD, 'ocenzurowane', 'No to jest █████ pomysł', ''],
            [$row['status'], $row['verdict'], $row['masked_text'], $row['original_text']]);
        self::assertSame([], self::moderation());
    }

    /**
     * The audit's probe M7: a mask of a post whose `[img]` sits inside `[code]` would turn
     * it into a live image.
     */
    public function testAMaskThatWouldReviveMyCodeIsNotPublished(): void
    {
        $message = "[code][img]https://tracker.example/p.png[/img][/code]\nTo jest głupi pomysł.";
        $pid = $this->heldReply($message);
        $sent = $this->forum->transport->lastBody()['elementy'][0]['tekst'];
        $masked = str_replace('głupi', '█████', $sent);
        $this->forum->deliver(self::payload($pid, ['kwalifikacja' => 'ocenzurowane', 'ocenzurowany' => $masked]));

        self::assertSame([0, $message, Status::HELD],
            [(int)$this->forum->post($pid)['visible'], $this->forum->post($pid)['message'], $this->forum->row($pid)['status']]);
        self::assertSame($masked, $this->forum->row($pid)['masked_text']);
    }

    /**
     * A verdict applies only to the text that was sent: a text changed past the edit hook
     * gets no verdict applied.
     *
     * @dataProvider changedPastTheHook
     * @param array<string,mixed> $fields
     */
    public function testAVerdictForATextThatChangedSinceItWasSentIsNotApplied(bool $thread, array $fields): void
    {
        $this->forum->set(['minos_blocked' => 'soft-delete']);
        $pid = (int)$this->forum->write($thread, 'Pierwotna treść.', ['subject' => 'Pierwotny tytuł'])->return_values['pid'];
        $change = $thread ? ['subject' => 'Inny tytuł'] : ['message' => 'Zupełnie inna treść.'];
        $this->forum->db->update_query('posts', $change, "pid='" . $pid . "'");

        self::assertSame(200, $this->forum->deliver(self::payload($pid, $fields)));

        self::assertSame([0, Status::HELD, Status::REASON_TEXT_CHANGED],
            [(int)$this->forum->post($pid)['visible'], $this->forum->row($pid)['status'], $this->forum->row($pid)['verdict']]);
        self::assertSame([], self::moderation());
    }

    /** @return array<string,array{0:bool,1:array<string,mixed>}> */
    public function changedPastTheHook(): array
    {
        return [
            'bezpieczne, the body'      => [false, []],
            'zablokowane, the body'     => [false, ['kwalifikacja' => 'zablokowane']],
            'bezpieczne, the subject'   => [true, []],
            'ocenzurowane, the subject' => [true, ['kwalifikacja' => 'ocenzurowane', 'ocenzurowany' => "Pierwotny tytuł\n\n████████ treść."]],
        ];
    }

    public function testAForumThatChangedHowItRendersPostsGetsNoVerdictApplied(): void
    {
        $pid = $this->heldReply('Tekst [b]pogrubiony[/b].');
        $GLOBALS['minos_test']['forums'][Forum::FID] = ['allowmycode' => 0];
        $this->forum->deliver(self::payload($pid));
        self::assertSame([0, Status::REASON_TEXT_CHANGED], [(int)$this->forum->post($pid)['visible'], $this->forum->row($pid)['verdict']],
            'readers now see another text than the one assessed');
    }

    public function testARowWithoutADigestGetsNoVerdictApplied(): void
    {
        // A row sent before the plugin kept digests.
        $pid = $this->heldReply();
        $this->forum->platform()->updatePending($pid, ['sent_sha256' => '']);
        $this->forum->deliver(self::payload($pid));
        self::assertSame([0, Status::HELD, Status::REASON_TEXT_CHANGED],
            [(int)$this->forum->post($pid)['visible'], $this->forum->row($pid)['status'], $this->forum->row($pid)['verdict']]);
    }

    public function testACensoredThreadKeepsItsSubjectAndGetsItsBody(): void
    {
        $handler = $this->forum->write(true, 'Treść z brzydkim słowem', ['subject' => 'Tytuł']);
        $pid = (int)$handler->return_values['pid'];
        $this->forum->deliver(self::payload($pid, ['kwalifikacja' => 'ocenzurowane', 'ocenzurowany' => "Tytuł\n\nTreść z ████████ słowem"]));
        self::assertSame('Treść z ████████ słowem', $this->forum->post($pid)['message']);
        self::assertSame(Status::CENSORED, $this->forum->row($pid)['status']);
    }

    /**
     * @dataProvider censoredButHeld
     * @param array<string,string> $settings
     */
    public function testCensoredStaysInTheQueueWhenTheMaskCannotStandForThePost(bool $thread, string $message,
        ?string $masked, array $settings): void
    {
        $this->forum->set($settings);
        $pid = (int)$this->forum->write($thread, $message, ['subject' => 'Zły tytuł'])->return_values['pid'];
        $this->forum->deliver(self::payload($pid, ['kwalifikacja' => 'ocenzurowane', 'ocenzurowany' => $masked]));

        self::assertSame(Status::HELD, $this->forum->row($pid)['status']);
        self::assertSame([0, $message], [(int)$this->forum->post($pid)['visible'], $this->forum->post($pid)['message']]);
        self::assertSame([], self::moderation());
        self::assertSame((string)$masked, $this->forum->row($pid)['masked_text'], 'the mask is kept for the ACP page');
    }

    /** @return array<string,array{0:bool,1:string,2:?string,3:array<string,string>}> */
    public function censoredButHeld(): array
    {
        return [
            'the administrator keeps censored posts'   => [false, 'abc zły', 'abc ███', ['minos_censored' => 'queue']],
            'no ocenzurowany in the payload'           => [false, 'abc zły', null, []],
            'the post was longer than what was sent'   => [false, str_repeat('a', 3100), str_repeat('█', 3000), []],
            'a length other than what was sent'        => [false, 'abc zły', 'abc ███ i więcej', []],
            'a mask in the thread subject'             => [true, 'Treść', "███ tytuł\n\nTreść", []],
            'the same length, other letters'           => [false, 'abc zły', 'xyz ███', []],
            'the audit\'s probe M1'                    => [false, 'Ala ma kota.', 'Kup: zly.pl█', []],
            'a post with formatting'                   => [false, '[i]abc[/i] zły', 'abc ███', []],
            'a post with an entity'                    => [false, 'abc &#122;ły', 'abc ███', []],
            'double spaces the text squeezes'          => [false, 'abc  zły', 'abc ███', []],
            'blank lines the text squeezes'            => [false, "abc\n\n\n\nzły", "abc\n\n███", []],
            'a no-break space'                         => [false, "abc\u{00A0}zły", 'abc ███', []],
        ];
    }

    public function testBlockedStaysInTheQueueOrIsSoftDeleted(): void
    {
        $queued = $this->heldReply();
        $this->forum->deliver(self::payload($queued, ['kwalifikacja' => 'zablokowane', 'kategorie' => ['nekanie']]));
        self::assertSame([0, Status::HELD], [(int)$this->forum->post($queued)['visible'], $this->forum->row($queued)['status']]);

        $this->forum->set(['minos_blocked' => 'soft-delete']);
        $deleted = $this->heldReply();
        $this->forum->deliver(self::payload($deleted, ['kwalifikacja' => 'zablokowane']));
        self::assertSame([-1, Status::DELETED], [(int)$this->forum->post($deleted)['visible'], $this->forum->row($deleted)['status']]);
        self::assertSame([['soft_delete_posts', [$deleted]]], self::moderation());
    }

    /**
     * @dataProvider unassessed
     * @param array<string,mixed> $payload
     */
    public function testNoVerdictFollowsTheFailureMode(array $payload): void
    {
        $closed = $this->heldReply();
        $this->forum->deliver(['id' => 'mybb:' . $closed] + $payload);
        self::assertSame([0, Status::HELD, 'nieocenione'],
            [(int)$this->forum->post($closed)['visible'], $this->forum->row($closed)['status'], $this->forum->row($closed)['verdict']]);

        $this->forum->set(['minos_failure_mode' => 'fail-open']);
        $open = $this->heldReply();
        $this->forum->deliver(['id' => 'mybb:' . $open] + $payload);
        self::assertSame([1, Status::PUBLISHED, 'nieocenione'],
            [(int)$this->forum->post($open)['visible'], $this->forum->row($open)['status'], $this->forum->row($open)['verdict']]);
    }

    /** @return array<string,array{0:array<string,mixed>}> */
    public function unassessed(): array
    {
        return [
            'nieocenione'                 => [['status' => 'nieocenione']],
            'an unknown kwalifikacja'     => [['status' => 'ocenione', 'kwalifikacja' => 'podejrzane', 'kategorie' => []]],
            'an unknown status'           => [['status' => 'moze', 'kwalifikacja' => 'bezpieczne']],
        ];
    }

    public function testSupportIsFlaggedWhateverTheVerdict(): void
    {
        $pid = $this->heldReply();
        $this->forum->deliver(self::payload($pid, ['kategorie' => ['samookaleczenie'], 'wsparcie' => true]));
        $row = $this->forum->row($pid);
        self::assertSame([Status::PUBLISHED, '1', 'samookaleczenie'], [$row['status'], (string)$row['support'], $row['categories']]);
        self::assertContains($pid, array_map('intval', array_column($this->forum->platform()->attentionRows(50), 'pid')),
            'the ACP page lists it');
    }

    public function testAPostAModeratorAlreadyDecidedIsLeftAlone(): void
    {
        $approved = $this->heldReply();
        $this->forum->db->update_query('posts', ['visible' => 1], "pid='" . $approved . "'");
        $this->forum->deliver(self::payload($approved, ['kwalifikacja' => 'zablokowane']));
        self::assertSame([1, Status::SUPERSEDED], [(int)$this->forum->post($approved)['visible'], $this->forum->row($approved)['status']]);

        $gone = $this->heldReply();
        $this->forum->db->delete_query('posts', "pid='" . $gone . "'");
        $this->forum->deliver(self::payload($gone));
        self::assertSame(Status::GONE, $this->forum->row($gone)['status']);
        self::assertSame([], self::moderation());
    }

    public function testAVerdictBeforeTheAcceptanceIsNotOverwrittenByIt(): void
    {
        // The gateway can deliver before its own 202 reaches the forum.
        $forum = $this->forum;
        $delivered = null;
        $forum->plugin(function (string $url, array $headers, string $body) use ($forum, &$delivered): array {
            $id = json_decode($body, true)['elementy'][0]['id'];
            $delivered = $forum->deliver(self::payload((int)substr($id, 5)));
            return ['status' => 202, 'body' => '{}'];
        });
        $pid = $this->heldReply();
        self::assertSame(200, $delivered);
        self::assertSame(Status::PUBLISHED, $this->forum->row($pid)['status']);
    }

    /**
     * @dataProvider failureModes
     */
    public function testASafeVerdictOnACutPostFollowsTheFailureMode(string $mode, int $visible, string $status): void
    {
        $this->forum->set(['minos_failure_mode' => $mode]);
        $pid = $this->heldReply(str_repeat('a', 3000) . ' OGON_NIEOCENIONY_OBELGA');
        self::assertStringNotContainsString('OGON_NIEOCENIONY_OBELGA', $this->forum->transport->lastBody()['elementy'][0]['tekst']);

        $this->forum->deliver(self::payload($pid));

        $row = $this->forum->row($pid);
        self::assertSame([$visible, $status, Status::REASON_TRUNCATED], [(int)$this->forum->post($pid)['visible'], $row['status'], $row['verdict']]);
        self::assertSame('wpis dłuższy niż 3000 znaków — oceniono początek',
            $this->forum->platform()->lang('minos_reason_' . $row['verdict']), 'the note moderators see');
    }

    /** @return array<string,array{0:string,1:int,2:string}> */
    public function failureModes(): array
    {
        return [
            'fail-closed' => ['fail-closed', 0, Status::HELD],
            'fail-open'   => ['fail-open', 1, Status::PUBLISHED],
        ];
    }

    public function testABlockedCutPostIsStillBlocked(): void
    {
        $this->forum->set(['minos_failure_mode' => 'fail-open']);
        $pid = $this->heldReply(str_repeat('zły ', 800));
        $this->forum->deliver(self::payload($pid, ['kwalifikacja' => 'zablokowane']));
        self::assertSame([0, Status::HELD], [(int)$this->forum->post($pid)['visible'], $this->forum->row($pid)['status']]);
    }

    public function testACensoredTextThatCannotBeStoredKeepsThePostHeld(): void
    {
        // The database silently refuses to change the message.
        $this->forum->pdo->exec('CREATE TRIGGER keep_message BEFORE UPDATE OF message ON mybb_posts BEGIN SELECT RAISE(IGNORE); END');
        $pid = $this->heldReply('abc zły');
        $this->forum->deliver(self::payload($pid, ['kwalifikacja' => 'ocenzurowane', 'ocenzurowany' => 'abc ███']));
        self::assertSame([0, 'abc zły', Status::HELD],
            [(int)$this->forum->post($pid)['visible'], $this->forum->post($pid)['message'], $this->forum->row($pid)['status']]);
        self::assertSame([], self::moderation(), 'the original is never published on this verdict');
    }

    public function testALateVerdictIsAppliedToAPostTheFailureModePublished(): void
    {
        $this->forum->set(['minos_failure_mode' => 'fail-open']);
        $blocked = $this->autoPublished();
        $censored = $this->autoPublished('abc zły');
        $safe = $this->autoPublished();

        $this->forum->deliver(self::payload($blocked, ['kwalifikacja' => 'zablokowane']));
        $this->forum->deliver(self::payload($censored, ['kwalifikacja' => 'ocenzurowane', 'ocenzurowany' => 'abc ███']));
        $this->forum->deliver(self::payload($safe));

        self::assertSame([0, Status::HELD], [(int)$this->forum->post($blocked)['visible'], $this->forum->row($blocked)['status']]);
        self::assertContains(['unapprove_posts', [$blocked]], self::moderation());
        self::assertSame([1, 'abc ███', Status::CENSORED],
            [(int)$this->forum->post($censored)['visible'], $this->forum->post($censored)['message'], $this->forum->row($censored)['status']]);
        self::assertSame([1, Status::PUBLISHED, 'bezpieczne', '0'], [(int)$this->forum->post($safe)['visible'],
            $this->forum->row($safe)['status'], $this->forum->row($safe)['verdict'], (string)$this->forum->row($safe)['auto_published']]);
    }

    public function testALateCensoredVerdictFollowsTheSetting(): void
    {
        $this->forum->set(['minos_failure_mode' => 'fail-open', 'minos_censored' => 'queue']);
        $pid = $this->autoPublished('abc zły');
        $this->forum->deliver(self::payload($pid, ['kwalifikacja' => 'ocenzurowane', 'ocenzurowany' => 'abc ███']));
        self::assertSame([0, 'abc zły', Status::HELD],
            [(int)$this->forum->post($pid)['visible'], $this->forum->post($pid)['message'], $this->forum->row($pid)['status']]);
        self::assertSame('abc ███', $this->forum->row($pid)['masked_text']);
    }

    public function testALateVerdictForATextThatChangedSinceItWasSentDoesNothing(): void
    {
        $this->forum->set(['minos_failure_mode' => 'fail-open']);
        $pid = $this->autoPublished('Pierwotna treść.');
        $this->forum->db->update_query('posts', ['message' => 'Zupełnie inna treść.'], "pid='" . $pid . "'");
        $GLOBALS['minos_test']['moderation'] = [];

        $this->forum->deliver(self::payload($pid, ['kwalifikacja' => 'zablokowane']));

        self::assertSame([1, Status::PUBLISHED, '0'],
            [(int)$this->forum->post($pid)['visible'], $this->forum->row($pid)['status'], (string)$this->forum->row($pid)['auto_published']]);
        self::assertSame([], self::moderation());
    }

    public function testAModeratorsDecisionOnAnAutoPublishedPostStaysFinal(): void
    {
        $this->forum->set(['minos_failure_mode' => 'fail-open']);
        $unapproved = $this->autoPublished();
        $this->forum->db->update_query('posts', ['visible' => 0], "pid='" . $unapproved . "'");
        $edited = $this->autoPublished();
        $edit = new FakePostHandler('update', 'post', ['pid' => $edited, 'message' => 'Poprawione.']);
        $GLOBALS['plugins']->run_hooks('datahandler_post_update', $edit);
        $GLOBALS['minos_test']['moderation'] = [];

        $this->forum->deliver(self::payload($unapproved));
        $this->forum->deliver(self::payload($edited, ['kwalifikacja' => 'zablokowane']));

        self::assertSame([0, Status::SUPERSEDED], [(int)$this->forum->post($unapproved)['visible'], $this->forum->row($unapproved)['status']]);
        self::assertSame([1, Status::PUBLISHED], [(int)$this->forum->post($edited)['visible'], $this->forum->row($edited)['status']],
            'an edit ended the late window');
        self::assertSame([], self::moderation());
    }

    /**
     * The audit's probe M11 the other way round (shared rule D9.10): fail-closed, the post's
     * verdict did not come in time and the failure mode held it (`timeout`); the verdict
     * that then comes is applied as if it had come in time — `bezpieczne` publishes it.
     */
    public function testALateVerdictIsAppliedToAPostTheFailureModeHeldForItsTimeout(): void
    {
        $pid = $this->timedOut('Zwykły post w kolejce bramy.');

        self::assertSame(200, $this->forum->deliver(self::payload($pid)));

        self::assertSame([1, Status::PUBLISHED, 'bezpieczne', 'bezpieczne'], [(int)$this->forum->post($pid)['visible'],
            $this->forum->row($pid)['status'], $this->forum->row($pid)['verdict'], $this->forum->row($pid)['answer']]);
        self::assertSame([['approve_posts', [$pid]]], self::moderation());
        self::assertSame(200, $this->forum->deliver(self::payload($pid)), 'a repeated delivery');
        self::assertSame([['approve_posts', [$pid]]], self::moderation(), 'applies nothing twice');
    }

    /**
     * The other verdicts follow the same rules as on time: `zablokowane` by setting,
     * `ocenzurowane` with its mask, `nieocenione` the failure mode again.
     */
    public function testEveryLateVerdictAfterATimeoutIsAppliedAsOnTime(): void
    {
        $this->forum->set(['minos_blocked' => 'soft-delete']);
        $blocked = $this->timedOut('Spadaj stąd.');
        $censored = $this->timedOut('abc zły');
        $none = $this->timedOut('Bez oceny.');

        $this->forum->deliver(self::payload($blocked, ['kwalifikacja' => 'zablokowane', 'kategorie' => ['nekanie']]));
        $this->forum->deliver(self::payload($censored, ['kwalifikacja' => 'ocenzurowane', 'ocenzurowany' => 'abc ███']));
        $this->forum->deliver(self::payload($none, ['status' => 'nieocenione', 'kwalifikacja' => null]));

        self::assertSame([-1, Status::DELETED, 'zablokowane', 'nekanie'], [(int)$this->forum->post($blocked)['visible'],
            $this->forum->row($blocked)['status'], $this->forum->row($blocked)['verdict'], $this->forum->row($blocked)['categories']]);
        self::assertSame([1, 'abc ███', Status::CENSORED], [(int)$this->forum->post($censored)['visible'],
            $this->forum->post($censored)['message'], $this->forum->row($censored)['status']]);
        self::assertSame([0, Status::HELD, 'nieocenione'], [(int)$this->forum->post($none)['visible'],
            $this->forum->row($none)['status'], $this->forum->row($none)['verdict']]);
    }

    /**
     * A moderator who approved, deleted or edited the held post decided: the late verdict
     * changes nothing — neither the post nor its row.
     */
    public function testALateVerdictAfterATimeoutLeavesAPostAModeratorActedOn(): void
    {
        $approved = $this->timedOut('Zatwierdzony przez moderatora.');
        $this->forum->db->update_query('posts', ['visible' => 1], "pid='" . $approved . "'");
        $deleted = $this->timedOut('Usunięty przez moderatora.');
        $this->forum->db->update_query('posts', ['visible' => -1], "pid='" . $deleted . "'");
        $gone = $this->timedOut('Usunięty na stałe.');
        $this->forum->db->delete_query('posts', "pid='" . $gone . "'");
        $edited = $this->timedOut('Poprawiony przez moderatora.');
        $GLOBALS['minos_test']['moderators'] = [self::UID];
        $this->forum->edit($edited, 'Poprawiony przez moderatora.');
        $GLOBALS['minos_test']['moderators'] = [];
        $GLOBALS['minos_test']['moderation'] = [];

        foreach ([$approved, $deleted, $gone] as $pid) {
            self::assertSame(200, $this->forum->deliver(self::payload($pid, ['kwalifikacja' => 'zablokowane'])));
            self::assertSame([Status::HELD, Status::REASON_TIMEOUT, ''],
                [$this->forum->row($pid)['status'], $this->forum->row($pid)['verdict'], $this->forum->row($pid)['answer']],
                "post {$pid}: its row stays as the failure mode left it");
        }
        self::assertSame([Status::SUPERSEDED, Status::REASON_EDITED], [$this->forum->row($edited)['status'],
            $this->forum->row($edited)['verdict']], 'a moderator\'s edit decided the post');
        self::assertSame(200, $this->forum->deliver(self::payload($edited)));
        self::assertSame([0, Status::SUPERSEDED], [(int)$this->forum->post($edited)['visible'], $this->forum->row($edited)['status']]);
        self::assertSame([1, -1], [(int)$this->forum->post($approved)['visible'], (int)$this->forum->post($deleted)['visible']]);
        self::assertSame([], self::moderation());
    }

    /**
     * An author's edit of the held post starts a new revision: the late verdict about the
     * text before it is acknowledged and not applied. A text changed past the edit hook keeps
     * the post held for a human.
     */
    public function testALateVerdictAfterATimeoutIsAboutTheTextThatWasSent(): void
    {
        $revised = $this->timedOut('Pierwsza wersja.');
        $changed = $this->timedOut('Wysłana treść.');
        $this->forum->edit($revised, 'Druga wersja.');
        $this->forum->db->update_query('posts', ['message' => 'Treść zmieniona poza wtyczką.'], "pid='" . $changed . "'");
        $GLOBALS['minos_test']['moderation'] = [];

        self::assertSame(200, $this->forum->deliver(self::payload($revised)));
        self::assertSame(200, $this->forum->deliver(self::payload($changed)));

        self::assertSame([0, Status::RETRY, '1'], [(int)$this->forum->post($revised)['visible'],
            $this->forum->row($revised)['status'], (string)$this->forum->row($revised)['rev']], 'the new revision waits to be sent');
        self::assertSame([0, Status::HELD, Status::REASON_TEXT_CHANGED], [(int)$this->forum->post($changed)['visible'],
            $this->forum->row($changed)['status'], $this->forum->row($changed)['verdict']]);
        self::assertSame([], self::moderation());
    }

    /**
     * Only a hold for the timeout waits for a late verdict: a post held because the gateway
     * refused it (a configuration error), or because a verdict came and held it, gets
     * nothing from another delivery.
     */
    public function testOnlyAHoldForTheTimeoutTakesALateVerdict(): void
    {
        $blocked = $this->heldReply('Zablokowany na czas.');
        $this->forum->deliver(self::payload($blocked, ['kwalifikacja' => 'zablokowane']));
        $this->forum->plugin(new RecordingTransport([RecordingTransport::refused(403, 'brak_webhooka')]));
        $refused = $this->heldReply('Odrzucony przez bramę.');
        $GLOBALS['minos_test']['moderation'] = [];

        $this->forum->deliver(self::payload($blocked));
        $this->forum->deliver(self::payload($refused));

        self::assertSame([Status::HELD, 'zablokowane'], [$this->forum->row($blocked)['status'], $this->forum->row($blocked)['verdict']]);
        self::assertSame([Status::HELD, Status::REASON_CONFIG_ERROR],
            [$this->forum->row($refused)['status'], $this->forum->row($refused)['verdict']]);
        self::assertSame([], self::moderation());
    }

    /**
     * A held reply the failure mode (fail-closed, the default) held after its timeout: the
     * audit's probe M11 up to the delivery.
     */
    private function timedOut(string $message): int
    {
        $pid = $this->heldReply($message);
        $this->forum->platform()->updatePending($pid, ['accepted_at' => TIME_NOW - 21 * 60]);
        task_minos(['tid' => 1]);
        self::assertSame([0, Status::HELD, Status::REASON_TIMEOUT],
            [(int)$this->forum->post($pid)['visible'], $this->forum->row($pid)['status'], $this->forum->row($pid)['verdict']]);
        return $pid;
    }

    /**
     * A held reply the failure mode (fail-open) published after the timeout.
     */
    private function autoPublished(string $message = 'Treść.'): int
    {
        $pid = $this->heldReply($message);
        $this->forum->platform()->updatePending($pid, ['accepted_at' => TIME_NOW - 21 * 60]);
        $this->forum->plugin->task->run(TIME_NOW);
        self::assertSame([1, '1'], [(int)$this->forum->post($pid)['visible'], (string)$this->forum->row($pid)['auto_published']]);
        return $pid;
    }

    private function assertStillWaiting(int $pid): void
    {
        self::assertSame(0, (int)$this->forum->post($pid)['visible']);
        self::assertSame(Status::PENDING, $this->forum->row($pid)['status']);
        self::assertSame([], self::moderation());
    }
}
