<?php

declare(strict_types=1);

namespace Minos\MyBB;

/**
 * What the plugin sends as `tekst`: a post's plain text, cut to the gateway's limit.
 *
 * The gateway assesses words, not markup, and counts at most 3000 CHARACTERS (not bytes)
 * per item, so a post is turned into plain text first — MyCode tags and HTML tags removed,
 * the text between them kept — and only then cut. Only the FIRST 3000 characters are sent
 * and assessed; the rest of a longer post is not (see the README). A verdict that would
 * rewrite such a post (`ocenzurowane`) is therefore never applied to it: {@see Applier}
 * holds it instead of publishing a post cut in half.
 *
 * Text a user wrote stays, wherever it is: a `[quote]` block is kept, because its content
 * is shown on the page and a quote is the obvious place to hide abuse. Media tags (`[img]`,
 * `[video]`, attachments) carry addresses, not text, and are dropped.
 *
 * Works without the mbstring extension (PCRE with `/u`), which not every forum host has.
 * No PHP 8 syntax.
 */
final class Text
{
    /** The gateway's per-item ceiling, in characters (`limit_dlugosci` above it). */
    public const MAX_CHARS = 3000;

    /** Between a new thread's subject and its first post in the sent text. */
    public const SUBJECT_SEPARATOR = "\n\n";

    /** The character the gateway masks with, one per masked character. */
    public const MASK = '█';

    /** Tags whose content is an address, not text: dropped with their content. */
    private const MEDIA = '~\[(img|video)(?:=[^\]\n]*)?\].*?\[/\1\]|\[attachment=\d+\]~isu';

    /** Block MyCode tags: replaced by a line break, so words on both sides stay apart. */
    private const BLOCK_TAGS = '~\[/?(?:quote|code|php|list|\*|hr|align)(?:=[^\]\n]*)?\]~iu';

    /** Inline MyCode tags: removed, the text between them kept as it was written. */
    private const INLINE_TAGS = '~\[/?(?:b|i|u|s|url|email|color|size|font|sub|sup|spoiler)(?:=[^\]\n]*)?\]~iu';

    /** An HTML tag (a forum may allow HTML); `a < b` and `<3` are not tags and stay. */
    private const HTML_TAG = '~</?[a-z][a-z0-9]*(?:\s[^<>]*)?/?>~iu';

    /** A link, for the `links` spam signal. */
    private const LINK = '~(?:https?://|www\.)[^\s\[\]<>"\']+~iu';

    /**
     * The text to send for a new post.
     *
     * @param string $message The post's message as stored (MyCode source).
     * @param string $subject A new thread's subject, put in front of the message so the
     *     title is assessed too; '' for a reply (whose subject is MyBB's automatic
     *     "RE: …" in all but rare cases — see the README).
     * @return array{tekst:string,truncated:bool,prefix_len:int,text_len:int} `tekst` is
     *     what goes on the wire ('' when the post has no text at all); `truncated` says
     *     whether it was cut; `prefix_len` is the number of characters the subject and its
     *     separator take at its head; `text_len` its length in characters.
     */
    public static function forPost(string $message, string $subject = ''): array
    {
        $subject = self::squeeze(self::valid($subject));
        $body = self::plain($message);
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
     * A message as plain text: MyCode and HTML tags removed, whitespace tidied.
     *
     * @param string $message The message as stored.
     * @return string The plain text, trimmed.
     */
    public static function plain(string $message): string
    {
        $text = str_replace(["\r\n", "\r"], "\n", self::valid($message));
        $text = (string)preg_replace(self::MEDIA, ' ', $text);
        $text = (string)preg_replace(self::BLOCK_TAGS, "\n", $text);
        $text = (string)preg_replace(self::INLINE_TAGS, '', $text);
        $text = (string)preg_replace(self::HTML_TAG, '', $text);
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
        $found = preg_match_all(self::LINK, self::valid($message), $m);
        return $found ? min(100000, count(array_unique(array_map('strtolower', $m[0])))) : 0;
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
     * Valid UTF-8: every broken byte sequence becomes U+FFFD, so the text can always be
     * encoded as JSON.
     *
     * @param string $text Anything.
     * @return string Valid UTF-8.
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
     *
     * @param string $text Valid UTF-8 with "\n" line breaks.
     * @return string The tidied text.
     */
    private static function squeeze(string $text): string
    {
        $text = (string)preg_replace('/[ \t\x{00A0}]+/u', ' ', $text);
        $text = (string)preg_replace('/ *\n */', "\n", $text);
        $text = (string)preg_replace('/\n{3,}/', "\n\n", $text);
        return trim($text);
    }
}
