<?php

declare(strict_types=1);

namespace Minos\MyBB\Tests\Plugin;

use Minos\MyBB\Status;
use Minos\MyBB\Tests\Support\FakePostHandler;
use Minos\MyBB\Tests\Support\Forum;
use Minos\MyBB\Tests\Support\PluginTestCase;

/**
 * Shared rule D9.8 (A4 of client-php's `docs/plugin-behaviour.md`): MyBB 1.8.41 notifies a
 * thread's and a forum's subscribers only when a post is inserted visible at once, never on
 * an approval from its queue (the live check's question 2). A post the plugin publishes
 * sends them what MyBB sends for a post visible at once, by the setting "Powiadamiaj
 * subskrybentów…" (on by default) — once: a late verdict never sends them again. Test data
 * only.
 */
final class NotificationTest extends PluginTestCase
{
    private const MAIL = 20;

    private const PM = 21;

    private const FORUM = 22;

    private const AWAY = 23;

    private const NO_VIEW = 24;

    /** @var int The thread the subscribers follow. */
    private $tid;

    protected function setUp(): void
    {
        parent::setUp();
        $this->tid = $this->forum->thread();
        $this->forum->user(self::UID, 'Autor Testowy');
        $this->forum->user(self::MAIL, 'Subskrybent Mail');
        $this->forum->user(self::PM, 'Subskrybent PW');
        $this->forum->user(self::FORUM, 'Subskrybent Działu');
        // Not back since the thread's last post (an hour ago): MyBB does not notify again.
        $this->forum->user(self::AWAY, 'Nieobecny', ['lastactive' => Forum::HOUR_AGO - 60]);
        $this->forum->user(self::NO_VIEW, 'Bez Dostępu');
        $GLOBALS['minos_test']['user_permissions'][self::NO_VIEW] = ['canview' => 0];
        $this->forum->subscribe(self::MAIL, $this->tid, 1);
        $this->forum->subscribe(self::PM, $this->tid, 2);
        $this->forum->subscribe(self::UID, $this->tid, 1);
        $this->forum->subscribe(self::AWAY, $this->tid, 1);
        $this->forum->subscribe(self::NO_VIEW, $this->tid, 1);
        $this->forum->subscribeForum(self::FORUM);
        $this->forum->subscribeForum(self::UID);
        // MyBB's own settings (not the plugin's group): as the board has them.
        $this->forum->mybb->settings += ['bbname' => 'Forum Testowe', 'subscribeexcerpt' => '100', 'enablepms' => '1',
            'orig_bblanguage' => 'polish'];
    }

    /**
     * The issue's case: B subscribes to thread T, reply A is approved by a `bezpieczne`
     * delivery → one e-mail for B, one private message for the PM subscriber, none for the
     * author, none for a subscriber not back since the last post or without the right to
     * view the forum.
     */
    public function testASafeVerdictNotifiesTheThreadsSubscribersAsMyBBWouldAtOnce(): void
    {
        $pid = $this->reply('Odpowiedź do powiadomienia subskrybentów.');
        self::assertSame([[], []], [$this->forum->mails(), $this->forum->pms()], 'nothing while it is held');

        self::assertSame(200, $this->forum->deliver(self::payload($pid)));

        $mails = $this->forum->mails();
        self::assertSame(['subskrybent mail@example.com'], array_column($mails, 'mailto'));
        self::assertSame('New Reply to Wątek', $mails[0]['subject']);
        self::assertStringContainsString('Odpowiedź do powiadomienia subskrybentów.', $mails[0]['message'], 'the excerpt');
        self::assertStringContainsString('showthread.php?tid=' . $this->tid . '&action=newpost', $mails[0]['message']);
        self::assertStringContainsString('Autor Testowy has just replied', $mails[0]['message']);
        $pms = $this->forum->pms();
        self::assertSame([[self::PM, 0, 'New Reply to Wątek']], array_map(static function (array $pm): array {
            return [(int)$pm['toid'], (int)$pm['fromid'], $pm['subject']];
        }, $pms), 'one private message, from "MyBB Engine"');
        self::assertTrue($GLOBALS['minos_test']['pms'][0]['override'], 'as send_pm($pm, -1, true) sends it');
        self::assertSame(['queue_size' => 1, 'locked' => 0], $this->forum->cache->read('mailqueue'));
        self::assertNotContains('error', array_column($this->logEntries(), 'event'), 'no step failed');

        self::assertSame(200, $this->forum->deliver(self::payload($pid)), 'a repeated delivery');
        self::assertSame([1, 1], [count($this->forum->mails()), count($this->forum->pms())], 'sends nothing twice');
    }

