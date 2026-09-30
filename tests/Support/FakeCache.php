<?php

declare(strict_types=1);

namespace Minos\MyBB\Tests\Support;

/**
 * MyBB's `$cache`: `read`, `update` and `update_tasks`. The plugin is active by default.
 */
final class FakeCache
{
    /** @var array<string,mixed> */
    public $data = ['plugins' => ['active' => ['minos' => 'minos']]];

    /** @var int */
    public $tasksUpdated = 0;

    public function read($name, $hard = false)
    {
        return $this->data[$name] ?? false;
    }

    public function update($name, $contents)
    {
        $this->data[$name] = $contents;
    }

    public function update_tasks()
    {
        $this->tasksUpdated++;
    }
}
