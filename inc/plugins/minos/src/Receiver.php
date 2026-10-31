<?php

declare(strict_types=1);

namespace Minos\MyBB;

use Minos\Client\Signature;
use Minos\Client\WebhookPayload;

/**
 * The webhook: what `minos-webhook.php` does with one delivery, in the order of the
 * contract's receiving checklist.
 *
 * 1. The body is the RAW bytes (`php://input`), read by the entry script before anything
 *    parses it.
 * 2. A plugin that is deactivated, or switched off ("Włączona = nie"), does nothing: `404`,
 *    before the signature is even looked at, and no row or post changes. The gateway
 *    retries any answer but `2xx` until its TTL, then drops the verdict; the posts already
 *    held stay in MyBB's queue for humans.
 * 3. `X-Wergiliusz-Podpis` is checked with the client library's `Signature::check`
 *    (a fresh timestamp, a matching HMAC, compared in constant time): `401` otherwise, and
 *    the gateway tries again. A refusal is counted with its reason only — `malformed`,
 *    `stale` or `mismatch`, never the body, the header or the secret — in the datacache
 *    entry {@see REJECTED}, so the ACP can tell the administrator that verdicts arrive but
 *    their signature does not match ({@see Admin::notice}); the next verified delivery
 *    forgets it, and so does a newly saved secret. Without a usable stored secret nothing
 *    can be verified: `401` too, and nothing counted (the ACP lists the missing secret).
 * 4. `WebhookPayload::parse` reads it (`400` for a body that is no payload at all). An id
 *    that is not `mybb:<pid>` or `mybb:<pid>.<n>`, a post the plugin is not waiting for, a
 *    verdict for a revision an author's edit has replaced ({@see Submitter::onUpdated}),
 *    and a delivery already applied (deliveries repeat) get `200` and nothing else.
 * 5. The verdict is applied ({@see Applier}) — the claim on the row makes sure a repeated or
 *    concurrent delivery applies nothing twice. A verdict for a post the failure mode
 *    already published (`fail-open`) is applied late while nobody touched the post
 *    ({@see Applier::late}); a moderator's decision stays final. A verdict for a post the
 *    failure mode held after its timeout (`fail-closed`, `timeout`) is applied as if it
 *    had come in time ({@see Applier::verdict}: shared rule D9.10), while the post is still
 *    in the queue — a moderator who approved or deleted it, or edited it, decided; an
 *    author's edit started a new revision, which this verdict is not about. When applying throws, the
 *    claim is given back and the answer is `500`, so the gateway delivers again and the
 *    redelivery applies the verdict ({@see Applier::failedStep}: after three failures the
 *    post is left to a human).
 * 6. The answer is a bare `200`; the gateway discards its body. The work in step 5 is a
 *    few queries, well inside the gateway's 10 seconds.
 *
 * The answers never say more than their status: no reason, no id, no secret.
 * No PHP 8 syntax.
 */
final class Receiver
{
    /**
     * The datacache entry (under {@see Platform::STATE_PREFIX}) of the signatures refused
     * since a delivery last passed: `count`, the last `reason`, its moment `at`.
     */
    public const REJECTED = 'signature_rejected';

    /** The reasons `Signature::check` gives for a refusal. */
    public const REJECTION_REASONS = [Signature::MALFORMED, Signature::STALE, Signature::MISMATCH];

    /** @var Platform */
    private $platform;

    /** @var Applier */
    private $applier;

    public function __construct(Platform $platform, Applier $applier)
    {
        $this->platform = $platform;
        $this->applier = $applier;
    }

