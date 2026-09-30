<?php

declare(strict_types=1);

namespace Minos\MyBB\Tests\Plugin;

use Minos\MyBB\Settings;
use PHPUnit\Framework\TestCase;

/**
 * Settings that decide publish or hold are read fail-closed; unusable ones stop holding.
 */
final class SettingsTest extends TestCase
{
    private const WORKING = [
        'minos_enabled' => '1', 'minos_gateway_url' => 'https://gateway.wergiliusz.app',
        'minos_api_key' => 'wgb2b_abcdefgh12345678', 'minos_webhook_secret' => 'sekret', 'minos_profile' => 'forum_adult',
    ];

    public function testAWorkingConfigurationIsActive(): void
    {
        self::assertTrue((new Settings(self::WORKING))->active());
        self::assertSame([], (new Settings(self::WORKING))->problems());
    }

    /**
     * @dataProvider unusable
     */
    public function testAnUnusableConfigurationDoesNotHold(string $name, string $value): void
    {
        $settings = new Settings([$name => $value] + self::WORKING);
        self::assertFalse($settings->active());
        self::assertNotSame([], $settings->problems());
    }

    /** @return array<string,array{0:string,1:string}> */
    public function unusable(): array
    {
        return [
            'plain http to another host' => ['minos_gateway_url', 'http://gateway.wergiliusz.app'],
            'credentials in the address' => ['minos_gateway_url', 'https://user:pass@gateway.example'],
            'not an address'             => ['minos_gateway_url', 'gateway'],
            'no key'                     => ['minos_api_key', ''],
            'a key without its prefix'   => ['minos_api_key', 'abcdefgh'],
            'a key with a line break'    => ['minos_api_key', "wgb2b_abc\r\nX-Evil: 1"],
            'no secret'                  => ['minos_webhook_secret', '  '],
            'an unknown profile'         => ['minos_profile', 'demo_all'],
        ];
    }

    public function testTheMockGatewayOnThisMachineIsAllowedOverHttp(): void
    {
        $settings = new Settings(['minos_gateway_url' => 'http://127.0.0.1:8100/'] + self::WORKING);
        self::assertSame('http://127.0.0.1:8100', $settings->gatewayUrl());
    }

    public function testSettingsThatDecidePublishingAreReadFailClosed(): void
    {
        $unknown = new Settings(['minos_failure_mode' => 'otworz', 'minos_censored' => 'cokolwiek', 'minos_blocked' => '?'] + self::WORKING);
        self::assertFalse($unknown->failOpen());
        self::assertFalse($unknown->publishCensored());
        self::assertFalse($unknown->softDeleteBlocked());

        $chosen = new Settings(['minos_failure_mode' => 'fail-open', 'minos_censored' => 'publish', 'minos_blocked' => 'soft-delete'] + self::WORKING);
        self::assertTrue($chosen->failOpen());
        self::assertTrue($chosen->publishCensored());
        self::assertTrue($chosen->softDeleteBlocked());
    }

    public function testTheTimeoutIsAtLeastTwentyMinutes(): void
    {
        self::assertSame(20 * 60, (new Settings(self::WORKING))->timeoutS());
        self::assertSame(20 * 60, (new Settings(['minos_timeout' => 'x'] + self::WORKING))->timeoutS());
        self::assertSame(20 * 60, (new Settings(['minos_timeout' => '1'] + self::WORKING))->timeoutS(), 'shorter than the gateway\'s TTL');
        self::assertSame(45 * 60, (new Settings(['minos_timeout' => '45'] + self::WORKING))->timeoutS());
        self::assertSame(1440 * 60, (new Settings(['minos_timeout' => '99999'] + self::WORKING))->timeoutS());
    }

    public function testTheForumSelection(): void
    {
        self::assertTrue((new Settings(['minos_forums' => '-1']))->appliesToForum(9));
        self::assertFalse((new Settings(['minos_forums' => '']))->appliesToForum(9));
        self::assertTrue((new Settings(['minos_forums' => '2,9']))->appliesToForum(9));
        self::assertFalse((new Settings(['minos_forums' => '2,19']))->appliesToForum(9));
    }

    public function testAPrefixNeverShowsMoreThanFourCharactersOfTheSecretPart(): void
    {
        $key = 'wgb2b_abcdefgh12345678';
        $prefix = Settings::prefixOf($key);
        self::assertStringStartsWith('wgb2b_', $prefix);
        self::assertLessThanOrEqual(strlen('wgb2b_') + 4, strlen(rtrim($prefix, '…')));
        self::assertStringNotContainsString('efgh', $prefix);
        self::assertSame('…', Settings::prefixOf('krotki'), 'a short secret shows nothing of itself');
        self::assertSame('', Settings::prefixOf(''));
    }

    public function testTheWebhookAddressIsInTheForumRoot(): void
    {
        self::assertSame('https://forum.example/minos-webhook.php', Settings::webhookUrl('https://forum.example/'));
    }
}
