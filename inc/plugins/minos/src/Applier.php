<?php

declare(strict_types=1);

namespace Minos\MyBB;

use Minos\Client\WebhookPayload;

/**
 * Applies what the gateway said about a held post — or what the administrator chose for
 * when it said nothing — to the post, and records the outcome in its row.
 *
 * The caller has claimed the row ({@see Platform::claimPending}), so each post is applied
 * once (and, for a verdict, only at the revision it is about). Before touching the post it
 * is read again: a post that is gone, or that a moderator already dealt with, is left alone —
 * a human decision beats the plugin's.
 *
 * The rules, from the contract's receiving checklist:
 * - `bezpieczne` → publish (approve);
 * - `ocenzurowane` → publish the masked text, or keep in the queue — by setting, and
 *   always keep it when the masked text cannot stand for the whole post (below);
 * - `zablokowane` → keep in the queue, or soft-delete — by setting;
 * - `nieocenione`, no answer in time, a configuration error, a post with no text or one
 *   whose text could not be read → the failure mode: `fail-open` publishes (and marks the row `auto_published`), `fail-closed`
 *   keeps in the queue. Never a guess;
 * - a post longer than the 3000 characters sent: the verdict judged its beginning only,
 *   so a publishing verdict (`bezpieczne`) goes to the failure mode too; `zablokowane`
 *   still holds it;
 * - `wsparcie` → whatever the verdict, the moderators get a report in the ModCP's report
 *   centre and the row is listed on the ACP page; such a post is never deleted, rejected or
 *   hidden automatically — `zablokowane` keeps it held even with the soft-delete setting, a
 *   late `zablokowane` leaves it published (shared rule D9.9). Nothing goes to its author.
 *
 * A post the plugin publishes from the queue (a verdict, the failure mode in `fail-open`)
 * notifies its subscribers as MyBB notifies them of a post visible at once, by setting and
 * once ({@see publish}, shared rule D9.8).
 *
 * A verdict that arrives AFTER the failure mode published a post ({@see late}) is still
 * applied while the post is public and untouched: `zablokowane` sends it back to the
 * queue, `ocenzurowane` follows the setting. One that arrives after the failure mode held
 * the post for its timeout (`fail-closed`) is applied by {@see verdict} as if it had come in
 * time, while the post is still in the queue and untouched (shared rule D9.10).
 *
 * A verdict applies only to the text that was sent. Before any verdict is applied, the
 * post is rendered again ({@see Text::forPost}, with its forum's current options) and its
 * SHA-256 compared with the one the row kept at submission: a text changed past the edit
 * hook (or a row from before digests were kept) does not match, and the verdict is not
 * applied — on time the post stays in the queue for a human (`text_changed`), late
 * nothing happens.
 *
 * The masked text replaces the message only when it stands for the whole post, character
 * by character: not when the text sent was cut at 3000 characters; not unless every
 * character of the mask is the one that was sent or `█` ({@see Text::maskFits}); not when
 * a mask falls in what heads the text — a subject, a poll (the plugin never rewrites
 * titles, subjects or polls); not when nothing but that head remains; and not unless the body that was sent IS the message as
 * stored (line endings aside) — a post with MyCode, HTML or entities renders to another
 * text, and publishing its mask would revive or drop markup; so does a plain one with
 * whitespace the text squeezes (double spaces, tabs, NBSP, blank lines in a row), which
 * stays held too (the safe direction). Such a post stays in the
 * queue with the mask kept in `masked_text` for the ACP page, and so does one whose
 * message could not be replaced — the original is never published on that verdict. The
 * original message is kept in the row of a published masked post.
 * No PHP 8 syntax.
 */
final class Applier
{
    /** Steps that may throw for one row before it is left to a human. */
    public const MAX_FAILURES = 3;

    /** @var Platform */
    private $platform;

    public function __construct(Platform $platform)
    {
        $this->platform = $platform;
    }

