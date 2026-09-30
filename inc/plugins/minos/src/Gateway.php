<?php

declare(strict_types=1);

namespace Minos\MyBB;

/**
 * `POST {gateway}/api/v1/b2b/oceny`: sends a batch and reads the answer into one of three
 * outcomes the plugin acts on (`docs/contract.md` in `minos-moderation/client-php`):
 *
 * - {@see ACCEPTED}: any 2xx (the contract's `202`). An attempt is promised, not a verdict;
 * - {@see RETRY}: `429`, `503` (and any other 5xx), no answer at all. The items stay
 *   pending and go again after `blad.ponow_za_s`, or after a backoff;
 * - {@see CONFIG_ERROR}: any other answer (a `4xx` but `429`, a redirect). The request is
 *   wrong and retrying will not help; the administrator must see it.
 *
 * What goes on the wire is the batch the caller built and the key header — the item ids
 * are `mybb:<pid>`, and no e-mail, IP address or user id is ever in an item
 * ({@see Submitter::item}). The key is never part of a result, a log or an exception.
 * No PHP 8 syntax.
 */
final class Gateway
{
    /** The route (a wire path — never renamed). */
    public const PATH = '/api/v1/b2b/oceny';

    /** The key header (a wire label — never renamed). */
    public const KEY_HEADER = 'X-Gateway-Key';

    /** Seconds one request may take, connection included. */
    public const TIMEOUT_S = 10;

    /** Items one request may carry (the gateway's ceiling). */
    public const MAX_ITEMS = 20;

    public const ACCEPTED = 'accepted';
    public const RETRY = 'retry';
    public const CONFIG_ERROR = 'config_error';

    /** @var callable(string, array<int,string>, string, int): array{status:int,body:string} */
    private $transport;

    /**
     * @param callable|null $transport `fn(string $url, array $headers, string $body,
     *     int $timeoutS): array{status:int,body:string}` — status 0 when there was no
     *     answer. cURL ({@see curl}) by default; the tests pass their own.
     */
    public function __construct(?callable $transport = null)
    {
        $this->transport = $transport ?? [self::class, 'curl'];
    }

    /**
     * Sends one batch.
     *
     * @param string                          $baseUrl The gateway's address (checked by {@see Settings}).
     * @param string                          $key     The B2B key.
     * @param array<int,array<string,mixed>>  $items   1–{@see MAX_ITEMS} items.
     * @return array{outcome:string,status:int,kod:string,ponow_za_s:?int,element:?int}
     *     `kod` is the gateway's error code, or `http_<status>`, or `siec` for no answer;
     *     '' when accepted.
     */
    public function submit(string $baseUrl, string $key, array $items): array
    {
        $body = json_encode(['elementy' => array_values($items)], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        if ($body === false) {
            // Text is made valid UTF-8 before it gets here, so this is a bug, not the network.
            return self::result(self::CONFIG_ERROR, 0, 'bledne_kodowanie', null, null);
        }
        $answer = call_user_func($this->transport, $baseUrl . self::PATH, [
            'Content-Type: application/json; charset=utf-8',
            'Accept: application/json',
            self::KEY_HEADER . ': ' . $key,
        ], $body, self::TIMEOUT_S);
        return self::classify((int)($answer['status'] ?? 0), (string)($answer['body'] ?? ''));
    }

    /**
     * Reads an answer into an outcome.
     *
     * @param int    $status The HTTP status; 0 for no answer.
     * @param string $body   The answer's body.
     * @return array{outcome:string,status:int,kod:string,ponow_za_s:?int,element:?int}
     */
    public static function classify(int $status, string $body): array
    {
        if ($status >= 200 && $status < 300) {
            return self::result(self::ACCEPTED, $status, '', null, null);
        }
        $error = json_decode($body, true);
        $error = is_array($error) && is_array($error['blad'] ?? null) ? $error['blad'] : [];
        $code = is_string($error['kod'] ?? null) && preg_match('/^[a-z0-9_]{1,40}\z/', $error['kod'])
            ? $error['kod']
            : ($status === 0 ? 'siec' : 'http_' . $status);
        $retryS = is_int($error['ponow_za_s'] ?? null) && $error['ponow_za_s'] >= 0 ? $error['ponow_za_s'] : null;
        $element = is_int($error['element'] ?? null) && $error['element'] >= 0 ? $error['element'] : null;

        if ($status === 0 || $status === 429 || $status >= 500) {
            return self::result(self::RETRY, $status, $code, $retryS, null);
        }
        return self::result(self::CONFIG_ERROR, $status, $code, null, $element);
    }

    /**
     * The default transport: one cURL `POST`, no redirects followed.
     *
     * @param string            $url      The full address.
     * @param array<int,string> $headers  Header lines.
     * @param string            $body     The JSON body.
     * @param int               $timeoutS Seconds for the whole request.
     * @return array{status:int,body:string} Status 0 when there was no answer.
     */
    public static function curl(string $url, array $headers, string $body, int $timeoutS): array
    {
        $handle = curl_init($url);
        if ($handle === false) {
            return ['status' => 0, 'body' => ''];
        }
        curl_setopt_array($handle, [
            CURLOPT_POST           => true,
            CURLOPT_POSTFIELDS     => $body,
            CURLOPT_HTTPHEADER     => $headers,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_FOLLOWLOCATION => false,
            CURLOPT_CONNECTTIMEOUT => min(5, $timeoutS),
            CURLOPT_TIMEOUT        => $timeoutS,
            CURLOPT_PROTOCOLS      => CURLPROTO_HTTPS | CURLPROTO_HTTP,
        ]);
        $answer = curl_exec($handle);
        $status = $answer === false ? 0 : (int)curl_getinfo($handle, CURLINFO_RESPONSE_CODE);
        if (PHP_VERSION_ID < 80000) {
            curl_close($handle); // a no-op since PHP 8.0, deprecated in 8.5
        }
        return ['status' => $status, 'body' => is_string($answer) ? $answer : ''];
    }

    /**
     * @return array{outcome:string,status:int,kod:string,ponow_za_s:?int,element:?int}
     */
    private static function result(string $outcome, int $status, string $code, ?int $retryS, ?int $element): array
    {
        return [
            'outcome'    => $outcome,
            'status'     => $status,
            'kod'        => $code,
            'ponow_za_s' => $retryS,
            'element'    => $element,
        ];
    }
}
