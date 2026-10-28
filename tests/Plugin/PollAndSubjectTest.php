<?php

declare(strict_types=1);

namespace Minos\MyBB\Tests\Plugin;

use Minos\MyBB\Status;
use Minos\MyBB\Tests\Support\Forum;
use Minos\MyBB\Tests\Support\PluginTestCase;
use Minos\MyBB\Tests\Support\RecordingTransport;

/**
 * What heads a post for its readers is assessed with it (shared rule D9.1): a thread's poll
 * — question and options — and a reply's own subject, when its author changed MyBB's
 * default "RE: <thread title>". The plugin never rewrites either: a mask there holds the post.
 */
final class PollAndSubjectTest extends PluginTestCase
{
    /**
     * A poll added to a thread while it waits (in MyBB 1.8.41 by a direct post to
     * `polls.php`: its form refuses an author whose thread is in the queue) is a new revision
     * of the first post: title, poll, body.
     */
    public function testAPollAddedWhileTheThreadWaitsIsSentWithIt(): void
    {
        $handler = $this->forum->write(true, 'Treść pierwszego postu.', ['subject' => 'Tytuł wątku']);
        $pid = (int)$handler->return_values['pid'];
        $sent = count($this->forum->transport->requests);

        $this->forum->addPoll((int)$handler->return_values['tid'], 'Pytanie X', ['Opcja Y', '[b]Opcja Z[/b]']);

        self::assertCount($sent, $this->forum->transport->requests, 'the task sends it, not the poll\'s request');
        task_minos(['tid' => 1]);
        $item = $this->forum->transport->lastBody()['elementy'][0];
        self::assertSame('mybb:' . $pid . '.1', $item['id']);
        self::assertSame("Tytuł wątku\n\nPytanie X\nOpcja Y\nOpcja Z\n\nTreść pierwszego postu.", $item['tekst']);

        self::assertSame(200, $this->forum->deliver(self::payload($pid, ['id' => 'mybb:' . $pid . '.1'])));
        self::assertSame([1, Status::PUBLISHED], [(int)$this->forum->post($pid)['visible'], $this->forum->row($pid)['status']],
            'the verdict applies to the text with its poll');
    }

    public function testAPollRenderedForAForumWithoutMyCodeKeepsItLiterally(): void
    {
        $GLOBALS['minos_test']['forums'][Forum::FID] = ['allowmycode' => 0];
        $handler = $this->forum->write(true, 'Treść.', ['subject' => 'Tytuł']);
        $this->forum->addPoll((int)$handler->return_values['tid'], 'Pytanie &#65;', ['[b]Tak[/b]', 'Nie']);
        task_minos(['tid' => 1]);

        self::assertSame("Tytuł\n\nPytanie A\n[b]Tak[/b]\nNie\n\nTreść.", $this->forum->transport->lastBody()['elementy'][0]['tekst']);
    }

    /**
     * Only moderators change a poll in MyBB 1.8.41, and a moderator's change of a waiting
     * thread is a human decision, as a moderator's edit is.
     */
    public function testAModeratorsPollOnAWaitingThreadLeavesItToThem(): void
    {
        $handler = $this->forum->write(true, 'Treść.', ['subject' => 'Tytuł']);
        $pid = (int)$handler->return_values['pid'];
        $sent = count($this->forum->transport->requests);
        $GLOBALS['minos_test']['moderators'] = [self::UID];

        $poll = $this->forum->addPoll((int)$handler->return_values['tid'], 'Pytanie', ['A', 'B']);
        $this->forum->editPoll($poll, 'Pytanie zmienione', ['A', 'B', 'C']);
        task_minos(['tid' => 1]);

        self::assertCount($sent, $this->forum->transport->requests);
        self::assertSame([Status::SUPERSEDED, Status::REASON_EDITED], [$this->forum->row($pid)['status'], $this->forum->row($pid)['verdict']]);
    }

    /**
     * A poll added after the thread was published changes a published post: not assessed
     * again (shared rule D9.2 is not adopted yet).
     */
    public function testAPollAddedAfterPublicationIsNotSent(): void
    {
        $handler = $this->forum->write(true, 'Treść.', ['subject' => 'Tytuł']);
        $pid = (int)$handler->return_values['pid'];
        $this->forum->deliver(self::payload($pid));
        $sent = count($this->forum->transport->requests);

        $this->forum->addPoll((int)$handler->return_values['tid'], 'Pytanie', ['A', 'B']);
        task_minos(['tid' => 1]);

        self::assertCount($sent, $this->forum->transport->requests);
        self::assertSame([1, Status::PUBLISHED, '0'], [(int)$this->forum->post($pid)['visible'], $this->forum->row($pid)['status'],
            (string)$this->forum->row($pid)['rev']]);
    }

