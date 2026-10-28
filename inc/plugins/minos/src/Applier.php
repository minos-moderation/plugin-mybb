<?php

declare(strict_types=1);

namespace Minos\MyBB;

use Minos\Client\WebhookPayload;

/**
 * Applies what the gateway said about a held post — or what the administrator chose for
 * when it said nothing — to the post, and records the outcome in its row.
 *
 * The caller has claimed the row ({@see Platform::claimPending}), so each post is applied
 * once. Before touching the post it is read again: a post that is gone, or that a moderator
 * (or its author, by editing it) already dealt with, is left alone — a human decision beats
 * the plugin's.
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
 * - `wsparcie` → the row is flagged and listed on the ACP page, whatever the verdict.
 *
 * A verdict that arrives AFTER the failure mode published a post ({@see late}) is still
 * applied while the post is public and untouched: `zablokowane` sends it back to the
 * queue, `ocenzurowane` follows the setting.
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
 * a mask falls in a new thread's subject (the plugin does not rewrite titles); not when
 * nothing but the subject remains; and not unless the body that was sent IS the message as
 * stored (line endings aside) — a post with MyCode, HTML or entities renders to another
 * text, and publishing its mask would revive or drop markup. Such a post stays in the
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
     * Applies a delivered payload to a row that was waiting for it.
     *
     * @param array<string,mixed>                                                                $row      The claimed row.
     * @param array{status:string,kwalifikacja:?string,kategorie:array<int,string>,ocenzurowany:?string,wsparcie:bool} $payload  From `WebhookPayload::parse`.
     * @param Settings                                                                           $settings The settings.
     * @param int                                                                                $now      Unix seconds.
     * @return string The row's new status.
     */
    public function verdict(array $row, array $payload, Settings $settings, int $now): string
    {
        $record = self::record($payload);
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
                        $this->platform->approve($post);
                        $status = Status::PUBLISHED;
                        break;
                    case 'ocenzurowane':
                        $status = $this->censor($row, $post, $payload['ocenzurowany'], $sent, $settings)
                            ? $this->approved($post, Status::CENSORED)
                            : Status::HELD;
                        break;
                    default: // 'zablokowane' — parse() admits nothing else
                        if ($settings->softDeleteBlocked()) {
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
                $this->platform->approve($post);
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
     * - `zablokowane` → back to the moderation queue;
     * - `ocenzurowane` → the masked text, by setting and when it stands for the whole post;
     *   otherwise back to the queue;
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
        $pid = (int)$row['pid'];
        $qualification = $payload['status'] === WebhookPayload::ASSESSED ? $payload['kwalifikacja'] : null;
        $post = $this->platform->post($pid);
        $status = self::untouchable($post, 1);
        if ($status !== null) {
            $this->platform->updatePending($pid, ['status' => $status, 'auto_published' => 0]);
            return $status;
        }
        $sent = $this->sentText($row, $post);
        if ($sent === null) {
            // Not the text that was assessed: nothing is applied, and no later verdict will be.
            $this->platform->updatePending($pid, ['status' => Status::PUBLISHED, 'auto_published' => 0]);
            return Status::PUBLISHED;
        }
        if ($qualification === null || ($qualification === 'bezpieczne' && (int)$row['truncated'] === 1)) {
            $this->platform->updatePending($pid, ['status' => Status::PUBLISHED]);
            return Status::PUBLISHED;
        }

        $record = self::record($payload) + ['auto_published' => 0, 'decided_at' => $now];
        if ($qualification === 'bezpieczne') {
            $status = Status::PUBLISHED;
        } elseif ($qualification === 'ocenzurowane' && $this->censor($row, $post, $payload['ocenzurowany'], $sent, $settings)) {
            $status = Status::CENSORED;
        } else {
            $this->platform->unapprove($post);
            $status = Status::HELD;
        }
        $this->platform->updatePending($pid, $record + self::maskKept($status, $payload) + ['status' => $status]);
        return $status;
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
            if ($this->platform->claimPending($pid, $now)) {
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
        $text = Text::forPost($post['message'], (int)$row['is_thread'] === 1 ? $post['subject'] : '',
            $this->platform->forumParsing($post['fid']));
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
     * Approves a post and returns the status to record.
     *
     * @param array<string,mixed> $post
     */
    private function approved(array $post, string $status): string
    {
        $this->platform->approve($post);
        return $status;
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
