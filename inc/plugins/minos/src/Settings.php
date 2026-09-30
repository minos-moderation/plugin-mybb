<?php

declare(strict_types=1);

namespace Minos\MyBB;

/**
 * The plugin's settings, read from MyBB's `$mybb->settings` and checked.
 *
 * Every value that decides whether a post is published or held is read FAIL-CLOSED: an
 * unknown failure mode means `fail-closed`, an unknown `ocenzurowane` handling means
 * "keep in the queue". A setting that makes the plugin unable to work at all (no key, no
 * secret, a gateway address that is not HTTPS) switches holding off instead — the forum
 * then behaves as if the plugin were not there, and the ACP says why ({@see problems}).
 * No PHP 8 syntax.
 */
final class Settings
{
    /** The setting names, as installed (`Installer::settings`). */
    public const ENABLED = 'minos_enabled';
    public const GATEWAY_URL = 'minos_gateway_url';
    public const API_KEY = 'minos_api_key';
    public const WEBHOOK_SECRET = 'minos_webhook_secret';
    public const PROFILE = 'minos_profile';
    public const FAILURE_MODE = 'minos_failure_mode';
    public const TIMEOUT_MIN = 'minos_timeout';
    public const CENSORED = 'minos_censored';
    public const BLOCKED = 'minos_blocked';
    public const FORUMS = 'minos_forums';
    public const SKIP_MODERATORS = 'minos_skip_moderators';

    /** The two secret settings: never rendered back, never logged. */
    public const SECRET_NAMES = [self::API_KEY, self::WEBHOOK_SECRET];

    /** The gateway the key is issued for. */
    public const DEFAULT_GATEWAY_URL = 'https://gateway.wergiliusz.app';

    /** The forum profiles of the contract; the first is the default. */
    public const PROFILES = ['forum_adult', 'forum_teen'];

    /** Setting values (stored in MyBB's settings table — never renamed). */
    public const FAIL_OPEN = 'fail-open';
    public const FAIL_CLOSED = 'fail-closed';
    public const PUBLISH = 'publish';
    public const QUEUE = 'queue';
    public const SOFT_DELETE = 'soft-delete';

    /**
     * The receive timeout by default, in minutes: the gateway's 15-minute TTL plus five
     * minutes of grace for a delivery that is still being retried.
     */
    public const DEFAULT_TIMEOUT_MIN = 20;

    /** A B2B key: `wgb2b_` and a token with no whitespace (it goes into a header). */
    private const KEY_PATTERN = '/^wgb2b_[A-Za-z0-9._~-]{1,200}\z/';

    /** @var array<string,mixed> */
    private $raw;

    /**
     * @param array<string,mixed> $settings MyBB's `$mybb->settings`.
     */
    public function __construct(array $settings)
    {
        $this->raw = $settings;
    }

    /**
     * Whether the administrator has switched the plugin on.
     *
     * @return bool The `minos_enabled` setting.
     */
    public function enabled(): bool
    {
        return (string)($this->raw[self::ENABLED] ?? '0') === '1';
    }

    /**
     * Whether new posts are held and sent: switched on and nothing in {@see problems}.
     *
     * @return bool True when the plugin can do its work.
     */
    public function active(): bool
    {
        return $this->enabled() && $this->problems() === [];
    }

    /**
     * What stops the plugin from working, as language keys (for the ACP notice).
     *
     * @return array<int,string> Empty when the configuration is usable.
     */
    public function problems(): array
    {
        $problems = [];
        if ($this->gatewayUrl() === null) {
            $problems[] = 'minos_problem_gateway_url';
        }
        if ($this->apiKey() === null) {
            $problems[] = 'minos_problem_api_key';
        }
        if ($this->webhookSecret() === null) {
            $problems[] = 'minos_problem_webhook_secret';
        }
        if ($this->profile() === null) {
            $problems[] = 'minos_problem_profile';
        }
        return $problems;
    }

    /**
     * The gateway's base address, without a trailing slash.
     *
     * @return string|null Null unless it is `https://` — or `http://` to this machine
     *     (`localhost`, `127.0.0.1`, `[::1]`), for developing against the mock gateway.
     */
    public function gatewayUrl(): ?string
    {
        $url = rtrim(trim((string)($this->raw[self::GATEWAY_URL] ?? '')), '/');
        $parts = parse_url($url);
        if (!is_array($parts) || !isset($parts['scheme'], $parts['host'])
            || isset($parts['user']) || isset($parts['pass']) || isset($parts['query'])
            || isset($parts['fragment'])) {
            return null;
        }
        $scheme = strtolower($parts['scheme']);
        $local = in_array(strtolower($parts['host']), ['localhost', '127.0.0.1', '[::1]'], true);
        return $scheme === 'https' || ($scheme === 'http' && $local) ? $url : null;
    }

