<?php

declare(strict_types=1);

namespace Minos\MyBB\Tests\Support;

use PHPUnit\Framework\TestCase;

/**
 * A test on an installed, configured plugin, with a registered user writing.
 */
abstract class PluginTestCase extends TestCase
{
    /** The writing user. Test data only. */
    protected const UID = 7;

    /** @var Forum */
    protected $forum;

    protected function setUp(): void
    {
        $this->forum = Forum::create();
        $this->forum->install();
        $this->forum->mybb->user = [
            'uid' => self::UID, 'username' => 'Autor Testowy', 'email' => 'autor@example.com',
            'postnum' => 3, 'moderateposts' => 0,
        ];
    }

    /**
     * A held reply's pid.
     */
    protected function heldReply(string $message = 'Zwykła odpowiedź.'): int
    {
        return (int)$this->forum->write(false, $message)->return_values['pid'];
    }

    /**
     * A payload as the gateway builds it.
     *
     * @param array<string,mixed> $fields Fields over a `bezpieczne` verdict.
     * @return array<string,mixed>
     */
    protected static function payload(int $pid, array $fields = []): array
    {
        return $fields + [
            'id' => 'mybb:' . $pid, 'status' => 'ocenione', 'kwalifikacja' => 'bezpieczne',
            'kategorie' => [], 'wsparcie' => false, 'wersja' => '3f0c9a41d2b7e8c5',
        ];
    }

    /**
     * @return array<int,array{0:string,1:array<int,int>}> The `Moderation` calls so far.
     */
    protected static function moderation(): array
    {
        return $GLOBALS['minos_test']['moderation'];
    }

    /**
     * @return array<int,array<string,mixed>> The plugin's log, oldest first.
     */
    protected function logEntries(): array
    {
        return array_reverse($this->forum->platform()->recentLog(1000));
    }
}
