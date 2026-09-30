<?php

declare(strict_types=1);

namespace Minos\MyBB;

use SplObjectStorage;

/**
 * Holds a new post in MyBB's moderation queue and sends it to the gateway.
 *
 * 1. `datahandler_post_validate_post` / `_thread` ({@see onValidate}): for a new post the
 *    plugin moderates, it makes MyBB itself choose `visible = 0` ({@see Platform::holdCurrentUsersPosts}).
 * 2. `datahandler_post_insert_post_end` / `_thread_end` ({@see onInserted}): the post has
 *    its `pid`; the plugin records a row and sends ONE item. The row is written first, so a
 *    verdict that arrives before the gateway's `202` finds it.
 * 3. The gateway's answer ({@see send}): `202` → wait for the webhook; `429`/`503`/no
 *    answer → the task sends it again after `ponow_za_s`, or after a backoff doubling from
 *    60 s; any other refusal → a configuration error: the failure mode is applied, the
 *    code (never the content) is logged, and the ACP shows a notice.
 *
 * A post the plugin does not touch: a draft, an edit, a post in a forum it does not
 * cover, a moderator's post (by setting), a post MyBB holds anyway (the forum moderates
 * new posts, or the user is under moderation — humans asked for those), a post written on
 * someone else's behalf, and every post while the plugin is off or misconfigured.
 *
 * What an item carries: `id` `mybb:<pid>`, `tekst` ({@see Text::forPost}), `profil`, and
 * `meta` with `links` and — for a registered author, when MyBB knows it — `author_first_post`.
 * Never an e-mail, an IP address, a username or a user id ({@see item}).
 * No PHP 8 syntax.
 */
final class Submitter
{
    /** The item id's prefix: `mybb:<pid>`. The id carries no content. */
    public const ID_PREFIX = 'mybb:';

    /** The only `meta` fields the plugin sends. */
    public const META_FIELDS = ['links', 'author_first_post'];

    /** The first retry pause without `ponow_za_s`, in seconds; it doubles per attempt. */
    public const BACKOFF_S = 60;

    /** The longest retry pause, in seconds. */
    public const MAX_BACKOFF_S = 3600;

    /** @var Platform */
    private $platform;

    /** @var Gateway */
    private $gateway;

    /** @var Applier */
    private $applier;

    /** @var SplObjectStorage Datahandlers whose post the plugin is holding in this request. */
    private $held;

    public function __construct(Platform $platform, Gateway $gateway, Applier $applier)
    {
        $this->platform = $platform;
        $this->gateway = $gateway;
        $this->applier = $applier;
        $this->held = new SplObjectStorage();
    }

    /**
     * The validate hooks: decides whether to hold the post being written.
     *
     * @param object $handler MyBB's `PostDataHandler`.
     * @param bool   $thread  A new thread (else a reply).
     */
    public function onValidate($handler, bool $thread): void
    {
        if (($handler->method ?? '') !== 'insert') {
            return;
        }
        $data = is_array($handler->data ?? null) ? $handler->data : [];
        if (!empty($data['savedraft'])) {
            return;
        }
        $settings = new Settings($this->platform->settings());
        if (!$settings->active()) {
            return;
        }
        $uid = (int)($data['uid'] ?? 0);
        $fid = (int)($data['fid'] ?? 0);
        // MyBB applies `moderateposts` only when the poster is the current user.
        if ($fid <= 0 || $uid !== $this->platform->currentUserId() || !$settings->appliesToForum($fid)) {
            return;
        }
        if ($settings->skipModerators() && $this->platform->isModerator($fid, $uid)) {
            return;
        }
        if ($this->platform->forumHoldsAnyway($fid, $uid, $thread)) {
            return;
        }
        $this->platform->holdCurrentUsersPosts();
        $this->held->attach($handler);
    }

