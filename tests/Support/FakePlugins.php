<?php

declare(strict_types=1);

namespace Minos\MyBB\Tests\Support;

/**
 * MyBB's `$plugins`: `add_hook` and `run_hooks` with MyBB 1.8's semantics, including the
 * one that bites — a hook's truthy return value REPLACES the arguments.
 */
final class FakePlugins
{
    /** @var array<string,array<int,callable>> */
    public $hooks = [];

    public function add_hook($hook, $function, $priority = 10, $file = '')
    {
        $this->hooks[$hook][] = $function;
        return true;
    }

    public function run_hooks($hook, &$arguments = '')
    {
        foreach ($this->hooks[$hook] ?? [] as $function) {
            $returned = $function($arguments);
            if ($returned) {
                $arguments = $returned;
            }
        }
        return $arguments;
    }
}
