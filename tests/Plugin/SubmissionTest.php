<?php

declare(strict_types=1);

namespace Minos\MyBB\Tests\Plugin;

use Minos\MyBB\Status;
use Minos\MyBB\Tests\Support\FakePostHandler;
use Minos\MyBB\Tests\Support\Forum;
use Minos\MyBB\Tests\Support\PluginTestCase;
use Minos\MyBB\Tests\Support\RecordingTransport;

/**
 * A new post, written through MyBB's hooks: held, recorded, sent — and what goes on the
 * wire. Then the gateway's refusals.
 */
final class SubmissionTest extends PluginTestCase
{
    public function testANewReplyIsHeldAndSentOnce(): void
    {
        $pid = $this->heldReply('Dzień dobry wszystkim!');

        self::assertSame(0, (int)$this->forum->post($pid)['visible'], 'the post waits in MyBB\'s queue');
        self::assertSame(Status::PENDING, $this->forum->row($pid)['status']);
        self::assertCount(1, $this->forum->transport->requests);
        $request = $this->forum->transport->requests[0];
        self::assertSame('https://gateway.wergiliusz.app/api/v1/b2b/oceny', $request['url']);
        self::assertContains('X-Gateway-Key: ' . Forum::KEY, $request['headers']);
        self::assertContains('Content-Type: application/json; charset=utf-8', $request['headers']);
        self::assertSame(10, $request['timeout']);
    }

    public function testTheBodyCarriesTheItemAndNothingAboutTheAuthor(): void
    {
        $pid = $this->heldReply('Hej! Zobaczcie https://example.com/x i www.example.org');
        $body = $this->forum->transport->lastBody();

        self::assertSame(['elementy'], array_keys($body));
        self::assertCount(1, $body['elementy']);
        $item = $body['elementy'][0];
        self::assertSame(['id', 'tekst', 'profil', 'meta'], array_keys($item));
        self::assertSame('mybb:' . $pid, $item['id']);
        self::assertMatchesRegularExpression('/^[A-Za-z0-9._:-]{1,64}$/', $item['id'], 'the contract\'s id alphabet');
        self::assertSame('forum_adult', $item['profil']);
        self::assertSame([], array_diff(array_keys($item['meta']), ['links', 'link_domains', 'author_first_post']));
        self::assertSame(2, $item['meta']['links']);
        self::assertSame(['example.com', 'example.org'], $item['meta']['link_domains']);

        $raw = $this->forum->transport->requests[0]['body'];
        foreach (['autor@example.com', '203.0.113.77', 'Autor Testowy', '"uid', '"email', '"ip', '"username', '"' . self::UID . '"'] as $personal) {
            self::assertStringNotContainsString($personal, $raw, "the body must not carry {$personal}");
        }
    }

    public function testTheTextIsWhatReadersOfTheForumSee(): void
    {
        $this->heldReply('Miłego dnia <b TY_OBELGA_WIDOCZNA>');
        self::assertSame('Miłego dnia <b TY_OBELGA_WIDOCZNA>', $this->forum->transport->lastBody()['elementy'][0]['tekst'],
            'HTML is off by default: readers see the brackets and every word');

        $GLOBALS['minos_test']['forums'][Forum::FID] = ['allowhtml' => 1];
        $this->heldReply('Miłego dnia <b TY_OBELGA_WIDOCZNA> <img src="https://example.com/a.png" alt="OBELGA_ALT">');
        self::assertSame('Miłego dnia OBELGA_ALT', $this->forum->transport->lastBody()['elementy'][0]['tekst']);
    }

    public function testAPostThatFailedValidationRaisesNoFlag(): void
    {
        $handler = new FakePostHandler('insert', 'post', ['uid' => self::UID, 'fid' => Forum::FID, 'tid' => 1, 'message' => 'x']);
        $handler->errors = [['error_code' => 'message_too_short']];
        $GLOBALS['plugins']->run_hooks('datahandler_post_validate_post', $handler);
        self::assertSame(0, $this->forum->mybb->user['moderateposts']);
    }