    /**
     * The insert-end hooks: records and sends a post the plugin held.
     *
     * @param object $handler MyBB's `PostDataHandler`, with `return_values`.
     * @param bool   $thread  A new thread (else a reply).
     * @param int    $now     Unix seconds.
     */
    public function onInserted($handler, bool $thread, int $now): void
    {
        if (!$this->held->contains($handler)) {
            return;
        }
        $this->held->detach($handler);
        $this->platform->releaseCurrentUsersPosts();

        $values = is_array($handler->return_values ?? null) ? $handler->return_values : [];
        $data = is_array($handler->data ?? null) ? $handler->data : [];
        $pid = (int)($values['pid'] ?? 0);
        if ($pid <= 0 || (int)($values['visible'] ?? 1) !== 0) {
            return; // not in the queue after all: nothing to decide
        }
        $message = (string)($data['message'] ?? '');
        $subject = $thread ? (string)($data['subject'] ?? '') : '';
        $text = Text::forPost($message, $subject);
        $row = [
            'pid'          => $pid,
            'tid'          => $thread ? (int)($values['tid'] ?? 0) : (int)($data['tid'] ?? 0),
            'is_thread'    => $thread ? 1 : 0,
            // RETRY until the gateway accepts it: a request that dies half-way is sent
            // again by the task, never mistaken for an accepted one.
            'status'       => Status::RETRY,
            'submitted_at' => $now,
            'retry_at'     => $now + self::BACKOFF_S,
            'truncated'    => $text['truncated'] ? 1 : 0,
            'prefix_len'   => $text['prefix_len'],
            'text_len'     => $text['text_len'],
        ];
        $this->platform->addPending($row);
        $row = $this->platform->pending($pid) ?? $row;

        $settings = new Settings($this->platform->settings());
        if ($text['tekst'] === '') {
            $this->decideWithout($row, Status::REASON_NO_TEXT, $settings, $now);
            return;
        }
        $meta = ['links' => Text::links($subject . "\n" . $message)];
        $first = $this->platform->currentUserHasNoPosts();
        if ($first !== null) {
            $meta['author_first_post'] = $first;
        }
        $this->send([['row' => $row, 'item' => self::item($pid, $text['tekst'], (string)$settings->profile(), $meta)]],
            $settings, $now);
    }

    /**
     * The update hook: an edit of a post still waiting for its verdict makes that verdict
     * stale (it would judge text that is gone), so the post is left to a human. It stays in
     * MyBB's queue — an edit keeps an unapproved post unapproved.
     *
     * @param object $handler MyBB's `PostDataHandler` in update mode.
     * @param int    $now     Unix seconds.
     */
    public function onUpdated($handler, int $now): void
    {
        $pid = (int)(is_array($handler->data ?? null) ? ($handler->data['pid'] ?? 0) : 0);
        if ($pid > 0 && $this->platform->claimPending($pid)) {
            $this->platform->updatePending($pid, [
                'status' => Status::SUPERSEDED, 'verdict' => Status::REASON_EDITED, 'decided_at' => $now,
            ]);
        }
    }

    /**
     * Sends waiting rows again (the task), in batches of at most {@see Gateway::MAX_ITEMS}.
     *
     * @param array<int,array<string,mixed>> $rows     Rows due for a retry.
     * @param Settings                       $settings The settings (active).
     * @param int                            $now      Unix seconds.
     * @return int How many items were sent.
     */
    public function resubmit(array $rows, Settings $settings, int $now): int
    {
        $batch = [];
        foreach ($rows as $row) {
            $pid = (int)$row['pid'];
            $post = $this->platform->post($pid);
            if ($post === null || $post['visible'] !== 0) {
                if ($this->platform->claimPending($pid)) {
                    $this->platform->updatePending($pid, [
                        'status' => $post === null ? Status::GONE : Status::SUPERSEDED, 'decided_at' => $now,
                    ]);
                }
                continue;
            }
            $text = Text::forPost($post['message'], (int)$row['is_thread'] === 1 ? $post['subject'] : '');
            if ($text['tekst'] === '') {
                $this->decideWithout($row, Status::REASON_NO_TEXT, $settings, $now);
                continue;
            }
            $shape = ['truncated' => $text['truncated'] ? 1 : 0, 'prefix_len' => $text['prefix_len'], 'text_len' => $text['text_len']];
            $this->platform->updatePending($pid, $shape, Status::RETRY);
            $batch[] = [
                'row'  => $shape + $row,
                'item' => self::item($pid, $text['tekst'], (string)$settings->profile(),
                    ['links' => Text::links($post['subject'] . "\n" . $post['message'])]),
            ];
        }
        foreach (array_chunk($batch, Gateway::MAX_ITEMS) as $chunk) {
            $this->send($chunk, $settings, $now);
        }
        return count($batch);
    }

