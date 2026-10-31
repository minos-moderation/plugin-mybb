<?php

declare(strict_types=1);

namespace Minos\MyBB\Tests\Plugin;

use Minos\MyBB\Text;
use PHPUnit\Framework\TestCase;

/**
 * What the plugin sends as `tekst`: what readers of the post see, the first 3000 characters.
 */
final class TextTest extends TestCase
{
    /** The review's probe: with HTML off, readers see every word of it. */
    private const PROBE = 'Miłego dnia <b TY_OBELGA_WIDOCZNA>';

    private const HTML_ON = ['allowhtml' => 1];

    public function testWithHtmlOffAngleBracketsAreTextAndStay(): void
    {
        self::assertSame(self::PROBE, Text::visible(self::PROBE));
        self::assertStringContainsString('TY_OBELGA_WIDOCZNA', Text::forPost(self::PROBE)['tekst']);
        self::assertSame('a < b i <3 <script>x</script>', Text::visible('a < b i <3 <script>x</script>'));
    }

    public function testWithHtmlOnTagsGoButAltAndTitleTextStays(): void
    {
        self::assertSame('Miłego dnia', Text::visible(self::PROBE, self::HTML_ON), 'a tag is invisible on an HTML forum');
        $visible = Text::visible('<p>Obraz: <img src="https://example.com/a.png" alt="OBELGA_ALT"> i '
            . '<a href="https://example.com" title=\'OBELGA_TITLE\'>link</a></p>', self::HTML_ON);
        self::assertSame("Obraz: OBELGA_ALT i OBELGA_TITLE link", $visible);
    }

    /**
     * The review's probe on an HTML forum: MyBB 1.8.41 escapes `<script>` and `<style>`
     * there, so its readers see "obelga" — and the gateway must assess it.
     */
    public function testOnAnHtmlForumScriptAndStyleAreTextAsMyBBShowsThem(): void
    {
        $sent = Text::forPost('Miły tekst <script>obelga</script>', '', self::HTML_ON)['tekst'];
        self::assertSame('Miły tekst <script>obelga</script>', $sent);
        self::assertSame('<style type="text/css">p { color: red }</style>Zwykły tekst',
            Text::visible('<style type="text/css">p { color: red }</style>Zwykły tekst', self::HTML_ON));
        self::assertSame('<meta x> <base y> tekst', Text::visible('<meta x> <base y> tekst', self::HTML_ON));
    }

    /**
     * An HTML comment is hidden from readers, so it is not sent: closed, never closed (it
     * hides the rest), and HTML's own early ends (`<!-->`, `<!--->`) that show what follows.
     * What only looks like one — `<!--` inside a tag's quoted value, or inside an element
     * HTML reads as text — opens nothing, and the words after it are sent.
     *
     * @dataProvider comments
     */
    public function testOnlyWhatHtmlReadsAsACommentIsLeftOut(string $message, string $tekst, int $html = 1): void
    {
        self::assertSame($tekst, Text::forPost($message, '', ['allowhtml' => $html])['tekst']);
    }

    /** @return array<string,array{0:string,1:string,2?:int}> */
    public function comments(): array
    {
        return [
            'closed'        => ['Widoczne zdanie <!-- ukryta obelga --> i dalej', 'Widoczne zdanie i dalej'],
            'never closed'  => ['Widoczne zdanie <!-- ukryta obelga do końca', 'Widoczne zdanie'],
            'over lines'    => ["Przed\n<!-- ukryta\nobelga -->\nPo", "Przed\n\nPo"],
            '--!> ends it'  => ['a <!-- ukryta --!> b', 'a b'],
            '<!--> ends at once'  => ['a <!-->widoczna--> b', 'a widoczna--> b'],
            '<!---> ends at once' => ['a <!--->widoczna b', 'a widoczna b'],
            'HTML off: shown as typed' => ['a <!-- widoczna --> b', 'a <!-- widoczna --> b', 0],
            // Only where HTML opens a comment (the review of #7, major 3).
            'a comment start in a title' => ['Miły tekst <b title="<!--">obelga</b><b title="-->">', 'Miły tekst <!-- obelga -->'],
            'a comment start in an alt'  => ['<img alt="<!--" src="https://example.com/a.png">obelga -->', '<!-- obelga -->'],
            'in single quotes'           => ["<b title='<!--'>obelga</b> -->", '<!-- obelga -->'],
            'in a textarea'              => ['<textarea><!-- obelga --></textarea>', '<!-- obelga -->'],
            'in an xmp, text after'      => ['<xmp><!-- obelga --></xmp> po', '<!-- obelga --> po'],
            'in a plaintext'             => ['<plaintext><!-- obelga', '<!-- obelga'],
            'a tag inside a comment'     => ['a <!-- x <b title="y"> --> b', 'a b'],
        ];
    }