    public function testTheFormShownAgainPutsTheFlagBack(): void
    {
        // A later validate hook (another plugin's) refused the post after this one held it.
        $handler = new FakePostHandler('insert', 'post', ['uid' => self::UID, 'fid' => Forum::FID, 'tid' => 1, 'message' => 'x']);
        $GLOBALS['plugins']->run_hooks('datahandler_post_validate_post', $handler);
        self::assertSame(1, $this->forum->mybb->user['moderateposts']);
        $nothing = '';
        $GLOBALS['plugins']->run_hooks('newreply_start', $nothing);
        self::assertSame(0, $this->forum->mybb->user['moderateposts']);

        $thread = new FakePostHandler('insert', 'thread', ['uid' => self::UID, 'fid' => Forum::FID, 'message' => 'x']);
        $GLOBALS['plugins']->run_hooks('datahandler_post_validate_thread', $thread);
        $GLOBALS['plugins']->run_hooks('newthread_start', $nothing);
        self::assertSame(0, $this->forum->mybb->user['moderateposts']);
    }

    public function testAuthorFirstPostComesFromPostnumAndIsOmittedForGuests(): void
    {
        $this->forum->mybb->user['postnum'] = 0;
        $this->heldReply();
        self::assertTrue($this->forum->transport->lastBody()['elementy'][0]['meta']['author_first_post']);

        $this->forum->mybb->user['postnum'] = 12;
        $this->heldReply();
        self::assertFalse($this->forum->transport->lastBody()['elementy'][0]['meta']['author_first_post']);

        $this->forum->mybb->user = ['uid' => 0, 'moderateposts' => 0];
        $pid = $this->heldReply('Gość pisze.');
        self::assertSame(0, (int)$this->forum->post($pid)['visible'], 'a guest\'s post is held too');
        self::assertArrayNotHasKey('author_first_post', $this->forum->transport->lastBody()['elementy'][0]['meta']);
    }

    /**
     * The audit's probe M8: a resubmission carries the signal the first send did, as it
     * was then — the task has no author to ask.
     */
    public function testAResubmissionCarriesAuthorFirstPostAsFirstSent(): void
    {
        $this->forum->mybb->user['postnum'] = 0;
        $this->forum->plugin(new RecordingTransport([RecordingTransport::refused(503, 'kolejka_niedostepna')]));
        $first = $this->heldReply('Pierwszy post autora.');
        self::assertTrue($this->forum->transport->lastBody()['elementy'][0]['meta']['author_first_post']);
        $this->forum->mybb->user = ['uid' => 0, 'moderateposts' => 0];
        $guest = $this->heldReply('Post gościa.');
        foreach ([$first, $guest] as $pid) {
            $this->forum->platform()->updatePending($pid, ['retry_at' => TIME_NOW - 1]);
        }
        $this->forum->mybb->user = ['uid' => 99, 'postnum' => 50, 'moderateposts' => 0];
        $this->forum->plugin(new RecordingTransport());

        task_minos(['tid' => 1]);

        $meta = array_column($this->forum->transport->lastBody()['elementy'], 'meta', 'id');
        self::assertTrue($meta['mybb:' . $first]['author_first_post'], 'as first sent, not the user the task runs for');
        self::assertArrayNotHasKey('author_first_post', $meta['mybb:' . $guest], 'unknown for a guest');
    }

    public function testTheTextIsPlainAndCutAtThreeThousandCharacters(): void
    {
        $pid = $this->heldReply('[b]' . str_repeat('ą', 3200) . '[/b]');
        $text = $this->forum->transport->lastBody()['elementy'][0]['tekst'];
        self::assertSame(3000, mb_strlen($text, 'UTF-8'));
        self::assertStringNotContainsString('[b]', $text);
        self::assertSame('1', (string)$this->forum->row($pid)['truncated']);
    }

