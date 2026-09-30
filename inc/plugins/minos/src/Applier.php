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
 *   failure mode: `fail-open` publishes, `fail-closed` keeps in the queue. Never a guess;
 * - `wsparcie` → the row is flagged and listed on the ACP page, whatever the verdict.
 *
 * The masked text replaces the message only when it is the whole post: not when the text
 * sent was cut at 3000 characters, not when its length differs from what was sent, not
 * when a mask falls in a new thread's subject (the plugin does not rewrite titles), and not
 * when nothing but the subject remains. Such a post stays in the queue. A published masked
 * post is plain text: its MyCode formatting is gone, because the gateway masks the plain
 * text it was sent. The original message is kept in the row for moderators.
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
     * Applies a delivered payload.
     *
     * @param array<string,mixed>                                                                $row      The claimed row.
     * @param array{status:string,kwalifikacja:?string,kategorie:array<int,string>,ocenzurowany:?string,wsparcie:bool} $payload  From `WebhookPayload::parse`.
     * @param Settings                                                                           $settings The settings.
     * @param int                                                                                $now      Unix seconds.
     * @return string The row's new status.
     */
    public function verdict(array $row, array $payload, Settings $settings, int $now): string
    {
        $record = [
            'verdict'    => $payload['kwalifikacja'] ?? $payload['status'],
            'categories' => substr(implode(',', $payload['kategorie']), 0, 255),
            'support'    => $payload['wsparcie'] ? 1 : 0,
        ];
        if ($payload['status'] !== WebhookPayload::ASSESSED || $payload['kwalifikacja'] === null) {
            $record['verdict'] = 'nieocenione';
            return $this->failure($row, 'nieocenione', $settings, $now, $record);
        }

        $post = $this->platform->post((int)$row['pid']);
        $status = $this->untouchable($post);
        if ($status === null) {
            switch ($payload['kwalifikacja']) {
                case 'bezpieczne':
                    $this->platform->approve($post);
                    $status = Status::PUBLISHED;
                    break;
                case 'ocenzurowane':
                    $status = $this->censor($row, $post, $payload['ocenzurowany'], $settings);
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
     * @param string                   $reason   `nieocenione`, `timeout`, `config_error` or `no_text`.
     * @param Settings                 $settings The settings.
     * @param int                      $now      Unix seconds.
     * @param array<string,int|string> $record   More columns to record.
     * @return string The row's new status.
     */
    public function failure(array $row, string $reason, Settings $settings, int $now, array $record = []): string
    {
        $post = $this->platform->post((int)$row['pid']);
        $status = $this->untouchable($post);
        if ($status === null) {
            if ($settings->failOpen()) {
                $this->platform->approve($post);
                $status = Status::PUBLISHED;
            } else {
                $status = Status::HELD;
            }
        }
        $this->platform->updatePending((int)$row['pid'],
            ['verdict' => $reason] + $record + ['status' => $status, 'decided_at' => $now]);
        return $status;
    }

    /**
     * The status for a post the plugin must not touch, or null when it may.
     *
     * @param array<string,mixed>|null $post The post as it is now.
     * @return string|null {@see Status::GONE}, {@see Status::SUPERSEDED} or null.
     */
    private function untouchable(?array $post): ?string
    {
        if ($post === null) {
            return Status::GONE;
        }
        return $post['visible'] === 0 ? null : Status::SUPERSEDED;
    }

    /**
     * `ocenzurowane`: publish the masked text when it stands for the whole post.
     *
     * @param array<string,mixed>      $row     The row.
     * @param array<string,mixed>      $post    The post.
     * @param string|null              $masked  `ocenzurowany`.
     * @param Settings                 $settings The settings.
     * @return string The new status.
     */
    private function censor(array $row, array $post, ?string $masked, Settings $settings): string
    {
        if (!$settings->publishCensored() || $masked === null || (int)$row['truncated'] === 1
            || Text::length($masked) !== (int)$row['text_len']) {
            return Status::HELD;
        }
        $prefix = (int)$row['prefix_len'];
        if ($prefix > 0 && strpos(Text::head($masked, $prefix), Text::MASK) !== false) {
            return Status::HELD;
        }
        $body = Text::tail($masked, $prefix);
        if (trim($body) === '') {
            return Status::HELD;
        }
        // The original goes into the row first, so it survives whatever happens next.
        $this->platform->updatePending($post['pid'], ['original_text' => $post['message']]);
        $this->platform->replaceMessage($post['pid'], $body);
        $this->platform->approve($post);
        return Status::CENSORED;
    }
}
