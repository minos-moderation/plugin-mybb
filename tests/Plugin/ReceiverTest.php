<?php

declare(strict_types=1);

namespace Minos\MyBB\Tests\Plugin;

use Minos\Client\Signature;
use Minos\MyBB\Status;
use Minos\MyBB\Tests\Support\Forum;
use Minos\MyBB\Tests\Support\PluginTestCase;

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

    public function testOnlyAPostWithAStoredSecretAndAnActivePluginIsHandled(): void
    {
        $pid = $this->heldReply();
        self::assertSame(405, $this->forum->plugin->receiver->handle('GET', null, '', time()));

        $this->forum->cache->data['plugins'] = ['active' => []];
        self::assertSame(503, $this->forum->deliver(self::payload($pid)));
        $this->forum->cache->data['plugins'] = ['active' => ['minos' => 'minos']];

        $this->forum->set(['minos_webhook_secret' => '']);
        self::assertSame(503, $this->forum->deliver(self::payload($pid), ''), 'an empty secret verifies nothing');
        $this->assertStillWaiting($pid);
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
        $pid = $this->heldReply('No to jest [b]głupi[/b] pomysł');
        $this->forum->deliver(self::payload($pid, [
            'kwalifikacja' => 'ocenzurowane', 'kategorie' => ['wulgaryzmy'], 'ocenzurowany' => 'No to jest █████ pomysł',
        ]));
        self::assertSame(['No to jest █████ pomysł', 1], [$this->forum->post($pid)['message'], (int)$this->forum->post($pid)['visible']]);
        $row = $this->forum->row($pid);
        self::assertSame([Status::CENSORED, 'No to jest [b]głupi[/b] pomysł', 'wulgaryzmy'],
            [$row['status'], $row['original_text'], $row['categories']]);
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
    }

    /** @return array<string,array{0:bool,1:string,2:?string,3:array<string,string>}> */
    public function censoredButHeld(): array
    {
        return [
            'the administrator keeps censored posts' => [false, 'abc zły', 'abc ███', ['minos_censored' => 'queue']],
            'no ocenzurowany in the payload'         => [false, 'abc zły', null, []],
            'the post was longer than what was sent' => [false, str_repeat('a', 3100), str_repeat('█', 3000), []],
            'a length other than what was sent'      => [false, 'abc zły', 'abc ███ i więcej', []],
            'a mask in the thread subject'           => [true, 'Treść', "███ tytuł\n\nTreść", []],
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

    private function assertStillWaiting(int $pid): void
    {
        self::assertSame(0, (int)$this->forum->post($pid)['visible']);
        self::assertSame(Status::PENDING, $this->forum->row($pid)['status']);
        self::assertSame([], self::moderation());
    }
}
