<?php

declare(strict_types=1);

namespace Minos\MyBB\Tests\Support;

/**
 * MyBB's `PostDataHandler`: the public properties the plugin's hooks read.
 */
final class FakePostHandler
{
    /** @var string `insert` or `update`. */
    public $method;

    /** @var string `post` or `thread`. */
    public $action;

    /** @var array<string,mixed> */
    public $data;

    /** @var array<string,mixed> */
    public $return_values = [];

    /** @param array<string,mixed> $data */
    public function __construct(string $method, string $action, array $data)
    {
        $this->method = $method;
        $this->action = $action;
        $this->data = $data;
    }
}
