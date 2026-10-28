<?php

declare(strict_types=1);

namespace Minos\MyBB\Tests\Plugin;

use Minos\Client\Signature;
use Minos\MyBB\Installer;
use Minos\MyBB\Receiver;
use Minos\MyBB\Tests\Support\PluginTestCase;

/**
 * The trail of refused signatures (shared rule R19 of the audit, K-60): the webhook answers a
 * bare `401` as before, counts the refusal with `Signature::check`'s reason only, and the
 * ACP notice tells the administrator what the reason points at — on the gateway's own
 * signature vector, read from the bundled client (never regenerated).
 */
final class SignatureTraceTest extends PluginTestCase
{
    /** @var array{secret:string,timestamp:int,body:string,header:string} */
    private $vector;

    protected function setUp(): void
    {
        parent::setUp();
        $file = __DIR__ . '/../../vendor/minos-moderation/client-php/vectors/signature.json';
        $this->vector = json_decode((string)file_get_contents($file), true);
        self::assertIsArray($this->vector, 'the gateway\'s signature vector is readable');
        $this->forum->set(['minos_webhook_secret' => $this->vector['secret']]);
    }

    public function testTheVectorPassesAndLeavesNoTrail(): void
    {
        self::assertSame(200, $this->receive($this->vector['header'], $this->vector['body'], $this->vector['timestamp']),
            'the vector is a valid delivery (its id is not one this plugin sends: 200, nothing applied)');
        self::assertNull(Receiver::rejections($this->forum->platform()));
        self::assertNull($this->forum->plugin->admin->notice($this->vector['timestamp']));
    }

    public function testAClockMoreThanFiveMinutesOffIsCountedAsStaleAndNamedAsTheClock(): void
    {
        $now = $this->vector['timestamp'] + 301;
        self::assertSame(401, $this->receive($this->vector['header'], $this->vector['body'], $now));
        $trail = Receiver::rejections($this->forum->platform());
        self::assertSame(['count' => 1, 'reason' => Signature::STALE, 'at' => $now], $trail);
        $notice = (string)$this->forum->plugin->admin->notice($now);
        self::assertStringContainsString($this->forum->platform()->lang('minos_signature_stale'), html_entity_decode($notice, ENT_QUOTES, 'UTF-8'));
        self::assertStringNotContainsString($this->forum->platform()->lang('minos_signature_mismatch'), html_entity_decode($notice, ENT_QUOTES, 'UTF-8'));
    }

    public function testABodyOneByteLongerIsAMismatchNamedAsTheSecret(): void
    {
        $now = $this->vector['timestamp'];
        self::assertSame(401, $this->receive($this->vector['header'], $this->vector['body'] . ' ', $now));
        self::assertSame(Signature::MISMATCH, Receiver::rejections($this->forum->platform())['reason']);
        $notice = html_entity_decode((string)$this->forum->plugin->admin->notice($now), ENT_QUOTES, 'UTF-8');
        self::assertStringContainsString($this->forum->platform()->lang('minos_signature_mismatch'), $notice);
    }

    public function testAWrongStoredSecretIsAMismatchToo(): void
    {
        $this->forum->set(['minos_webhook_secret' => 'inny-sekret-tylko-do-testow-0123456789']);
        self::assertSame(401, $this->receive($this->vector['header'], $this->vector['body'], $this->vector['timestamp']));
        self::assertSame(Signature::MISMATCH, Receiver::rejections($this->forum->platform())['reason']);
    }

    public function testAMissingOrForeignHeaderIsMalformed(): void
    {
        $now = $this->vector['timestamp'];
        self::assertSame(401, $this->forum->plugin->receiver->handle('POST', null, $this->vector['body'], $now));
        self::assertSame(401, $this->receive('sha256=' . str_repeat('a', 64), $this->vector['body'], $now));
        self::assertSame(['count' => 2, 'reason' => Signature::MALFORMED, 'at' => $now], Receiver::rejections($this->forum->platform()),
            'every refusal is counted, the last reason kept');
        $notice = html_entity_decode((string)$this->forum->plugin->admin->notice($now), ENT_QUOTES, 'UTF-8');
        self::assertStringContainsString($this->forum->platform()->lang('minos_signature_malformed'), $notice);
        self::assertStringContainsString('2', $notice);
    }

    public function testTheTrailHoldsNeitherTheSecretNorTheBodyNorTheHeader(): void
    {
        $now = $this->vector['timestamp'] + 301;
        $this->receive($this->vector['header'], $this->vector['body'], $now);
        $this->receive($this->vector['header'], $this->vector['body'] . ' ', $this->vector['timestamp']);
        $kept = json_encode($this->forum->cache->stored(), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)
            . (string)$this->forum->plugin->admin->notice($now)
            . json_encode($this->logEntries(), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        self::assertStringContainsString(Receiver::REJECTED, $kept, 'the guard: the trail was written');
        foreach ([$this->vector['secret'], substr($this->vector['header'], -64), 'ocenzurowane', 'k-1'] as $secret) {
            self::assertStringNotContainsString($secret, $kept);
        }
    }

    public function testAVerifiedDeliveryClearsTheTrail(): void
    {
        $this->receive($this->vector['header'], $this->vector['body'], $this->vector['timestamp'] + 301);
        self::assertNotNull(Receiver::rejections($this->forum->platform()));
        self::assertSame(200, $this->receive($this->vector['header'], $this->vector['body'], $this->vector['timestamp']));
        self::assertNull(Receiver::rejections($this->forum->platform()));
        self::assertNull($this->forum->plugin->admin->notice($this->vector['timestamp']));
    }

    public function testANewlySavedSecretClearsTheTrailAndAnotherSettingDoesNot(): void
    {
        $this->receive($this->vector['header'], $this->vector['body'], $this->vector['timestamp'] + 301);
        $this->forum->mybb->request_method = 'post';
        $this->forum->mybb->input = ['upsetting' => [], Installer::SECRET_INPUT => ['minos_api_key' => 'wgb2b_inny_klucz_1234567890', 'minos_webhook_secret' => '']];
        minos_hook_settings_change();
        self::assertNotNull(Receiver::rejections($this->forum->platform()), 'a new key says nothing about the secret');
        $this->forum->mybb->input = ['upsetting' => [], Installer::SECRET_INPUT => ['minos_webhook_secret' => 'nowy-sekret-tylko-do-testow-0123456789']];
        minos_hook_settings_change();
        self::assertNull(Receiver::rejections($this->forum->platform()));
    }

    public function testASwitchedOffPluginCountsNothing(): void
    {
        $this->forum->set(['minos_enabled' => '0']);
        self::assertSame(404, $this->receive($this->vector['header'], $this->vector['body'] . ' ', $this->vector['timestamp']));
        self::assertNull(Receiver::rejections($this->forum->platform()));
    }

    public function testTheTrailIsRemovedOnUninstall(): void
    {
        $this->receive($this->vector['header'], $this->vector['body'], $this->vector['timestamp'] + 301);
        $this->forum->mybb->request_method = 'post';
        $this->forum->mybb->input = ['no' => 1];
        $this->forum->plugin->installer->uninstall();
        self::assertArrayNotHasKey('minos_' . Receiver::REJECTED, $this->forum->cache->stored());
    }

    private function receive(string $header, string $body, int $now): int
    {
        return $this->forum->plugin->receiver->handle('POST', $header, $body, $now);
    }
}