    /**
     * Applies a delivered payload to a row that was waiting for it — or that the failure mode
     * held for its timeout, the verdict coming late ({@see Receiver}): the same rules, as on
     * time.
     *
     * @param array<string,mixed>                                                                $row      The claimed row.
     * @param array{status:string,kwalifikacja:?string,kategorie:array<int,string>,ocenzurowany:?string,wsparcie:bool} $payload  From `WebhookPayload::parse`.
     * @param Settings                                                                           $settings The settings.
     * @param int                                                                                $now      Unix seconds.
     * @return string The row's new status.
     */
    public function verdict(array $row, array $payload, Settings $settings, int $now): string
    {
        $status = $this->onTime($row, $payload, $settings, $now);
        $this->signalSupport($row, $payload, $status, $now);
        return $status;
    }

    /**
     * {@see verdict}, before the `wsparcie` signal.
     *
     * @param array<string,mixed> $row
     * @param array{status:string,kwalifikacja:?string,kategorie:array<int,string>,ocenzurowany:?string,wsparcie:bool} $payload
     */
    private function onTime(array $row, array $payload, Settings $settings, int $now): string
    {
        $record = self::record($payload) + self::answered($payload, $now);
        if ($payload['status'] !== WebhookPayload::ASSESSED || $payload['kwalifikacja'] === null) {
            return $this->failure($row, 'nieocenione', $settings, $now, $record);
        }

        $post = $this->platform->post((int)$row['pid']);
        $status = self::untouchable($post, 0);
        if ($status === null) {
            $sent = $this->sentText($row, $post);
            if ($sent === null) {
                // Not the text that was assessed: the verdict judged another one.
                $record['verdict'] = Status::REASON_TEXT_CHANGED;
                $status = Status::HELD;
            } elseif ($payload['kwalifikacja'] === 'bezpieczne' && (int)$row['truncated'] === 1) {
                unset($record['verdict']);
                return $this->failure($row, Status::REASON_TRUNCATED, $settings, $now, $record);
            } else {
                switch ($payload['kwalifikacja']) {
                    case 'bezpieczne':
                        $this->publish($post, $settings, $now);
                        $status = Status::PUBLISHED;
                        break;
                    case 'ocenzurowane':
                        if ($this->censor($row, $post, $payload['ocenzurowany'], $sent, $settings)) {
                            $this->publish($post, $settings, $now);
                            $status = Status::CENSORED;
                        } else {
                            $status = Status::HELD;
                        }
                        break;
                    default: // 'zablokowane' — parse() admits nothing else
                        // A post with `wsparcie` is never deleted automatically (D9.9): it
                        // stays held until a person decides.
                        if ($settings->softDeleteBlocked() && !$payload['wsparcie']) {
                            $this->platform->softDelete($post);
                            $status = Status::DELETED;
                        } else {
                            $status = Status::HELD;
                        }
                }
            }
        }
        $this->platform->updatePending((int)$row['pid'],
            $record + self::maskKept($status, $payload) + ['status' => $status, 'decided_at' => $now]);
        return $status;
    }

    /**
     * Applies the failure mode: no usable verdict.
     *
     * @param array<string,mixed>      $row      The claimed row.
     * @param string                   $reason   `nieocenione`, `timeout`, `config_error`,
     *     `no_text`, `text_error` or `truncated`.
     * @param Settings                 $settings The settings.
     * @param int                      $now      Unix seconds.
     * @param array<string,int|string> $record   More columns to record.
     * @return string The row's new status.
     */
    public function failure(array $row, string $reason, Settings $settings, int $now, array $record = []): string
    {
        $post = $this->platform->post((int)$row['pid']);
        $status = self::untouchable($post, 0);
        $auto = 0;
        if ($status === null) {
            if ($settings->failOpen()) {
                $this->publish($post, $settings, $now);
                $status = Status::PUBLISHED;
                $auto = 1;
            } else {
                $status = Status::HELD;
            }
        }
        $this->platform->updatePending((int)$row['pid'],
            ['verdict' => $reason, 'auto_published' => $auto] + $record + ['status' => $status, 'decided_at' => $now]);
        return $status;
    }

