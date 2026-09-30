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
            'no key' => [static function (Forum $forum): array {
                $forum->set(['minos_api_key' => '']);
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

    public function testAnEditBeforeTheVerdictLeavesThePostToAHuman(): void
    {
        $pid = $this->heldReply('Pierwsza wersja.');
        $edit = new FakePostHandler('update', 'post', ['pid' => $pid, 'message' => 'Druga wersja.']);
        $GLOBALS['plugins']->run_hooks('datahandler_post_update', $edit);

        self::assertSame([Status::SUPERSEDED, Status::REASON_EDITED], [$this->forum->row($pid)['status'], $this->forum->row($pid)['verdict']]);
        self::assertSame(200, $this->forum->deliver(self::payload($pid)));
        self::assertSame(0, (int)$this->forum->post($pid)['visible'], 'the stale verdict is not applied');
        self::assertSame([], self::moderation());
    }
}
