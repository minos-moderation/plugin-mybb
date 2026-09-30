<?php

declare(strict_types=1);

namespace Minos\MyBB;

/**
 * What the plugin sends as `tekst`: the text a READER of the post sees, cut to the
 * gateway's limit.
 *
 * The gateway assesses words, so the post is rendered the way MyBB 1.8 shows it, as text
 * (the rules follow MyBB's own text renderer, `Postparser::text_parse_message`), and only
 * then cut to 3000 characters:
 *
 * - HTML: in a forum with HTML off (MyBB's default) `<…>` is literal text on the page and is
 *   KEPT — `Miłego dnia <b obelga>` shows every word. Only decimal numeric entities
 *   (`&#65;`) are decoded, as MyBB's `htmlspecialchars_uni` lets them through. In a forum
 *   with HTML on, tags are removed but the text of their `alt` and `title` attributes is
 *   kept, `<script>` and `<style>` go with their content, and entities are decoded.
 * - MyCode (when the forum parses it): `[quote=NAME]` becomes "NAME napisał(a):" before the
 *   quoted text; `[url=X]Y[/url]` becomes "Y (X)"; `[img]X[/img]` and `[video=…]X[/video]`
 *   become the address X; `[code]` and `[php]` keep their content as written; formatting
 *   tags go and their text stays. MyCode MyBB would not parse — an unknown tag, a tag
 *   without its pair, any tag in a forum with MyCode off — stays literally, as readers see
 *   it.
 *
 * Only the FIRST 3000 characters are sent; {@see Applier} does not publish a cut post on a
 * verdict about its beginning. Works without mbstring (PCRE with `/u`). No PHP 8 syntax.
 */
final class Text
{
    /** The gateway's per-item ceiling, in characters (`limit_dlugosci` above it). */
    public const MAX_CHARS = 3000;

    /** Between a new thread's subject and its first post in the sent text. */
    public const SUBJECT_SEPARATOR = "\n\n";

    /** The character the gateway masks with, one per masked character. */
    public const MASK = '█';

    /** How a quote's author is shown (the Polish board's wording). */
    public const QUOTE_LEAD = 'napisał(a):';

    /** The contract's ceiling for `meta.link_domains`. */
    public const MAX_DOMAINS = 10;

    /** A forum's parsing options when MyBB does not say (its defaults for a new forum). */
    public const DEFAULT_FORUM = ['allowhtml' => 0, 'allowmycode' => 1, 'allowimgcode' => 1, 'allowvideocode' => 1];

    /** A link, for the spam signals. */
    private const LINK = '~(?:https?://|www\.)[^\s\[\]<>"\'()]+~iu';

    /**
     * Two-label public suffixes common on Polish forums and elsewhere: a registrable domain
     * under them has three labels. An approximation of the Public Suffix List.
     */
    private const TWO_LABEL_SUFFIXES = [
        'com.pl', 'net.pl', 'org.pl', 'edu.pl', 'gov.pl', 'info.pl', 'biz.pl', 'waw.pl', 'nom.pl',
        'co.uk', 'org.uk', 'ac.uk', 'gov.uk', 'me.uk', 'com.au', 'net.au', 'org.au', 'co.nz',
        'co.jp', 'co.kr', 'co.in', 'co.za', 'com.br', 'com.tr', 'com.ua', 'com.cn', 'com.mx', 'co.il',
    ];

    /** Tags after which a reader sees a new line (HTML forums). */
    private const HTML_BLOCKS = ['br', 'p', 'div', 'li', 'tr', 'blockquote', 'hr', 'h1', 'h2', 'h3', 'h4', 'h5', 'h6', 'pre', 'table', 'ul', 'ol'];

