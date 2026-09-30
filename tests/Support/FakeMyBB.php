<?php

declare(strict_types=1);

namespace Minos\MyBB\Tests\Support;

/**
 * MyBB's `$mybb`: the properties the plugin reads.
 */
final class FakeMyBB
{
    /** @var array<string,mixed> */
    public $settings = [];

    /** @var array<string,mixed> The current user (`uid` 0 for a guest). */
    public $user = ['uid' => 0, 'postnum' => 0, 'moderateposts' => 0];

    /** @var array<string,mixed> */
    public $input = [];

    /** @var string */
    public $request_method = 'get';

    /** @var int */
    public $version_code = 1841;
}