    /**
     * Handles one delivery.
     *
     * @param string      $method    The HTTP method.
     * @param string|null $signature The `X-Wergiliusz-Podpis` header, or null.
     * @param string      $body      The exact bytes of the body.
     * @param int         $now       Unix seconds.
     * @return int The HTTP status to answer with.
     */
    public function handle(string $method, ?string $signature, string $body, int $now): int
    {
        if (strtoupper($method) !== 'POST') {
            return 405;
        }
        $settings = new Settings($this->platform->settings());
        if (!$this->platform->isPluginActive() || !$settings->enabled()) {
            return 404; // switched off: nothing is applied, the posts stay in the queue
        }
        $secret = $settings->webhookSecret();
        if ($secret === null) {
            return 401; // nothing can be verified without a secret
        }
        $check = Signature::check($secret, (string)$signature, $body, $now);
        if ($check !== Signature::OK) {
            $this->recordRejection($check, $now);
            return 401;
        }
        $this->forgetRejections();
        $payload = WebhookPayload::parse($body);
        if ($payload === null) {
            return 400;
        }
        if (!preg_match('/^' . preg_quote(Submitter::ID_PREFIX, '/') . '([1-9][0-9]{0,9})(?:'
            . preg_quote(Submitter::REVISION_SEPARATOR, '/') . '([1-9][0-9]{0,4}))?\z/', $payload['id'], $m)) {
            return 200; // not an id this plugin sends
        }
        $pid = (int)$m[1];
        $rev = isset($m[2]) ? (int)$m[2] : 0;
        $row = $this->platform->pending($pid);
        if ($row === null || (int)$row['rev'] !== $rev) {
            return 200; // unknown, or a revision an edit has since replaced
        }
        // The claims name the revision too: an edit between the read above and the claim
        // starts a new one, and this verdict is then not applied.
        if ($this->platform->claimPending($pid, $now, Status::OPEN, false, $rev)) {
            try {
                $this->applier->verdict($row, $payload, $settings, $now);
            } catch (\Throwable $e) {
                $this->applier->failedStep($pid, 'verdict', $now);
                return 500; // the gateway delivers again
            }
        } elseif ((int)$row['auto_published'] === 1
            && $this->platform->claimPending($pid, $now, [Status::PUBLISHED], true, $rev)) {
            // Published by the failure mode before this verdict came: still applied.
            try {
                $this->applier->late($row, $payload, $settings, $now);
            } catch (\Throwable $e) {
                $this->applier->failedStep($pid, 'late', $now);
                return 500;
            }
        } elseif ($this->heldOnlyForTime($row) && $this->platform->claimTimedOut($pid, $now, $rev)) {
            // Held by the failure mode because this verdict was late: applied as on time.
            try {
                $this->applier->verdict($row, $payload, $settings, $now);
            } catch (\Throwable $e) {
                $this->applier->failedStep($pid, 'verdict', $now);
                return 500;
            }
        }
        return 200; // applied, or already handled
    }

    /**
     * Whether a row is one the failure mode held because no verdict came in time
     * (`fail-closed`), and its post is still in the queue: nobody approved or deleted it
     * since. A moderator's edit marks the row decided ({@see Submitter::onUpdated}), an
     * author's starts a new revision; a text changed past the edit hook is caught by the
     * digest ({@see Applier::verdict}).
     *
     * @param array<string,mixed> $row The row.
     */
    private function heldOnlyForTime(array $row): bool
    {
        if ($row['status'] !== Status::HELD || $row['verdict'] !== Status::REASON_TIMEOUT) {
            return false;
        }
        $post = $this->platform->post((int)$row['pid']);
        return $post !== null && $post['visible'] === 0;
    }

    /**
     * The signatures refused since a delivery last passed, for the ACP.
     *
     * @param Platform $platform The forum.
     * @return array{count:int,reason:string,at:int}|null Null when there were none (or the
     *     entry is not one this plugin wrote).
     */
    public static function rejections(Platform $platform): ?array
    {
        $entry = $platform->state(self::REJECTED);
        if (!is_array($entry) || !in_array($entry['reason'] ?? null, self::REJECTION_REASONS, true)) {
            return null;
        }
        return ['count' => max(1, (int)($entry['count'] ?? 1)), 'reason' => (string)$entry['reason'], 'at' => (int)($entry['at'] ?? 0)];
    }

    /**
     * Counts one refused signature: the reason and the moment, nothing of the delivery.
     */
    private function recordRejection(string $reason, int $now): void
    {
        if (!in_array($reason, self::REJECTION_REASONS, true)) {
            return;
        }
        $last = self::rejections($this->platform);
        $this->platform->setState(self::REJECTED, [
            'count'  => $last !== null ? min(999999999, $last['count'] + 1) : 1,
            'reason' => $reason,
            'at'     => $now,
        ]);
    }

    /**
     * A delivery passed: the refusals before it no longer tell anything.
     */
    private function forgetRejections(): void
    {
        if ($this->platform->state(self::REJECTED) !== null) {
            $this->platform->setState(self::REJECTED, null);
        }
    }
}