    /**
     * The text to send for a new post.
     *
     * @param string              $message The post's message as stored (MyCode source).
     * @param string              $subject A new thread's subject, put in front of the message
     *     so the title is assessed too; '' for a reply.
     * @param array<string,mixed> $forum   The forum's `allowhtml`, `allowmycode`,
     *     `allowimgcode`, `allowvideocode` ({@see DEFAULT_FORUM} for missing ones).
     * @return array{tekst:string,truncated:bool,prefix_len:int,text_len:int} `tekst` is what
     *     goes on the wire ('' when readers see no text at all); `truncated` says whether it
     *     was cut; `prefix_len` counts the characters the subject and its separator take at
     *     its head; `text_len` its length in characters.
     */
    public static function forPost(string $message, string $subject = '', array $forum = []): array
    {
        // A subject is always shown escaped, never as HTML or MyCode.
        $subject = self::squeeze(self::numericEntities(self::valid($subject)));
        $body = self::visible($message, $forum);
        if ($subject === '') {
            $text = $body;
            $prefix = 0;
        } elseif ($body === '') {
            $text = $subject;
            $prefix = self::length($subject);
        } else {
            $text = $subject . self::SUBJECT_SEPARATOR . $body;
            $prefix = self::length($subject) + self::length(self::SUBJECT_SEPARATOR);
        }

        $truncated = self::length($text) > self::MAX_CHARS;
        if ($truncated) {
            $text = rtrim(self::head($text, self::MAX_CHARS));
        }
        return [
            'tekst'      => $text,
            'truncated'  => $truncated,
            'prefix_len' => min($prefix, self::length($text)),
            'text_len'   => self::length($text),
        ];
    }

    /**
     * A message as its readers see it, as text.
     *
     * @param string              $message The message as stored.
     * @param array<string,mixed> $forum   The forum's parsing options.
     * @return string The text, trimmed.
     */
    public static function visible(string $message, array $forum = []): string
    {
        $forum += self::DEFAULT_FORUM;
        $text = str_replace(["\r\n", "\r"], "\n", self::valid($message));
        if (!empty($forum['allowmycode'])) {
            $text = self::mycode($text, !empty($forum['allowvideocode']));
        }
        $text = !empty($forum['allowhtml']) ? self::html($text) : self::numericEntities($text);
        return self::squeeze($text);
    }

    /**
     * How many distinct links a post carries (the `links` spam signal).
     *
     * @param string $message The message as stored.
     * @return int The count, capped at the contract's 100000.
     */
    public static function links(string $message): int
    {
        return min(100000, count(self::urls($message)));
    }

    /**
     * The registrable domains a post links to (the `link_domains` spam signal).
     *
     * @param string $message The message as stored.
     * @return array<int,string> At most {@see MAX_DOMAINS}, lower case, in order of
     *     appearance; IP addresses are left out.
     */
    public static function linkDomains(string $message): array
    {
        $domains = [];
        foreach (self::urls($message) as $url) {
            $domain = self::registrableDomain($url);
            if ($domain !== null) {
                $domains[$domain] = true;
            }
            if (count($domains) === self::MAX_DOMAINS) {
                break;
            }
        }
        return array_keys($domains);
    }

    /**
     * The length of a UTF-8 string in characters.
     *
     * @param string $text Valid UTF-8.
     * @return int Characters.
     */
    public static function length(string $text): int
    {
        if (function_exists('mb_strlen')) {
            return mb_strlen($text, 'UTF-8');
        }
        return (int)preg_match_all('/./su', $text);
    }

    /**
     * The first characters of a UTF-8 string.
     *
     * @param string $text  Valid UTF-8.
     * @param int    $chars How many characters.
     * @return string The head.
     */
    public static function head(string $text, int $chars): string
    {
        if (function_exists('mb_substr')) {
            return mb_substr($text, 0, $chars, 'UTF-8');
        }
        return preg_match('/\A.{0,' . max(0, $chars) . '}/su', $text, $m) ? $m[0] : '';
    }

