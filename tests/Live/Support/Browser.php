<?php

declare(strict_types=1);

namespace Minos\MyBB\Tests\Live\Support;

/**
 * A browser for the live forum: cURL with its own cookie jar, so each user (the
 * administrator, an author, a subscriber, a guest) keeps a session of their own, the way
 * MyBB sees separate visitors. No JavaScript, no images (MyBB's `task.php` image in the
 * footer is not fetched: tasks run only when a test asks for them).
 */
final class Browser
{
    /** @var string The forum's base URL, without a trailing slash. */
    private $base;

    /** @var string The cookie jar file. */
    private $jar;

    /** @var int The last answer's status. */
    public $status = 0;

    /** @var float The last request's wall time, in seconds. */
    public $seconds = 0.0;

    public function __construct(string $base)
    {
        $this->base = rtrim($base, '/');
        $this->jar = (string)tempnam(sys_get_temp_dir(), 'minos-live-jar-');
    }

    public function __destruct()
    {
        if (is_file($this->jar)) {
            unlink($this->jar);
        }
    }

    /**
     * GET a page of the forum.
     *
     * @param string $path A path under the forum, with its query.
     * @return string The body.
     */
    public function get(string $path): string
    {
        return $this->request('GET', $path, null, []);
    }

    /**
     * POST a form to the forum.
     *
     * @param string               $path   A path under the forum.
     * @param array<string,mixed>  $fields The form's fields (nested arrays allowed).
     * @return string The body.
     */
    public function post(string $path, array $fields): string
    {
        return $this->request('POST', $path, http_build_query($fields), ['Content-Type: application/x-www-form-urlencoded']);
    }

    /**
     * POST raw bytes with headers (the webhook).
     *
     * @param array<int,string> $headers
     * @return string The body.
     */
    public function postRaw(string $path, string $body, array $headers): string
    {
        return $this->request('POST', $path, $body, $headers);
    }

    /**
     * MyBB's friendly redirect page ("You will now be taken…", a meta refresh): the page it
     * leads to. Any other page is returned as it is.
     */
    public function followRefresh(string $html): string
    {
        if (preg_match('#<meta http-equiv="refresh" content="\d+;URL=([^"]+)"#i', $html, $m)) {
            return $this->get(html_entity_decode($m[1], ENT_QUOTES, 'UTF-8'));
        }
        return $html;
    }

    /**
     * The `my_post_key` a page carries (MyBB's CSRF token of this session).
     */
    public static function postKey(string $html): string
    {
        if (preg_match('/name="my_post_key" value="([0-9a-f]{32})"/', $html, $m)
            || preg_match('/my_post_key=([0-9a-f]{32})/', $html, $m)
            || preg_match('/var my_post_key = "([0-9a-f]{32})"/', $html, $m)) {
            return $m[1];
        }
        throw new \RuntimeException('no my_post_key on the page');
    }

    /**
     * An input's value on a page (hidden or text).
     */
    public static function hidden(string $html, string $name): string
    {
        if (preg_match('/<input\b[^>]*\bname="' . preg_quote($name, '/') . '"[^>]*>/', $html, $input)
            && preg_match('/\bvalue="([^"]*)"/', $input[0], $m)) {
            return html_entity_decode($m[1], ENT_QUOTES, 'UTF-8');
        }
        throw new \RuntimeException('no field ' . $name);
    }

    /**
     * @param array<int,string> $headers
     */
    private function request(string $method, string $path, ?string $body, array $headers): string
    {
        $curl = curl_init($this->base . '/' . ltrim($path, '/'));
        curl_setopt_array($curl, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_MAXREDIRS      => 5,
            CURLOPT_COOKIEJAR      => $this->jar,
            CURLOPT_COOKIEFILE     => $this->jar,
            CURLOPT_TIMEOUT        => 120,
            CURLOPT_HTTPHEADER     => $headers,
            CURLOPT_CUSTOMREQUEST  => $method,
        ]);
        if ($body !== null) {
            curl_setopt($curl, CURLOPT_POSTFIELDS, $body);
        }
        $started = microtime(true);
        $answer = curl_exec($curl);
        $this->seconds = microtime(true) - $started;
        $this->status = (int)curl_getinfo($curl, CURLINFO_RESPONSE_CODE);
        $error = curl_error($curl);
        curl_close($curl);
        if (!is_string($answer)) {
            throw new \RuntimeException($method . ' ' . $path . ': ' . $error);
        }
        return $answer;
    }
}
