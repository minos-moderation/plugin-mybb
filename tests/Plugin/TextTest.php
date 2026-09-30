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
            . '<a href="https://example.com" title=\'OBELGA_TITLE\'>link</a></p><script>ukryte()</script>', self::HTML_ON);
        self::assertSame("Obraz: OBELGA_ALT i OBELGA_TITLE link", $visible);
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

    public function testAPostReadersSeeNoTextInSendsNothing(): void
    {
        self::assertSame('', Text::forPost('[attachment=7]')['tekst']);
        self::assertSame('Tytuł', Text::forPost('[attachment=7]', 'Tytuł')['tekst']);
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
}
