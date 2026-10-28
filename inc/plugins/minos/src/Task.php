<?php

declare(strict_types=1);

namespace Minos\MyBB;

/**
 * The periodic job (`inc/tasks/minos.php`, every 5 minutes through MyBB's task system).
 *
 * 0. A row claimed more than 5 minutes ago goes back to the status it was claimed from:
 *    the process that claimed it died before recording an outcome.
 * 1. A row that waited longer than the receive timeout gets the failure mode: accepted by
 *    the gateway but never answered (its worker was down for the whole TTL, or deliveries
 *    to the webhook fail), or never accepted at all. The contract: "treat an item without
 *    an answer after the TTL plus a few minutes as `nieocenione`".
 * 2. Rows whose retry is due are sent again — only while the plugin is on and configured;
 *    otherwise they wait for step 1.
 * 3. Old log entries (30 days) and rows decided long ago (90 days, with the original text
 *    of censored posts) are forgotten.
 *
 * While the plugin is switched off ("Włączona = nie") the run does none of steps 0–2: no
 * claim is given back, no failure mode applied, nothing sent — switching it off releases
 * nothing already held, and those posts stay in MyBB's queue for humans. Step 3 still runs:
 * forgetting old data is no moderation. The run notes that the plugin was off (so does a
 * deactivation, {@see Installer::deactivate}); the first run after it is switched on again
 * sends what was waiting again, with a fresh timeout, before step 1 could fail it.
 *
 * Each run handles at most {@see LIMIT} rows per step; the rest wait five minutes. A row
 * that throws is logged and left for the next run ({@see Applier::failedStep}); it never
 * stops the others.
 * No PHP 8 syntax.
 */
final class Task
{
    /** Rows per step and run. */
    public const LIMIT = 100;

    /** A claim older than this was left by a process that died: its row is given back. */
    public const CLAIM_TTL_S = 300;

    /** How long log entries are kept. */
    public const LOG_RETENTION_S = 30 * 86400;

    /** How long decided rows are kept. */
    public const DECIDED_RETENTION_S = 90 * 86400;

    /** The state key ({@see Platform::state}) set while the plugin is switched off. */
    public const SWITCHED_OFF = 'switched_off';

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
        if (!$settings->enabled()) {
            if (!isset($this->platform->state()[self::SWITCHED_OFF])) {
                $this->platform->setState(self::SWITCHED_OFF, $now);
            }
            $this->forget($now);
            return ['timeouts' => 0, 'resent' => 0];
        }
        $this->platform->releaseStaleClaims($now - self::CLAIM_TTL_S);
        if (isset($this->platform->state()[self::SWITCHED_OFF])) {
            // On again: what waited while the plugin was off is sent again, with a fresh
            // timeout, instead of getting the failure mode all at once.
            $this->platform->rearmWaiting($now);
            $this->platform->setState(self::SWITCHED_OFF, null);
            $this->platform->log('resumed', '', 0, $now);
        }

        $timeouts = 0;
        foreach ($this->platform->expiredPending($now - $settings->timeoutS(), self::LIMIT) as $row) {
            // One row that throws (a hook of another plugin inside `Moderation`, say) is that
            // row's problem: the others are still timed out, retried and pruned.
            try {
                if ($this->platform->claimPending((int)$row['pid'], $now)) {
                    $this->applier->failure($row, Status::REASON_TIMEOUT, $settings, $now);
                    $this->platform->log('timeout', '', (int)$row['pid'], $now);
                    $timeouts++;
                }
            } catch (\Throwable $e) {
                $this->applier->failedStep((int)$row['pid'], 'timeout', $now);
            }
        }

        $resent = 0;
        if ($settings->active()) {
            $resent = $this->submitter->resubmit($this->platform->dueRetries($now, self::LIMIT), $settings, $now);
        }

        $this->forget($now);
        return ['timeouts' => $timeouts, 'resent' => $resent];
    }

    /**
     * Step 3: old log entries and rows decided long ago are forgotten.
     *
     * @param int $now Unix seconds.
     */
    private function forget(int $now): void
    {
        $this->platform->pruneLog($now - self::LOG_RETENTION_S);
        $this->platform->pruneDecided($now - self::DECIDED_RETENTION_S);
    }
}
