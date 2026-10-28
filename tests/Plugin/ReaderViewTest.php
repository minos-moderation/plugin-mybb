<?php

declare(strict_types=1);

namespace Minos\MyBB\Tests\Plugin;

use Minos\MyBB\Text;
use PHPUnit\Framework\TestCase;

/**
 * The plugin's `tekst` against what MyBB 1.8.41 shows its readers, as the live run recorded
 * it with MyBB's own parser (`tests/fixtures/mybb-1.8.41/reader-view.json`; the visible text
 * is the page's text without scripts, styles and comments, as a browser shows it).
 */
final class ReaderViewTest extends TestCase
{
    /**
     * Shared rule S1 on an HTML forum: every word a reader sees is sent, and nothing a reader
     * does not see is — but the `alt`/`title` hints next to their words.
     *
     * @dataProvider htmlCases
     */
    public function testOnAnHtmlForumTheReadersWordsAreSentAndNothingHidden(string $id): void
    {
        $case = self::cases()[$id];
        $sent = self::words(Text::forPost($case['message'], '', $case['forum'])['tekst']);
        foreach (self::words($case['text']) as $word) {
            self::assertContains($word, $sent, $id . ': a word the reader sees is sent');
        }
        $shown = array_merge(self::words($case['text']), self::words(implode(' ', $case['hints'])));
        foreach ($sent as $word) {
            self::assertContains($word, $shown, $id . ': nothing hidden is sent');
        }
    }

    /**
     * @return array<string,array{0:string}>
     */
    public function htmlCases(): array
    {
        $ids = array_filter(array_keys(self::cases()), static function (string $id): bool {
            return strncmp($id, 'html-', 5) === 0;
        });
        self::assertGreaterThanOrEqual(5, count($ids), 'the guard: the fixture has HTML cases');
        $cases = [];
        foreach ($ids as $id) {
            $cases[$id] = [$id];
        }
        return $cases;
    }

    /**
     * @return array<string,array{forum:array<string,int>,message:string,text:string,hints:array<int,string>,has_text:bool}>
     */
    private static function cases(): array
    {
        $file = __DIR__ . '/../fixtures/mybb-1.8.41/reader-view.json';
        $data = json_decode((string)file_get_contents($file), true);
        self::assertIsArray($data);
        return $data['cases'];
    }

    /**
     * @return array<int,string>
     */
    private static function words(string $text): array
    {
        return array_values(array_filter((array)preg_split('/\s+/u', $text), 'strlen'));
    }
}
