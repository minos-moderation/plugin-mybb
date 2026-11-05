<?php

declare(strict_types=1);

namespace Minos\MyBB\Tests\Plugin;

use Minos\MyBB\Tests\Support\Forum;
use Minos\MyBB\Tests\Support\PluginTestCase;

/**
 * Shared rules S1, S2 and F7 for links and images, through the hook functions and on the
 * request the gateway gets: `tekst` is what the reader sees — a labelled link is its label
 * alone, never "label (address)", and an image MyBB shows as an image is not in it with its
 * address; `links` and `link_domains` count the links, never an image's address. A bare
 * address the reader sees is both text and a link. Test data only.
 */
final class LinksAndImagesTest extends PluginTestCase
{
    /**
     * @dataProvider posts
     * @param array<string,int>  $forum   The forum's parsing options.
     * @param array<int,string>  $domains The expected `link_domains` ([] for none sent).
     */
    public function testTheTextIsWhatTheReaderSeesAndOnlyLinksAreCounted(string $message, array $forum, string $tekst,
        int $links, array $domains): void
    {
        $GLOBALS['minos_test']['forums'][Forum::FID] = $forum;
        $this->heldReply($message);

        self::assertCount(1, $this->forum->transport->requests, 'the guard: it was sent');
        $item = $this->forum->transport->lastBody()['elementy'][0];
        self::assertSame($tekst, $item['tekst']);
        self::assertSame([$links, $domains], [$item['meta']['links'], $item['meta']['link_domains'] ?? []]);
    }

    /** @return array<string,array{0:string,1:array<string,int>,2:string,3:int,4:array<int,string>}> */
    public function posts(): array
    {
        return [
            'a labelled link: its label, its address a link' => [
                'Zobacz [url=https://strona.przyklad.example.com/a]ten opis[/url].', [],
                'Zobacz ten opis.', 1, ['example.com']],
            'a labelled link without a scheme' => ['Zobacz [url=www.przyklad.example.net]opis[/url] tu.', [],
                'Zobacz opis tu.', 1, ['example.net']],
            'a data: address MyBB shows instead of a link' => ['Zobacz [url=data:text/plain,abc]opis[/url]', [],
                'Zobacz data:text/plain,abc', 0, []],
            'an image beside text: its alt, without its address, no link' => [
                'Zdjęcie: [img]https://obrazki.example.org/a.png[/img] koniec', [], 'Zdjęcie: a.png koniec', 0, []],
            'an image whose address ends in a word the author chose' => [
                'Tekst obok [img]https://nieistnieje.invalid/katalog/słowo[/img]', [], 'Tekst obok słowo', 0, []],
            'a long alt, cut as MyBB cuts it, its spaces encoded' => [
                'Opis [img]https://obrazki.example.org/x/' . str_repeat('ą', 30) . ' środek ' . str_repeat('b', 30) . '.png[/img]', [],
                'Opis ' . str_repeat('ą', 30) . '%20środek%20bb...bbbbbb.png', 0, []],
            'an image with its size and alignment' => [
                "Opis [img=100x50 align=right]\nhttps://obrazki.example.org/a.png[/img] dalej", [], 'Opis a.png dalej', 0, []],
            'an image inside a link: the link counts, the image does not' => [
                '[url=https://example.com/][img]https://cdn.example.org/a.png[/img][/url] i tekst', [], 'a.png i tekst', 1, ['example.com']],
            'a bare address beside an image: text and a link' => [
                'Patrz https://example.net/strona [img]https://obrazki.example.org/a.png[/img]', [],
                'Patrz https://example.net/strona a.png', 1, ['example.net']],
            'an image MyBB shows as a link (image MyCode off)' => [
                '[img]https://obrazki.example.org/a.png[/img] opis', ['allowimgcode' => 0],
                'https://obrazki.example.org/a.png opis', 1, ['example.org']],
            'an image tag MyBB leaves as typed (two line breaks)' => [
                "Opis [img]\n\nhttps://obrazki.example.org/a.png[/img]", [],
                'Opis https://obrazki.example.org/a.png', 1, ['example.org']],
            'an image in a code block, shown as written' => [
                '[code][img]https://obrazki.example.org/a.png[/img][/code] tekst', [],
                "[img]https://obrazki.example.org/a.png[/img]\ntekst", 1, ['example.org']],
            'a quoted image and a link in the quote' => [
                '[quote=Ktoś][img]https://obrazki.example.org/a.png[/img] [url=https://example.com/]tu[/url][/quote] Zgoda.', [],
                "Ktoś napisał(a):\na.png tu\nZgoda.", 1, ['example.com']],
            'HTML: an image\'s hint is text, its address is no link' => [
                'Tekst <img src="https://obrazki.example.org/a.png" alt="podpis"> <a href="https://example.com/">link</a>',
                ['allowhtml' => 1], 'Tekst podpis link', 1, ['example.com']],
            'HTML off: an <img> tag is text on the page' => [
                'Tekst <img src="https://obrazki.example.org/a.png">', [],
                'Tekst <img src="https://obrazki.example.org/a.png">', 1, ['example.org']],
        ];
    }

    /**
     * A thread's poll options are parsed like the post: an image in an option is no link,
     * a link in another is.
     */
    public function testAPollOptionsImageIsNoLink(): void
    {
        $handler = $this->forum->write(true, 'Pierwszy post z ankietą.', ['subject' => 'Ankieta']);
        $this->forum->addPoll((int)$handler->return_values['tid'], 'Które?',
            ['[img]https://obrazki.example.org/a.png[/img] to', 'https://example.com/b']);
        task_minos(['tid' => 1]); // the author's poll: the thread sent again, with it
        self::assertStringContainsString('https://example.com/b', $this->forum->transport->lastBody()['elementy'][0]['tekst'],
            'the guard: the revision with the poll was sent');

        $meta = $this->forum->transport->lastBody()['elementy'][0]['meta'];
        self::assertSame([1, ['example.com']], [$meta['links'], $meta['link_domains']]);
    }
}
