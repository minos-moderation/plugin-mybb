<?php

declare(strict_types=1);

namespace Minos\MyBB\Tests\Support;

use ArrayObject;

/**
 * MyBB's `$cache` (datacache): `read`, `update`, `delete`, `update_tasks` and
 * `update_mailqueue`, with MyBB
 * 1.8.41's semantics (`inc/class_datacache.php`, database mode): every entry is loaded
 * into the request's own copy when the request begins; `read($name)` answers from that
 * copy, `read($name, true)` from the database (and refreshes the copy); `update` changes
 * both; `delete` deletes the database row ONLY — MyBB's `delete()` never touches the
 * request's copy, so a plain `read` in the same request still answers the old value.
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
        // The request's copy keeps the entry, as in MyBB. Unsetting a missing offset of an
        // ArrayObject raises a notice on PHP 7.4 (not on 8); MyBB's delete of a missing
        // entry is a DELETE that matches nothing.
        if ($this->database->offsetExists($name)) {
            $this->database->offsetUnset($name);
        }
    }

    public function update_tasks()
    {
        $this->tasksUpdated++;
    }

    /** MyBB: the `mailqueue` entry's `queue_size` from the table. */
    public function update_mailqueue($lastRun = 0, $lockTime = 0)
    {
        global $db;
        $query = $db->simple_select('mailqueue', 'COUNT(*) AS queue_size');
        $this->update('mailqueue', ['queue_size' => (int)$db->fetch_field($query, 'queue_size'), 'locked' => (int)$lockTime]);
    }

    /**
     * The `datacache` table as it is now (what every request that begins now loads).
     *
     * @return array<string,mixed> Entries by name.
     */
    public function stored(): array
    {
        return $this->database->getArrayCopy();
    }
}