    public function testANewThreadSendsItsSubjectAndIsRecordedAsAThread(): void
    {
        $handler = $this->forum->write(true, 'Treść pierwszego postu.', ['subject' => 'Mój wątek']);
        $row = $this->forum->row((int)$handler->return_values['pid']);

        self::assertSame("Mój wątek\n\nTreść pierwszego postu.", $this->forum->transport->lastBody()['elementy'][0]['tekst']);
        self::assertSame('1', (string)$row['is_thread']);
        self::assertSame((string)$handler->return_values['tid'], (string)$row['tid']);
    }

    public function testTheHooksHandTheHandlerBackUnchanged(): void
    {
        $handler = new FakePostHandler('insert', 'post', ['uid' => self::UID, 'fid' => Forum::FID, 'tid' => 1, 'message' => 'x']);
        $same = $handler;
        $GLOBALS['plugins']->run_hooks('datahandler_post_validate_post', $handler);
        self::assertSame($same, $handler, 'a truthy return value would have replaced MyBB\'s datahandler');
    }

    public function testTheUsersModerationFlagIsRestoredAfterTheInsert(): void
    {
        $this->heldReply();
        self::assertSame(0, $this->forum->mybb->user['moderateposts']);
    }

    /**
     * @dataProvider untouched
     * @param callable(Forum):array<string,mixed> $arrange Returns the write options.
     */
    public function testPostsThePluginLeavesAlone(callable $arrange, int $visible): void
    {
        $options = $arrange($this->forum);
        $handler = $this->forum->write(false, 'Treść.', $options);

        self::assertSame($visible, (int)$handler->return_values['visible']);
        self::assertSame([], $this->forum->transport->requests, 'nothing is sent');
        self::assertNull($this->forum->row((int)$handler->return_values['pid']), 'nothing is recorded');
    }

    /** @return array<string,array{0:callable,1:int}> */
    public function untouched(): array
    {
        return [
            'a draft' => [static function (): array {
                return ['savedraft' => 1];
            }, -2],
            'the plugin switched off' => [static function (Forum $forum): array {
                $forum->set(['minos_enabled' => '0']);
                return [];
            }, 1],
            'another forum' => [static function (Forum $forum): array {
                $forum->set(['minos_forums' => '5,6']);
                return [];
            }, 1],
            'a moderator' => [static function (): array {
                $GLOBALS['minos_test']['moderators'] = [self::UID];
                return [];
            }, 1],
            'a forum that moderates replies itself' => [static function (): array {
                $GLOBALS['minos_test']['forum_permissions'][Forum::FID] = ['modposts' => 1];
                return [];
            }, 0],
            'a user under moderation' => [static function (Forum $forum): array {
                $forum->mybb->user['moderateposts'] = 1;
                return [];
            }, 0],
            'written on someone else\'s behalf' => [static function (): array {
                return ['uid' => 99];
            }, 1],
        ];
    }

    /**
     * Switched on but unable to submit: every new post gets the failure mode at once, with
     * nothing sent (the audit's probe C4: a key with an inner space used to switch
     * holding off, publishing every post unassessed even under fail-closed).
     *
     * @dataProvider unusableSettings
     * @param array<string,string> $settings
     */
    public function testSwitchedOnButUnableToSubmitEveryNewPostGetsTheFailureMode(array $settings): void
    {
        $this->forum->set($settings);
        $held = $this->heldReply('Post przy niekompletnych ustawieniach.');
        $this->forum->set(['minos_failure_mode' => 'fail-open']);
        $published = (int)$this->forum->write(true, 'Wątek przy niekompletnych ustawieniach.')->return_values['pid'];

        self::assertSame([], $this->forum->transport->requests, 'nothing can be sent');
        self::assertSame([0, Status::HELD, Status::REASON_NOT_CONFIGURED],
            [(int)$this->forum->post($held)['visible'], $this->forum->row($held)['status'], $this->forum->row($held)['verdict']]);
        self::assertSame([1, Status::PUBLISHED, Status::REASON_NOT_CONFIGURED],
            [(int)$this->forum->post($published)['visible'], $this->forum->row($published)['status'], $this->forum->row($published)['verdict']]);
        self::assertNotNull($this->forum->plugin->admin->notice(), 'every ACP page says why');
    }

