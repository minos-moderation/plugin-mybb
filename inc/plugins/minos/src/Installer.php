<?php

declare(strict_types=1);

namespace Minos\MyBB;

/**
 * The plugin's life in MyBB's plugin list: `_info`, `_install`, `_is_installed`,
 * `_uninstall`, `_activate`, `_deactivate`.
 *
 * - Install: the two tables, the settings group "Minos" (Polish titles), the task
 *   (switched off until activation).
 * - Activate: refreshes the settings' texts (the webhook address in them follows `bburl`)
 *   without touching their values, and switches the task on. Deactivate switches it off.
 * - Uninstall: removes the settings and the task and asks whether to drop the tables too —
 *   "No" keeps the history and the originals of censored posts.
 *
 * Setting titles live in the database, rendered in Polish at install and activation, not
 * in `$l['setting_minos_…']` keys: MyBB's settings page would prefer such a key and could
 * not fill in the webhook address. No PHP 8 syntax.
 */
final class Installer
{
    /** The plugin's version (MyBB's plugin list; the zip's name). */
    public const VERSION = '0.1.0';

    /** The task's schedule: every 5 minutes. */
    public const TASK_MINUTES = '0,5,10,15,20,25,30,35,40,45,50,55';

    /** The request input a masked setting posts its new value under. */
    public const SECRET_INPUT = 'minos_secret_input';

    /** The request input that asks to clear a masked setting. */
    public const SECRET_CLEAR = 'minos_secret_clear';

    /** @var Platform */
    private $platform;

    public function __construct(Platform $platform)
    {
        $this->platform = $platform;
    }

    /**
     * `minos_info()`.
     *
     * @return array<string,string> MyBB's plugin information.
     */
    public function info(): array
    {
        return [
            'name'          => $this->platform->lang('minos_name'),
            'description'   => $this->platform->lang('minos_description',
                htmlspecialchars(Settings::webhookUrl($this->platform->boardUrl()), ENT_QUOTES, 'UTF-8')),
            'website'       => 'https://github.com/minos-moderation/plugin-mybb',
            'author'        => 'Minos',
            'authorsite'    => 'https://github.com/minos-moderation',
            'version'       => self::VERSION,
            'compatibility' => '18*',
            'codename'      => Platform::CODENAME,
        ];
    }

    /**
     * `minos_install()`.
     */
    public function install(): void
    {
        $this->platform->createTables();
        $this->platform->saveSettings($this->group(), $this->settings());
        $this->platform->saveTask($this->task(0));
    }

    /**
     * `minos_is_installed()`.
     *
     * @return bool Whether the settings group exists.
     */
    public function isInstalled(): bool
    {
        return $this->platform->settingsInstalled();
    }

    /**
     * `minos_uninstall()`. On the first (GET) request it asks about the tables and the ACP
     * ends the request; the answer comes back as a POST, with `no` for "No".
     */
    public function uninstall(): void
    {
        if ($this->platform->requestMethod() !== 'post') {
            $this->platform->acpConfirm(
                'index.php?module=config-plugins&action=deactivate&uninstall=1&plugin=' . Platform::CODENAME,
                htmlspecialchars($this->platform->lang('minos_uninstall_question'), ENT_QUOTES, 'UTF-8'),
                $this->platform->lang('minos_uninstall_title'));
            return;
        }
        $this->platform->removeSettings();
        $this->platform->removeTask();
        if ($this->platform->input('no') === null) {
            $this->platform->dropTables();
        }
    }

    /**
     * `minos_activate()`.
     */
    public function activate(): void
    {
        $this->platform->createTables(); // tables kept by an earlier uninstall are reused
        $this->platform->saveSettings($this->group(), $this->settings());
        $this->platform->saveTask($this->task(1));
        $this->platform->setTaskEnabled(true);
    }

    /**
     * `minos_deactivate()`.
     */
    public function deactivate(): void
    {
        $this->platform->setTaskEnabled(false);
    }

