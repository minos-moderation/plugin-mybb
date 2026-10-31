<?php

declare(strict_types=1);

namespace Minos\MyBB;

/**
 * The plugin in MyBB's admin control panel.
 *
 * - The key and the webhook secret: the settings page shows an empty password field and,
 *   beside it, only the stored value's prefix ({@see Settings::prefixOf}); a typed value
 *   replaces the stored one, an empty field keeps it, a checkbox clears it.
 * - A notice on every ACP page (the log page too) while the plugin is switched on but
 *   cannot work (what is missing), while the gateway's last answer to a submission was a
 *   configuration error (its code — never content), while the last answer at the
 *   gateway's address was a `2xx` other than `202` (something else answers there: the
 *   posts are retried, the address should be checked), and while the gateway refuses posts
 *   until a known moment — the key's daily limit, or an overload — with that moment: new
 *   posts get the failure mode until then. The first accepted submission clears it. And
 *   while the webhook refuses deliveries for their signature ({@see Receiver::rejections}):
 *   how many since the last one that passed, when, and what the reason points at — the
 *   secret, the server's clock, or a header that is not the gateway's — so a wrong secret
 *   shows without reading any server log. A verified delivery or a newly saved secret
 *   clears it.
 * - A page under Tools & Maintenance → Logs → "Minos — dziennik": the configuration's
 *   state, the verdicts of the last 7 days ({@see verdictFigures}: the median and the 95th
 *   percentile of the time from the gateway's `202` to the verdict, and how many of each
 *   verdict), the posts that need a look (held, waiting, published unassessed, decided by a
 *   human) with the gateway's masked text of a censored post the plugin kept in the queue,
 *   the posts the gateway flagged with `wsparcie` (the author may need support) on their
 *   own list, and the log of codes. Each list shows its 50 newest rows.
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

    /** The window of the verdict figures, in seconds. */
    public const FIGURES_WINDOW_S = 7 * 86400;

    /** The gateway's verdicts, in the order the page counts them (wire values). */
    public const VERDICTS = ['bezpieczne', 'ocenzurowane', 'zablokowane', 'nieocenione'];

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
            } else {
                continue;
            }
            if ($name === Settings::WEBHOOK_SECRET) {
                // The refusals counted so far were checked against the old secret.
                $this->platform->setState(Receiver::REJECTED, null);
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
     * @param int|null $now Unix seconds (now by default).
     * @return string|null Escaped HTML, or null.
     */
    public function notice(?int $now = null): ?string
    {
        $now = $now ?? time();
        $settings = new Settings($this->platform->settings());
        if (!$settings->enabled()) {
            return null;
        }
        $problems = $settings->problems();
        if ($problems !== []) {
            return self::e($this->platform->lang('minos_notice_problems', $this->problemList($problems)));
        }
        $notices = [];
        // Every ACP page asks; a missing table must not turn that into a database error.
        $last = $this->platform->tablesExist() ? $this->platform->lastLog(['config_error', 'recovered']) : null;
        if ($last !== null && $last['event'] === 'config_error') {
            $notices[] = self::e($this->platform->lang('minos_notice_config_error', (string)$last['code'],
                date('Y-m-d H:i', (int)$last['dateline'])));
        }
        // The gateway's own code (checked against `[a-z0-9_]` by Gateway::classify) and a
        // time: never the key, never the answer's body.
        $refusal = $this->platform->state(Submitter::REFUSAL);
        if (is_array($refusal) && (int)($refusal['until'] ?? 0) > $now) {
            $key = (string)($refusal['kod'] ?? '') === Submitter::DAILY_LIMIT ? 'minos_notice_daily_limit' : 'minos_notice_overloaded';
            $notices[] = self::e($this->platform->lang($key, date('Y-m-d H:i', (int)$refusal['until']), (string)$refusal['kod']));
        }
        // The last answer at the gateway's address was a 2xx but 202: not the gateway's.
        $foreign = $this->platform->state(Submitter::FOREIGN);
        if (is_array($foreign) && preg_match('/^http_2[0-9]{2}\z/', (string)($foreign['kod'] ?? ''))) {
            $notices[] = self::e($this->platform->lang('minos_notice_foreign', (string)$foreign['kod'],
                date('Y-m-d H:i', (int)($foreign['at'] ?? 0))));
        }
        $rejections = Receiver::rejections($this->platform);
        if ($rejections !== null) {
            $notices[] = self::e($this->platform->lang('minos_notice_signature', (string)$rejections['count'],
                date('Y-m-d H:i', $rejections['at']), $this->platform->lang('minos_signature_' . $rejections['reason'])));
        }
        return $notices === [] ? null : implode('<br />', $notices);
    }

    /**
     * The verdicts the gateway delivered in the last {@see FIGURES_WINDOW_S}: the time from
     * its `202` to the verdict — the median (P50) and the 95th percentile (P95), nearest
     * rank, over the verdicts whose `202` the plugin recorded (a verdict can overtake its
     * `202`, or come for a post whose request timed out on the forum's side) — and how many
     * of each verdict. Read from the plugin's own table: no request to the gateway, no text.
     *
     * @param int $now Unix seconds.
     * @return array{p50:int|null,p95:int|null,timed:int,counts:array<string,int>} Seconds;
     *     null without a timed verdict.
     */
    public function verdictFigures(int $now): array
    {
        $counts = array_fill_keys(self::VERDICTS, 0);
        $seconds = [];
        foreach ($this->platform->answersSince($now - self::FIGURES_WINDOW_S) as $answer) {
            if (isset($counts[$answer['answer']])) {
                $counts[$answer['answer']]++;
            }
            if ($answer['seconds'] !== null) {
                $seconds[] = $answer['seconds'];
            }
        }
        sort($seconds);
        return [
            'p50'    => self::percentile($seconds, 50),
            'p95'    => self::percentile($seconds, 95),
            'timed'  => count($seconds),
            'counts' => $counts,
        ];
    }

    /**
     * The page's body.
     *
     * @param int|null $now Unix seconds (now by default).
     * @return string Escaped HTML.
     */
    public function page(?int $now = null): string
    {
        $now = $now ?? time();
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

        $figures = $this->verdictFigures($now);
        $rows = [
            [$this->platform->lang('minos_acp_figures_p50'), $this->duration($figures['p50'])],
            [$this->platform->lang('minos_acp_figures_p95'), $this->duration($figures['p95'])],
            [$this->platform->lang('minos_acp_figures_timed'), (string)$figures['timed']],
        ];
        foreach ($figures['counts'] as $verdict => $count) {
            $rows[] = [$verdict, (string)$count];
        }
        $html .= self::table($this->platform->lang('minos_acp_figures'), [], $rows,
            $this->platform->lang('minos_acp_figures_desc'));

        $rows = [];
        foreach ($this->platform->attentionRows(self::ROWS) as $row) {
            $rows[] = [
                ['url' => $this->platform->postUrl((int)$row['pid'], (int)$row['tid']), 'text' => '#' . (int)$row['pid']],
                $this->platform->lang((int)$row['auto_published'] === 1 && $row['status'] === Status::PUBLISHED
                    ? 'minos_status_auto_published' : 'minos_status_' . $row['status']),
                $this->verdictLabel((string)$row['verdict']),
                (string)$row['categories'],
                (string)($row['masked_text'] ?? ''),
                (int)$row['support'] === 1 ? $this->platform->lang('minos_acp_support_yes') : '',
                date('Y-m-d H:i', (int)$row['submitted_at']),
            ];
        }
        $html .= self::table($this->platform->lang('minos_acp_attention'), [
            'minos_acp_col_post', 'minos_acp_col_status', 'minos_acp_col_verdict',
            'minos_acp_col_categories', 'minos_acp_col_masked', 'minos_acp_col_support', 'minos_acp_col_time',
        ], $rows, $this->platform->lang('minos_acp_attention_desc'), $this->platform);

        // `wsparcie` on its own list, which the rows above never push out (D9.9).
        $rows = [];
        foreach ($this->platform->supportRows(self::ROWS) as $row) {
            $rows[] = [
                ['url' => $this->platform->postUrl((int)$row['pid'], (int)$row['tid']), 'text' => '#' . (int)$row['pid']],
                $this->platform->lang((int)$row['auto_published'] === 1 && $row['status'] === Status::PUBLISHED
                    ? 'minos_status_auto_published' : 'minos_status_' . $row['status']),
                $this->verdictLabel((string)$row['verdict']),
                (string)$row['categories'],
                date('Y-m-d H:i', (int)$row['submitted_at']),
            ];
        }
        $html .= self::table($this->platform->lang('minos_acp_support'), [
            'minos_acp_col_post', 'minos_acp_col_status', 'minos_acp_col_verdict', 'minos_acp_col_categories',
            'minos_acp_col_time',
        ], $rows, $this->platform->lang('minos_acp_support_desc'), $this->platform);

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
     * The nearest-rank percentile of sorted values.
     *
     * @param array<int,int> $sorted Ascending.
     * @param int            $p      1–100.
     * @return int|null Null for no values.
     */
    private static function percentile(array $sorted, int $p): ?int
    {
        if ($sorted === []) {
            return null;
        }
        return $sorted[max(1, (int)ceil($p / 100 * count($sorted))) - 1];
    }

    /**
     * A time in seconds as the page shows it.
     */
    private function duration(?int $seconds): string
    {
        if ($seconds === null) {
            return $this->platform->lang('minos_acp_figures_none');
        }
        if ($seconds < 60) {
            return $this->platform->lang('minos_acp_seconds', (string)$seconds);
        }
        return $this->platform->lang('minos_acp_minutes', (string)intdiv($seconds, 60), (string)($seconds % 60));
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
        $columns = $headers !== [] ? count($headers) : count($rows[0] ?? [1]);
        if ($description !== '') {
            $html .= '<tr><td class="tcat" colspan="' . max(1, $columns) . '">' . self::e($description) . '</td></tr>';
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
