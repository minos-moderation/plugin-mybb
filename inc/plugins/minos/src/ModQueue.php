<?php

declare(strict_types=1);

namespace Minos\MyBB;

/**
 * What the gateway said, where a moderator decides: MyBB's moderation queue in the ModCP
 * (`modcp.php?action=modqueue`, the posts and the threads awaiting moderation).
 *
 * MyBB 1.8.41 builds each queued post from its template (`modcp_modqueue_posts_post`,
 * `modcp_modqueue_threads_thread`) into the page's global `$posts` / `$threads`, runs
 * `modcp_modqueue_posts_end` / `modcp_modqueue_threads_end` (no arguments), and only then
 * puts the list into the page — and has no hook per post. So the plugin finds each post
 * the list carries by MyBB's own form field (`posts[<pid>]`, `threads[<tid>]`) and adds,
 * after that post's row, one of its own: the plugin's state of the post, the verdict and
 * the reason (a configuration error, a timeout, a post longer than 3000 characters whose
 * beginning only was assessed…), the categories, `wsparcie`, and the gateway's masked text
 * of a censored post it kept in the queue (shared rule A2d: the mask is shown in the
 * moderation panel). Everything is escaped here; nothing but the plugin's own row (never a
 * key, a secret or a delivery) is shown. A post the plugin did not hold (MyBB holds it for
 * its own reasons) gets nothing. A theme whose template keeps MyBB's form field but no
 * table row after it gets the notes at the end of the list, each naming its post; one whose
 * template has no such field gets no note (the post cannot be found in the list).
 * No PHP 8 syntax.
 */
final class ModQueue
{
    /** @var Platform */
    private $platform;

    public function __construct(Platform $platform)
    {
        $this->platform = $platform;
    }

    /**
     * `modcp_modqueue_posts_end` (`$threads` false) and `modcp_modqueue_threads_end`.
     *
     * @param bool $threads The threads awaiting moderation (else the posts).
     */
    public function onRendered(bool $threads): void
    {
        $list = $threads ? 'threads' : 'posts';
        $html = $this->platform->modQueueList($list);
        if ($html === '' || !preg_match_all('/name="' . $list . '\[([1-9][0-9]{0,9})\]"/', $html, $m)) {
            return;
        }
        $ids = array_values(array_unique(array_map('intval', $m[1])));
        $rows = $threads ? $this->platform->threadRows($ids) : $this->platform->pendingRows($ids);
        $tail = '';
        foreach ($rows as $id => $row) {
            $note = $this->note($row);
            $marker = 'name="' . $list . '[' . $id . ']"';
            $at = strpos($html, $marker);
            $end = $at === false ? false : stripos($html, '</tr>', $at);
            if ($end === false) {
                $tail .= self::row('#' . (int)$row['pid'] . ' — ' . $note);
                continue;
            }
            $end += strlen('</tr>');
            $html = substr($html, 0, $end) . self::row($note) . substr($html, $end);
        }
        $this->platform->setModQueueList($list, $html . $tail);
    }

    /**
     * `modcp_reports_report`: a report the plugin raised for `wsparcie` is filed by uid 0,
     * and MyBB 1.8.41's `modcp.php` fills the "last report" cell only for a reporter with an
     * id, while its template `modcp_reports_report` reads it anyway (`Undefined array key
     * "lastreporter"` on PHP 8, an empty cell). For the plugin's own reports — uid 0 on a
     * post whose row the gateway flagged with `wsparcie` — the cell names the moment and
     * Minos. Any other report is MyBB's.
     */
    public function onReportRendered(): void
    {
        $report = $this->platform->modcpReport();
        if ($report === null || $this->platform->modcpReportHasLastReporter() || (int)($report['uid'] ?? -1) !== 0
            || !in_array((string)($report['type'] ?? ''), ['post', ''], true)) {
            return;
        }
        $row = $this->platform->pendingRows([(int)($report['id'] ?? 0)]);
        $row = reset($row);
        if ($row === false || (int)$row['support'] !== 1) {
            return;
        }
        $this->platform->setModcpReportLastReporter(self::e($this->platform->lang('minos_report_lastreporter',
            date('Y-m-d H:i', (int)($report['lastreport'] ?? $report['dateline'] ?? 0)))));
    }

    /**
     * One post's note: escaped HTML.
     *
     * @param array<string,mixed> $row The plugin's row of the post.
     */
    private function note(array $row): string
    {
        $status = (int)$row['auto_published'] === 1 && $row['status'] === Status::PUBLISHED
            ? 'minos_status_auto_published' : 'minos_status_' . $row['status'];
        $parts = [$this->platform->lang('minos_modqueue_status', $this->platform->lang($status))];
        $verdict = (string)$row['verdict'];
        if ($verdict !== '') {
            $key = 'minos_reason_' . $verdict;
            $label = $this->platform->lang($key);
            $parts[] = $this->platform->lang('minos_modqueue_verdict', $label === $key ? $verdict : $label);
        }
        if ((int)$row['truncated'] === 1 && $verdict !== Status::REASON_TRUNCATED) {
            $parts[] = $this->platform->lang('minos_reason_truncated');
        }
        if ((string)$row['categories'] !== '') {
            $parts[] = $this->platform->lang('minos_modqueue_categories', str_replace(',', ', ', (string)$row['categories']));
        }
        if ((int)$row['support'] === 1) {
            $parts[] = $this->platform->lang('minos_modqueue_support');
        }
        $html = '<strong>' . self::e($this->platform->lang('minos_modqueue_title')) . '</strong> '
            . self::e(implode(' · ', $parts));
        $masked = (string)($row['masked_text'] ?? '');
        if ($masked !== '') {
            $html .= '<br />' . self::e($this->platform->lang('minos_modqueue_masked')) . '<br />'
                . nl2br(self::e($masked));
        }
        return $html;
    }

    /**
     * The row the note goes in, spanning the list's three columns.
     */
    private static function row(string $html): string
    {
        return '<tr class="minos_modqueue"><td class="trow_shaded" colspan="3"><div class="smalltext">' . $html
            . '</div></td></tr>';
    }

    private static function e(string $text): string
    {
        return htmlspecialchars($text, ENT_QUOTES, 'UTF-8');
    }
}
