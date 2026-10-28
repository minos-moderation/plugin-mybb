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
 *    60 s — unless `ponow_za_s` (as the gateway gave it) ends after the post's receive
 *    timeout: then the failure mode applies at once (shared rule D9.3a), since the post
 *    could not be assessed in time anyway; any other refusal → a configuration error: the
 *    failure mode is applied, the code (never the content) is logged, and the ACP shows a
 *    notice. A daily-limit or overload refusal is kept with the moment it ends, for the
 *    ACP notice ("the gateway does not accept until …"); the next `202` clears it.
 *
 * The shared pause: every "retry" answer (a `429` of any kind, a `503` or another `5xx`, a
 * `408`, no answer at all) also means "send nothing before T" for the whole forum — T is
 * `ponow_za_s` as the gateway gave it, or {@see BACKOFF_S} without one ({@see PAUSE}).
 * Until T a new post is not sent in its author's request (the author does not wait for a
 * gateway that has just refused): it waits as `retry` until T, or gets the failure mode at
 * once when T falls after its timeout (D9.3a). The task sends nothing before T either, and
 * a run stops at the first batch the gateway asks to retry.
 *
 * An edit of a post still waiting for its verdict ({@see onUpdated}): by its author (anyone
 * but a moderator of its forum), a new revision, sent by the task as `mybb:<pid>.<n>` — a
 * verdict for an earlier revision is then acknowledged and not applied; by a moderator, a
 * human is dealing with it, and nothing is sent.
 *
 * A post the plugin does not touch: a draft, an edit of a published post, a post in a forum
 * it does not cover, a moderator's post (by setting), a post MyBB holds anyway (the forum moderates
 * new posts, or the user is under moderation — humans asked for those), a post written on
 * someone else's behalf, and every post while the plugin is switched off. Switched on but
 * unable to submit (no key or secret, an unusable gateway address), it holds the post and
 * applies the failure mode at once, without sending.
 *
 * A poll created or changed while its thread waits ({@see onPollChanged}) is such an edit of
 * the thread's first post: its question and options are part of the text.
 *
 * What an item carries: `id` `mybb:<pid>` (`mybb:<pid>.<n>` for revision n ≥ 1), `tekst`
 * ({@see Text::ofPost}: what readers of the post see — a new thread's subject and poll, a
 * reply's own subject, the post), `profil`, and `meta` with `links`, `link_domains` and —
 * for a registered author, when MyBB knows it — `author_first_post`.
 * Never an e-mail, an IP address, a username or a user id ({@see item}).
 * No PHP 8 syntax.
 */
final class Submitter
{
    /** The item id's prefix: `mybb:<pid>`. The id carries no content. */
    public const ID_PREFIX = 'mybb:';

    /** Between the post id and a revision ≥ 1 in an item id: `mybb:<pid>.<n>`. */
    public const REVISION_SEPARATOR = '.';

    /** The only `meta` fields the plugin sends. */
    public const META_FIELDS = ['links', 'link_domains', 'author_first_post'];

    /** The first retry pause without `ponow_za_s`, in seconds; it doubles per attempt. */
    public const BACKOFF_S = 60;

    /** The longest retry pause, in seconds. */
    public const MAX_BACKOFF_S = 3600;

    /** The state key ({@see Platform::state}) of the gateway's last long refusal, for the ACP. */
    public const REFUSAL = 'refusal';

    /**
     * The state key of the shared pause: `['until' => T, 'kod' => code]` — nothing is sent
     * before T (the class doc). The next `202` clears it; past T it is ignored.
     */
    public const PAUSE = 'pause';

    /** The refusal that means "the key's allowance for the day is spent". */
    public const DAILY_LIMIT = 'limit_dobowy_klucza';

    /** Refusals that mean "the gateway is overloaded" (not this key's doing). */
    public const OVERLOADED = ['limit_globalny_b2b', 'kolejka_pelna'];

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
        // A post that failed validation is not inserted: raising the flag would only
        // mislabel the form MyBB shows again.
        if (method_exists($handler, 'get_errors') && $handler->get_errors() !== []) {
            return;
        }
        $settings = new Settings($this->platform->settings());
        if (!$settings->enabled()) {
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
     * `newreply_start` / `newthread_start`: MyBB shows the form again — the post was not
     * inserted (a later validate hook found an error), so the flag raised for it goes back.
     */
    public function onFormShown(): void
    {
        $this->platform->releaseCurrentUsersPosts();
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
        // The post as stored, as every later rendering reads it (the thread's title, a poll).
        $post = $this->platform->post($pid) ?? [
            'fid' => (int)($data['fid'] ?? 0), 'message' => (string)($data['message'] ?? ''),
            'subject' => (string)($data['subject'] ?? ''), 'thread_subject' => '', 'replyto_subject' => '', 'poll' => null,
        ];
        // Whether a reply's own subject goes with it is decided now and kept with the row, so
        // a later change of the thread's title does not turn MyBB's default into another text.
        $own = !$thread && Text::sendsOwnSubject($post);
        $text = Text::ofPost($post, $thread, $this->platform->forumParsing((int)$post['fid']), $own);
        $signals = $text['error'] ? null : self::linkSignals(Text::sourceOf($post, $thread, $own));
        // Read now, in the author's request: a resubmission (the task) has no author.
        $first = $this->platform->currentUserHasNoPosts();
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
            'sent_sha256'  => self::digest($text),
            'first_post'   => $first === null ? '' : ($first ? '1' : '0'),
            'own_subject'  => $own ? 1 : 0,
        ];
        $this->platform->addPending($row);
        $row = $this->platform->pending($pid) ?? $row;

        $settings = new Settings($this->platform->settings());
        if (!$settings->active()) {
            // Switched on, but nothing can be sent: the failure mode, at once.
            $this->decideWithout($row, Status::REASON_NOT_CONFIGURED, $settings, $now);
            return;
        }
        if ($signals === null) {
            $this->unreadable($row, $settings, $now);
            return;
        }
        if ($text['tekst'] === '') {
            $this->decideWithout($row, Status::REASON_NO_TEXT, $settings, $now);
            return;
        }
        $until = $this->pausedUntil($now);
        if ($until !== null) {
            // The gateway has just asked to wait: the author does not wait for its refusal.
            $this->waitOut([$row], $until, $settings, $now);
            return;
        }
        $meta = $signals + self::firstPost($row);
        $this->send([['row' => $row, 'item' => self::item($pid, $text['tekst'], (string)$settings->profile(), $meta)]],
            $settings, $now);
    }

    /**
     * The update hook (`datahandler_post_update`, run before MyBB writes the new text): an
     * edit makes a verdict still to come stale — it would judge text that is gone.
     *
     * - By the post's author (anyone who is not a moderator of its forum), while the plugin
     *   waits for the verdict (`pending`, `retry`) or after the failure mode held the post
     *   because no verdict came ({@see Status::REVISED_REASONS}): a new revision
     *   ({@see Platform::revise}). The task sends it, not the editor's request.
     * - By a moderator, while the plugin waits: a human is dealing with the post, so it is
     *   left to them (`superseded`, listed on the ACP page) and nothing is sent.
     * - A post the failure mode published (`fail-open`): a late verdict would judge text that
     *   is gone, so it no longer applies ({@see Applier::late}). Edits of published posts
     *   are not assessed again.
     * - An author's edit after which the text to send is the one already sent (its digest
     *   unchanged — the post saved as it was, an edit reason added) changes nothing: the
     *   verdict on its way still judges this text.
     *
     * An edit keeps an unapproved post unapproved (MyBB's `update_post`), so the post stays
     * in MyBB's queue throughout.
     *
     * @param object $handler MyBB's `PostDataHandler` in update mode.
     * @param int    $now     Unix seconds.
     */
    public function onUpdated($handler, int $now): void
    {
        $data = is_array($handler->data ?? null) ? $handler->data : [];
        $pid = (int)($data['pid'] ?? 0);
        if ($pid <= 0) {
            return;
        }
        // MyBB writes the new message and subject after this hook: the post as it will read.
        $changes = [];
        foreach (['message', 'subject'] as $field) {
            if (isset($data[$field]) && is_string($data[$field])) {
                $changes[$field] = $data[$field];
            }
        }
        $this->edited($pid, $now, $changes);
    }

    /**
     * `polls_do_newpoll_end` / `polls_do_editpoll_end`: a poll is part of its thread's first
     * post, so creating or changing it is an edit of that post by the current user
     * ({@see onUpdated}). In MyBB 1.8.41 only moderators change a poll, and a thread's author
     * can add one to a thread still in the queue only by posting `polls.php` directly — its
     * form refuses them; a poll added after publication is an edit of a published post, not
     * assessed again.
     *
     * @param int $now Unix seconds.
     */
    public function onPollChanged(int $now): void
    {
        $pid = $this->platform->pollThreadFirstPost();
        if ($pid > 0) {
            $this->edited($pid, $now);
        }
    }

    /**
     * An edit of a post by the current user ({@see onUpdated}, {@see onPollChanged}).
     *
     * @param int                  $pid     The post.
     * @param int                  $now     Unix seconds.
     * @param array<string,string> $changes The new `message` and `subject`, when MyBB has
     *     not written them yet.
     */
    private function edited(int $pid, int $now, array $changes = []): void
    {
        $post = $this->platform->post($pid);
        $moderator = $post !== null && $this->platform->isModerator($post['fid'], $this->platform->currentUserId());
        if ($moderator) {
            if ($this->platform->claimPending($pid, $now)) {
                $this->platform->updatePending($pid, [
                    'status' => Status::SUPERSEDED, 'verdict' => Status::REASON_EDITED, 'decided_at' => $now,
                ]);
                return;
            }
        } else {
            $row = $post !== null ? $this->platform->pending($pid) : null;
            $own = $row !== null ? self::ownSubjectAfter($row, $post, $changes) : null;
            if ($row !== null && $this->sendsTheSameText($row, $changes + $post, (bool)$own)) {
                return;
            }
            if ($this->platform->revise($pid, $now, $own === null ? null : ($own ? 1 : 0))) {
                return;
            }
        }
        $this->platform->updatePending($pid, ['auto_published' => 0], Status::PUBLISHED);
    }

    /**
     * Whether a reply's subject goes with it after an edit: decided again when the edit
     * changes the subject, else as the row keeps it.
     *
     * @param array<string,mixed>  $row     The row.
     * @param array<string,mixed>  $post    The post before the edit ({@see Platform::post}).
     * @param array<string,string> $changes The edit's new `message` and `subject`.
     */
    private static function ownSubjectAfter(array $row, array $post, array $changes): bool
    {
        if ((int)$row['is_thread'] === 1) {
            return false;
        }
        if (isset($changes['subject']) && trim($changes['subject']) !== trim((string)$post['subject'])) {
            return Text::sendsOwnSubject(['subject' => $changes['subject']] + $post);
        }
        return (int)($row['own_subject'] ?? 0) === 1;
    }

    /**
     * Whether a post, as it reads after an edit, gives the text its row last recorded as
     * sent (or as unreadable or empty: an edit that leaves it so starts nothing either).
     *
     * @param array<string,mixed> $row        The row.
     * @param array<string,mixed> $post       The post after the edit ({@see Platform::post}).
     * @param bool                $ownSubject For a reply: whether its subject goes with it.
     */
    private function sendsTheSameText(array $row, array $post, bool $ownSubject): bool
    {
        $text = Text::ofPost($post, (int)$row['is_thread'] === 1, $this->platform->forumParsing((int)$post['fid']),
            $ownSubject);
        return hash_equals((string)$row['sent_sha256'], self::digest($text));
    }

    /**
     * Sends waiting rows again (the task), in batches of at most {@see Gateway::MAX_ITEMS}.
     *
     * Nothing is sent while the shared pause lasts ({@see PAUSE}), and a run stops at the
     * first batch the gateway asks to retry: the rows not sent wait until the pause ends,
     * or get the failure mode now when it ends after their timeout ({@see waitOut}).
     *
     * A row that throws while its item is built, and a batch that throws while it is sent,
     * are logged by step and post id and left for the next run; the other rows and batches
     * go on ({@see Applier::failedStep}).
     *
     * @param array<int,array<string,mixed>> $rows     Rows due for a retry.
     * @param Settings                       $settings The settings (active).
     * @param int                            $now      Unix seconds.
     * @return int How many items were sent.
     */
    public function resubmit(array $rows, Settings $settings, int $now): int
    {
        $until = $this->pausedUntil($now);
        if ($until !== null) {
            $this->waitOut($rows, $until, $settings, $now);
            return 0;
        }
        $batch = [];
        foreach ($rows as $row) {
            try {
                $entry = $this->element($row, $settings, $now);
            } catch (\Throwable $e) {
                $this->applier->failedStep((int)$row['pid'], 'resubmit', $now);
                continue;
            }
            if ($entry !== null) {
                $batch[] = $entry;
            }
        }
        $sent = 0;
        foreach (array_chunk($batch, Gateway::MAX_ITEMS) as $chunk) {
            // An earlier batch of this run was refused (or another request was): no more.
            $until = $this->pausedUntil($now);
            if ($until !== null) {
                $this->waitOut(array_column($chunk, 'row'), $until, $settings, $now);
                continue;
            }
            try {
                $this->send($chunk, $settings, $now);
                $sent += count($chunk);
            } catch (\Throwable $e) {
                // Its rows stay `retry`, due: the next run sends them again. The failure is
                // the batch's, not its first row's, so no row's count grows.
                try {
                    $this->platform->log('error', 'send', (int)$chunk[0]['row']['pid'], $now);
                } catch (\Throwable $ignored) {
                    // The database refuses even this; the rows wait as they are.
                }
            }
        }
        return $sent;
    }

    /**
     * One row's entry of a resubmission, or null when the row was decided instead (the post
     * is gone, a human acted, or it has no text that can be sent).
     *
     * @param array<string,mixed> $row
     * @return array{row:array<string,mixed>,item:array<string,mixed>}|null
     */
    private function element(array $row, Settings $settings, int $now): ?array
    {
        $pid = (int)$row['pid'];
        $post = $this->platform->post($pid);
        if ($post === null || $post['visible'] !== 0) {
            if ($this->platform->claimPending($pid, $now)) {
                $this->platform->updatePending($pid, [
                    'status' => $post === null ? Status::GONE : Status::SUPERSEDED, 'decided_at' => $now,
                ]);
            }
            return null;
        }
        $own = (int)($row['own_subject'] ?? 0) === 1;
        $text = Text::ofPost($post, (int)$row['is_thread'] === 1, $this->platform->forumParsing($post['fid']), $own);
        $signals = $text['error'] ? null : self::linkSignals(Text::sourceOf($post, (int)$row['is_thread'] === 1, $own));
        if ($signals === null) {
            $this->unreadable($row, $settings, $now);
            return null;
        }
        if ($text['tekst'] === '') {
            $this->decideWithout($row, Status::REASON_NO_TEXT, $settings, $now);
            return null;
        }
        $shape = [
            'truncated' => $text['truncated'] ? 1 : 0, 'prefix_len' => $text['prefix_len'],
            'text_len' => $text['text_len'], 'sent_sha256' => self::digest($text),
        ];
        $this->platform->updatePending($pid, $shape, Status::RETRY, self::rev($row));
        return [
            'row'  => $shape + $row,
            'item' => self::item($pid, $text['tekst'], (string)$settings->profile(), $signals + self::firstPost($row),
                (int)$row['rev']),
        ];
    }

    /**
     * `author_first_post` as the row kept it at the first send.
     *
     * @param array<string,mixed> $row
     * @return array<string,bool> The signal, or nothing (a guest, or a row from before it was kept).
     */
    private static function firstPost(array $row): array
    {
        $first = (string)($row['first_post'] ?? '');
        return $first === '1' || $first === '0' ? ['author_first_post' => $first === '1'] : [];
    }

    /**
     * One item of the request body.
     *
     * @param int                 $pid     The post.
     * @param string              $text    Its text ({@see Text::forPost}).
     * @param string              $profile The profile.
     * @param array<string,mixed> $meta    Spam signals; only {@see META_FIELDS} are kept.
     * @param int                 $rev     The revision ({@see itemId}).
     * @return array{id:string,tekst:string,profil:string,meta:array<string,mixed>}
     */
    public static function item(int $pid, string $text, string $profile, array $meta, int $rev = 0): array
    {
        $meta = array_intersect_key($meta, array_flip(self::META_FIELDS));
        if (isset($meta['links'])) {
            $meta['links'] = max(0, min(100000, (int)$meta['links']));
        }
        if (isset($meta['link_domains'])) {
            $meta['link_domains'] = array_slice(array_values(array_filter((array)$meta['link_domains'], 'is_string')), 0, Text::MAX_DOMAINS);
            if ($meta['link_domains'] === []) {
                unset($meta['link_domains']);
            }
        }
        if (isset($meta['author_first_post'])) {
            $meta['author_first_post'] = (bool)$meta['author_first_post'];
        }
        return ['id' => self::itemId($pid, $rev), 'tekst' => $text, 'profil' => $profile, 'meta' => $meta];
    }

    /**
     * An item's id (a wire string, never renamed): `mybb:<pid>` for the post as first sent,
     * `mybb:<pid>.<n>` for its revision n ≥ 1. It carries no content.
     *
     * @param int $pid The post.
     * @param int $rev The revision.
     * @return string The id.
     */
    public static function itemId(int $pid, int $rev): string
    {
        return self::ID_PREFIX . $pid . ($rev > 0 ? self::REVISION_SEPARATOR . $rev : '');
    }

    /**
     * What the row keeps of the text it sends: its SHA-256, never the text. A verdict is
     * applied only while the post still renders to that text ({@see Applier}).
     *
     * @param array{tekst:string,error:bool} $text From {@see Text::forPost}.
     * @return string 64 hex characters; '' when nothing can be sent.
     */
    public static function digest(array $text): string
    {
        return $text['error'] || $text['tekst'] === '' ? '' : hash('sha256', $text['tekst']);
    }

    /**
     * The link signals of a post: `links`, and `link_domains` when it has any.
     *
     * @return array<string,mixed>|null Null when the text could not be read (a PCRE failure).
     */
    private static function linkSignals(string $text): ?array
    {
        try {
            $signals = ['links' => Text::links($text)];
            $domains = Text::linkDomains($text);
        } catch (TextError $e) {
            return null;
        }
        if ($domains !== []) {
            $signals['link_domains'] = $domains;
        }
        return $signals;
    }

    /**
     * Sends a batch and records the answer on each row — on the revision the request carried
     * only: an author may edit the post while the request is on its way, and the answer
     * about the earlier text then changes nothing on the new revision, which goes out next.
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
                ], Status::RETRY, self::rev($entry['row']));
            }
            $last = $this->platform->lastLog(['config_error', 'recovered']);
            if ($last !== null && $last['event'] === 'config_error') {
                $this->platform->log('recovered', '', 0, $now);
            }
            $this->platform->setState(self::REFUSAL, null);
            $this->platform->setState(self::PAUSE, null);
            return;
        }

        if ($result['outcome'] === Gateway::RETRY) {
            $retryS = $result['ponow_za_s'];
            if ($retryS !== null && ($result['kod'] === self::DAILY_LIMIT || in_array($result['kod'], self::OVERLOADED, true))) {
                $this->platform->setState(self::REFUSAL, ['kod' => $result['kod'], 'since' => $now, 'until' => $now + $retryS]);
            }
            // The whole forum waits, not only this batch; a longer pause already kept stays.
            $until = max($now + max(1, $retryS ?? self::BACKOFF_S), (int)$this->pausedUntil($now));
            $this->platform->setState(self::PAUSE, ['until' => $until, 'kod' => $result['kod']]);
            foreach ($batch as $entry) {
                $row = $entry['row'];
                $attempts = (int)$row['attempts'] + 1;
                $deadline = (int)$row['submitted_at'] + $settings->timeoutS();
                // Rule D9.3(a) first: the gateway refuses beyond the post's timeout — its
                // `ponow_za_s` as given, not a pause of the plugin's own — so no verdict can
                // come in time: the failure mode now, not after the wait. Such a row gets no
                // `retry_at`: a far `ponow_za_s` would not even fit the column (MySQL and
                // PostgreSQL refuse the value, and MyBB stops the page on a database error).
                if ($retryS !== null && $now + $retryS > $deadline) {
                    $this->platform->updatePending((int)$row['pid'], ['attempts' => $attempts, 'error_code' => $result['kod']],
                        Status::RETRY, self::rev($row));
                    $this->decideWithout($row, Status::REASON_GATEWAY_LIMIT, $settings, $now);
                    continue;
                }
                $pause = $retryS ?? min(self::MAX_BACKOFF_S, self::BACKOFF_S * (2 ** min(10, $attempts - 1)));
                // Never later than the timeout, when the task gives the row the failure mode anyway.
                $this->platform->updatePending((int)$row['pid'], [
                    'retry_at' => min($now + max(1, $pause), max($now + 1, $deadline)), 'attempts' => $attempts,
                    'error_code' => $result['kod'],
                ], Status::RETRY, self::rev($row));
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
                $this->platform->updatePending((int)$entry['row']['pid'], ['retry_at' => $now], Status::RETRY,
                    self::rev($entry['row']));
                continue;
            }
            $this->platform->updatePending((int)$entry['row']['pid'], ['error_code' => $result['kod']], Status::RETRY,
                self::rev($entry['row']));
            $this->decideWithout($entry['row'], Status::REASON_CONFIG_ERROR, $settings, $now);
        }
    }

    /**
     * The end of the shared pause ({@see PAUSE}), while it lasts.
     *
     * @param int $now Unix seconds.
     * @return int|null T, or null when nothing holds the sending back.
     */
    private function pausedUntil(int $now): ?int
    {
        $pause = $this->platform->state(self::PAUSE);
        $until = is_array($pause) ? (int)($pause['until'] ?? 0) : 0;
        return $until > $now ? $until : null;
    }

    /**
     * Rows that may not be sent before T: each waits as `retry` until T, or — when T falls
     * after its receive timeout, so no verdict could come in time — gets the failure mode
     * now (`gateway_limit`, rule D9.3a), as a refusal that long would have given it. A row
     * that throws is that row's problem ({@see Applier::failedStep}); the others go on.
     *
     * @param array<int,array<string,mixed>> $rows     Rows waiting as `retry`.
     * @param int                            $until    T.
     * @param Settings                       $settings The settings.
     * @param int                            $now      Unix seconds.
     */
    private function waitOut(array $rows, int $until, Settings $settings, int $now): void
    {
        foreach ($rows as $row) {
            try {
                if ($until > (int)$row['submitted_at'] + $settings->timeoutS()) {
                    $this->decideWithout($row, Status::REASON_GATEWAY_LIMIT, $settings, $now);
                } else {
                    $this->platform->updatePending((int)$row['pid'], ['retry_at' => $until], Status::RETRY, self::rev($row));
                }
            } catch (\Throwable $e) {
                $this->applier->failedStep((int)$row['pid'], 'resubmit', $now);
            }
        }
    }

    /**
     * A post whose text could not be read: logged with its id (never its content), and the
     * failure mode applies — nothing is sent, not even a new thread's subject alone, and no
     * verdict can ever publish it.
     *
     * @param array<string,mixed> $row
     */
    private function unreadable(array $row, Settings $settings, int $now): void
    {
        $this->platform->log('text_error', '', (int)$row['pid'], $now);
        $this->decideWithout($row, Status::REASON_TEXT_ERROR, $settings, $now);
    }

    /**
     * Applies the failure mode to a row nobody else has decided, at the revision it was read
     * at (a newer one, started by an edit meanwhile, is not this outcome's).
     *
     * @param array<string,mixed> $row
     */
    private function decideWithout(array $row, string $reason, Settings $settings, int $now): void
    {
        if ($this->platform->claimPending((int)$row['pid'], $now, Status::OPEN, false, self::rev($row))) {
            $this->applier->failure($row, $reason, $settings, $now);
        }
    }

    /**
     * The revision a row was read at.
     *
     * @param array<string,mixed> $row
     */
    private static function rev(array $row): int
    {
        return (int)($row['rev'] ?? 0);
    }
}
