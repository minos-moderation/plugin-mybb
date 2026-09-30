<?php

declare(strict_types=1);

namespace Minos\MyBB;

/**
 * The plugin in MyBB's admin control panel.
 *
 * - The key and the webhook secret: the settings page shows an empty password field and,
 *   beside it, only the stored value's prefix ({@see Settings::prefixOf}); a typed value
 *   replaces the stored one, an empty field keeps it, a checkbox clears it.
 * - A notice on every ACP page while the plugin is switched on but cannot work (what is
 *   missing), or while the gateway's last answer to a submission was a configuration
 *   error (its code — never content).
 * - A page under Tools & Maintenance → Logs → "Minos — dziennik": the configuration's
 *   state, the posts that need a look (held, waiting, and those the gateway flagged with
 *   `wsparcie` — the author may need support), and the log of codes.
 *
 * Everything shown is escaped here; the texts are the Polish language file's.
 * No PHP 8 syntax.
 */
final class Admin
{
    /** The page's action under the `tools` module. */
    public const ACTION = 'minos';

    /** Rows per table on the page. */
    private const ROWS = 50;

    /** @var Platform */
    private $platform;

    public function __construct(Platform $platform)
    {
        $this->platform = $platform;
    }

    /**
     * `admin_config_settings_change`: before MyBB saves the settings, a typed key or secret
     * is moved into `upsetting`; an empty field keeps the stored value.
     */
    public function onSettingsChange(): void
    {
        if ($this->platform->requestMethod() !== 'post') {
            return;
        }
        $typed = $this->platform->input(Installer::SECRET_INPUT);
        $clear = $this->platform->input(Installer::SECRET_CLEAR);
        foreach (Settings::SECRET_NAMES as $name) {
            $value = is_array($typed) && is_string($typed[$name] ?? null) ? trim($typed[$name]) : '';
            if ($value !== '') {
                $this->platform->submitSetting($name, $value);
            } elseif (is_array($clear) && !empty($clear[$name])) {
                $this->platform->submitSetting($name, '');
            }
        }
    }

    /**
     * `admin_formcontainer_output_row`: beside a secret setting, its prefix and a "clear"
     * box; beside the secret, the webhook address to register with the key.
     *
     * @param array<string,mixed> $args MyBB's hook arguments (references).
     */
    public function onFormRow(array &$args): void
    {
        $id = is_array($args['row_options'] ?? null) ? (string)($args['row_options']['id'] ?? '') : '';
        $name = substr($id, strlen('row_setting_'));
        if (strncmp($id, 'row_setting_', 12) !== 0 || !in_array($name, Settings::SECRET_NAMES, true)) {
            return;
        }
        $prefix = Settings::prefixOf((string)($this->platform->settings()[$name] ?? ''));
        $note = $prefix === ''
            ? $this->platform->lang('minos_secret_missing')
            : $this->platform->lang('minos_secret_saved', self::e($prefix));
        if ($name === Settings::WEBHOOK_SECRET) {
            $note .= '<br />' . $this->platform->lang('minos_webhook_url_hint',
                self::e(Settings::webhookUrl($this->platform->boardUrl())));
        }
        $args['description'] = (string)($args['description'] ?? '') . '<br />' . $note;
        if ($prefix !== '') {
            $args['content'] = (string)($args['content'] ?? '')
                . ' <label><input type="checkbox" name="' . Installer::SECRET_CLEAR . '[' . self::e($name) . ']" value="1" /> '
                . self::e($this->platform->lang('minos_secret_clear')) . '</label>';
        }
    }

    /**
     * `admin_tools_action_handler`: registers the page.
     *
     * @param array<string,array<string,string>> $actions MyBB's action list.
     */
    public function onToolsActions(array &$actions): void
    {
        $actions[self::ACTION] = ['active' => self::ACTION, 'file' => self::ACTION];
    }

    /**
     * `admin_tools_menu_logs`: the page's link in the Logs sidebar.
     *
     * @param array<int|string,array<string,string>> $menu MyBB's menu.
     */
    public function onToolsMenuLogs(array &$menu): void
    {
        $menu[] = [
            'id'    => self::ACTION,
            'title' => $this->platform->lang('minos_acp_menu'),
            'link'  => 'index.php?module=tools-' . self::ACTION,
        ];
    }

    /**
     * `admin_tools_permissions`: the page's permission for non-super administrators.
     *
     * @param array<string,string> $permissions MyBB's permission list.
     */
    public function onToolsPermissions(array &$permissions): void
    {
        $permissions[self::ACTION] = $this->platform->lang('minos_acp_permission');
    }

    /**
     * `admin_load`: the notice, and the page itself when it is the one being loaded
     * (MyBB would otherwise require a module file the plugin does not ship).
     */
    public function onLoad(): void
    {
        $notice = $this->notice();
        if ($notice !== null) {
            $this->platform->acpMessage($notice);
        }
        $location = $this->platform->acpLocation();
        if ($location['module'] === 'tools' && $location['action'] === self::ACTION) {
            $this->platform->acpOutput($this->platform->lang('minos_acp_title'), $this->page());
        }
    }