    public function testAMaskInThePollHoldsThePost(): void
    {
        $handler = $this->forum->write(true, 'Treść bez formatowania.', ['subject' => 'Tytuł']);
        $pid = (int)$handler->return_values['pid'];
        $this->forum->addPoll((int)$handler->return_values['tid'], 'Głupie pytanie', ['A', 'B']);
        task_minos(['tid' => 1]);

        $this->forum->deliver(self::payload($pid, ['id' => 'mybb:' . $pid . '.1', 'kwalifikacja' => 'ocenzurowane',
            'ocenzurowany' => "Tytuł\n\n██████ pytanie\nA\nB\n\nTreść bez formatowania."]));

        self::assertSame([0, Status::HELD], [(int)$this->forum->post($pid)['visible'], $this->forum->row($pid)['status']]);
        self::assertSame('Treść bez formatowania.', $this->forum->post($pid)['message']);
        self::assertStringContainsString('██████', $this->forum->row($pid)['masked_text'], 'the mask is shown on the ACP page');
    }

    /**
     * @dataProvider replySubjects
     */
    public function testAReplysOwnSubjectIsSentAndMyBBsDefaultIsNot(string $subject, string $tekst): void
    {
        $this->forum->write(false, 'Treść odpowiedzi.', ['subject' => $subject, 'tid' => $this->thread('Wątek')]);
        self::assertSame($tekst, $this->forum->transport->lastBody()['elementy'][0]['tekst']);
    }

    /** @return array<string,array{0:string,1:string}> */
    public function replySubjects(): array
    {
        return [
            'its own subject'          => ['Obraźliwy', "Obraźliwy\n\nTreść odpowiedzi."],
            'MyBB\'s default'          => ['RE: Wątek', 'Treść odpowiedzi.'],
            'the thread\'s title'      => ['Wątek', 'Treść odpowiedzi.'],
            'a quote of another reply' => ['RE: Obraźliwy', "RE: Obraźliwy\n\nTreść odpowiedzi."],
        ];
    }

    public function testTheDefaultSubjectOfALongTitleIsMyBBsShortenedOne(): void
    {
        $title = str_repeat('ż', 90);
        $tid = $this->thread($title);
        $this->forum->write(false, 'Treść.', ['subject' => 'RE: ' . str_repeat('ż', 82) . '...', 'tid' => $tid]);
        self::assertSame('Treść.', $this->forum->transport->lastBody()['elementy'][0]['tekst']);
    }

    public function testAMaskInAReplysSubjectHoldsIt(): void
    {
        $pid = (int)$this->forum->write(false, 'Treść.', ['subject' => 'Głupi temat', 'tid' => $this->thread('Wątek')])
            ->return_values['pid'];

        $this->forum->deliver(self::payload($pid, ['kwalifikacja' => 'ocenzurowane', 'ocenzurowany' => "█████ temat\n\nTreść."]));

        self::assertSame([0, Status::HELD], [(int)$this->forum->post($pid)['visible'], $this->forum->row($pid)['status']]);
        self::assertSame(['Głupi temat', 'Treść.'], [$this->forum->post($pid)['subject'], $this->forum->post($pid)['message']]);
    }

    public function testAReplyWithItsOwnSubjectGetsItsMaskedBody(): void
    {
        $pid = (int)$this->forum->write(false, 'To jest głupie.', ['subject' => 'Mój temat', 'tid' => $this->thread('Wątek')])
            ->return_values['pid'];

        $this->forum->deliver(self::payload($pid, ['kwalifikacja' => 'ocenzurowane', 'ocenzurowany' => "Mój temat\n\nTo jest ██████."]));

        self::assertSame([1, Status::CENSORED], [(int)$this->forum->post($pid)['visible'], $this->forum->row($pid)['status']]);
        self::assertSame(['Mój temat', 'To jest ██████.'], [$this->forum->post($pid)['subject'], $this->forum->post($pid)['message']]);
    }

    /**
     * A link in a reply's own subject counts in the spam signals, as the subject is sent.
     */
    public function testALinkInAReplysOwnSubjectIsALink(): void
    {
        $this->forum->write(false, 'Treść.', ['subject' => 'Zobacz www.example.com', 'tid' => $this->thread('Wątek')]);
        $meta = $this->forum->transport->lastBody()['elementy'][0]['meta'];
        self::assertSame([1, ['example.com']], [$meta['links'], $meta['link_domains']]);
    }

    /**
     * A published thread with a given title, to reply to.
     */
    private function thread(string $title): int
    {
        $tid = $this->forum->thread();
        $this->forum->db->update_query('threads', ['subject' => $this->forum->db->escape_string($title)], "tid='" . $tid . "'");
        return $tid;
    }
}