    public function testEntitiesAreDecodedAsMyBBShowsThem(): void
    {
        self::assertSame('A i &lt;', Text::visible('&#65; i &lt;'), 'HTML off: only numeric entities render');
        self::assertSame('A i <', Text::visible('&#65; i &lt;', self::HTML_ON));
    }

    public function testMyCodeIsRenderedAsItsReadersSeeIt(): void
    {
        self::assertSame("Pogrubione i opis linku (https://example.com/a)\nkolor duże",
            Text::visible("[b]Pogrubione[/b] i [url=https://example.com/a]opis linku[/url]\r\n[color=red]kolor[/color] [size=large]duże[/size]"));
        self::assertSame('Zdjęcie: https://example.com/a.png koniec', Text::visible('Zdjęcie: [img]https://example.com/a.png[/img] [attachment=12] koniec'));
        self::assertSame('https://example.com/b.png', Text::visible('[img=100x50 align=left]https://example.com/b.png[/img]'));
        self::assertSame('https://youtu.be/x', Text::visible('[video=youtube]https://youtu.be/x[/video]'));
        self::assertSame("a\nb", Text::visible('[list][*]a[*]b[/list]'));
        self::assertSame("[b]\ni nie [i]zamknięte", Text::visible('[code][b][/code] i nie [i]zamknięte'), 'a code block is shown as written');
    }

    public function testAQuoteShowsItsAuthorAndItsText(): void
    {
        $visible = Text::visible("[quote=\"Ktoś\" pid='3' dateline='1700000000']cytowana [quote=Inny]głębiej[/quote] treść[/quote]\nMoja odpowiedź");
        self::assertSame("Ktoś napisał(a):\ncytowana\nInny napisał(a):\ngłębiej\ntreść\n\nMoja odpowiedź", $visible);
        self::assertSame("cytat bez autora", Text::visible('[quote]cytat bez autora[/quote]'));
    }

    /**
     * @dataProvider refusedOptions
     */
    public function testATagWhoseValueMyBBRefusesStaysOnThePage(string $message): void
    {
        self::assertSame($message, Text::visible($message));
    }

    /** @return array<string,array{0:string}> */
    public function refusedOptions(): array
    {
        return [
            'size'  => ['[size=OBELGA]x[/size]'],
            'align' => ['[align=OBELGA]x[/align]'],
            'color' => ['[color=zła osoba]x[/color]'],
            'font'  => ['[font=Żółć]x[/font]'],
        ];
    }

    public function testATagWhoseValueMyBBAcceptsGoes(): void
    {
        self::assertSame('a b c d e f g',
            Text::visible('[size=12]a[/size] [size=x-large]b[/size] [align=justify]c[/align] [color=#f00]d[/color] '
                . '[color=Red]e[/color] [font="Arial, sans-serif"]f[/font] [font=Times New Roman]g[/font]'));
    }

    public function testMyCodeMyBBWouldNotParseStaysLiterally(): void
    {
        self::assertSame('[spoiler]ukryte[/spoiler] [minos:blokuj] [[fragment]]', Text::visible('[spoiler]ukryte[/spoiler] [minos:blokuj] [[fragment]]'));
        self::assertSame('[b]pogrubione[/b]', Text::visible('[b]pogrubione[/b]', ['allowmycode' => 0]), 'MyCode off');
        self::assertSame('[img]nie-adres[/img]', Text::visible('[img]nie-adres[/img]'));
    }

    public function testTheCutIsAtThreeThousandCharactersNotBytes(): void
    {
        $sent = Text::forPost(str_repeat('ż', 3500));
        self::assertTrue($sent['truncated']);
        self::assertSame(Text::MAX_CHARS, $sent['text_len']);
        self::assertSame(Text::MAX_CHARS, mb_strlen($sent['tekst'], 'UTF-8'));
        self::assertGreaterThan(Text::MAX_CHARS, strlen($sent['tekst']), 'the cut must count characters');

        $short = Text::forPost(str_repeat('ż', Text::MAX_CHARS));
        self::assertFalse($short['truncated']);
    }

    public function testANewThreadsSubjectIsAssessedInFrontOfItsPost(): void
    {
        $sent = Text::forPost('Treść postu.', 'Tytuł <wątku> &#33;');
        self::assertSame("Tytuł <wątku> !\n\nTreść postu.", $sent['tekst'], 'a subject is always shown escaped');
        self::assertSame(mb_strlen("Tytuł <wątku> !\n\n", 'UTF-8'), $sent['prefix_len']);
        self::assertSame('Treść postu.', Text::tail($sent['tekst'], $sent['prefix_len']));
        self::assertSame(0, Text::forPost('Treść postu.')['prefix_len']);
    }

