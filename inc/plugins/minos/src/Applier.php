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
 * - `nieocenione`, no answer in time, a configuration error, a post with no text → the
 *   failure mode: `fail-open` publishes (and marks the row `auto_published`), `fail-closed`
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
 * The masked text replaces the message only when it is the whole post: not when the text
 * sent was cut at 3000 characters, not when its length differs from what was sent, not
 * when a mask falls in a new thread's subject (the plugin does not rewrite titles), and not
 * when nothing but the subject remains. Such a post stays in the queue, and so does one
 * whose message could not be replaced — the original is never published on that verdict.
 * A published masked post is plain text: its MyCode formatting is gone, because the
 * gateway masks the text it was sent. The original message is kept in the row.
 * No PHP 8 syntax.
 */
final class Applier
{
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
        if ($payload['kwalifikacja'] === 'bezpieczne' && (int)$row['truncated'] === 1) {
            unset($record['verdict']);
            return $this->failure($row, Status::REASON_TRUNCATED, $settings, $now, $record);
        }

        $post = $this->platform->post((int)$row['pid']);
        $status = self::untouchable($post, 0);
        if ($status === null) {
            switch ($payload['kwalifikacja']) {
                case 'bezpieczne':
                    $this->platform->approve($post);
                    $status = Status::PUBLISHED;
                    break;
                case 'ocenzurowane':
                    $status = $this->censor($row, $post, $payload['ocenzurowany'], $settings)
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
        $this->platform->updatePending((int)$row['pid'], $record + ['status' => $status, 'decided_at' => $now]);
        return $status;
    }

    /**
     * Applies the failure mode: no usable verdict.
     *
     * @param array<string,mixed>      $row      The claimed row.
     * @param string                   $reason   `nieocenione`, `timeout`, `config_error`,
     *     `no_text` or `truncated`.
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
        if ($qualification === null || ($qualification === 'bezpieczne' && (int)$row['truncated'] === 1)) {
            $this->platform->updatePending($pid, ['status' => Status::PUBLISHED]);
            return Status::PUBLISHED;
        }

        $record = self::record($payload) + ['auto_published' => 0, 'decided_at' => $now];
        if ($qualification === 'bezpieczne') {
            $status = Status::PUBLISHED;
        } elseif ($qualification === 'ocenzurowane' && $this->censor($row, $post, $payload['ocenzurowany'], $settings)) {
            $status = Status::CENSORED;
        } else {
            $this->platform->unapprove($post);
            $status = Status::HELD;
        }
        $this->platform->updatePending($pid, $record + ['status' => $status]);
        return $status;
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
     * @param Settings            $settings The settings.
     * @return bool True when the message now IS the masked text (checked by reading it
     *     back); false leaves the post as it was.
     */
    private function censor(array $row, array $post, ?string $masked, Settings $settings): bool
    {
        if (!$settings->publishCensored() || $masked === null || (int)$row['truncated'] === 1
            || Text::length($masked) !== (int)$row['text_len']) {
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
        // The original goes into the row first, so it survives whatever happens next.
        $this->platform->updatePending($post['pid'], ['original_text' => $post['message']]);
        return $this->platform->replaceMessage($post['pid'], $body);
    }
}
