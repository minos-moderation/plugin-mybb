<?php

declare(strict_types=1);

namespace Minos\MyBB\Tests\Plugin;

use Minos\MyBB\Status;
use Minos\MyBB\Tests\Support\PluginTestCase;

/**
 * The ACP page's figures for the last 7 days: the time from the gateway's 202 to the
 * verdict (P50, P95) and how many of each verdict — posts submitted, verdicts delivered
 * through the webhook, the page rendered.
 */
final class VerdictFiguresTest extends PluginTestCase
{
    public function testThePageShowsThePercentilesAndTheCountsOfTheLastSevenDays(): void
    {
        // Ten verdicts, delivered this many seconds after their 202.
        $deliveries = [
            [3, []], [5, []], [8, ['kwalifikacja' => 'zablokowane']], [13, []], [21, []],
            [34, ['status' => 'nieocenione', 'kwalifikacja' => null]], [55, []],
            [89, ['kwalifikacja' => 'ocenzurowane', 'ocenzurowany' => 'Wpis ████.']], [144, ['kwalifikacja' => 'zablokowane']],
            [233, []],
        ];
        foreach ($deliveries as $i => [$seconds, $fields]) {
            $pid = $this->heldReply('Wpis ' . str_repeat('x', 4 - strlen((string)$i)) . $i . '.');
            self::assertSame(200, $this->forum->deliver(self::payload($pid, $fields), null, null, TIME_NOW + $seconds));
        }
        // Older than 7 days, and not answered yet: neither counts.
        $old = $this->heldReply('Stary wpis.');
        $this->forum->deliver(self::payload($old, ['kwalifikacja' => 'zablokowane']), null, null, TIME_NOW + 2);
        $this->forum->platform()->updatePending($old, ['accepted_at' => TIME_NOW - 9 * 86400, 'answered_at' => TIME_NOW - 8 * 86400]);
        $this->heldReply('Czeka.');

        $figures = $this->forum->plugin->admin->verdictFigures(TIME_NOW + 300);

        self::assertSame(['p50' => 21, 'p95' => 233, 'timed' => 10,
            'counts' => ['bezpieczne' => 6, 'ocenzurowane' => 1, 'zablokowane' => 2, 'nieocenione' => 1]], $figures);
        $lang = $this->forum->platform();
        $page = $this->forum->plugin->admin->page(TIME_NOW + 300);
        foreach ([
            [$lang->lang('minos_acp_figures_p50'), $lang->lang('minos_acp_seconds', '21')],
            [$lang->lang('minos_acp_figures_p95'), $lang->lang('minos_acp_minutes', '3', '53')],
            ['bezpieczne', '6'], ['ocenzurowane', '1'], ['zablokowane', '2'], ['nieocenione', '1'],
        ] as [$label, $value]) {
            self::assertStringContainsString('<td>' . self::e($label) . '</td><td>' . self::e($value) . '</td>', $page, $label);
        }
    }

    /**
     * Nearest rank over forty verdicts 1…40 s after their 202: P50 is the 20th, P95 the 38th —
     * not the slowest.
     */
    public function testThePercentilesAreNearestRank(): void
    {
        for ($i = 1; $i <= 40; $i++) {
            $pid = $this->heldReply('Wpis numer ' . $i . '.');
            $this->forum->deliver(self::payload($pid), null, null, TIME_NOW + $i);
        }
        $figures = $this->forum->plugin->admin->verdictFigures(TIME_NOW + 60);
        self::assertSame([20, 38, 40], [$figures['p50'], $figures['p95'], $figures['timed']]);
    }

    /**
     * The answer the gateway gave is what counts, whatever the plugin then did with it: a
     * safe verdict on a cut post (the failure mode), a verdict for a changed text (held), a
     * late verdict on a post the failure mode published.
     */
    public function testEveryDeliveredVerdictCountsAsTheGatewayGaveIt(): void
    {
        $cut = $this->heldReply(str_repeat('a', 3100));
        $this->forum->deliver(self::payload($cut), null, null, TIME_NOW + 10);
        $changed = $this->heldReply('Przed zmianą.');
        $this->forum->db->update_query('posts', ['message' => 'Po zmianie.'], "pid='" . $changed . "'");
        $this->forum->deliver(self::payload($changed), null, null, TIME_NOW + 20);
        $this->forum->set(['minos_failure_mode' => 'fail-open']);
        $late = $this->heldReply('Opublikowany bez oceny.');
        $this->forum->platform()->updatePending($late, ['accepted_at' => TIME_NOW - 21 * 60]);
        $this->forum->plugin->task->run(TIME_NOW);
        $this->forum->deliver(self::payload($late, ['kwalifikacja' => 'zablokowane']), null, null, TIME_NOW + 30);

        self::assertSame([Status::HELD, Status::HELD, Status::HELD],
            [$this->forum->row($cut)['status'], $this->forum->row($changed)['status'], $this->forum->row($late)['status']]);
        $figures = $this->forum->plugin->admin->verdictFigures(TIME_NOW + 60);
        self::assertSame(['bezpieczne' => 2, 'ocenzurowane' => 0, 'zablokowane' => 1, 'nieocenione' => 0], $figures['counts']);
        self::assertSame([20, 21 * 60 + 30, 3], [$figures['p50'], $figures['p95'], $figures['timed']]);
    }

    public function testWithoutVerdictsThePageSaysSoAndStoresNoText(): void
    {
        $pid = $this->heldReply('Tekst, którego nie ma na stronie.');
        $page = $this->forum->plugin->admin->page(TIME_NOW);

        self::assertStringContainsString(self::e($this->forum->platform()->lang('minos_acp_figures_none')), $page);
        $this->forum->deliver(self::payload($pid), null, null, TIME_NOW + 4);
        self::assertStringNotContainsString('Tekst, którego', (string)json_encode($this->forum->row($pid), JSON_UNESCAPED_UNICODE));
    }

    private static function e(string $text): string
    {
        return htmlspecialchars($text, ENT_QUOTES, 'UTF-8');
    }
}