    /**
     * Applies a verdict that came after the failure mode published the post (the row is
     * `published` with `auto_published = 1`, claimed from there). While the post is still
     * public and nobody touched it — an edit clears `auto_published`, a moderator's action
     * changes its visibility — the verdict is applied:
     * - `zablokowane` → back to the moderation queue — with `wsparcie`, it stays published
     *   (never hidden automatically, D9.9);
     * - `ocenzurowane` → the masked text, by setting and when it stands for the whole post;
     *   otherwise back to the queue (with `wsparcie`, published as it is);
     * - `bezpieczne` → it stays published (a cut post stays marked as published unassessed);
     * - `nieocenione` → nothing new.
     *
     * @param array<string,mixed>                                                                $row      The claimed row.
     * @param array{status:string,kwalifikacja:?string,kategorie:array<int,string>,ocenzurowany:?string,wsparcie:bool} $payload  From `WebhookPayload::parse`.
     * @param Settings                                                                           $settings The settings.
     * @param int                                                                                $now      Unix seconds.
     * @return string The row's new status.
     */
    public function late(array $row, array $payload, Settings $settings, int $now): string
    {
        $status = $this->afterPublication($row, $payload, $settings, $now);
        $this->signalSupport($row, $payload, $status, $now);
        return $status;
    }

    /**
     * {@see late}, before the `wsparcie` signal.
     *
     * @param array<string,mixed> $row
     * @param array{status:string,kwalifikacja:?string,kategorie:array<int,string>,ocenzurowany:?string,wsparcie:bool} $payload
     */
    private function afterPublication(array $row, array $payload, Settings $settings, int $now): string
    {
        $pid = (int)$row['pid'];
        $qualification = $payload['status'] === WebhookPayload::ASSESSED ? $payload['kwalifikacja'] : null;
        $answered = self::answered($payload, $now);
        $post = $this->platform->post($pid);
        $status = self::untouchable($post, 1);
        if ($status !== null) {
            $this->platform->updatePending($pid, ['status' => $status, 'auto_published' => 0] + $answered);
            return $status;
        }
        $sent = $this->sentText($row, $post);
        if ($sent === null) {
            // Not the text that was assessed: nothing is applied, and no later verdict will be.
            $this->platform->updatePending($pid, ['status' => Status::PUBLISHED, 'auto_published' => 0] + $answered);
            return Status::PUBLISHED;
        }
        if ($qualification === null || ($qualification === 'bezpieczne' && (int)$row['truncated'] === 1)) {
            $this->platform->updatePending($pid, ['status' => Status::PUBLISHED] + $answered);
            return Status::PUBLISHED;
        }

        $record = self::record($payload) + $answered + ['auto_published' => 0, 'decided_at' => $now];
        if ($qualification === 'bezpieczne') {
            $status = Status::PUBLISHED;
        } elseif ($qualification === 'ocenzurowane' && $this->censor($row, $post, $payload['ocenzurowany'], $sent, $settings)) {
            $status = Status::CENSORED;
        } elseif ($payload['wsparcie']) {
            // Never hidden automatically (D9.9): it stays published until a person decides;
            // the moderators get the signal.
            $status = Status::PUBLISHED;
        } else {
            $this->platform->unapprove($post);
            $status = Status::HELD;
        }
        $this->platform->updatePending($pid, $record + self::maskKept($status, $payload) + ['status' => $status]);
        return $status;
    }

    /**
     * `wsparcie` (shared rule D9.9, A7 of client-php's `docs/plugin-behaviour.md`): whatever
     * the verdict, the forum's moderators get an active signal in their own queue — a report
     * in the ModCP's report centre ({@see Platform::reportForSupport}), which MyBB also
     * announces to them at the top of every page. Never a message to the author. Raised once
     * per applied delivery while the post exists; a report still open for the post is not
     * doubled. One that fails is logged (`error`/`support`, the post id) and changes
     * nothing of the verdict.
     *
     * @param array<string,mixed>                       $row     The claimed row.
     * @param array{kwalifikacja:?string,wsparcie:bool} $payload The payload.
     * @param string                                    $status  The row's new status.
     */
    private function signalSupport(array $row, array $payload, string $status, int $now): void
    {
        if (!$payload['wsparcie'] || $status === Status::GONE) {
            return;
        }
        try {
            $this->platform->reportForSupport((int)$row['pid'], $this->platform->lang('minos_support_report',
                $payload['kwalifikacja'] ?? 'nieocenione'), $now);
        } catch (\Throwable $e) {
            $this->platform->log('error', 'support', (int)$row['pid'], $now);
        }
    }

