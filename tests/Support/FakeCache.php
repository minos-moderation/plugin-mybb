<?php

declare(strict_types=1);

namespace Minos\MyBB\Tests\Support;

use ArrayObject;

/**
 * MyBB's `$cache` (datacache): `read`, `update`, `delete` and `update_tasks`, with MyBB
 * 1.8.41's semantics (`inc/class_datacache.php`, database mode): every entry is loaded
 * into the request's own copy when the request begins; `read($name)` answers from that
 * copy, `read($name, true)` from the database; `update` and `delete` change both.
 *
 * Several stand-ins built over one `$database` are several concurrent requests of one
 * forum. The plugin is active by default.
 */
final class FakeCache
{
    /** @var array<string,mixed> This request's copy. */
    public $data;

    /** @var int */
    public $tasksUpdated = 0;

    /** @var ArrayObject<string,mixed> The `datacache` table. */
    private $database;

    /**
     * @param ArrayObject<string,mixed>|null $database A table shared with other requests.
     */
    public function __construct(?ArrayObject $database = null)
    {
        $this->database = $database ?? new ArrayObject(['plugins' => ['active' => ['minos' => 'minos']]]);
        $this->data = $this->database->getArrayCopy();
    }

    public function read($name, $hard = false)
    {
        if ($hard) {
            $this->data[$name] = $this->database[$name] ?? false;
        }
        return $this->data[$name] ?? false;
    }

    public function update($name, $contents)
    {
        $this->data[$name] = $contents;
        $this->database[$name] = $contents;
    }

    public function delete($name, $greedy = false)
    {
        unset($this->data[$name], $this->database[$name]);
    }

    public function update_tasks()
    {
        $this->tasksUpdated++;
    }
}