    /**
     * The settings group.
     *
     * @return array{title:string,description:string}
     */
    public function group(): array
    {
        return [
            'title'       => $this->platform->lang('minos_group_title'),
            'description' => $this->platform->lang('minos_group_desc', Settings::webhookUrl($this->platform->boardUrl())),
        ];
    }

    /**
     * The settings, in display order, with their defaults.
     *
     * @return array<string,array{title:string,description:string,optionscode:string,value:string}>
     */
    public function settings(): array
    {
        $select = function (string $name, array $options): string {
            $lines = ['select'];
            foreach ($options as $value) {
                $lines[] = $value . '=' . $this->platform->lang('minos_setting_' . $name . '_' . str_replace('-', '_', $value));
            }
            return implode("\n", $lines);
        };
        $definitions = [
            Settings::ENABLED         => ['enabled', 'onoff', '1'],
            Settings::GATEWAY_URL     => ['gateway_url', 'text', Settings::DEFAULT_GATEWAY_URL],
            Settings::API_KEY         => ['api_key', self::maskedInput(Settings::API_KEY), ''],
            Settings::WEBHOOK_SECRET  => ['webhook_secret', self::maskedInput(Settings::WEBHOOK_SECRET), ''],
            Settings::PROFILE         => ['profile', $select('profile', Settings::PROFILES), Settings::PROFILES[0]],
            Settings::FAILURE_MODE    => ['failure_mode', $select('failure_mode', [Settings::FAIL_CLOSED, Settings::FAIL_OPEN]), Settings::FAIL_CLOSED],
            Settings::TIMEOUT_MIN     => ['timeout', "numeric\nmin=1\nmax=1440", (string)Settings::DEFAULT_TIMEOUT_MIN],
            Settings::CENSORED        => ['censored', $select('censored', [Settings::PUBLISH, Settings::QUEUE]), Settings::PUBLISH],
            Settings::BLOCKED         => ['blocked', $select('blocked', [Settings::QUEUE, Settings::SOFT_DELETE]), Settings::QUEUE],
            Settings::FORUMS          => ['forums', 'forumselect', '-1'],
            Settings::SKIP_MODERATORS => ['skip_moderators', 'yesno', '1'],
        ];
        $settings = [];
        foreach ($definitions as $name => [$short, $optionscode, $value]) {
            $settings[$name] = [
                'title'       => $this->platform->lang('minos_setting_' . $short),
                'description' => $this->platform->lang('minos_setting_' . $short . '_desc'),
                'optionscode' => $optionscode,
                'value'       => $value,
            ];
        }
        return $settings;
    }

    /**
     * The `optionscode` of a secret setting: MyBB's `php` type, whose code the settings
     * page evaluates as a double-quoted string. It renders an EMPTY password field named
     * outside `upsetting[...]`, so MyBB never prints the stored value into the page (its
     * own `passwordbox` type would, in the `value` attribute) and never saves the field by
     * itself; {@see Admin::onSettingsChange} moves a typed value into place. This holds
     * even while the plugin is deactivated and its hooks are not loaded. MyBB also refuses
     * to open its raw "Edit setting" page for a `php` setting, so the ACP has no page that
     * prints the value.
     *
     * @param string $name The setting.
     * @return string The optionscode.
     */
    public static function maskedInput(string $name): string
    {
        return "php\n" . '<input type=\"password\" name=\"' . self::SECRET_INPUT . '[' . $name
            . ']\" value=\"\" class=\"text_input\" autocomplete=\"new-password\" />';
    }

    /**
     * @return array{title:string,description:string,minute:string,enabled:int}
     */
    private function task(int $enabled): array
    {
        return [
            'title'       => $this->platform->lang('minos_task_title'),
            'description' => $this->platform->lang('minos_task_desc'),
            'minute'      => self::TASK_MINUTES,
            'enabled'     => $enabled,
        ];
    }
}