    /**
     * A UTF-8 string without its first characters.
     *
     * @param string $text  Valid UTF-8.
     * @param int    $chars How many characters to drop.
     * @return string The rest.
     */
    public static function tail(string $text, int $chars): string
    {
        return (string)substr($text, strlen(self::head($text, $chars)));
    }

    /**
     * MyCode rendered as text, paired the way MyBB's parser pairs it.
     */
    private static function mycode(string $text, bool $video): string
    {
        // [code] and [php] show their content as written, MyCode included.
        $blocks = [];
        $text = (string)preg_replace_callback('#\[(code|php)\](.*?)\[/\1\]#is', static function (array $m) use (&$blocks): string {
            $blocks[] = $m[2];
            return "\x00" . (count($blocks) - 1) . "\x00";
        }, $text);

        $text = self::quotes($text);

        $find = [
            '#\[(b|u|i|s|url|email|color)\](.*?)\[/\1\]#is'                                            => '$2',
            '#\[(email|color|size|font|align)=[^\]\n]*\](.*?)\[/\1\]#is'                              => '$2',
            '#\[img(?:=[1-9][0-9]*x[1-9][0-9]*)?(?: align=(?:left|right))?\]\s*(https?://[^<>"\']+?)\[/img\]#is' => '$1',
            '#\[url=((?!javascript)[a-z]+?://)([^\r\n"<]+?)\](.+?)\[/url\]#si'                         => '$3 ($1$2)',
            '#\[url=((?!javascript:)[^\r\n"<&\(\)]+?)\](.+?)\[/url\]#si'                               => '$2 ($1)',
            '#\[attachment=[0-9]+\]#i'                                                                 => '',
            '#\[hr\]#i'                                                                                => "\n",
        ];
        if ($video) {
            $find['#\[video=[a-z0-9_]+\](.*?)\[/video\]#is'] = '$1';
        }
        for ($pass = 0; $pass < 20; $pass++) {
            $before = $text;
            $text = (string)preg_replace(array_keys($find), array_values($find), $text);
            // Lists, innermost first: each `[*]` starts a line.
            $text = (string)preg_replace_callback('#\[list(?:=(?:a|A|i|I|1))?\]((?:(?!\[list(?:=[aAiI1])?\]).)*?)\[/list\]#is',
                static function (array $m): string {
                    return "\n" . str_replace('[*]', "\n", $m[1]) . "\n";
                }, $text);
            if ($text === $before) {
                break;
            }
        }

        return (string)preg_replace_callback("#\x00([0-9]+)\x00#", static function (array $m) use ($blocks): string {
            return "\n" . $blocks[(int)$m[1]] . "\n";
        }, $text);
    }

    /**
     * `[quote]` blocks, innermost first: the author's line, then the quoted text.
     */
    private static function quotes(string $text): string
    {
        $pattern = '#\[quote(=[^\]\n]*)?\]((?:(?!\[quote(?:=[^\]\n]*)?\]).)*?)\[/quote\]#is';
        for ($pass = 0; $pass < 20; $pass++) {
            $before = $text;
            $text = (string)preg_replace_callback($pattern, static function (array $m): string {
                $author = self::quoteAuthor($m[1] ?? '');
                return "\n" . ($author !== '' ? $author . ' ' . self::QUOTE_LEAD . "\n" : '') . $m[2] . "\n";
            }, $text);
            if ($text === $before) {
                break;
            }
        }
        return $text;
    }

    /**
     * The author in `=NAME`, `="NAME" pid='…' dateline='…'` or `='NAME' …`.
     */
    private static function quoteAuthor(string $argument): string
    {
        $argument = trim(ltrim($argument, '='));
        if ($argument === '') {
            return '';
        }
        $quote = $argument[0];
        if (($quote === '"' || $quote === "'") && ($end = strpos($argument, $quote, 1)) !== false) {
            return trim(substr($argument, 1, $end - 1));
        }
        return trim((string)preg_replace('#\s+(pid|dateline)=.*\z#is', '', $argument));
    }

