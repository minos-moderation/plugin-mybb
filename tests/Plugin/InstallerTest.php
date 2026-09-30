<?php

declare(strict_types=1);

namespace Minos\MyBB\Tests\Plugin;

use Minos\MyBB\Installer;
use Minos\MyBB\Settings;
use Minos\MyBB\Tests\Support\Forum;
use Minos\MyBB\Tests\Support\PageEnded;
use PHPUnit\Framework\TestCase;

/**
 * The lifecycle functions MyBB's plugin list calls, from `inc/plugins/minos.php`.
 */
final class InstallerTest extends TestCase
{
    /** @var Forum */
    private $forum;

    protected function setUp(): void
    {
        $this->forum = Forum::create();
        $this->forum->plugin();
    }

    public function testInfoNamesThePluginAndTheWebhookAddress(): void
    {
        $info = minos_info();
        self::assertSame(['Minos — moderacja postów', 'minos', '18*'], [$info['name'], $info['codename'], $info['compatibility']]);
        self::assertStringContainsString('https://forum.example/minos-webhook.php', $info['description']);
    }

    public function testInstallCreatesTablesSettingsAndASwitchedOffTask(): void
    {
        self::assertFalse(minos_is_installed());
        minos_install();

        self::assertTrue(minos_is_installed());
        self::assertTrue($this->forum->db->table_exists('minos_pending'));
        self::assertTrue($this->forum->db->table_exists('minos_log'));
        self::assertSame([
            'minos_enabled' => '1', 'minos_gateway_url' => 'https://gateway.wergiliusz.app', 'minos_api_key' => '',
            'minos_webhook_secret' => '', 'minos_profile' => 'forum_adult', 'minos_failure_mode' => 'fail-closed',
            'minos_timeout' => '20', 'minos_censored' => 'publish', 'minos_blocked' => 'queue', 'minos_forums' => '-1',
            'minos_skip_moderators' => '1',
        ], $this->settings('value'));
        foreach ($this->settings('title') as $name => $title) {
            self::assertNotSame('', $title, $name);
            self::assertStringNotContainsString('minos_setting_', $title, "{$name}: a language key instead of a title");
        }
        $task = $this->task();
        self::assertSame(['minos', '0'], [$task['file'], (string)$task['enabled']]);
        self::assertCount(12, explode(',', $task['minute']), 'every five minutes');
    }

    public function testActivationSwitchesTheTaskOnAndKeepsStoredValues(): void
    {
        minos_install();
        $this->forum->set(['minos_api_key' => Forum::KEY, 'minos_profile' => 'forum_teen']);
        minos_activate();
        self::assertSame('1', (string)$this->task()['enabled']);
        self::assertSame([Forum::KEY, 'forum_teen'],
            [$this->settings('value')['minos_api_key'], $this->settings('value')['minos_profile']]);
        minos_deactivate();
        self::assertSame('0', (string)$this->task()['enabled']);
    }

    public function testASecretSettingRendersAnEmptyFieldOutsideTheSavedInputs(): void
    {
        minos_install();
        $this->forum->set(['minos_api_key' => Forum::KEY]);
        $optionscode = $this->settings('optionscode')['minos_api_key'];

        // What MyBB 1.8.41's settings page does with a `php` setting (config/settings.php).
        self::assertStringStartsWith('php', $optionscode);
        $setting_code = '';
        eval("\$setting_code = \"" . substr($optionscode, 3) . "\";");

        self::assertStringContainsString('type="password"', $setting_code);
        self::assertStringContainsString('value=""', $setting_code);
        self::assertStringContainsString('name="' . Installer::SECRET_INPUT . '[minos_api_key]"', $setting_code);
        self::assertStringNotContainsString('upsetting', $setting_code, 'MyBB must never save the empty field by itself');
        self::assertStringNotContainsString(Forum::KEY, $setting_code);
    }

    public function testUninstallAsksFirstThenRemovesEverything(): void
    {
        minos_install();
        try {
            minos_uninstall();
            self::fail('the first request must ask');
        } catch (PageEnded $asked) {
            self::assertCount(1, $this->forum->page->confirms);
        }
        self::assertTrue(minos_is_installed(), 'asking removes nothing');

        $this->forum->mybb->request_method = 'post';
        minos_uninstall();
        self::assertFalse(minos_is_installed());
        self::assertSame([], $this->settings('value'));
        self::assertNull($this->task());
        self::assertFalse($this->forum->db->table_exists('minos_pending'));
    }

    public function testUninstallCanKeepTheTables(): void
    {
        minos_install();
        $this->forum->mybb->request_method = 'post';
        $this->forum->mybb->input['no'] = 'Nie';
        minos_uninstall();
        self::assertFalse(minos_is_installed());
        self::assertTrue($this->forum->db->table_exists('minos_pending'));

        minos_install();
        self::assertTrue(minos_is_installed(), 'a later install reuses the kept tables');
    }

    /** @return array<string,string> By setting name. */
    private function settings(string $column): array
    {
        $query = $this->forum->db->simple_select('settings', 'name,' . $column, "name LIKE 'minos\\_%' ESCAPE '\\'");
        $values = [];
        while ($row = $this->forum->db->fetch_array($query)) {
            $values[$row['name']] = (string)$row[$column];
        }
        return $values;
    }

    /** @return array<string,mixed>|null */
    private function task(): ?array
    {
        return $this->forum->db->fetch_array($this->forum->db->simple_select('tasks', '*', "file='minos'"));
    }
}