    /** @return array<string,array{0:array<string,string>}> */
    public function unusableSettings(): array
    {
        return [
            'no key'                     => [['minos_api_key' => '']],
            'a key with an inner space'  => [['minos_api_key' => 'wgb2b_test_forum key_000000000000']],
            'a key without its prefix'   => [['minos_api_key' => 'test_forum_key_000000000000']],
            'no secret'                  => [['minos_webhook_secret' => '']],
            'a gateway address not HTTPS' => [['minos_gateway_url' => 'http://gateway.wergiliusz.app']],
            'an unknown profile'         => [['minos_profile' => 'demo_all']],
        ];
    }

    public function testModeratorsAreHeldWhenTheAdministratorSaysSo(): void
    {
        $GLOBALS['minos_test']['moderators'] = [self::UID];
        $this->forum->set(['minos_skip_moderators' => '0']);
        $pid = $this->heldReply();
        self::assertSame(0, (int)$this->forum->post($pid)['visible']);
    }

    public function testAPostWithNoTextGetsTheFailureModeWithoutARequest(): void
    {
        $held = $this->heldReply('[attachment=4]');
        self::assertSame([], $this->forum->transport->requests);
        self::assertSame([Status::HELD, Status::REASON_NO_TEXT], [$this->forum->row($held)['status'], $this->forum->row($held)['verdict']]);

        $this->forum->set(['minos_failure_mode' => 'fail-open']);
        $published = $this->heldReply('[attachment=5]');
        self::assertSame(1, (int)$this->forum->post($published)['visible']);
    }

    /**
     * A post whose text PCRE cannot read (the audit's probe, unclosed `[url=` tags) gets the
     * failure mode unsent: never `no_text`, and a new thread never goes out with its subject
     * alone — which a `bezpieczne` would then have published even under fail-closed.
     */
    public function testAnUnreadableReplyIsHeldUnsentUnderFailClosed(): void
    {
        $pid = $this->heldReply(self::bomb());

        self::assertSame([], $this->forum->transport->requests);
        $row = $this->forum->row($pid);
        self::assertSame([Status::HELD, Status::REASON_TEXT_ERROR], [$row['status'], $row['verdict']]);
        self::assertSame(0, (int)$this->forum->post($pid)['visible']);
        self::assertSame([['text_error', $pid]], array_map(static function (array $entry): array {
            return [$entry['event'], (int)$entry['pid']];
        }, $this->logEntries()));
        self::assertSame('nie udało się odczytać tekstu postu', $this->forum->platform()->lang('minos_reason_' . $row['verdict']));
    }

    public function testAnUnreadableNewThreadIsHeldWithoutSendingItsSubject(): void
    {
        $pid = (int)$this->forum->write(true, self::bomb(), ['subject' => 'Niewinny tytuł'])->return_values['pid'];

        self::assertSame([], $this->forum->transport->requests, 'not even the subject alone');
        self::assertSame([Status::HELD, Status::REASON_TEXT_ERROR], [$this->forum->row($pid)['status'], $this->forum->row($pid)['verdict']]);
        self::assertSame(0, (int)$this->forum->post($pid)['visible']);
        self::assertSame(200, $this->forum->deliver(self::payload($pid)), 'a verdict for it changes nothing');
        self::assertSame(0, (int)$this->forum->post($pid)['visible']);
    }

    public function testAnUnreadablePostIsPublishedUnassessedUnderFailOpen(): void
    {
        $this->forum->set(['minos_failure_mode' => 'fail-open']);
        $pid = (int)$this->forum->write(true, self::bomb(), ['subject' => 'Niewinny tytuł'])->return_values['pid'];

        self::assertSame([], $this->forum->transport->requests);
        $row = $this->forum->row($pid);
        self::assertSame([Status::PUBLISHED, Status::REASON_TEXT_ERROR, '1'], [$row['status'], $row['verdict'], (string)$row['auto_published']]);
        self::assertSame(1, (int)$this->forum->post($pid)['visible']);
    }

