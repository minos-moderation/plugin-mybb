<?php

declare(strict_types=1);

namespace Minos\MyBB\Tests\Plugin;

use Minos\MyBB\Text;
use PHPUnit\Framework\TestCase;

/**
 * What the plugin sends as `tekst`: plain text, the first 3000 characters.
 */
final class TextTest extends TestCase
{
    public function testMyCodeAndHtmlTagsGoAndTheirTextStays(): void
    {
        $plain = Text::plain("[b]Pogrubione[/b] i [url=https://example.com/a]opis linku[/url]\r\n"
            . '[color=red]kolor[/color] <em>html</em> [size=large]duże[/size]');
        self::assertSame("Pogrubione i opis linku\nkolor html duże", $plain);
        self::assertDoesNotMatchRegularExpression('~\[/?(b|url|color|size)\b|<em>~', $plain);
    }

    public function testAQuoteIsKeptBecauseItIsShownOnThePage(): void
    {
        $plain = Text::plain("[quote=\"Ktoś\" pid='3' dateline='1700000000']cytowana treść[/quote]\nMoja odpowiedź");
        self::assertStringContainsString('cytowana treść', $plain);
        self::assertStringContainsString('Moja odpowiedź', $plain);
        self::assertStringNotContainsString('[quote', $plain);
    }

    public function testMediaAddressesAreNotText(): void
    {
        $plain = Text::plain('Zdjęcie: [img]https://example.com/a.png[/img] [video=youtube]https://youtu.be/x[/video] [attachment=12] koniec');
        self::assertSame('Zdjęcie: koniec', $plain);
    }

    public function testWhatIsNotATagStays(): void
    {
        self::assertSame('a < b i <3 oraz [minos:blokuj] [[fragment]]', Text::plain('a < b i <3 oraz [minos:blokuj] [[fragment]]'));
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
        self::assertSame(Text::MAX_CHARS, $short['text_len']);
    }

    public function testANewThreadsSubjectIsAssessedInFrontOfItsPost(): void
    {
        $sent = Text::forPost('Treść postu.', 'Tytuł wątku');
        self::assertSame("Tytuł wątku\n\nTreść postu.", $sent['tekst']);
        self::assertSame(mb_strlen("Tytuł wątku\n\n", 'UTF-8'), $sent['prefix_len']);
        self::assertSame('Treść postu.', Text::tail($sent['tekst'], $sent['prefix_len']));

        $replyOnly = Text::forPost('Treść postu.');
        self::assertSame(0, $replyOnly['prefix_len']);
    }

    public function testAPostWithNoTextSendsNothing(): void
    {
        self::assertSame('', Text::forPost('[img]https://example.com/a.png[/img]')['tekst']);
        self::assertSame('Tytuł', Text::forPost('[img]https://example.com/a.png[/img]', 'Tytuł')['tekst']);
    }

    public function testBrokenUtf8BecomesValidText(): void
    {
        $sent = Text::forPost("zła sekwencja \xC3\x28 tutaj");
        self::assertSame(1, preg_match('//u', $sent['tekst']));
        self::assertNotFalse(json_encode($sent['tekst']));
    }

    public function testLinksAreCountedOnce(): void
    {
        self::assertSame(2, Text::links('https://a.example/x [url=https://a.example/x]x[/url] www.b.example i tyle'));
        self::assertSame(0, Text::links('bez linków'));
    }
}
