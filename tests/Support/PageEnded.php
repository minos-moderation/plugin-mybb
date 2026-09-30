<?php

declare(strict_types=1);

namespace Minos\MyBB\Tests\Support;

/**
 * Thrown where MyBB's ACP ends the request (`output_footer`, `output_confirm_action`).
 */
final class PageEnded extends \RuntimeException
{
}
