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
 *   kept, and entities are decoded. MyBB escapes `<script>`, `<style>`, `<base>` and
 *   `<meta>` there (`parse_message`), so readers see those tags and what is between them as
 *   text, and so are they sent; an HTML comment is hidden from readers, and so is it from
 *   the gateway (verified on MyBB 1.8.41: `tests/fixtures/mybb-1.8.41/reader-view.json`).
 * - MyCode (when the forum parses it): `[quote=NAME]` becomes "NAME napisał(a):" before the
 *   quoted text; `[url=X]Y[/url]` becomes "Y (X)"; `[img]X[/img]` and `[video=…]X[/video]`
 *   become the address X; `[code]` and `[php]` keep their content as written; formatting
 *   tags go and their text stays. MyCode MyBB would not parse — an unknown tag, a tag
 *   without its pair, a `[size]`, `[align]`, `[color]` or `[font]` whose value MyBB's
 *   patterns refuse (`[size=OBELGA]`), any tag in a forum with MyCode off — stays
 *   literally, as readers see it.
 *
 * What heads the post for its readers comes first: a new thread's subject, then the
 * question and options of its poll (MyBB shows the poll above the first post); a reply's
 * own subject, when its author changed MyBB's default "RE: <thread title>" ({@see ofPost}).
 * A title or a subject alone is not a text to assess (shared rule F7): a post whose body
 * shows no text and that carries no poll gets '' — nothing is sent. A poll is text: a first
 * post with an empty body and a poll sends the title and the poll. Neither is an image, a
 * video or a link whose label shows nothing (F7): a body that shows only `[img]` (with
 * MyBB's image MyCode on), a `[video]` MyBB embeds, `[url=X] [/url]` or an `<img>`, even
 * with its `alt` or `title`, shows no text ({@see shows}); a bare address the reader sees is
 * text. Beside other text, an image's address or hint is still sent as before.
 *
 * Only the FIRST 3000 characters are sent; {@see Applier} does not publish a cut post on a
 * verdict about its beginning. Works without mbstring (PCRE with `/u`).
 *
 * Every regular expression runs through one checked helper ({@see replace}, {@see matchAll}…):
 * when PCRE fails (a post crafted to exhaust its backtrack limit, say), the helper throws
 * {@see TextError} instead of handing back an empty string. {@see forPost} turns that into
 * `error: true` — a text the plugin could not read is not an empty text, and nothing (not
 * even a new thread's subject alone) may be sent in its place. No PHP 8 syntax.
 */
final class Text
{
    /** The gateway's per-item ceiling, in characters (`limit_dlugosci` above it). */
    public const MAX_CHARS = 3000;

    /** Between a subject, a poll and the post in the sent text. */
    public const SUBJECT_SEPARATOR = "\n\n";

    /** Between a poll's question and each of its options in the sent text. */
    public const POLL_SEPARATOR = "\n";

    /** How MyBB 1.8.41 begins a reply's default subject (`newreply.php`, the post datahandler). */
    public const REPLY_PREFIX = 'RE: ';

    /**
     * MyBB shortens a thread title longer than {@see TITLE_MAX_CHARS} characters to this many,
     * and "...", in a reply's default subject (`newreply.php`).
     */
    private const DEFAULT_SUBJECT_CHARS = 82;

    /** The longest subject MyBB accepts (`PostDataHandler::verify_subject`). */
    private const TITLE_MAX_CHARS = 85;

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

    /**
     * What a reader does not see on its own (as plugin-wordpress's `Text::INVISIBLE`, plus
     * marks): white space and separators (`\p{Z}`: U+3000, U+2028…), format characters
     * (`\p{Cf}`: U+200B, U+2060, U+FEFF, the soft hyphen U+00AD…), controls (`\p{Cc}`),
     * marks without a base (`\p{M}`: U+034F, U+FE0F — real text keeps its base letters),
     * and the blank fillers some scripts carry (the Hangul fillers, the blank Braille
     * pattern).
     */
    private const INVISIBLE = '/[\s\p{Z}\p{Cf}\p{Cc}\p{M}\x{115F}\x{1160}\x{3164}\x{FFA0}\x{2800}]+/u';

    /**
     * A tag's attributes, quoted values whole (they may hold `<`, `>`, `<!--`). Possessive,
     * a run of plain characters one step: no backtracking into it, and no step per
     * character that a long attribute (10 000 characters) would exhaust PCRE's JIT stack with.
     */
    private const ATTRIBUTES = '(?:[^<>"\']++|"[^"]*+"|\'[^\']*+\')*+';

    /** Tags after which a reader sees a new line (HTML forums). */
    private const HTML_BLOCKS = ['br', 'p', 'div', 'li', 'tr', 'blockquote', 'hr', 'h1', 'h2', 'h3', 'h4', 'h5', 'h6', 'pre', 'table', 'ul', 'ol'];

    /**
     * The text to send for a post as {@see Platform::post} reads it: for a thread's first
     * post its subject and its poll, for a reply its own subject ({@see ownSubject}).
     *
     * @param array<string,mixed> $post        The post: `message`, `subject`,
     *     `thread_subject`, `replyto_subject` and `poll` (`question`, `options`, or null).
     * @param bool                $opensThread The post opens its thread.
     * @param array<string,mixed> $forum       The forum's parsing options.
     * @param bool|null           $ownSubject  For a reply: whether its subject goes with it,
     *     as decided when it was first sent (the row keeps it) — a later change of the
     *     thread's title or of the quoted post's subject changes nothing; null decides now.
     * @return array{tekst:string,truncated:bool,prefix_len:int,text_len:int,error:bool} As {@see forPost}.
     */
    public static function ofPost(array $post, bool $opensThread, array $forum = [], ?bool $ownSubject = null): array
    {
        if ($opensThread) {
            $poll = is_array($post['poll'] ?? null) ? $post['poll'] : null;
            return self::forPost((string)$post['message'], (string)$post['subject'], $forum, $poll);
        }
        return self::forPost((string)$post['message'], self::replySubject($post, $ownSubject), $forum);
    }

    /**
     * Whether a reply's subject goes with it, decided now ({@see ownSubject}).
     *
     * @param array<string,mixed> $post As for {@see ofPost}.
     */
    public static function sendsOwnSubject(array $post): bool
    {
        return self::ownSubject((string)$post['subject'], (string)($post['thread_subject'] ?? ''),
            (string)($post['replyto_subject'] ?? '')) !== '';
    }

    /**
     * The subject sent in front of a reply: its own, or ''.
     *
     * @param array<string,mixed> $post As for {@see ofPost}.
     */
    private static function replySubject(array $post, ?bool $ownSubject): string
    {
        if ($ownSubject === null) {
            $ownSubject = self::sendsOwnSubject($post);
        }
        return $ownSubject ? trim((string)$post['subject']) : '';
    }

    /**
     * The raw text the link signals of a post are read from: what {@see ofPost} sends, as
     * stored (subject, poll, message).
     *
     * @param array<string,mixed> $post        As for {@see ofPost}.
     * @param bool                $opensThread The post opens its thread.
     * @param bool|null           $ownSubject  As for {@see ofPost}.
     * @return string The parts, one per line.
     */
    public static function sourceOf(array $post, bool $opensThread, ?bool $ownSubject = null): string
    {
        $parts = [$opensThread ? (string)$post['subject'] : self::replySubject($post, $ownSubject)];
        if ($opensThread && is_array($post['poll'] ?? null)) {
            $parts[] = (string)$post['poll']['question'];
            foreach ((array)$post['poll']['options'] as $option) {
                $parts[] = (string)$option;
            }
        }
        $parts[] = (string)$post['message'];
        return implode("\n", $parts);
    }

    /**
     * A reply's subject when its readers learn something from it: '' for one of MyBB's
     * defaults and for the thread's title itself. MyBB 1.8.41 (`newreply.php`) fills in "RE: "
     * and the thread's title, or — for a reply to a post (`replyto`, the post whose quote or
     * reply button was used) — "RE: " and that post's subject without its own leading "RE:";
     * either cut to 82 characters and "..." when longer than 85. MyBB shows a reply's subject
     * in the threaded view, in search results, in the printable version and as the title of
     * the post's link in the linear view (its default templates).
     *
     * @param string $subject        The reply's subject as stored.
     * @param string $threadSubject  Its thread's title as stored.
     * @param string $replytoSubject The subject of the post it replies to ('' for none).
     * @return string The subject, or ''.
     */
    public static function ownSubject(string $subject, string $threadSubject, string $replytoSubject = ''): string
    {
        $subject = trim($subject);
        $defaults = ['', $threadSubject];
        foreach ([$threadSubject, self::withoutReplyPrefix($replytoSubject)] as $base) {
            if ($base === '') {
                continue;
            }
            $defaults[] = self::REPLY_PREFIX . $base;
            if (strlen($base) > self::TITLE_MAX_CHARS) {
                try {
                    if (self::length($base) > self::TITLE_MAX_CHARS) {
                        $defaults[] = self::REPLY_PREFIX . self::head($base, self::DEFAULT_SUBJECT_CHARS) . '...';
                    }
                } catch (TextError $e) {
                    // Without mbstring, a title PCRE cannot split: the subject is sent.
                }
            }
        }
        return in_array($subject, $defaults, true) ? '' : $subject;
    }

    /**
     * A subject without one leading "RE:" (any case) and one white-space character after it,
     * as `newreply.php` strips the quoted post's subject (`#^RE:\s?#i`).
     */
    private static function withoutReplyPrefix(string $subject): string
    {
        if (strncasecmp($subject, 'RE:', 3) !== 0) {
            return $subject;
        }
        $rest = (string)substr($subject, 3);
        return $rest !== '' && strpos(" \t\n\r\f\v", $rest[0]) !== false ? (string)substr($rest, 1) : $rest;
    }

    /**
     * The text to send for a post.
     *
     * @param string              $message The post's message as stored (MyCode source).
     * @param string              $subject A subject to put in front of the message, so it is
     *     assessed too: a new thread's, or a reply's own; '' for none.
     * @param array<string,mixed> $forum   The forum's `allowhtml`, `allowmycode`,
     *     `allowimgcode`, `allowvideocode` ({@see DEFAULT_FORUM} for missing ones).
     * @param array{question:string,options:array<int,string>}|null $poll A thread's poll,
     *     after the subject: its question, then its options, as MyBB shows them.
     * @return array{tekst:string,truncated:bool,prefix_len:int,text_len:int,error:bool} `tekst`
     *     is what goes on the wire ('' when the body shows no text and there is no poll — a
     *     subject alone is never sent, shared rule F7); `truncated` says
     *     whether it was cut; `prefix_len` counts the characters the subject, the poll and
     *     their separators take at its head; `text_len` its length in characters. `error` is
     *     true when the text could not be read at all (a PCRE failure): `tekst` is then ''
     *     and must not be taken for a post without text.
     */
    public static function forPost(string $message, string $subject = '', array $forum = [], ?array $poll = null): array
    {
        try {
            // A subject is always shown escaped, never as HTML or MyCode.
            $heads = [self::squeeze(self::numericEntities(self::valid($subject)))];
            $pollText = $poll !== null ? self::poll($poll, $forum) : '';
            $heads[] = $pollText;
            $head = implode(self::SUBJECT_SEPARATOR, array_values(array_filter($heads, static function (string $part): bool {
                return $part !== '';
            })));
            $body = self::visible($message, $forum);
            if ($pollText === '' && ($body === '' || !self::shows($message, $forum))) {
                // A title alone is not a text to assess (F7), nor an image, a video or a link
                // without a label: nothing goes out, not even the title.
                return ['tekst' => '', 'truncated' => false, 'prefix_len' => 0, 'text_len' => 0, 'error' => false];
            }
            if ($head === '') {
                $text = $body;
                $prefix = 0;
            } elseif ($body === '') {
                $text = $head;
                $prefix = self::length($head);
            } else {
                $text = $head . self::SUBJECT_SEPARATOR . $body;
                $prefix = self::length($head) + self::length(self::SUBJECT_SEPARATOR);
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
                'error'      => false,
            ];
        } catch (TextError $e) {
            return ['tekst' => '', 'truncated' => false, 'prefix_len' => 0, 'text_len' => 0, 'error' => true];
        }
    }

    /**
     * A message as its readers see it, as text.
     *
     * @param string              $message The message as stored.
     * @param array<string,mixed> $forum   The forum's parsing options.
     * @return string The text, trimmed.
     * @throws TextError When PCRE fails on it.
     */
    public static function visible(string $message, array $forum = []): string
    {
        return self::render($message, $forum, false);
    }

    /**
     * Whether a reader sees any text in a message (shared rule F7): not when all it shows is
     * an image (`[img]` with MyBB's image MyCode on, an `<img>` on an HTML forum — its `alt`
     * or `title` notwithstanding), a video MyBB embeds (a known site, a valid address), an
     * attachment, or a link whose label shows nothing (`[url=X] [/url]`, `[url=X][img]…`,
     * `<a href="X"> </a>`), with nothing else that takes visible place — white space and
     * invisible characters ({@see INVISIBLE}: a zero-width space, a soft hyphen…) do not. A bare address the
     * reader sees (`https://…`, `[url]X[/url]`, an image or video tag MyBB does not render)
     * is text. MyBB 1.8.41's reader view, recorded
     * by the live run (`tests/fixtures/mybb-1.8.41/reader-view.json`), is the reference.
     * When PCRE fails on this reading, the answer is yes: in doubt, the text is sent.
     *
     * @param string              $message The message as stored.
     * @param array<string,mixed> $forum   The forum's parsing options.
     */
    public static function shows(string $message, array $forum = []): bool
    {
        // A shortcut with the same answer: what comes before the first character that could
        // open any markup — `[` (MyCode), `<` (HTML), `&` (an entity), a NUL (the plugin's own
        // placeholder) — is on the page as written, whatever the forum's options; when it
        // already shows something, so does the post, and the reading below (each pass over
        // every image, video and link pattern) is not needed. A post of text with long
        // markup after it costs one reading ({@see visible}), not two.
        $lead = strcspn($message, "[<&\0");
        if ($lead > 0 && !self::blank(self::valid(substr($message, 0, $lead)))) {
            return true;
        }
        try {
            return !self::blank(self::render($message, $forum, true));
        } catch (TextError $e) {
            // Not knowing is no reason to send less: the post goes out as {@see visible}
            // renders it (whose own failure is `error`, a separate matter).
            return true;
        }
    }

    /**
     * Whether a text shows a reader nothing: only characters in {@see INVISIBLE}, which take
     * no visible place on the page. When PCRE fails here the text is not blank: in doubt,
     * it is sent.
     */
    private static function blank(string $text): bool
    {
        return preg_replace(self::INVISIBLE, '', $text) === '';
    }

    /**
     * A message as text: as its readers see it ({@see visible}), or — `$textOnly` — only
     * the text they see, without images, videos, labels-less links and hints
     * ({@see shows}).
     *
     * @param array<string,mixed> $forum The forum's parsing options.
     * @throws TextError When PCRE fails on it.
     */
    private static function render(string $message, array $forum, bool $textOnly): string
    {
        $forum += self::DEFAULT_FORUM;
        $text = str_replace(["\r\n", "\r"], "\n", self::valid($message));
        if (!empty($forum['allowmycode'])) {
            $text = self::mycode($text, $forum, $textOnly);
        }
        $text = !empty($forum['allowhtml']) ? self::html($text, $textOnly) : self::numericEntities($text);
        return self::squeeze($text);
    }

    /**
     * How many distinct links a post carries (the `links` spam signal).
     *
     * @param string $message The message as stored.
     * @return int The count, capped at the contract's 100000.
     * @throws TextError When PCRE fails on it.
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
     * @throws TextError When PCRE fails on it.
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
     * @throws TextError When PCRE fails on it (without mbstring only).
     */
    public static function length(string $text): int
    {
        if (function_exists('mb_strlen')) {
            return mb_strlen($text, 'UTF-8');
        }
        return count(self::chars($text));
    }

    /**
     * The first characters of a UTF-8 string.
     *
     * @param string $text  Valid UTF-8.
     * @param int    $chars How many characters.
     * @return string The head.
     * @throws TextError When PCRE fails on it (without mbstring only).
     */
    public static function head(string $text, int $chars): string
    {
        if (function_exists('mb_substr')) {
            return mb_substr($text, 0, $chars, 'UTF-8');
        }
        return implode('', array_slice(self::chars($text), 0, max(0, $chars)));
    }

    /**
     * A UTF-8 string without its first characters.
     *
     * @param string $text  Valid UTF-8.
     * @param int    $chars How many characters to drop.
     * @return string The rest.
     * @throws TextError When PCRE fails on it (without mbstring only).
     */
    public static function tail(string $text, int $chars): string
    {
        return (string)substr($text, strlen(self::head($text, $chars)));
    }

    /**
     * Whether a masked text is the sent text with some characters replaced by the mask, one
     * per character, as the contract masks: the same length, and every character either
     * the one that was sent or {@see MASK}.
     *
     * @param string $sent   The text that was sent.
     * @param string $masked The gateway's `ocenzurowany`.
     * @return bool False for any other text, and when either cannot be read.
     */
    public static function maskFits(string $sent, string $masked): bool
    {
        try {
            $sentChars = self::chars($sent);
            $maskedChars = self::chars($masked);
        } catch (TextError $e) {
            return false;
        }
        if (count($sentChars) !== count($maskedChars)) {
            return false;
        }
        foreach ($maskedChars as $i => $char) {
            if ($char !== $sentChars[$i] && $char !== self::MASK) {
                return false;
            }
        }
        return true;
    }

    /**
     * A poll as MyBB 1.8.41's `showthread.php` shows it: the question escaped (like a
     * subject), each option through the post parser with the forum's options, one per line.
     *
     * @param array{question:string,options:array<int,string>} $poll
     * @param array<string,mixed>                               $forum The forum's parsing options.
     * @return string The text; '' for a poll without any.
     * @throws TextError When PCRE fails on it.
     */
    private static function poll(array $poll, array $forum): string
    {
        $lines = [self::squeeze(self::numericEntities(self::valid((string)($poll['question'] ?? ''))))];
        foreach ((array)($poll['options'] ?? []) as $option) {
            $lines[] = self::squeeze(str_replace("\n", ' ', self::visible((string)$option, $forum)));
        }
        return implode(self::POLL_SEPARATOR, array_values(array_filter($lines, static function (string $line): bool {
            return $line !== '';
        })));
    }

    /**
     * MyCode rendered as text, paired the way MyBB's parser pairs it.
     *
     * @param array<string,mixed> $forum The forum's parsing options.
     * @param bool $textOnly Only the text a reader sees ({@see shows}).
     */
    private static function mycode(string $text, array $forum, bool $textOnly): string
    {
        // [code] and [php] show their content as written, MyCode included.
        $blocks = [];
        $text = self::replaceCallback('#\[(code|php)\](.*?)\[/\1\]#is', static function (array $m) use (&$blocks): string {
            $blocks[] = $m[2];
            return "\x00" . (count($blocks) - 1) . "\x00";
        }, $text);

        $text = self::quotes($text);
        $text = $textOnly ? self::shownMyCode($text, $forum) : self::inline($text, !empty($forum['allowvideocode']));

        // PCRE's own `\x00` escape: PHP before 8.2 refuses a literal NUL byte in a pattern.
        return self::replaceCallback('#\x00([0-9]+)\x00#', static function (array $m) use ($blocks): string {
            return "\n" . $blocks[(int)$m[1]] . "\n";
        }, $text);
    }

    /**
     * The MyCode after quotes and code blocks, rendered as text the way MyBB's text renderer
     * does (`text_parse_message`): an image or a video becomes its address, a link its label
     * and its address.
     */
    private static function inline(string $text, bool $video): string
    {
        $find = [
            '#\[(b|u|i|s|url|email)\](.*?)\[/\1\]#is'                                                  => '$2',
            '#\[email=[^\]\n]*\](.*?)\[/email\]#is'                                                   => '$1',
            // Only the option values MyBB 1.8.41's parser accepts (class_parser.php, the
            // color/size/align/font patterns); any other value leaves the tag on the page.
            '#\[color=([a-zA-Z]*|\#?[\da-fA-F]{3}|\#?[\da-fA-F]{6})\](.*?)\[/color\]#si'                => '$2',
            '#\[size=(xx-small|x-small|small|medium|large|x-large|xx-large)\](.*?)\[/size\]#si'           => '$2',
            '#\[size=([0-9\+\-]+?)\](.*?)\[/size\]#si'                                                  => '$2',
            '#\[align=(left|center|right|justify)\](.*?)\[/align\]#si'                                   => '$2',
            '#\[font=\s*("?)([a-z0-9 ,\-_\'"]+)\1\s*\](.*?)\[/font\]#si'                                => '$3',
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
            $text = self::replace(array_keys($find), array_values($find), $text);
            // Lists, innermost first: each `[*]` starts a line.
            $text = self::replaceCallback('#\[list(?:=(?:a|A|i|I|1))?\]((?:(?!\[list(?:=[aAiI1])?\]).)*?)\[/list\]#is',
                static function (array $m): string {
                    return "\n" . str_replace('[*]', "\n", $m[1]) . "\n";
                }, $text);
            if ($text === $before) {
                break;
            }
        }
        return $text;
    }

    /**
     * The MyCode after quotes and code blocks, reduced to the text a reader sees
     * ({@see shows}): an image MyBB shows as an image is gone; a video MyBB embeds is gone;
     * a link is its label — what the label shows, read by the same passes. What MyBB leaves
     * on the page as typed stays, as in {@see inline}. No recursion: each pass is linear.
     *
     * @param array<string,mixed> $forum The forum's parsing options.
     */
    private static function shownMyCode(string $text, array $forum): string
    {
        $images = !empty($forum['allowimgcode']);
        $video = !empty($forum['allowvideocode']);
        for ($pass = 0; $pass < 20; $pass++) {
            $before = $text;
            // A link first, by its label, before the images: `[url=X][img]…[/img][/url]` is an
            // image link, not the text "[url=X][/url]" that removing the image first would
            // leave. MyBB shows a `data:` address instead of the link (`mycode_parse_url`).
            foreach (['#\[url=((?!javascript)[a-z]+?://)([^\r\n"<]+?)\](.+?)\[/url\]#si' => [3, 1, 2],
                '#\[url=((?!javascript:)[^\r\n"<&\(\)]+?)\](.+?)\[/url\]#si' => [2, 1, 1]] as $pattern => $parts) {
                $text = self::replaceCallback($pattern, static function (array $m) use ($parts): string {
                    $address = $parts[1] === $parts[2] ? $m[$parts[1]] : $m[$parts[1]] . $m[$parts[2]];
                    return ' ' . (stripos($address, 'data:') === 0 ? $address : $m[$parts[0]]) . ' ';
                }, $text);
            }
            // MyBB 1.8.41's own image patterns (`parse_mycode`): at most one line break before
            // the address, nothing else; any other `[img]` stays on the page as typed.
            if ($images) {
                $text = self::replace('#\[img(?:=[1-9][0-9]*x[1-9][0-9]*)?(?: align=(?:left|right))?\](?:\r\n?|\n?)https?://[^<>"\']+?\[/img\]#is', ' ', $text);
            }
            // MyBB's video pattern (no line breaks), and only a video it embeds goes; one it
            // leaves as typed stays text — and is kept as typed, not read again below.
            if ($video) {
                $text = self::replaceCallback('#\[video=(.*?)\](.*?)\[/video\]#i', static function (array $m): string {
                    return self::embedsVideo($m[1], $m[2]) ? ' ' : $m[0];
                }, $text);
            }
            $text = self::inline($text, false);
            if ($text === $before) {
                break;
            }
        }
        return $text;
    }

    /**
     * Whether MyBB 1.8.41 shows a `[video=SITE]URL[/video]` as an embedded player:
     * `Postparser::mycode_parse_video`, step by step — a site it knows, a valid URL, and an
     * id its rule for that site finds in the address. Anything else MyBB leaves on the page
     * as typed (`[video=SITE]URL[/video]`), which is text.
     */
    private static function embedsVideo(string $video, string $url): bool
    {
        if (empty($video) || empty($url) || filter_var($url, FILTER_VALIDATE_URL) === false) {
            return false;
        }
        $parsed = @parse_url(urldecode($url));
        if ($parsed === false) {
            return false;
        }
        $fragments = empty($parsed['fragment']) ? [] : explode('&', $parsed['fragment']);
        if ($video === 'liveleak' && !empty($parsed['query'])) {
            $parsed['query'] = 'i' . substr($parsed['query'], 1);
        }
        $input = [];
        foreach (empty($parsed['query']) ? [] : explode('&', $parsed['query']) as $query) {
            $pair = explode('=', $query);
            if (count($pair) === 2) {
                $input[str_replace('amp;', '', $pair[0])] = $pair[1];
            }
        }
        $path = empty($parsed['path']) ? [] : explode('/', $parsed['path']);
        $id = '';
        switch ($video) {
            case 'dailymotion':
                if (!empty($path[2])) {
                    $id = explode('_', $path[2], 2)[0];
                } elseif (!empty($path[1])) {
                    $id = $path[1];
                }
                break;
            case 'metacafe':
                $id = !empty($path[2]) ? $path[2] : '';
                break;
            case 'myspacetv':
                $id = !empty($path[4]) ? $path[4] : '';
                break;
            case 'facebook':
                if (!empty($input['v'])) {
                    $id = $input['v'];
                } elseif (!empty($path[3]) && substr($path[3], 0, 3) === 'vb.' && !empty($path[4])) {
                    $id = $path[4];
                } elseif (!empty($path[3])) {
                    $id = $path[3];
                }
                break;
            case 'mixer':
                $id = !empty($path[1]) ? $path[1] : '';
                break;
            case 'liveleak':
                $id = !empty($input['i']) ? $input['i'] : '';
                break;
            case 'yahoo':
                if (!empty($path[2])) {
                    $id = $path[2];
                } elseif (!empty($path[1])) {
                    $id = $path[1];
                }
                break;
            case 'vimeo':
                if (!empty($path[3])) {
                    $id = $path[3];
                } elseif (!empty($path[1])) {
                    $id = $path[1];
                }
                break;
            case 'youtube':
                if (!empty($fragments[0])) {
                    $id = str_replace('!v=', '', $fragments[0]);
                } elseif (!empty($input['v'])) {
                    $id = $input['v'];
                } elseif (!empty($path[1])) {
                    $id = $path[1];
                }
                break;
            case 'twitch':
                if (count($path) >= 3 && $path[1] === 'videos') {
                    $id = 'video=v' . $path[2];
                } elseif (count($path) >= 4 && $path[2] === 'v') {
                    $id = 'video=v' . $path[3];
                } elseif (count($path) >= 2) {
                    $id = 'channel=' . $path[1];
                }
                break;
            default:
                return false;
        }
        return !empty($id);
    }

    /**
     * `[quote]` blocks, innermost first: the author's line, then the quoted text.
     */
    private static function quotes(string $text): string
    {
        $pattern = '#\[quote(=[^\]\n]*)?\]((?:(?!\[quote(?:=[^\]\n]*)?\]).)*?)\[/quote\]#is';
        for ($pass = 0; $pass < 20; $pass++) {
            $before = $text;
            $text = self::replaceCallback($pattern, static function (array $m): string {
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
        return trim(self::replace('#\s+(pid|dateline)=.*\z#is', '', $argument));
    }

    /**
     * HTML as its reader sees it: tags gone, `alt`/`title` text kept (not with
     * `$textOnly`: {@see shows}), entities decoded.
     *
     * MyBB 1.8.41 escapes `<script>`, `<style>`, `<base>` and `<meta>` in a forum with HTML
     * on (`Postparser::parse_message`, the same pattern): the reader sees them as text, with
     * what is between them. A comment is hidden: `<!-->` and `<!--->` end at once (HTML's
     * own parsing), any other ends at `-->` (or `--!>`), and one never closed hides the rest.
     * Only where HTML opens a comment, though: one pass from left to right takes each tag
     * whole (its quoted values may hold `<!--`, `-->`, `<` or `>`), and the content of an
     * element HTML reads as text (`<textarea>`, `<title>`, `<xmp>`, `<plaintext>`,
     * `<iframe>`, `<noembed>`, `<noframes>`, `<noscript>`) is kept as it is, as is what
     * follows the open quote of a tag never closed. In doubt, the text stays.
     */
    private static function html(string $text, bool $textOnly = false): string
    {
        $text = self::replace('#<(/?)(base|meta|script|style)([^>]*)>#i', '&lt;$1$2$3&gt;', $text);
        $text = self::replaceCallback('#<(textarea|title|xmp|iframe|noembed|noframes|noscript)\b' . self::ATTRIBUTES
            . '>.*?(?:</\1\s*>|\z)|<plaintext\b.*\z|<!--(?:-?>|.*?(?:--!?>|\z))|</?[a-z][a-z0-9]*\b' . self::ATTRIBUTES . '>'
            // A tag never closed, its last quote open to the end: what follows stays text.
            . '|<[a-z][a-z0-9]*\b(?:[^<>"\']++|"[^"]*+"?|\'[^\']*+\'?)*+#is',
            static function (array $m): string {
                return strncmp($m[0], '<!--', 4) === 0 ? ' ' : $m[0];
            }, $text);
        $text = self::replaceCallback('#<([a-z][a-z0-9]*)\b(' . self::ATTRIBUTES . ')>#i', static function (array $m) use ($textOnly): string {
            $shown = [];
            // A hint goes with the words beside it; alone it is not a text (F7).
            if (!$textOnly && self::matchAll('#(?:^|\s)(?:alt|title)\s*=\s*(?:"([^"]*)"|\'([^\']*)\'|([^\s"\'>]+))#i', $m[2], $attributes, PREG_SET_ORDER) > 0) {
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
        $text = self::replaceCallback('#</([a-z][a-z0-9]*)\s*>#i', static function (array $m): string {
            return in_array(strtolower($m[1]), self::HTML_BLOCKS, true) ? "\n" : '';
        }, $text);
        return html_entity_decode($text, ENT_QUOTES | ENT_HTML5, 'UTF-8');
    }

    /**
     * Decimal numeric entities, the only ones MyBB shows as characters with HTML off.
     */
    private static function numericEntities(string $text): string
    {
        return self::replaceCallback('/&#([0-9]{1,7});/', static function (array $m): string {
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
        if (self::matchAll(self::LINK, self::valid($message), $m) === 0) {
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
        if (self::isUtf8($text)) {
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
        $text = self::replace('/[ \t\x{00A0}]+/u', ' ', $text);
        $text = self::replace('/ *\n */', "\n", $text);
        $text = self::replace('/\n{3,}/', "\n\n", $text);
        return trim($text);
    }

    // ------------------------------------------------------------- checked PCRE calls

    /**
     * `preg_replace`, checked.
     *
     * @param string|array<int,string> $pattern
     * @param string|array<int,string> $replacement
     * @throws TextError When PCRE fails.
     */
    private static function replace($pattern, $replacement, string $subject): string
    {
        $result = preg_replace($pattern, $replacement, $subject);
        if (!is_string($result) && self::jitGaveUp()) {
            $result = self::withoutJit(static function () use ($pattern, $replacement, $subject) {
                return preg_replace(self::interpreted($pattern), $replacement, $subject);
            });
        }
        if (!is_string($result)) {
            throw self::failure();
        }
        return $result;
    }

    /**
     * `preg_replace_callback`, checked.
     *
     * @throws TextError When PCRE fails.
     */
    private static function replaceCallback(string $pattern, callable $callback, string $subject): string
    {
        $result = preg_replace_callback($pattern, $callback, $subject);
        if (!is_string($result) && self::jitGaveUp()) {
            $result = self::withoutJit(static function () use ($pattern, $callback, $subject) {
                return preg_replace_callback(self::interpreted($pattern), $callback, $subject);
            });
        }
        if (!is_string($result)) {
            throw self::failure();
        }
        return $result;
    }

    /**
     * `preg_match_all`, checked.
     *
     * @param array<int|string,mixed>|null $matches
     * @return int How many matched.
     * @throws TextError When PCRE fails.
     */
    private static function matchAll(string $pattern, string $subject, &$matches = null, int $flags = PREG_PATTERN_ORDER): int
    {
        $result = preg_match_all($pattern, $subject, $matches, $flags);
        if (!is_int($result) && self::jitGaveUp()) {
            $result = self::withoutJit(static function () use ($pattern, $subject, &$matches, $flags) {
                return preg_match_all(self::interpreted($pattern), $subject, $matches, $flags);
            });
        }
        if (!is_int($result)) {
            throw self::failure();
        }
        return $result;
    }

    /**
     * Whether the last PCRE call failed for its JIT stack only — a limit of the compiled
     * code, not of the match: the same pattern then runs again without JIT
     * ({@see withoutJit}), so a text MyBB renders never becomes `text_error` for it.
     */
    private static function jitGaveUp(): bool
    {
        return preg_last_error() === PREG_JIT_STACKLIMIT_ERROR;
    }

    /**
     * Runs a PCRE call with `pcre.jit` off, then puts the setting back. Where the host does
     * not let it change, the call is not repeated (null: the failure stands).
     *
     * @return mixed The call's result, or null.
     */
    private static function withoutJit(callable $call)
    {
        if (!function_exists('ini_set') || !function_exists('ini_get')) {
            return null;
        }
        $jit = ini_get('pcre.jit');
        if (!is_string($jit) || @ini_set('pcre.jit', '0') === false) {
            return null;
        }
        try {
            return $call();
        } finally {
            ini_set('pcre.jit', $jit);
        }
    }

    /**
     * The same pattern under another key of PHP's pattern cache, so that it is compiled
     * anew while `pcre.jit` is off (a cached pattern keeps its JIT code). The `S` modifier
     * it adds changes nothing since PHP 7.3.
     *
     * @param string|array<int,string> $pattern
     * @return string|array<int,string>
     */
    private static function interpreted($pattern)
    {
        return is_array($pattern) ? array_map(static function (string $one): string {
            return $one . 'S';
        }, $pattern) : $pattern . 'S';
    }

    /**
     * The characters of a UTF-8 string, checked.
     *
     * @return array<int,string>
     * @throws TextError When PCRE fails (invalid UTF-8 included).
     */
    private static function chars(string $text): array
    {
        $chars = preg_split('//u', $text, -1, PREG_SPLIT_NO_EMPTY);
        if (!is_array($chars)) {
            throw self::failure();
        }
        return $chars;
    }

    /**
     * Whether a string is valid UTF-8: PCRE's "bad UTF-8" error IS the answer here; any
     * other error is a failure.
     *
     * @throws TextError When PCRE fails otherwise.
     */
    private static function isUtf8(string $text): bool
    {
        $result = preg_match('//u', $text);
        if ($result === false && preg_last_error() !== PREG_BAD_UTF8_ERROR) {
            throw self::failure();
        }
        return $result === 1;
    }

    private static function failure(): TextError
    {
        return new TextError('PCRE error ' . preg_last_error());
    }
}
