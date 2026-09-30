<?php

declare(strict_types=1);

namespace Minos\MyBB;

/**
 * The states of a row in `PREFIX_minos_pending`, one row per post the plugin held.
 *
 * Stored values of the plugin's own table (English, like the code); the gateway's wire
 * strings (`bezpieczne`, `nieocenione`…) are kept separately, in the `verdict` column.
 */
final class Status
{
    /** Accepted by the gateway (202); waiting for the webhook. */
    public const PENDING = 'pending';

    /** Not accepted yet (429, 503, no answer); the task sends it again at `retry_at`. */
    public const RETRY = 'retry';

    /** Claimed by one webhook delivery or one task run, so no other applies it too. */
    public const APPLYING = 'applying';

    /** Approved as written. */
    public const PUBLISHED = 'published';

    /** Approved with the gateway's masked text; the original is in `original_text`. */
    public const CENSORED = 'censored';

    /** Left in MyBB's moderation queue for a human. */
    public const HELD = 'held';

    /** Soft-deleted (`zablokowane` with the soft-delete setting). */
    public const DELETED = 'deleted';

    /** A human (or an edit) acted on the post first; the verdict was not applied. */
    public const SUPERSEDED = 'superseded';

    /** The post no longer exists. */
    public const GONE = 'gone';

    /** Rows still waiting for an answer: a delivery for them is applied. */
    public const OPEN = [self::PENDING, self::RETRY];

    /** Reasons recorded in `verdict` when no verdict came (besides `nieocenione`). */
    public const REASON_TIMEOUT = 'timeout';
    public const REASON_CONFIG_ERROR = 'config_error';
    public const REASON_NO_TEXT = 'no_text';

    /** Recorded in `verdict` when the post was edited before its verdict came. */
    public const REASON_EDITED = 'edited';
}
