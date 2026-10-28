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
 * 3. `X-Wergiliusz-Podpis` is verified with the client library's `Signature::verify`
 *    (a fresh timestamp, a matching HMAC, compared in constant time): `401` otherwise, and
 *    the gateway tries again. Without a usable stored secret nothing can be verified:
 *    `401` too.
 * 4. `WebhookPayload::parse` reads it (`400` for a body that is no payload at all). An id
 *    that is not `mybb:<pid>`, a post the plugin is not waiting for, and a delivery already
 *    applied (deliveries repeat) get `200` and nothing else.
 * 5. The verdict is applied ({@see Applier}) — the claim on the row makes sure a repeated or
 *    concurrent delivery applies nothing twice. A verdict for a post the failure mode
 *    already published (`fail-open`) is applied late while nobody touched the post
 *    ({@see Applier::late}); a moderator's decision stays final. When applying throws, the
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
        if ($signature === null || !Signature::verify($secret, $signature, $body, $now)) {
            return 401;
        }
        $payload = WebhookPayload::parse($body);
        if ($payload === null) {
            return 400;
        }
        if (!preg_match('/^' . preg_quote(Submitter::ID_PREFIX, '/') . '([1-9][0-9]{0,9})\z/', $payload['id'], $m)) {
            return 200; // not an id this plugin sends
        }
        $pid = (int)$m[1];
        $row = $this->platform->pending($pid);
        if ($row === null) {
            return 200; // unknown
        }
        if ($this->platform->claimPending($pid, $now)) {
            try {
                $this->applier->verdict($row, $payload, $settings, $now);
            } catch (\Throwable $e) {
                $this->applier->failedStep($pid, 'verdict', $now);
                return 500; // the gateway delivers again
            }
        } elseif ((int)$row['auto_published'] === 1
            && $this->platform->claimPending($pid, $now, [Status::PUBLISHED], true)) {
            // Published by the failure mode before this verdict came: still applied.
            try {
                $this->applier->late($row, $payload, $settings, $now);
            } catch (\Throwable $e) {
                $this->applier->failedStep($pid, 'late', $now);
                return 500;
            }
        }
        return 200; // applied, or already handled
    }
}
