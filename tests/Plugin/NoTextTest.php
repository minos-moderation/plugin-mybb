<?php

declare(strict_types=1);

namespace Minos\MyBB\Tests\Plugin;

use Minos\MyBB\Status;
use Minos\MyBB\Text;
use Minos\MyBB\Tests\Support\Forum;
use Minos\MyBB\Tests\Support\PluginTestCase;

/**
 * Shared rule F7 for images, videos and links, through the hook functions: a post whose body
 * shows a reader nothing but an image, a video MyBB embeds or a link without a label gets
 * the failure mode at once as `no_text`, and nothing is sent — the gateway does not assess
 * images, and an address the reader does not see is not what a verdict is about. An image's
 * `alt` or `title` does not change that. A bare address the reader sees IS text: it is sent,
 * and counted in the link signals.
 */
final class NoTextTest extends PluginTestCase
{
    /**
     * @dataProvider nothingToRead
     * @param array<string,int> $forum The forum's parsing options.
     */
    public function testABodyThatShowsNoTextIsNotSent(string $message, array $forum): void
    {
        $GLOBALS['minos_test']['forums'][Forum::FID] = $forum;
        $pid = $this->heldReply($message);

        self::assertSame([], $this->forum->transport->requests, 'nothing is sent');
        self::assertSame([0, Status::HELD, Status::REASON_NO_TEXT],
            [(int)$this->forum->post($pid)['visible'], $this->forum->row($pid)['status'], $this->forum->row($pid)['verdict']]);
    }

    /** @return array<string,array{0:string,1:array<string,int>}> */
    public function nothingToRead(): array
    {
        return [
            'an image'                    => ['[img]https://example.com/obrazek.png[/img]', []],
            'an image with its size'      => ['[img=100x50]https://example.com/obrazek.png[/img]', []],
            'an image aligned'            => ['[img=100x50 align=right]https://example.com/obrazek.png[/img]', []],
            'two images and blank lines'  => ["[img]https://example.com/a.png[/img]\n\n[img]https://example.com/b.png[/img]", []],
            'a video MyBB embeds'         => ['[video=youtube]https://www.youtube.com/watch?v=dQw4w9WgXcQ[/video]', []],
            'a link with a blank label'   => ['[url=https://example.com/] [/url]', []],
            'an image link'               => ['[url=https://example.com/][img]https://example.com/obrazek.png[/img][/url]', []],
            'a link with a bold blank'    => ['[url=https://example.com/][b] [/b][/url]', []],
            'formatting around an image'  => ['[b][img]https://example.com/obrazek.png[/img][/b]', []],
            'an attachment'               => ['[attachment=4]', []],
            'HTML: an image with alt and title' => ['<img src="https://example.com/a.png" alt="podpis" title="tytuł">', ['allowhtml' => 1]],
            'HTML: a link without text'   => ['<a href="https://example.com/" title="tytuł"> </a>', ['allowhtml' => 1]],
            // Characters that take no visible place (the review of #7, major 1).
            'an image and U+200B'         => ["[img]https://example.com/a.png[/img]\u{200B}", []],
            'an image and &#8203;'        => ['[img]https://example.com/a.png[/img]&#8203;', []],
            'an image and U+2060'         => ["[img]https://example.com/a.png[/img]\u{2060}", []],
            'an image and U+FEFF'         => ["[img]https://example.com/a.png[/img]\u{FEFF}", []],
            'an image and U+00AD'         => ["[img]https://example.com/a.png[/img]\u{00AD}", []],
            'an image and &#173;'         => ['[img]https://example.com/a.png[/img]&#173;', []],
            'an image and U+3000'         => ["[img]https://example.com/a.png[/img]\u{3000}", []],
            'an image and U+2028'         => ["[img]https://example.com/a.png[/img]\u{2028}", []],
            'a link labelled U+00AD'      => ["[url=https://example.com/]\u{00AD}[/url]", []],
            'a blank link and U+00AD'     => ["[url=https://example.com/] [/url]\u{00AD}", []],
            'only U+200B'                 => ["\u{200B}", []],
            'bold U+200B'                 => ["[b]\u{200B}[/b]", []],
            'HTML: an image with alt and &#8203;' => ['<img src="https://example.com/a.png" alt="podpis">&#8203;', ['allowhtml' => 1]],
            'HTML: a link with a title and &shy;' => ['<a href="https://example.com/" title="tytuł">&shy;</a>', ['allowhtml' => 1]],
            'HTML: an image and &ZeroWidthSpace;' => ['<img src="https://example.com/a.png">&ZeroWidthSpace;', ['allowhtml' => 1]],
        ];
    }

    /**
     * @dataProvider textToRead
     * @param array<string,int> $forum The forum's parsing options.
     */
    public function testABodyThatShowsTextIsSent(string $message, array $forum, string $tekst): void
    {
        $GLOBALS['minos_test']['forums'][Forum::FID] = $forum;
        $pid = $this->heldReply($message);

        self::assertCount(1, $this->forum->transport->requests);
        $item = $this->forum->transport->lastBody()['elementy'][0];
        self::assertSame($tekst, $item['tekst']);
        self::assertSame(Status::PENDING, $this->forum->row($pid)['status']);
    }