    /**
     * A step that threw for one row (the task's timeout, a resubmission, a webhook's
     * verdict): the row's error alone, never the others'.
     *
     * The log gets the step and the post id — never the error's message, which may carry
     * the post's text or a secret. The row's claim is given back, so the task's next run or
     * the gateway's next delivery tries again; a step that applied part of a verdict before
     * it threw finds the post changed and leaves it ({@see untouchable}), never acting
     * twice. After {@see MAX_FAILURES} failed steps the row is left to a human: `held` with
     * `apply_error`, and nothing done to the post — the failure mode is no way out, since
     * under fail-open it approves, and approving may be what fails. A post the failure mode
     * already published stays published, and its late window closes.
     *
     * Never throws: a row whose own record cannot be written is left as it is.
     *
     * @param int    $pid  The post.
     * @param string $step What threw (`timeout`, `resubmit`, `send`, `verdict`, `late`).
     * @param int    $now  Unix seconds.
     */
    public function failedStep(int $pid, string $step, int $now): void
    {
        try {
            $this->platform->log('error', $step, $pid, $now);
            if ($this->platform->countFailure($pid) < self::MAX_FAILURES) {
                return;
            }
            if ($this->platform->claimPending($pid, $now) || $this->platform->claimTimedOut($pid, $now)) {
                $this->platform->updatePending($pid,
                    ['status' => Status::HELD, 'verdict' => Status::REASON_APPLY_ERROR, 'decided_at' => $now]);
            } elseif ($this->platform->claimPending($pid, $now, [Status::PUBLISHED], true)) {
                $this->platform->updatePending($pid, ['status' => Status::PUBLISHED, 'auto_published' => 0,
                    'verdict' => Status::REASON_APPLY_ERROR, 'decided_at' => $now]);
            }
        } catch (\Throwable $e) {
            // The database refuses even this: the next run or delivery meets the row as it is.
            return;
        }
    }

    /**
     * The columns a payload fills.
     *
     * @param array{status:string,kwalifikacja:?string,kategorie:array<int,string>,wsparcie:bool} $payload
     * @return array<string,int|string>
     */
    private static function record(array $payload): array
    {
        $qualification = $payload['status'] === WebhookPayload::ASSESSED ? $payload['kwalifikacja'] : null;
        return [
            'verdict'    => $qualification ?? 'nieocenione',
            'categories' => substr(implode(',', $payload['kategorie']), 0, 255),
            'support'    => $payload['wsparcie'] ? 1 : 0,
        ];
    }

    /**
     * What a delivered payload says, for the ACP's figures: the answer as the gateway gave
     * it and when it came — whatever the plugin then does with it.
     *
     * @param array{status:string,kwalifikacja:?string} $payload
     * @return array{answer:string,answered_at:int}
     */
    private static function answered(array $payload, int $now): array
    {
        $qualification = $payload['status'] === WebhookPayload::ASSESSED ? $payload['kwalifikacja'] : null;
        return ['answer' => $qualification ?? 'nieocenione', 'answered_at' => $now];
    }

    /**
     * The text the plugin would send for the post now, when it is the text that was sent.
     *
     * @param array<string,mixed> $row  The row, with the digest kept at submission.
     * @param array<string,mixed> $post The post as it is now ({@see Platform::post}).
     * @return string|null The text; null when it differs from what was sent, cannot be read,
     *     or the row kept no digest (sent before digests were kept).
     */
    private function sentText(array $row, array $post): ?string
    {
        $digest = (string)($row['sent_sha256'] ?? '');
        if (strlen($digest) !== 64) {
            return null;
        }
        $text = Text::ofPost($post, (int)$row['is_thread'] === 1, $this->platform->forumParsing($post['fid']),
            (int)($row['own_subject'] ?? 0) === 1);
        return !$text['error'] && hash_equals($digest, hash('sha256', $text['tekst'])) ? $text['tekst'] : null;
    }