    /**
     * The notice, if one is due.
     *
     * @return string|null Escaped HTML, or null.
     */
    public function notice(): ?string
    {
        $settings = new Settings($this->platform->settings());
        if (!$settings->enabled()) {
            return null;
        }
        $problems = $settings->problems();
        if ($problems !== []) {
            return self::e($this->platform->lang('minos_notice_problems', $this->problemList($problems)));
        }
        // Every ACP page asks; a missing table must not turn that into a database error.
        $last = $this->platform->tablesExist() ? $this->platform->lastLog(['config_error', 'recovered']) : null;
        if ($last !== null && $last['event'] === 'config_error') {
            return self::e($this->platform->lang('minos_notice_config_error', (string)$last['code'],
                date('Y-m-d H:i', (int)$last['dateline'])));
        }
        return null;
    }

    /**
     * The page's body.
     *
     * @return string Escaped HTML.
     */
    public function page(): string
    {
        $settings = new Settings($this->platform->settings());
        if (!$settings->enabled()) {
            $state = $this->platform->lang('minos_acp_state_disabled');
        } elseif ($settings->problems() !== []) {
            $state = $this->platform->lang('minos_acp_state_problems', $this->problemList($settings->problems()));
        } else {
            $state = $this->platform->lang('minos_acp_state_active');
        }
        $html = self::table($this->platform->lang('minos_acp_config'), [], [
            [$this->platform->lang('minos_acp_state'), $state],
            [$this->platform->lang('minos_acp_webhook'), Settings::webhookUrl($this->platform->boardUrl())],
            [$this->platform->lang('minos_acp_mode'), $settings->failOpen() ? Settings::FAIL_OPEN : Settings::FAIL_CLOSED],
        ], '');

        $rows = [];
        foreach ($this->platform->attentionRows(self::ROWS) as $row) {
            $rows[] = [
                ['url' => $this->platform->postUrl((int)$row['pid'], (int)$row['tid']), 'text' => '#' . (int)$row['pid']],
                $this->platform->lang('minos_status_' . $row['status']),
                $this->verdictLabel((string)$row['verdict']),
                (string)$row['categories'],
                (int)$row['support'] === 1 ? $this->platform->lang('minos_acp_support_yes') : '',
                date('Y-m-d H:i', (int)$row['submitted_at']),
            ];
        }
        $html .= self::table($this->platform->lang('minos_acp_attention'), [
            'minos_acp_col_post', 'minos_acp_col_status', 'minos_acp_col_verdict',
            'minos_acp_col_categories', 'minos_acp_col_support', 'minos_acp_col_time',
        ], $rows, $this->platform->lang('minos_acp_attention_desc'), $this->platform);

        $rows = [];
        foreach ($this->platform->recentLog(self::ROWS) as $entry) {
            $rows[] = [
                date('Y-m-d H:i', (int)$entry['dateline']),
                $this->platform->lang('minos_event_' . $entry['event']),
                (string)$entry['code'],
                (int)$entry['pid'] > 0 ? '#' . (int)$entry['pid'] : '',
            ];
        }
        $html .= self::table($this->platform->lang('minos_acp_log'), [
            'minos_acp_col_time', 'minos_acp_col_event', 'minos_acp_col_code', 'minos_acp_col_post',
        ], $rows, '', $this->platform);
        return $html;
    }

    /**
     * @param array<int,string> $problems Language keys.
     */
    private function problemList(array $problems): string
    {
        return implode('; ', array_map([$this->platform, 'lang'], $problems));
    }

    private function verdictLabel(string $verdict): string
    {
        $key = 'minos_reason_' . $verdict;
        $label = $this->platform->lang($key);
        return $label === $key ? $verdict : $label;
    }

    /**
     * A table in the ACP's look.
     *
     * @param array<int,string>              $headers  Language keys (none: a two-column list).
     * @param array<int,array<int,mixed>>    $rows     Cells: text, or `['url' => …, 'text' => …]`.
     */
    private static function table(string $title, array $headers, array $rows, string $description, ?Platform $platform = null): string
    {
        $html = '<div class="border_wrapper"><div class="title">' . self::e($title) . '</div>'
            . '<table class="general" cellspacing="0">';
        if ($description !== '') {
            $html .= '<tr><td class="tcat" colspan="' . max(1, count($headers)) . '">' . self::e($description) . '</td></tr>';
        }
        if ($headers !== [] && $platform !== null) {
            $html .= '<thead><tr>';
            foreach ($headers as $header) {
                $html .= '<th>' . self::e($platform->lang($header)) . '</th>';
            }
            $html .= '</tr></thead>';
        }
        $html .= '<tbody>';
        if ($rows === [] && $platform !== null) {
            $html .= '<tr><td colspan="' . max(1, count($headers)) . '">' . self::e($platform->lang('minos_acp_none')) . '</td></tr>';
        }
        foreach ($rows as $row) {
            $html .= '<tr>';
            foreach ($row as $cell) {
                $html .= '<td>' . (is_array($cell)
                    ? '<a href="' . self::e($cell['url']) . '" target="_blank" rel="noopener">' . self::e($cell['text']) . '</a>'
                    : self::e((string)$cell)) . '</td>';
            }
            $html .= '</tr>';
        }
        return $html . '</tbody></table></div><br />';
    }

    private static function e(string $text): string
    {
        return htmlspecialchars($text, ENT_QUOTES, 'UTF-8');
    }
}