    /** @return array<string,array{0:string,1:array<string,int>,2:string}> */
    public function textToRead(): array
    {
        return [
            'a bare address'              => ['https://example.com/strona', [], 'https://example.com/strona'],
            'an address in [url]'         => ['[url]https://example.com/strona[/url]', [], 'https://example.com/strona'],
            'a link with a label'         => ['[url=https://example.com/]opis[/url]', [], 'opis (https://example.com/)'],
            'an image beside text'        => ['Patrz: [img]https://example.com/a.png[/img]', [], 'Patrz: https://example.com/a.png'],
            'an image MyBB shows as a link' => ['[img]https://example.com/a.png[/img]', ['allowimgcode' => 0], 'https://example.com/a.png'],
            'a video of an unknown site'  => ['[video=nieznany]https://example.com/film[/video]', [], 'https://example.com/film'],
            'a video tag MyBB leaves'     => ['[video=youtube]to nie adres[/video]', [], 'to nie adres'],
            'an empty link MyBB leaves'   => ['[url=https://example.com/][/url]', [], '[url=https://example.com/][/url]'],
            'a quote of an image'         => ['[quote=Ktoś][img]https://example.com/a.png[/img][/quote]', [],
                "Ktoś napisał(a):\nhttps://example.com/a.png"],
            'an image and &shy; MyBB shows as typed' => ['[img]https://example.com/a.png[/img]&shy;', [],
                'https://example.com/a.png&shy;'],
            'a word and U+200B'           => ["obelga\u{200B}", [], "obelga\u{200B}"],
            'HTML: an image beside text, its hints with it' => ['Tekst <img src="https://example.com/a.png" alt="podpis">',
                ['allowhtml' => 1], 'Tekst podpis'],
        ];
    }

    /**
     * Links nested hundreds deep, each closed: MyBB renders them, and so does the plugin —
     * the reading of what shows ({@see \Minos\MyBB\Text::shows}) neither gives up (a
     * failure there means "send") nor grows faster than the text: the post is sent at once,
     * under fail-open too, never published unassessed as `text_error`.
     *
     * @dataProvider depths
     */
    public function testDeeplyNestedLinksAreSentAtOnce(int $depth): void
    {
        $this->forum->set(['minos_failure_mode' => 'fail-open']);
        $message = 'Obelga obelga ' . str_repeat('[url=http://a.pl]', $depth) . 'x' . str_repeat('[/url]', $depth);
        $started = microtime(true);
        $pid = $this->heldReply($message);
        $seconds = microtime(true) - $started;

        self::assertCount(1, $this->forum->transport->requests);
        self::assertStringStartsWith('Obelga obelga', $this->forum->transport->lastBody()['elementy'][0]['tekst']);
        self::assertSame([0, Status::PENDING], [(int)$this->forum->post($pid)['visible'], $this->forum->row($pid)['status']]);
        self::assertLessThan(1.0, $seconds, "{$depth} levels in {$seconds} s");
    }

    /** @return array<string,array{0:int}> */
    public function depths(): array
    {
        return ['300 levels' => [300], '350 levels' => [350], '1000 levels' => [1000]];
    }

    /**
     * A reading of what shows that PCRE gives up on counts as text: in doubt, send. (A post
     * whose text cannot be read at all stays `text_error`, as MyBB shows it empty.)
     */
    public function testWhenTheReadingOfWhatShowsFailsItCountsAsText(): void
    {
        $bomb = 'Widoczne SLOWO ' . str_repeat('[url=http://a.pl]x', 1000);
        self::assertTrue(Text::shows($bomb));
        self::assertTrue(Text::forPost($bomb)['error']);
    }

    public function testTheLinkSignalsOfABareAddressAreSent(): void
    {
        $this->heldReply('Zobacz https://www.przyklad.com.pl/strona');
        $meta = $this->forum->transport->lastBody()['elementy'][0]['meta'];
        self::assertSame([1, ['przyklad.com.pl']], [$meta['links'], $meta['link_domains']]);
    }

    /**
     * Under fail-open an image alone is published at once, marked as published unassessed;
     * a new thread of an image under an offensive title sends nothing, not even the title.
     */
    public function testUnderFailOpenAnImageAloneIsPublishedUnassessedAndATitleIsNotSent(): void
    {
        $this->forum->set(['minos_failure_mode' => 'fail-open']);
        $pid = (int)$this->forum->write(true, '[img]https://example.com/a.png[/img]', ['subject' => 'Obraźliwy tytuł'])
            ->return_values['pid'];
        task_minos(['tid' => 1]);

        self::assertSame([], $this->forum->transport->requests);
        $row = $this->forum->row($pid);
        self::assertSame([1, Status::PUBLISHED, Status::REASON_NO_TEXT, '1'],
            [(int)$this->forum->post($pid)['visible'], $row['status'], $row['verdict'], (string)$row['auto_published']]);
    }

    /**
     * An author's edit that adds words to a post held as `no_text` sends it, as a revision.
     */
    public function testAnEditThatAddsTextToAnImageSendsIt(): void
    {
        $pid = $this->heldReply('[img]https://example.com/a.png[/img]');
        self::assertSame(Status::REASON_NO_TEXT, $this->forum->row($pid)['verdict']);
        $this->forum->edit($pid, 'Opis: [img]https://example.com/a.png[/img]', 'RE: Wątek');
        task_minos(['tid' => 1]);
        self::assertCount(1, $this->forum->transport->requests);
        self::assertSame(['mybb:' . $pid . '.1', 'Opis: https://example.com/a.png'],
            [$this->forum->transport->lastBody()['elementy'][0]['id'], $this->forum->transport->lastBody()['elementy'][0]['tekst']]);
    }
}