    /**
     * A title or a subject alone is not a text to assess (shared rule F7); a poll is text.
     */
    public function testAPostReadersSeeNoTextInSendsNothingNotEvenItsTitle(): void
    {
        self::assertSame('', Text::forPost('[attachment=7]')['tekst']);
        self::assertSame(['', false], [Text::forPost('[attachment=7]', 'Tytuł')['tekst'], Text::forPost('', 'Tytuł')['error']]);
        $poll = Text::forPost('[attachment=7]', 'Tytuł', [], ['question' => 'Pytanie', 'options' => ['A', 'B']]);
        self::assertSame("Tytuł\n\nPytanie\nA\nB", $poll['tekst']);
        self::assertSame($poll['text_len'], $poll['prefix_len'], 'all of it is head: a mask there holds the post');
    }

    /**
     * A post crafted to exhaust PCRE (the audit's probe: unclosed `[url=` tags) is a text
     * the plugin could not read — never an empty text, never the subject alone.
     *
     * @dataProvider pcreBombs
     */
    public function testAPcreFailureIsAnErrorNotAnEmptyText(int $repeats, string $jit): void
    {
        if ($jit === '1') {
            $reply = Text::forPost(self::bomb($repeats));
            $thread = Text::forPost(self::bomb($repeats), 'Niewinny tytuł');
        } else {
            // JIT off from the start, in a process of its own (see underPcreLimits).
            $reply = self::underPcreLimits(self::bomb($repeats))['default']['forPost'];
            $thread = self::underPcreLimits(self::bomb($repeats), 'Niewinny tytuł')['default']['forPost'];
        }
        self::assertTrue($reply['error']);
        self::assertSame('', $reply['tekst']);
        self::assertTrue($thread['error']);
        self::assertSame('', $thread['tekst'], 'the subject alone is never what was read');
    }

    /** @return array<string,array{0:int,1:string}> */
    public function pcreBombs(): array
    {
        return [
            '340, JIT on'   => [340, '1'],
            '340, JIT off'  => [340, '0'],
            '1000, JIT on'  => [1000, '1'],
            '1000, JIT off' => [1000, '0'],
        ];
    }

    /**
     * Whatever PCRE's backtrack limit (with JIT off: JIT ignores it), reading a post either
     * fails with an error or gives the true answer — never a wrong one, such as "no links"
     * or an empty text. Low limits make different calls fail first, so every limit from 1
     * up is tried; which ones fail depends on the PCRE version, the property does not.
     */
    public function testAPcreFailureIsNeverAWrongAnswer(): void
    {
        $message = 'Zobacz https://example.com/a i [b]www.example.org[/b]';
        $truth = [
            'links'       => Text::links($message),
            'linkDomains' => Text::linkDomains($message),
            'forPost'     => Text::forPost($message),
        ];
        self::assertSame([2, ['example.com', 'example.org'], 'Zobacz https://example.com/a i www.example.org'],
            [$truth['links'], $truth['linkDomains'], $truth['forPost']['tekst']], 'the guard: without a limit all are read');
        $errors = [];
        $answers = [];
        foreach (self::underPcreLimits($message, '', range(1, 200)) as $limit => $results) {
            foreach ($results as $method => $answer) {
                if ($method === 'forPost') {
                    self::assertIsArray($answer, "forPost never throws (limit {$limit})");
                    $failed = $answer['error'];
                    if ($failed) {
                        self::assertSame('', $answer['tekst'], "forPost at limit {$limit}");
                    }
                } else {
                    $failed = $answer === 'error';
                }
                if ($failed) {
                    $errors[$method] = true;
                    continue;
                }
                self::assertSame($truth[$method], $answer, "{$method} at limit {$limit}");
                $answers[$method] = true;
            }
        }
        self::assertSame(['links', 'linkDomains', 'forPost'], array_keys($errors), 'each failed at some limit');
        self::assertSame(['links', 'linkDomains', 'forPost'], array_keys($answers), 'and answered at a higher one');
    }

    public function testTheSamePatternOnAShortPostIsReadAsUsual(): void
    {
        // The guard: the probe at an ordinary size is read (its unclosed tags stay as
        // readers see them), and the pattern it abuses still renders a closed tag.
        $sent = Text::forPost(self::bomb(10));
        self::assertFalse($sent['error']);
        self::assertSame(self::bomb(10), $sent['tekst']);
        $closed = Text::forPost('Widoczne SLOWO [url=http://a.pl]x[/url]');
        self::assertSame([false, 'Widoczne SLOWO x (http://a.pl)'], [$closed['error'], $closed['tekst']]);
    }