    /**
     * One item of the request body.
     *
     * @param int                 $pid     The post.
     * @param string              $text    Its text ({@see Text::forPost}).
     * @param string              $profile The profile.
     * @param array<string,mixed> $meta    Spam signals; only {@see META_FIELDS} are kept.
     * @return array{id:string,tekst:string,profil:string,meta:array<string,mixed>}
     */
    public static function item(int $pid, string $text, string $profile, array $meta): array
    {
        $meta = array_intersect_key($meta, array_flip(self::META_FIELDS));
        if (isset($meta['links'])) {
            $meta['links'] = max(0, min(100000, (int)$meta['links']));
        }
        if (isset($meta['author_first_post'])) {
            $meta['author_first_post'] = (bool)$meta['author_first_post'];
        }
        return ['id' => self::ID_PREFIX . $pid, 'tekst' => $text, 'profil' => $profile, 'meta' => $meta];
    }

    /**
     * Sends a batch and records the answer on each row.
     *
     * @param array<int,array{row:array<string,mixed>,item:array<string,mixed>}> $batch
     * @param Settings $settings The settings (active).
     * @param int      $now      Unix seconds.
     */
    private function send(array $batch, Settings $settings, int $now): void
    {
        $result = $this->gateway->submit((string)$settings->gatewayUrl(), (string)$settings->apiKey(),
            array_column($batch, 'item'));

        if ($result['outcome'] === Gateway::ACCEPTED) {
            foreach ($batch as $entry) {
                $this->platform->updatePending((int)$entry['row']['pid'], [
                    'status' => Status::PENDING, 'accepted_at' => $now, 'retry_at' => 0,
                    'attempts' => (int)$entry['row']['attempts'] + 1, 'error_code' => '',
                ], Status::RETRY);
            }
            $last = $this->platform->lastLog(['config_error', 'recovered']);
            if ($last !== null && $last['event'] === 'config_error') {
                $this->platform->log('recovered', '', 0, $now);
            }
            return;
        }

        if ($result['outcome'] === Gateway::RETRY) {
            foreach ($batch as $entry) {
                $attempts = (int)$entry['row']['attempts'] + 1;
                $pause = $result['ponow_za_s'] ?? min(self::MAX_BACKOFF_S, self::BACKOFF_S * (2 ** min(10, $attempts - 1)));
                $this->platform->updatePending((int)$entry['row']['pid'], [
                    'retry_at' => $now + max(1, $pause), 'attempts' => $attempts, 'error_code' => $result['kod'],
                ], Status::RETRY);
            }
            $this->platform->log('retry', $result['kod'], (int)$batch[0]['row']['pid'], $now);
            return;
        }

        // A configuration error. When the gateway names the one item it refused in a batch
        // of several, only that item gets the failure mode; the others go again next run.
        $this->platform->log('config_error', $result['kod'], (int)$batch[0]['row']['pid'], $now);
        $element = $result['element'];
        foreach ($batch as $position => $entry) {
            if ($element !== null && count($batch) > 1 && $position !== $element) {
                $this->platform->updatePending((int)$entry['row']['pid'], ['retry_at' => $now], Status::RETRY);
                continue;
            }
            $this->platform->updatePending((int)$entry['row']['pid'], ['error_code' => $result['kod']], Status::RETRY);
            $this->decideWithout($entry['row'], Status::REASON_CONFIG_ERROR, $settings, $now);
        }
    }

    /**
     * Applies the failure mode to a row nobody else has decided.
     *
     * @param array<string,mixed> $row
     */
    private function decideWithout(array $row, string $reason, Settings $settings, int $now): void
    {
        if ($this->platform->claimPending((int)$row['pid'])) {
            $this->applier->failure($row, $reason, $settings, $now);
        }
    }
}