    /**
     * HTML as its reader sees it: tags gone, `alt`/`title` text kept, entities decoded.
     */
    private static function html(string $text): string
    {
        $text = (string)preg_replace('#<(script|style)\b[^>]*>.*?</\1\s*>#is', ' ', $text);
        $text = (string)preg_replace_callback('#<([a-z][a-z0-9]*)\b([^<>]*)>#i', static function (array $m): string {
            $shown = [];
            if (preg_match_all('#(?:^|\s)(?:alt|title)\s*=\s*(?:"([^"]*)"|\'([^\']*)\'|([^\s"\'>]+))#i', $m[2], $attributes, PREG_SET_ORDER)) {
                foreach ($attributes as $attribute) {
                    $value = trim(($attribute[1] ?? '') . ($attribute[2] ?? '') . ($attribute[3] ?? ''));
                    if ($value !== '') {
                        $shown[] = $value;
                    }
                }
            }
            $block = in_array(strtolower($m[1]), self::HTML_BLOCKS, true);
            return ($block ? "\n" : '') . ($shown !== [] ? ' ' . implode(' ', $shown) . ' ' : '');
        }, $text);
        $text = (string)preg_replace_callback('#</([a-z][a-z0-9]*)\s*>#i', static function (array $m): string {
            return in_array(strtolower($m[1]), self::HTML_BLOCKS, true) ? "\n" : '';
        }, $text);
        return html_entity_decode($text, ENT_QUOTES | ENT_HTML5, 'UTF-8');
    }

    /**
     * Decimal numeric entities, the only ones MyBB shows as characters with HTML off.
     */
    private static function numericEntities(string $text): string
    {
        return (string)preg_replace_callback('/&#([0-9]{1,7});/', static function (array $m): string {
            return html_entity_decode($m[0], ENT_QUOTES | ENT_HTML5, 'UTF-8');
        }, $text);
    }

    /**
     * The distinct links of a message, lower-cased, in order.
     *
     * @return array<int,string>
     */
    private static function urls(string $message): array
    {
        if (!preg_match_all(self::LINK, self::valid($message), $m)) {
            return [];
        }
        return array_values(array_unique(array_map('strtolower', $m[0])));
    }

    /**
     * A link's registrable domain: the last two labels of its host, or three under a
     * two-label public suffix.
     */
    private static function registrableDomain(string $url): ?string
    {
        $host = parse_url(stripos($url, 'www.') === 0 ? 'http://' . $url : $url, PHP_URL_HOST);
        if (!is_string($host)) {
            return null;
        }
        $host = rtrim(strtolower($host), '.');
        if ($host === '' || filter_var(trim($host, '[]'), FILTER_VALIDATE_IP) !== false || strpos($host, '.') === false) {
            return null;
        }
        $labels = explode('.', $host);
        $take = in_array(implode('.', array_slice($labels, -2)), self::TWO_LABEL_SUFFIXES, true) ? 3 : 2;
        if (count($labels) < $take) {
            return null;
        }
        return implode('.', array_slice($labels, -$take));
    }

    /**
     * Valid UTF-8: every broken byte sequence becomes U+FFFD, so the text can always be
     * encoded as JSON.
     */
    private static function valid(string $text): string
    {
        if (preg_match('//u', $text) === 1) {
            return $text;
        }
        return htmlspecialchars_decode(htmlspecialchars($text, ENT_SUBSTITUTE | ENT_NOQUOTES, 'UTF-8'), ENT_NOQUOTES);
    }

    /**
     * Runs of spaces become one space, lines are trimmed, three or more line breaks
     * become two, and the whole is trimmed.
     */
    private static function squeeze(string $text): string
    {
        $text = (string)preg_replace('/[ \t\x{00A0}]+/u', ' ', $text);
        $text = (string)preg_replace('/ *\n */', "\n", $text);
        $text = (string)preg_replace('/\n{3,}/', "\n\n", $text);
        return trim($text);
    }
}