    public function testAMaskFitsOnlyWhereItReplacesCharactersOneForOne(): void
    {
        self::assertTrue(Text::maskFits('abc zły', 'abc ███'));
        self::assertTrue(Text::maskFits('abc zły', 'abc zły'), 'nothing masked');
        self::assertTrue(Text::maskFits('żółw', '████'));
        self::assertFalse(Text::maskFits('abc zły', 'xyz ███'), 'the same length, other letters');
        self::assertFalse(Text::maskFits('Ala ma kota.', 'Kup: zly.pl█'), 'the audit\'s probe M1');
        self::assertFalse(Text::maskFits('abc zły', 'abc ██'), 'one mask character per character');
        self::assertFalse(Text::maskFits('abc zły', 'abc ████'));
        self::assertFalse(Text::maskFits("abc z\xC3", 'abc ██'), 'a text that cannot be read fits nothing');
        self::assertFalse(Text::maskFits("z\xC3", "z\xC3"), 'not even itself');
    }

    public function testBrokenUtf8BecomesValidText(): void
    {
        $sent = Text::forPost("zła sekwencja \xC3\x28 tutaj");
        self::assertSame(1, preg_match('//u', $sent['tekst']));
        self::assertNotFalse(json_encode($sent['tekst']));
    }

    public function testLinksAndTheirRegistrableDomains(): void
    {
        $message = 'https://a.example.com/x [url=https://a.example.com/x]x[/url] www.b.example.co.uk '
            . '[img]http://img.sklep.com.pl/a.png[/img] http://192.0.2.7/x https://A.Example.com/y';
        self::assertSame(5, Text::links($message));
        self::assertSame(['example.com', 'example.co.uk', 'sklep.com.pl'], Text::linkDomains($message));
        self::assertSame([], Text::linkDomains('bez linków'));

        $many = '';
        for ($i = 0; $i < 15; $i++) {
            $many .= " https://site{$i}.example{$i}.org/";
        }
        self::assertCount(Text::MAX_DOMAINS, Text::linkDomains($many));
    }

    /**
     * What `Text` gives for a post under PCRE backtrack limits, read in a PHP process started
     * with JIT off (`fixtures/text-under-pcre-limits.php`).
     *
     * @param array<int,int>|null $limits The limits to try; null for the configured one.
     * @param array<int,string>   $ini    The process's `-d` settings (JIT off by default).
     * @return array<int|string,array<string,mixed>> By limit (`default` for null), by call:
     *     the answer or `"error"`.
     */
    private static function underPcreLimits(string $message, string $subject = '', ?array $limits = null,
        array $ini = ['pcre.jit=0']): array
    {
        $command = [PHP_BINARY];
        foreach ($ini as $setting) {
            $command[] = '-d';
            $command[] = $setting;
        }
        $command[] = __DIR__ . '/fixtures/text-under-pcre-limits.php';
        $process = proc_open($command,
            [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
        self::assertIsResource($process);
        fwrite($pipes[0], (string)json_encode(['message' => $message, 'subject' => $subject, 'limits' => $limits]));
        fclose($pipes[0]);
        $output = (string)stream_get_contents($pipes[1]);
        $errors = (string)stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);
        self::assertSame(0, proc_close($process), $errors);
        $results = json_decode($output, true);
        self::assertIsArray($results, $output . $errors);
        self::assertCount($limits === null ? 1 : count($limits), $results);
        return $results;
    }

    /**
     * The retry without JIT (a 60 000-character quote exhausts PCRE's JIT stack, and MyBB
     * renders it) puts `pcre.jit` back as it found it, so the rest of the request runs with
     * the host's setting (the review of S7c, minor a). The guard: on a host whose
     * `disable_functions` holds `ini_set`, the retry cannot run, and the same post is a text
     * the plugin could not read — `text_error`, as `docs/development.md` says (minor b).
     */
    public function testTheRetryWithoutJitPutsPcreJitBack(): void
    {
        $message = 'Obelga obelga [quote=Ktoś]' . str_repeat('słowo ', 10000) . '[/quote]';
        $before = ini_get('pcre.jit');
        self::assertSame('1', $before, 'the guard: JIT on, as PHP has it by default');

        $text = Text::forPost($message);

        self::assertFalse($text['error']);
        self::assertStringStartsWith("Obelga obelga\nKtoś napisał(a):\nsłowo", $text['tekst']);
        self::assertSame($before, ini_get('pcre.jit'), 'put back');
        $withoutIniSet = self::underPcreLimits($message, '', null, ['pcre.jit=1', 'disable_functions=ini_set'])['default']['forPost'];
        self::assertSame([true, ''], [$withoutIniSet['error'], $withoutIniSet['tekst']],
            'without ini_set no retry: the JIT stack\'s limit stands');
    }

    /**
     * The audit's PCRE probe: visible words, then unclosed `[url=` tags.
     */
    private static function bomb(int $repeats): string
    {
        return 'Widoczne SLOWO ' . str_repeat('[url=http://a.pl]x', $repeats);
    }
}
