<?php

declare(strict_types=1);

namespace Minos\MyBB\Tests\Live\Support;

/**
 * What the live run shares: its environment and its fixed test data.
 */
final class Live
{
    /** MyBB's table prefix on the live forum. */
    public const PREFIX = 'mybb_';

    /** The administrator the installer creates. Test data only. */
    public const ADMIN = 'admin_minos';

    /** The tag and the commit of MyBB this run must be on. */
    public const MYBB_TAG = 'mybb_1841';

    public const MYBB_VERSION = '1.8.41';

    /**
     * An environment variable the run needs.
     */
    public static function env(string $name): string
    {
        $value = getenv($name);
        if (!is_string($value) || $value === '') {
            throw new \RuntimeException("{$name} is not set");
        }
        return $value;
    }
}
