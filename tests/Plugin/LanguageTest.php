<?php

declare(strict_types=1);

namespace Minos\MyBB\Tests\Plugin;

use Minos\MyBB\Status;
use PHPUnit\Framework\TestCase;

/**
 * The language files: the `english/` fallback is the Polish file, and every key the code
 * asks for exists.
 */
final class LanguageTest extends TestCase
{
    private const ROOT = __DIR__ . '/../../';

    public function testTheEnglishFallbackIsThePolishFile(): void
    {
        self::assertFileEquals(self::ROOT . 'inc/languages/polish/minos.lang.php',
            self::ROOT . 'inc/languages/english/minos.lang.php');
    }

    public function testEveryKeyTheCodeNamesExists(): void
    {
        $strings = self::strings();
        $named = [];
        foreach (glob(self::ROOT . 'inc/plugins/minos/src/*.php') ?: [] as $file) {
            preg_match_all("/lang\\('(minos_[a-z_]+)'\\s*[,)]/", (string)file_get_contents($file), $m);
            $named = array_merge($named, $m[1]);
        }
        self::assertGreaterThan(20, count(array_unique($named)), 'the scan found no keys: it is broken');
        foreach (array_unique($named) as $key) {
            self::assertArrayHasKey($key, $strings);
        }
    }

    public function testEveryStatusReasonAndEventHasALabel(): void
    {
        $strings = self::strings();
        foreach ((new \ReflectionClass(Status::class))->getConstants() as $name => $value) {
            if (is_string($value)) {
                $key = (strncmp($name, 'REASON_', 7) === 0 ? 'minos_reason_' : 'minos_status_') . $value;
                self::assertArrayHasKey($key, $strings, $name);
            }
        }
        foreach (['config_error', 'retry', 'recovered', 'timeout'] as $event) {
            self::assertArrayHasKey('minos_event_' . $event, $strings);
        }
    }

    public function testEverySelectOptionHasALabel(): void
    {
        $strings = self::strings();
        foreach (['profile_forum_adult', 'profile_forum_teen', 'failure_mode_fail_open', 'failure_mode_fail_closed',
            'censored_publish', 'censored_queue', 'blocked_queue', 'blocked_soft_delete'] as $option) {
            self::assertArrayHasKey('minos_setting_' . $option, $strings);
        }
    }

    /** @return array<string,string> */
    private static function strings(): array
    {
        $l = [];
        require self::ROOT . 'inc/languages/polish/minos.lang.php';
        return $l;
    }
}
