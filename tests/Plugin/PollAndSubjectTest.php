<?php

declare(strict_types=1);

namespace Minos\MyBB\Tests\Plugin;

use Minos\MyBB\Status;
use Minos\MyBB\Text;
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
     * A moderator changes the poll of a thread waiting for the verdict on its revision with
     * the poll: a human decision, nothing sent, the verdict on its way not applied.
     */
    public function testAModeratorsPollEditOfAWaitingThreadLeavesItToThem(): void
    {
        $handler = $this->forum->write(true, 'Treść.', ['subject' => 'Tytuł']);
        $pid = (int)$handler->return_values['pid'];
        $poll = $this->forum->addPoll((int)$handler->return_values['tid'], 'Pytanie', ['A', 'B']);
        task_minos(['tid' => 1]);
        self::assertSame([Status::PENDING, '1'], [$this->forum->row($pid)['status'], (string)$this->forum->row($pid)['rev']]);
        $sent = count($this->forum->transport->requests);
        $GLOBALS['minos_test']['moderators'] = [self::UID];

        $this->forum->editPoll($poll, 'Pytanie moderatora', ['A', 'B', 'C']);
        task_minos(['tid' => 1]);

        self::assertCount($sent, $this->forum->transport->requests);
        self::assertSame([Status::SUPERSEDED, Status::REASON_EDITED], [$this->forum->row($pid)['status'], $this->forum->row($pid)['verdict']]);
        self::assertSame(200, $this->forum->deliver(self::payload($pid, ['id' => 'mybb:' . $pid . '.1'])));
        self::assertSame(0, (int)$this->forum->post($pid)['visible']);
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

    /**
     * Whether a reply's subject goes with it is decided when it is first sent: a moderator
     * renaming the thread meanwhile does not turn MyBB's default "RE: <old title>" into a
     * subject of the author's — the verdict still judges the text sent, and an author's edit
     * that keeps the subject sends nothing again.
     */
    public function testADefaultSubjectStaysUnsentWhenTheThreadIsRenamed(): void
    {
        $tid = $this->thread('Wątek');
        $pid = (int)$this->forum->write(false, 'Zwykła odpowiedź.', ['subject' => 'RE: Wątek', 'tid' => $tid])->return_values['pid'];
        self::assertSame('Zwykła odpowiedź.', $this->forum->transport->lastBody()['elementy'][0]['tekst']);
        $this->forum->db->update_query('threads', ['subject' => 'Wątek po zmianie tytułu'], "tid='" . $tid . "'");
        $sent = count($this->forum->transport->requests);

        $this->forum->edit($pid, 'Zwykła odpowiedź.', 'RE: Wątek');
        task_minos(['tid' => 1]);
        self::assertCount($sent, $this->forum->transport->requests, 'the same text: nothing sent again');

        self::assertSame(200, $this->forum->deliver(self::payload($pid)));
        self::assertSame([1, Status::PUBLISHED], [(int)$this->forum->post($pid)['visible'], $this->forum->row($pid)['status']]);
    }

    /**
     * The decision is the row's, not the request's: a reply sent first with MyBB's default
     * subject and refused (429) goes again from the task after the thread was renamed — still
     * without the subject, so the verdict about that text applies (review of S7b, gap A).
     */
    public function testARetriedReplyKeepsTheSubjectDecisionOfItsFirstSend(): void
    {
        $this->forum->plugin(new RecordingTransport([RecordingTransport::refused(429, 'limit_minutowy_klucza', 45),
            RecordingTransport::accepted()]));
        $tid = $this->thread('Wątek');
        $pid = (int)$this->forum->write(false, 'Zwykła odpowiedź.', ['subject' => 'RE: Wątek', 'tid' => $tid])->return_values['pid'];
        self::assertSame([Status::RETRY, '0'], [$this->forum->row($pid)['status'], (string)$this->forum->row($pid)['own_subject']]);
        $this->forum->db->update_query('threads', ['subject' => 'Wątek po zmianie tytułu'], "tid='" . $tid . "'");
        self::assertTrue(Text::sendsOwnSubject($this->forum->platform()->post($pid)),
            'the guard: decided now, "RE: Wątek" would be an own subject of the renamed thread');

        $this->forum->plugin->task->run(TIME_NOW + 45);

        self::assertCount(2, $this->forum->transport->requests, 'the retry went out from the task');
        self::assertSame('Zwykła odpowiedź.', $this->forum->transport->lastBody()['elementy'][0]['tekst']);
        self::assertSame(Status::PENDING, $this->forum->row($pid)['status']);
        self::assertSame(200, $this->forum->deliver(self::payload($pid), null, TIME_NOW + 60, TIME_NOW + 60));
        self::assertSame([1, Status::PUBLISHED, 'bezpieczne'],
            [(int)$this->forum->post($pid)['visible'], $this->forum->row($pid)['status'], $this->forum->row($pid)['verdict']]);
    }

    /**
     * MyBB's other default: a reply to a post (its reply or quote button) gets "RE: " and that
     * post's subject without its own "RE:" — not the author's subject either.
     *
     * @dataProvider repliesToAPost
     */
    public function testAReplyToAPostKeepsMyBBsDefaultForItUnsent(string $quotedSubject, string $subject, string $tekst): void
    {
        $tid = $this->thread('Wątek');
        $quoted = $this->forum->db->insert_query('posts', ['tid' => $tid, 'fid' => Forum::FID, 'uid' => 1,
            'subject' => $this->forum->db->escape_string($quotedSubject), 'message' => 'Cytowany post.', 'visible' => 1]);
        $this->forum->write(false, 'Odpowiedź z cytatem.', ['subject' => $subject, 'tid' => $tid, 'replyto' => $quoted]);
        self::assertSame($tekst, $this->forum->transport->lastBody()['elementy'][0]['tekst']);
    }

    /** @return array<string,array{0:string,1:string,2:string}> */
    public function repliesToAPost(): array
    {
        return [
            'the quoted post\'s own subject' => ['Temat cytowanego posta', 'RE: Temat cytowanego posta', 'Odpowiedź z cytatem.'],
            'its "Re:" stripped once'       => ['Re: Temat', 'RE: Temat', 'Odpowiedź z cytatem.'],
            'an own subject all the same'   => ['Temat cytowanego posta', 'Mój temat', "Mój temat\n\nOdpowiedź z cytatem."],
        ];
    }

    /**
     * An author's edit that gives a waiting reply its own subject: the next revision carries
     * it, and its verdict applies to that text.
     */
    public function testAnEditThatGivesAReplyItsOwnSubjectSendsIt(): void
    {
        $tid = $this->thread('Wątek');
        $pid = (int)$this->forum->write(false, 'Treść.', ['subject' => 'RE: Wątek', 'tid' => $tid])->return_values['pid'];

        $this->forum->edit($pid, 'Treść.', 'Obraźliwy temat');
        task_minos(['tid' => 1]);

        $item = $this->forum->transport->lastBody()['elementy'][0];
        self::assertSame(['mybb:' . $pid . '.1', "Obraźliwy temat\n\nTreść."], [$item['id'], $item['tekst']]);
        self::assertSame(200, $this->forum->deliver(self::payload($pid, ['id' => 'mybb:' . $pid . '.1'])));
        self::assertSame([1, Status::PUBLISHED], [(int)$this->forum->post($pid)['visible'], $this->forum->row($pid)['status']]);
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
     * Shared rule F7: a title — a new thread's, or a reply's own subject — over a body that
     * shows no text is not a text to assess. The post gets the failure mode at once and
     * nothing is sent; under fail-open it is published, marked as published unassessed.
     *
     * @dataProvider titlesAlone
     */
    public function testATitleAloneIsNeverSent(string $mode, bool $thread, int $visible, string $status): void
    {
        $this->forum->set(['minos_failure_mode' => $mode]);
        $options = $thread ? ['subject' => 'Obraźliwy tytuł'] : ['subject' => 'Obraźliwy temat', 'tid' => $this->thread('Wątek')];
        $pid = (int)$this->forum->write($thread, '[attachment=4]', $options)->return_values['pid'];
        task_minos(['tid' => 1]);

        self::assertSame([], $this->forum->transport->requests, 'nothing is sent, not even the title');
        $row = $this->forum->row($pid);
        self::assertSame([$visible, $status, Status::REASON_NO_TEXT, $visible === 1 ? '1' : '0'],
            [(int)$this->forum->post($pid)['visible'], $row['status'], $row['verdict'], (string)$row['auto_published']]);
    }

    /** @return array<string,array{0:string,1:bool,2:int,3:string}> */
    public function titlesAlone(): array
    {
        return [
            'a thread, fail-closed'           => ['fail-closed', true, 0, Status::HELD],
            'a thread, fail-open'             => ['fail-open', true, 1, Status::PUBLISHED],
            'a reply\'s own subject, fail-closed' => ['fail-closed', false, 0, Status::HELD],
            'a reply\'s own subject, fail-open'   => ['fail-open', false, 1, Status::PUBLISHED],
        ];
    }

    /**
     * A poll is text (shared rule S5): a thread whose first post shows no text, held as
     * `no_text`, is sent with its title and poll once its author adds the poll.
     */
    public function testAPollGivesAnEmptyThreadATextToAssess(): void
    {
        $handler = $this->forum->write(true, '[attachment=4]', ['subject' => 'Tytuł']);
        $pid = (int)$handler->return_values['pid'];
        self::assertSame([Status::HELD, Status::REASON_NO_TEXT], [$this->forum->row($pid)['status'], $this->forum->row($pid)['verdict']]);

        $this->forum->addPoll((int)$handler->return_values['tid'], 'Pytanie X', ['Opcja Y', 'Opcja Z']);
        task_minos(['tid' => 1]);

        $item = $this->forum->transport->lastBody()['elementy'][0] ?? [];
        self::assertSame(['mybb:' . $pid . '.1', "Tytuł\n\nPytanie X\nOpcja Y\nOpcja Z"], [$item['id'] ?? null, $item['tekst'] ?? null]);
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
