<?php

declare(strict_types=1);

namespace Minos\MyBB\Tests\Plugin;

use PHPUnit\Framework\TestCase;

/**
 * The answers of the live run on MyBB 1.8.41 (`tests/fixtures/mybb-1.8.41/`, recorded by
 * `bin/verify-mybb-1841.sh`, minos-moderation/minos#47) stay what `docs/development.md`
 * ("Verified on a live forum") says they are: each file names the MyBB commit and the PHP
 * version it was recorded with, and holds an answer to each of the seven questions. No
 * MyBB and no database here: the files only.
 */
final class LiveFixturesTest extends TestCase
{
    private const DIR = __DIR__ . '/../fixtures/mybb-1.8.41';

    /** The answers every engine's file holds: the seven questions, the smoke run's and the S7d items'. */
    private const ANSWERS = [
        'q1_pcre', 'q2_notifications', 'q3_polls', 'q4_edit_own_unapproved', 'q5_reply_subject',
        'q6_counters', 'q7_schema', 'q7_claim', 'c39_webhook_request_ms', 'html_forum_page',
        'activation_adds_a_missing_column', 'k43_late_after_timeout',
    ];

    /**
     * @return array<string,array{0:string}>
     */
    public function engines(): array
    {
        return ['MySQL' => ['mysqli'], 'PostgreSQL' => ['pgsql']];
    }

    /**
     * @dataProvider engines
     */
    public function testEachEnginesFileNamesItsMyBBCommitAndPhpAndAnswersEveryQuestion(string $engine): void
    {
        $fixture = self::read('live-' . $engine . '.json');
        self::assertSame(['version' => '1.8.41', 'tag' => 'mybb_1841'], array_intersect_key($fixture['mybb'], ['version' => 1, 'tag' => 1]));
        self::assertMatchesRegularExpression('/^[0-9a-f]{40}$/', $fixture['mybb']['commit']);
        self::assertMatchesRegularExpression('/^[78]\.[0-9]+\.[0-9]+/', $fixture['php']);
        self::assertSame($engine, $fixture['database']['engine']);
        foreach (self::ANSWERS as $answer) {
            self::assertArrayHasKey($answer, $fixture['answers'], $answer . ' answered');
        }
        self::assertSame(['reply_340', 'thread_340', 'reply_1000', 'thread_1000'], array_keys($fixture['answers']['q1_pcre']));
    }

    public function testBothEnginesAnswerAlike(): void
    {
        $mysql = self::read('live-mysqli.json')['answers'];
        $pgsql = self::read('live-pgsql.json')['answers'];
        foreach (self::ANSWERS as $answer) {
            if (in_array($answer, ['q7_schema', 'c39_webhook_request_ms'], true)) {
                continue; // the engine's own DDL, and a timing
            }
            self::assertSame($mysql[$answer], $pgsql[$answer], $answer);
        }
    }

    public function testTheReaderViewNamesItsCommitAndHasCasesOnBothSidesOfTheRule(): void
    {
        $view = self::read('reader-view.json');
        self::assertMatchesRegularExpression('/^[0-9a-f]{40}$/', $view['mybb']['commit']);
        $shown = array_filter($view['cases'], static function (array $case): bool {
            return $case['has_text'];
        });
        self::assertNotEmpty($shown, 'cases a reader sees text in');
        self::assertNotCount(count($view['cases']), $shown, 'and cases without any');
    }

    /**
     * @return array<string,mixed>
     */
    private static function read(string $name): array
    {
        $data = json_decode((string)file_get_contents(self::DIR . '/' . $name), true);
        self::assertIsArray($data, $name . ' is JSON');
        return $data;
    }
}