    public function testAResendOfAnUnreadablePostSendsNothing(): void
    {
        $this->forum->plugin(new RecordingTransport([RecordingTransport::refused(503, 'kolejka_niedostepna')]));
        $pid = $this->heldReply('Zwykły tekst.');
        $this->forum->db->update_query('posts', ['message' => self::bomb()], "pid='" . $pid . "'");
        $this->forum->platform()->updatePending($pid, ['retry_at' => TIME_NOW - 1]);
        $this->forum->plugin(new RecordingTransport());

        task_minos(['tid' => 1]);

        self::assertSame([], $this->forum->transport->requests);
        self::assertSame([Status::HELD, Status::REASON_TEXT_ERROR], [$this->forum->row($pid)['status'], $this->forum->row($pid)['verdict']]);
    }

    /**
     * The audit's PCRE probe: visible words, then 340 unclosed `[url=` tags (about 6 KB).
     */
    private static function bomb(): string
    {
        return 'Widoczne SLOWO ' . str_repeat('[url=http://a.pl]x', 340);
    }

    public function testA429KeepsThePostPendingUntilPonowZaS(): void
    {
        $this->forum->plugin(new RecordingTransport([RecordingTransport::refused(429, 'limit_minutowy_klucza', 120)]));
        $pid = $this->heldReply();
        $row = $this->forum->row($pid);

        self::assertSame(Status::RETRY, $row['status']);
        self::assertSame(TIME_NOW + 120, (int)$row['retry_at']);
        self::assertSame('limit_minutowy_klucza', $row['error_code']);
        self::assertSame(0, (int)$this->forum->post($pid)['visible']);
        self::assertSame([], self::moderation(), 'a retry is not a verdict');
        self::assertSame(['retry', 'limit_minutowy_klucza'], [$this->logEntries()[0]['event'], $this->logEntries()[0]['code']]);
    }

    /**
     * The audit's probe M4: the key's daily limit, `ponow_za_s` = 10 hours. The post cannot
     * be assessed within its 20 minutes, so it gets the failure mode at once (rule D9.3a)
     * instead of waiting, and every ACP page says until when the gateway refuses.
     *
     * @dataProvider failureModes
     */
    public function testTheDailyLimitAppliesTheFailureModeAtOnceAndSaysUntilWhen(string $mode, int $visible, string $status): void
    {
        $this->forum->set(['minos_failure_mode' => $mode]);
        $this->forum->plugin(new RecordingTransport([RecordingTransport::refused(429, 'limit_dobowy_klucza', 36000)]));
        $pid = $this->heldReply('Zwykły post po wyczerpaniu limitu.');

        self::assertCount(1, $this->forum->transport->requests);
        self::assertSame([$visible, $status, Status::REASON_GATEWAY_LIMIT, 'limit_dobowy_klucza'],
            [(int)$this->forum->post($pid)['visible'], $this->forum->row($pid)['status'], $this->forum->row($pid)['verdict'],
                $this->forum->row($pid)['error_code']]);
        $this->forum->platform()->updatePending($pid, ['submitted_at' => TIME_NOW - 19 * 60]);
        task_minos(['tid' => 1]);
        self::assertSame($status, $this->forum->row($pid)['status'], 'not waiting as retry after 19 minutes');

        $notice = (string)$this->forum->plugin->admin->notice(TIME_NOW);
        self::assertSame($this->refusalNotice('minos_notice_daily_limit', TIME_NOW + 36000, 'limit_dobowy_klucza'), $notice,
            'the daily limit\'s message');
        self::assertStringContainsString(date('Y-m-d H:i', TIME_NOW + 36000), $notice, 'the hour the gateway accepts again');
        foreach ([Forum::KEY, Forum::SECRET, 'komunikat', '{'] as $never) {
            self::assertStringNotContainsString($never, $notice, 'never the key or the answer\'s body');
        }
    }