    /**
     * The forum's B2B key.
     *
     * @return string|null Null when it is missing or malformed.
     */
    public function apiKey(): ?string
    {
        $key = trim((string)($this->raw[self::API_KEY] ?? ''));
        return preg_match(self::KEY_PATTERN, $key) ? $key : null;
    }

    /**
     * The key's webhook secret.
     *
     * @return string|null Null when it is missing: an empty secret would let anyone sign.
     */
    public function webhookSecret(): ?string
    {
        $secret = trim((string)($this->raw[self::WEBHOOK_SECRET] ?? ''));
        return $secret === '' ? null : $secret;
    }

    /**
     * The profile every post is assessed with.
     *
     * @return string|null One of {@see PROFILES}, or null for anything else.
     */
    public function profile(): ?string
    {
        $profile = trim((string)($this->raw[self::PROFILE] ?? self::PROFILES[0]));
        return in_array($profile, self::PROFILES, true) ? $profile : null;
    }

    /**
     * Whether a post without a verdict is published.
     *
     * @return bool True only for exactly `fail-open`; anything else holds.
     */
    public function failOpen(): bool
    {
        return (string)($this->raw[self::FAILURE_MODE] ?? '') === self::FAIL_OPEN;
    }

    /**
     * How long to wait for a verdict before applying the failure mode.
     *
     * @return int Seconds, from 1 minute to 24 hours; the default when not a number.
     */
    public function timeoutS(): int
    {
        $minutes = trim((string)($this->raw[self::TIMEOUT_MIN] ?? ''));
        $minutes = ctype_digit($minutes) ? (int)$minutes : self::DEFAULT_TIMEOUT_MIN;
        return 60 * max(1, min(1440, $minutes));
    }

    /**
     * Whether an `ocenzurowane` post is published with its masked text.
     *
     * @return bool True only for exactly `publish`; anything else keeps it in the queue.
     */
    public function publishCensored(): bool
    {
        return (string)($this->raw[self::CENSORED] ?? '') === self::PUBLISH;
    }

    /**
     * Whether a `zablokowane` post is soft-deleted rather than kept in the queue.
     *
     * @return bool True only for exactly `soft-delete`.
     */
    public function softDeleteBlocked(): bool
    {
        return (string)($this->raw[self::BLOCKED] ?? '') === self::SOFT_DELETE;
    }

    /**
     * Whether posts in a forum are moderated by the plugin.
     *
     * @param int $fid The forum id.
     * @return bool MyBB's forum selection: `-1` all, '' none, else a comma list.
     */
    public function appliesToForum(int $fid): bool
    {
        $value = trim((string)($this->raw[self::FORUMS] ?? '-1'));
        if ($value === '-1') {
            return true;
        }
        return in_array($fid, array_map('intval', array_filter(explode(',', $value), 'strlen')), true);
    }

    /**
     * Whether moderators' (and super moderators' and administrators') posts skip the plugin.
     *
     * @return bool The `minos_skip_moderators` setting; on unless set to 0.
     */
    public function skipModerators(): bool
    {
        return (string)($this->raw[self::SKIP_MODERATORS] ?? '1') !== '0';
    }

    /**
     * The address the gateway delivers verdicts to, for the key's record.
     *
     * @param string $boardUrl MyBB's `bburl`.
     * @return string `<bburl>/minos-webhook.php`.
     */
    public static function webhookUrl(string $boardUrl): string
    {
        return rtrim($boardUrl, '/') . '/minos-webhook.php';
    }

    /**
     * What the ACP may show of a stored secret: its first characters, never the rest.
     *
     * @param string $value The stored key or secret.
     * @return string '' when nothing is stored; else at most 4 characters after a `wgb2b_`
     *     prefix (or the first 4) and an ellipsis.
     */
    public static function prefixOf(string $value): string
    {
        $value = trim($value);
        if ($value === '') {
            return '';
        }
        $lead = strncmp($value, 'wgb2b_', 6) === 0 ? 'wgb2b_' : '';
        $rest = (string)substr($value, strlen($lead));
        $shown = strlen($rest) > 8 ? substr($rest, 0, 4) : '';
        return $lead . $shown . '…';
    }
}