    /**
     * A new thread the plugin publishes notifies the forum's subscribers (by e-mail), not its
     * author; a thread has no thread subscribers yet.
     */
    public function testAPublishedThreadNotifiesTheForumsSubscribers(): void
    {
        $pid = (int)$this->forum->write(true, 'Treść nowego wątku.', ['subject' => 'Nowy wątek do powiadomienia'])
            ->return_values['pid'];

        $this->forum->deliver(self::payload($pid));

        $mails = $this->forum->mails();
        self::assertSame(['subskrybent działu@example.com'], array_column($mails, 'mailto'));
        self::assertSame('New Thread in Forum testowe', $mails[0]['subject']);
        self::assertStringContainsString('The thread is titled Nowy wątek do powiadomienia', $mails[0]['message']);
        self::assertStringContainsString('Treść nowego wątku.', $mails[0]['message']);
        self::assertSame([], $this->forum->pms());
    }

    /**
     * A censored post published with its mask: the excerpt is the masked text, never the
     * original.
     */
    public function testACensoredPostNotifiesWithItsMask(): void
    {
        $pid = $this->reply('abc zły');
        $this->forum->deliver(self::payload($pid, ['kwalifikacja' => 'ocenzurowane', 'ocenzurowany' => 'abc ███']));

        self::assertSame(Status::CENSORED, $this->forum->row($pid)['status']);
        $mails = $this->forum->mails();
        self::assertCount(1, $mails);
        self::assertStringContainsString('abc ███', $mails[0]['message']);
        self::assertStringNotContainsString('zły', $mails[0]['message'] . $this->forum->pms()[0]['message']);
    }

    /**
     * Fail-open: the failure mode publishes the post and notifies once; the verdict that
     * comes later — whatever it says — sends nothing again (and `zablokowane` hides it).
     *
     * @dataProvider lateVerdicts
     * @param array<string,mixed> $fields
     */
    public function testALateVerdictOnAFailOpenPostNeverNotifiesAgain(array $fields, int $visible): void
    {
        $this->forum->set(['minos_failure_mode' => 'fail-open']);
        $pid = $this->reply('abc zły');
        $this->forum->platform()->updatePending($pid, ['accepted_at' => TIME_NOW - 21 * 60]);
        task_minos(['tid' => 1]);
        self::assertSame([1, 1, 1], [(int)$this->forum->post($pid)['visible'], count($this->forum->mails()), count($this->forum->pms())],
            'published by the failure mode, notified once');

        self::assertSame(200, $this->forum->deliver(self::payload($pid, $fields)));

        self::assertSame($visible, (int)$this->forum->post($pid)['visible'], 'the late verdict was applied');
        self::assertSame([1, 1], [count($this->forum->mails()), count($this->forum->pms())], 'nothing sent again');
    }

    /** @return array<string,array{0:array<string,mixed>,1:int}> */
    public function lateVerdicts(): array
    {
        return [
            'bezpieczne'   => [[], 1],
            'ocenzurowane' => [['kwalifikacja' => 'ocenzurowane', 'ocenzurowany' => 'abc ███'], 1],
            'zablokowane'  => [['kwalifikacja' => 'zablokowane'], 0],
        ];
    }

    /**
     * A post the plugin does not publish notifies nobody: blocked, held censored, not
     * assessed under fail-closed, the failure mode's hold after the timeout.
     */
    public function testAPostThatStaysHeldNotifiesNobody(): void
    {
        $this->forum->set(['minos_censored' => 'queue']);
        $blocked = $this->reply('Zablokowana.');
        $censored = $this->reply('abc zły');
        $none = $this->reply('Bez oceny.');
        $late = $this->reply('Po czasie.');
        $this->forum->platform()->updatePending($late, ['accepted_at' => TIME_NOW - 21 * 60]);

        $this->forum->deliver(self::payload($blocked, ['kwalifikacja' => 'zablokowane']));
        $this->forum->deliver(self::payload($censored, ['kwalifikacja' => 'ocenzurowane', 'ocenzurowany' => 'abc ███']));
        $this->forum->deliver(self::payload($none, ['status' => 'nieocenione', 'kwalifikacja' => null]));
        task_minos(['tid' => 1]);

        foreach ([$blocked, $censored, $none, $late] as $pid) {
            self::assertSame(0, (int)$this->forum->post($pid)['visible']);
        }
        self::assertSame([[], []], [$this->forum->mails(), $this->forum->pms()]);
    }

    /**
     * The setting off: the administrator relies on another plugin, and nothing is sent.
     */
    public function testWithTheSettingOffNothingIsSent(): void
    {
        $this->forum->set(['minos_notify_subscribers' => '0']);
        $pid = $this->reply('Bez powiadomień.');
        $this->forum->deliver(self::payload($pid));
        self::assertSame([1, [], []], [(int)$this->forum->post($pid)['visible'], $this->forum->mails(), $this->forum->pms()]);
    }