    /** @return array<string,array{0:string,1:int,2:string}> */
    public function failureModes(): array
    {
        return [
            'fail-closed' => ['fail-closed', 0, Status::HELD],
            'fail-open'   => ['fail-open', 1, Status::PUBLISHED],
        ];
    }

    public function testTheFirstAcceptedPostClearsTheNotice(): void
    {
        $this->forum->plugin(new RecordingTransport([RecordingTransport::refused(429, 'limit_dobowy_klucza', 36000), RecordingTransport::accepted()]));
        $this->heldReply();
        self::assertNotNull($this->forum->plugin->admin->notice(TIME_NOW));

        $accepted = $this->heldReply();

        self::assertSame(Status::PENDING, $this->forum->row($accepted)['status']);
        self::assertNull($this->forum->plugin->admin->notice(TIME_NOW));
    }

    public function testTheNoticeEndsWhenTheRefusalDoes(): void
    {
        $this->forum->plugin(new RecordingTransport([RecordingTransport::refused(429, 'limit_dobowy_klucza', 36000)]));
        $this->heldReply();
        self::assertNull($this->forum->plugin->admin->notice(TIME_NOW + 36001), 'past the hour it named, it says nothing');
    }

    public function testAnOverloadShorterThanTheTimeoutIsRetriedAndShown(): void
    {
        $this->forum->plugin(new RecordingTransport([RecordingTransport::refused(429, 'kolejka_pelna', 60)]));
        $pid = $this->heldReply();

        self::assertSame([Status::RETRY, TIME_NOW + 60], [$this->forum->row($pid)['status'], (int)$this->forum->row($pid)['retry_at']]);
        self::assertSame($this->refusalNotice('minos_notice_overloaded', TIME_NOW + 60, 'kolejka_pelna'),
            $this->forum->plugin->admin->notice(TIME_NOW), 'the overload\'s message, not the daily limit\'s');
    }

    /**
     * The ACP notice of a refusal as the language file words it, escaped as the ACP shows it.
     */
    private function refusalNotice(string $key, int $until, string $code): string
    {
        return htmlspecialchars($this->forum->platform()->lang($key, date('Y-m-d H:i', $until), $code), ENT_QUOTES, 'UTF-8');
    }

    public function testAnOverloadLongerThanTheTimeoutAppliesTheFailureModeAtOnce(): void
    {
        $this->forum->plugin(new RecordingTransport([RecordingTransport::refused(429, 'limit_globalny_b2b', 3600)]));
        $pid = $this->heldReply();
        self::assertSame([Status::HELD, Status::REASON_GATEWAY_LIMIT], [$this->forum->row($pid)['status'], $this->forum->row($pid)['verdict']]);
        self::assertStringContainsString('limit_globalny_b2b', (string)$this->forum->plugin->admin->notice(TIME_NOW));
    }

    public function testTheTimeoutCountsFromTheFirstSubmission(): void
    {
        // Sent 18 minutes ago, refused now for 3 minutes: past its 20 minutes.
        $this->forum->plugin(new RecordingTransport([RecordingTransport::refused(503, 'kolejka_niedostepna')]));
        $late = $this->heldReply();
        $early = $this->heldReply();
        $this->forum->platform()->updatePending($late, ['submitted_at' => TIME_NOW - 18 * 60, 'retry_at' => TIME_NOW - 1]);
        $this->forum->platform()->updatePending($early, ['submitted_at' => TIME_NOW - 60, 'retry_at' => TIME_NOW - 1]);
        $this->forum->plugin(new RecordingTransport([RecordingTransport::refused(429, 'limit_minutowy_klucza', 180)]));

        task_minos(['tid' => 1]);

        self::assertSame([Status::HELD, Status::REASON_GATEWAY_LIMIT], [$this->forum->row($late)['status'], $this->forum->row($late)['verdict']]);
        self::assertSame([Status::RETRY, TIME_NOW + 180], [$this->forum->row($early)['status'], (int)$this->forum->row($early)['retry_at']]);
    }

