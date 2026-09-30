<?php

declare(strict_types=1);

namespace Minos\MyBB;

/**
 * The periodic job (`inc/tasks/minos.php`, every 5 minutes through MyBB's task system).
 *
 * 1. A row that waited longer than the receive timeout gets the failure mode: accepted by
 *    the gateway but never answered (its worker was down for the whole TTL, or deliveries
 *    to the webhook fail), or never accepted at all. The contract: "treat an item without
 *    an answer after the TTL plus a few minutes as `nieocenione`".
 * 2. Rows whose retry is due are sent again — only while the plugin is on and configured;
 *    otherwise they wait for step 1.
 * 3. Old log entries (30 days) and rows decided long ago (90 days, with the original text
 *    of censored posts) are forgotten.
 *
 * Each run handles at most {@see LIMIT} rows per step; the rest wait five minutes.
 * No PHP 8 syntax.
 */
final class Task
{
    /** Rows per step and run. */
    public const LIMIT = 100;

    /** How long log entries are kept. */
    public const LOG_RETENTION_S = 30 * 86400;

    /** How long decided rows are kept. */
    public const DECIDED_RETENTION_S = 90 * 86400;

    /** @var Platform */
    private $platform;

    /** @var Submitter */
    private $submitter;

    /** @var Applier */
    private $applier;

    public function __construct(Platform $platform, Submitter $submitter, Applier $applier)
    {
        $this->platform = $platform;
        $this->submitter = $submitter;
        $this->applier = $applier;
    }

    /**
     * One run.
     *
     * @param int $now Unix seconds.
     * @return array{timeouts:int,resent:int} What it did.
     */
    public function run(int $now): array
    {
        $settings = new Settings($this->platform->settings());

        $timeouts = 0;
        foreach ($this->platform->expiredPending($now - $settings->timeoutS(), self::LIMIT) as $row) {
            if ($this->platform->claimPending((int)$row['pid'])) {
                $this->applier->failure($row, Status::REASON_TIMEOUT, $settings, $now);
                $this->platform->log('timeout', '', (int)$row['pid'], $now);
                $timeouts++;
            }
        }

        $resent = 0;
        if ($settings->active()) {
            $resent = $this->submitter->resubmit($this->platform->dueRetries($now, self::LIMIT), $settings, $now);
        }

        $this->platform->pruneLog($now - self::LOG_RETENTION_S);
        $this->platform->pruneDecided($now - self::DECIDED_RETENTION_S);
        return ['timeouts' => $timeouts, 'resent' => $resent];
    }
}