    /**
     * MyBB's own conditions: a reply in a thread still in the queue notifies nobody; private
     * messages switched off on the board send none; in a forum where users see only their own
     * threads, a subscriber who is no moderator gets nothing; a banned forum subscriber gets
     * nothing.
     */
    public function testMyBBsConditionsHold(): void
    {
        $hidden = (int)$this->forum->write(true, 'Wątek w kolejce.')->return_values['tid'];
        $this->forum->subscribe(self::MAIL, $hidden, 1);
        $inHidden = (int)$this->forum->write(false, 'Odpowiedź w wątku z kolejki.', ['tid' => $hidden])->return_values['pid'];
        $this->forum->deliver(self::payload($inHidden));
        self::assertSame([1, []], [(int)$this->forum->post($inHidden)['visible'], $this->forum->mails()], 'its thread is still held');

        $this->forum->mybb->settings['enablepms'] = '0';
        $GLOBALS['minos_test']['user_permissions'][self::MAIL] = ['canonlyviewownthreads' => 1];
        $pid = $this->reply('Odpowiedź przy wyłączonych PW.');
        $this->forum->deliver(self::payload($pid));
        self::assertSame([[], []], [$this->forum->mails(), $this->forum->pms()]);

        $GLOBALS['minos_test']['moderators'] = [self::MAIL];
        $this->forum->db->update_query('users', ['lastactive' => TIME_NOW + 60], "uid='" . self::MAIL . "'");
        $pid = $this->reply('Odpowiedź dla moderatora.');
        $this->forum->deliver(self::payload($pid));
        $GLOBALS['minos_test']['moderators'] = [];
        self::assertSame(['subskrybent mail@example.com'], array_column($this->forum->mails(), 'mailto'),
            'a moderator sees every thread');

        $this->forum->db->update_query('users', ['usergroup' => 7], "uid='" . self::FORUM . "'");
        $thread = (int)$this->forum->write(true, 'Wątek dla zbanowanego.')->return_values['pid'];
        $this->forum->deliver(self::payload($thread));
        self::assertNotContains('subskrybent działu@example.com', array_column($this->forum->mails(), 'mailto'));
    }

    /**
     * The webhook starts MyBB without a language (`inc/init.php` only): the board's is set,
     * with MyBB's `global` and `messages`, before MyBB's parser and private messages read it.
     * A subscriber's own language is used when the board has it.
     */
    public function testTheWebhookUsesTheBoardsLanguage(): void
    {
        $this->forum->lang->language = '';
        $this->forum->db->update_query('users', ['language' => 'english'], "uid='" . self::MAIL . "'");
        $this->forum->db->update_query('users', ['language' => 'klingonski'], "uid='" . self::PM . "'");
        $pid = $this->reply('Odpowiedź w webhooku.');

        $this->forum->deliver(self::payload($pid));

        self::assertSame('polish', $this->forum->lang->language);
        self::assertContains('global', $this->forum->lang->loaded);
        self::assertContains('messages', $this->forum->lang->loaded);
        self::assertSame([1, 1], [count($this->forum->mails()), count($this->forum->pms())],
            'their own language, or the board\'s when it is not installed');
    }

    /**
     * A notification that throws (another plugin's hook in the private-message handler) is
     * that step's failure alone: the post stays published, the webhook answers 200, and the
     * log names the step — never the error's text.
     */
    public function testANotificationThatThrowsLeavesThePostPublished(): void
    {
        $GLOBALS['minos_test']['pm_throws'] = true;
        $pid = $this->reply('Odpowiedź z błędem powiadomienia.');

        self::assertSame(200, $this->forum->deliver(self::payload($pid)));

        self::assertSame([1, Status::PUBLISHED], [(int)$this->forum->post($pid)['visible'], $this->forum->row($pid)['status']]);
        $entries = array_values(array_filter($this->logEntries(), static function (array $entry): bool {
            return $entry['event'] === 'error';
        }));
        self::assertSame([['notify', (string)$pid]], array_map(static function (array $entry): array {
            return [$entry['code'], (string)$entry['pid']];
        }, $entries));
        self::assertStringNotContainsString(\Moderation::SECRET_TEXT, (string)json_encode($this->logEntries()));
    }

    /**
     * A moderator's approval in MyBB's queue is MyBB's own: the plugin sends nothing for it.
     */
    public function testAModeratorsApprovalIsLeftToMyBB(): void
    {
        $pid = $this->reply('Zatwierdzona przez moderatora.');
        (new \Moderation())->approve_posts([$pid]);
        $this->forum->deliver(self::payload($pid));
        self::assertSame([Status::SUPERSEDED, [], []], [$this->forum->row($pid)['status'], $this->forum->mails(), $this->forum->pms()]);
    }

    private function reply(string $message): int
    {
        return (int)$this->forum->write(false, $message, ['tid' => $this->tid])->return_values['pid'];
    }
}