    /**
     * The mask of a post the plugin keeps in the queue, for the ACP page.
     *
     * @param array{ocenzurowany:?string} $payload
     * @return array<string,string> `masked_text`, or nothing.
     */
    private static function maskKept(string $status, array $payload): array
    {
        return $status === Status::HELD && $payload['ocenzurowany'] !== null ? ['masked_text' => $payload['ocenzurowany']] : [];
    }

    /**
     * The status for a post the plugin must not touch, or null when it may.
     *
     * @param array<string,mixed>|null $post     The post as it is now.
     * @param int                      $expected The visibility the plugin left it with.
     * @return string|null {@see Status::GONE}, {@see Status::SUPERSEDED} or null.
     */
    private static function untouchable(?array $post, int $expected): ?string
    {
        if ($post === null) {
            return Status::GONE;
        }
        return $post['visible'] === $expected ? null : Status::SUPERSEDED;
    }

    /**
     * Publishes a held post: approves it the way a moderator does, then — by setting — sends
     * its thread's or forum's subscribers what MyBB sends for a post visible at once (shared
     * rule D9.8: MyBB sends nothing on an approval). Once: only the plugin's own publication
     * of a post from the queue gets here — a late verdict on a post the failure mode
     * published ({@see late}) never does, and a moderator's approval is MyBB's own.
     *
     * A notification that fails is that step's failure alone: logged by step (`notify`) and
     * post id, never its message; the post stays published.
     *
     * @param array<string,mixed> $post     The post ({@see Platform::post}).
     * @param Settings            $settings The settings.
     * @param int                 $now      Unix seconds.
     */
    private function publish(array $post, Settings $settings, int $now): void
    {
        $before = null;
        if ($settings->notifySubscribers()) {
            try {
                $before = $this->platform->lastPosts($post);
            } catch (\Throwable $e) {
                $this->platform->log('error', 'notify', (int)$post['pid'], $now);
            }
        }
        $this->platform->approve($post);
        if ($before === null) {
            return;
        }
        try {
            $this->platform->notifySubscribers($post, $before);
        } catch (\Throwable $e) {
            $this->platform->log('error', 'notify', (int)$post['pid'], $now);
        }
    }

    /**
     * `ocenzurowane`: replaces the message with the masked text when it stands for the
     * whole post and the administrator publishes censored posts.
     *
     * @param array<string,mixed> $row      The row.
     * @param array<string,mixed> $post     The post.
     * @param string|null         $masked   `ocenzurowany`.
     * @param string              $sent     The text that was sent (checked by its digest).
     * @param Settings            $settings The settings.
     * @return bool True when the message now IS the masked text (checked by reading it
     *     back); false leaves the post as it was.
     */
    private function censor(array $row, array $post, ?string $masked, string $sent, Settings $settings): bool
    {
        if (!$settings->publishCensored() || $masked === null || (int)$row['truncated'] === 1
            || !Text::maskFits($sent, $masked)) {
            return false;
        }
        $prefix = (int)$row['prefix_len'];
        if ($prefix > 0 && strpos(Text::head($masked, $prefix), Text::MASK) !== false) {
            return false;
        }
        $body = Text::tail($masked, $prefix);
        if (trim($body) === '') {
            return false;
        }
        // The body that was sent must be the message as stored, so the mask maps onto it
        // character by character; MyCode, HTML or an entity render to another text. Only
        // line endings may differ: the text normalises CRLF, which readers never see.
        if (Text::tail($sent, $prefix) !== str_replace(["\r\n", "\r"], "\n", $post['message'])) {
            return false;
        }
        // The original goes into the row first, so it survives whatever happens next.
        $this->platform->updatePending($post['pid'], ['original_text' => $post['message']]);
        return $this->platform->replaceMessage($post['pid'], $body);
    }
}
