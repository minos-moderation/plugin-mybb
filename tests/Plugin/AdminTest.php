<?php

declare(strict_types=1);

namespace Minos\MyBB\Tests\Plugin;

use Minos\MyBB\Installer;
use Minos\MyBB\Tests\Support\Forum;
use Minos\MyBB\Tests\Support\PageEnded;
use Minos\MyBB\Tests\Support\PluginTestCase;
use Minos\MyBB\Tests\Support\RecordingTransport;

/**
 * The ACP: masked secrets, the notice, the page — through the hook functions.
 */
final class AdminTest extends PluginTestCase
{
    public function testATypedSecretIsSavedAndAnEmptyFieldKeepsTheStoredOne(): void
    {
        $this->forum->mybb->request_method = 'post';
        $this->forum->mybb->input = [
            'upsetting' => ['minos_profile' => 'forum_teen'],
            Installer::SECRET_INPUT => ['minos_api_key' => '  wgb2b_nowy_klucz_1234567890  ', 'minos_webhook_secret' => ''],
        ];
        minos_hook_settings_change();
        self::assertSame(['minos_profile' => 'forum_teen', 'minos_api_key' => 'wgb2b_nowy_klucz_1234567890'],
            $this->forum->mybb->input['upsetting'], 'the empty secret field is not saved');
    }

    public function testTheClearBoxEmptiesAStoredSecret(): void
    {
        $this->forum->mybb->request_method = 'post';
        $this->forum->mybb->input = ['upsetting' => [], Installer::SECRET_CLEAR => ['minos_webhook_secret' => '1']];
        minos_hook_settings_change();
        self::assertSame(['minos_webhook_secret' => ''], $this->forum->mybb->input['upsetting']);
    }

    public function testASecretRowShowsOnlyAPrefixAndTheWebhookAddress(): void
    {
        foreach (['minos_api_key', 'minos_webhook_secret'] as $name) {
            $title = 'Tytuł';
            $description = 'Opis';
            $content = '<input type="password" value="" />';
            $options = [];
            $rowOptions = ['id' => 'row_setting_' . $name];
            $args = ['title' => &$title, 'description' => &$description, 'content' => &$content, 'options' => &$options,
                'row_options' => &$rowOptions];
            $GLOBALS['plugins']->run_hooks('admin_formcontainer_output_row', $args);

            self::assertStringContainsString(Installer::SECRET_CLEAR, $content);
            self::assertStringNotContainsString(Forum::KEY, $description . $content);
            self::assertStringNotContainsString(Forum::SECRET, $description . $content);
            self::assertStringContainsString('…', $description);
        }
        self::assertStringContainsString('https://forum.example/minos-webhook.php', $description);
    }

    public function testOtherRowsAreUntouched(): void
    {
        $description = 'Opis';
        $rowOptions = ['id' => 'row_setting_bbname'];
        $args = ['description' => &$description, 'row_options' => &$rowOptions];
        $GLOBALS['plugins']->run_hooks('admin_formcontainer_output_row', $args);
        self::assertSame('Opis', $description);
    }

    public function testTheNoticeNamesWhatIsMissing(): void
    {
        $this->forum->set(['minos_api_key' => '']);
        minos_hook_admin_load();
        self::assertCount(1, $this->forum->page->extra_messages);
        self::assertStringContainsString('klucza', $this->forum->page->extra_messages[0]['message']);

        $this->forum->set(['minos_enabled' => '0']);
        $this->forum->page->extra_messages = [];
        minos_hook_admin_load();
        self::assertSame([], $this->forum->page->extra_messages, 'a plugin switched off is no problem');
    }

    public function testMissingTablesDoNotBreakTheAcp(): void
    {
        $this->forum->platform()->dropTables();
        minos_hook_admin_load();
        self::assertSame([], $this->forum->page->extra_messages);
    }

    public function testTheLogsPageIsRegisteredAndListsWhatNeedsALook(): void
    {
        $actions = [];
        $GLOBALS['plugins']->run_hooks('admin_tools_action_handler', $actions);
        $menu = [];
        $GLOBALS['plugins']->run_hooks('admin_tools_menu_logs', $menu);
        self::assertArrayHasKey('minos', $actions);
        self::assertSame('index.php?module=tools-minos', $menu[0]['link']);

        $supported = $this->heldReply();
        $this->forum->deliver(self::payload($supported, ['kategorie' => ['samookaleczenie'], 'wsparcie' => true]));
        $this->forum->plugin(new RecordingTransport([RecordingTransport::refused(403, 'profil_niedozwolony', null, 0)]));
        $refused = $this->heldReply('<script>alert(1)</script>');

        $this->forum->page->active_module = 'tools';
        $this->forum->page->active_action = 'minos';
        ob_start();
        try {
            minos_hook_admin_load();
            self::fail('the page ends the request');
        } catch (PageEnded $ended) {
            $html = (string)ob_get_clean();
        }
        self::assertStringContainsString('#' . $supported, $html);
        self::assertStringContainsString('okaż autorowi wsparcie', $html);
        self::assertStringContainsString('#' . $refused, $html);
        self::assertStringContainsString('profil_niedozwolony', $html);
        self::assertStringNotContainsString('<script>', $html);
        self::assertStringNotContainsString(Forum::KEY, $html);
        self::assertStringNotContainsString(Forum::SECRET, $html);
    }
}