    /**
     * The audit's probe C6: a `200` from something in front of the gateway (a hosting page)
     * accepted nothing — a configuration error, shown, not 20 minutes of silent waiting.
     */
    public function testA200IsNoAcceptance(): void
    {
        $this->forum->plugin(new RecordingTransport([['status' => 200, 'body' => '<html>portal hostingu</html>']]));
        $pid = $this->heldReply();
        self::assertSame([Status::HELD, Status::REASON_CONFIG_ERROR, 'http_200'],
            [$this->forum->row($pid)['status'], $this->forum->row($pid)['verdict'], $this->forum->row($pid)['error_code']]);
        $notice = (string)$this->forum->plugin->admin->notice();
        self::assertStringContainsString('http_200', $notice);
        self::assertStringNotContainsString('portal', $notice, 'never the body');
    }

    public function testA408IsRetriedAsANetworkFailure(): void
    {
        $this->forum->plugin(new RecordingTransport([['status' => 408, 'body' => '']]));
        $pid = $this->heldReply();
        self::assertSame([Status::RETRY, 'http_408', 0], [$this->forum->row($pid)['status'], $this->forum->row($pid)['error_code'],
            (int)$this->forum->post($pid)['visible']]);
        self::assertNull($this->forum->plugin->admin->notice(), 'no configuration error');
    }

    public function testA503OrNoAnswerRetriesAfterABackoff(): void
    {
        $this->forum->plugin(new RecordingTransport([RecordingTransport::refused(503, 'kolejka_niedostepna')]));
        $pid = $this->heldReply();
        self::assertSame([Status::RETRY, TIME_NOW + 60], [$this->forum->row($pid)['status'], (int)$this->forum->row($pid)['retry_at']]);

        $this->forum->plugin(new RecordingTransport([['status' => 0, 'body' => '']]));
        $pid = $this->heldReply();
        self::assertSame([Status::RETRY, 'siec'], [$this->forum->row($pid)['status'], $this->forum->row($pid)['error_code']]);
    }

    public function testAConfigurationErrorAppliesTheFailureModeAndIsShownToTheAdministrator(): void
    {
        $this->forum->plugin(new RecordingTransport([RecordingTransport::refused(403, 'brak_webhooka')]));
        $held = $this->heldReply('Tekst, którego nie ma w dzienniku.');
        self::assertSame([Status::HELD, Status::REASON_CONFIG_ERROR, 'brak_webhooka'],
            [$this->forum->row($held)['status'], $this->forum->row($held)['verdict'], $this->forum->row($held)['error_code']]);
        self::assertSame(0, (int)$this->forum->post($held)['visible']);
        self::assertStringContainsString('brak_webhooka', (string)$this->forum->plugin->admin->notice());

        $this->forum->set(['minos_failure_mode' => 'fail-open']);
        $published = $this->heldReply();
        self::assertSame(1, (int)$this->forum->post($published)['visible']);

        // Nothing but codes reached the plugin's tables: no content, no key, no secret.
        $stored = json_encode([$this->logEntries(), $this->forum->row($held)]);
        foreach ([Forum::KEY, Forum::SECRET, 'Tekst, którego'] as $secret) {
            self::assertStringNotContainsString($secret, (string)$stored);
        }
    }

    public function testASuccessAfterAConfigurationErrorClearsTheNotice(): void
    {
        $this->forum->plugin(new RecordingTransport([RecordingTransport::refused(401, 'brak_klucza'), RecordingTransport::accepted()]));
        $this->heldReply();
        self::assertNotNull($this->forum->plugin->admin->notice());
        $this->heldReply();
        self::assertNull($this->forum->plugin->admin->notice());
        self::assertSame(['config_error', 'recovered'], array_column($this->logEntries(), 'event'));
    }
}
