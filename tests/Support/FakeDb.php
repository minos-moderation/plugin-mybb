<?php

declare(strict_types=1);

namespace Minos\MyBB\Tests\Support;

use PDO;
use PDOStatement;

/**
 * MyBB's `$db` over SQLite (PDO): the methods the plugin and the stubs call, with MyBB's
 * semantics — table names without the prefix in the helpers, values escaped by the caller
 * and quoted by the helper, `affected_rows()` after an update. `type` is `sqlite`, so the
 * plugin's own SQLite DDL runs for real.
 */
final class FakeDb
{
    /** @var string */
    public $type = 'sqlite';

    /** @var string */
    public $table_prefix;

    /** @var array<int,string> Every statement, in order. */
    public $statements = [];

    /** @var PDO */
    private $pdo;

    /** @var int */
    private $affected = 0;

    /** @var array<int,array{0:string,1:callable}> Work to run before a write, once each. */
    private $before = [];

    public function __construct(PDO $pdo, string $prefix)
    {
        $this->pdo = $pdo;
        $this->table_prefix = $prefix;
    }

    /** @return PDOStatement */
    public function query($sql)
    {
        $this->statements[] = $sql;
        $statement = $this->pdo->query($sql);
        \assert($statement instanceof PDOStatement);
        return $statement;
    }

    /**
     * Runs work just before the next write whose SQL contains a string — another request
     * acting between two statements of this one. Test support, not part of MyBB's `$db`.
     */
    public function beforeNextWrite(string $needle, callable $work): void
    {
        $this->before[] = [$needle, $work];
    }

    public function write_query($sql, $hideErrors = 0)
    {
        foreach ($this->before as $i => [$needle, $work]) {
            if (strpos($sql, $needle) !== false) {
                unset($this->before[$i]);
                $work();
                break;
            }
        }
        $this->statements[] = $sql;
        $this->affected = (int)$this->pdo->exec($sql);
        return true;
    }

    /**
     * @param array<string,mixed> $options `order_by`, `order_dir`, `limit`.
     * @return PDOStatement
     */
    public function simple_select($table, $fields = '*', $conditions = '', $options = [])
    {
        $sql = 'SELECT ' . $fields . ' FROM ' . $this->table_prefix . $table;
        if ($conditions !== '') {
            $sql .= ' WHERE ' . $conditions;
        }
        if (isset($options['order_by'])) {
            $sql .= ' ORDER BY ' . $options['order_by'] . (isset($options['order_dir']) ? ' ' . $options['order_dir'] : '');
        }
        if (isset($options['limit'])) {
            $sql .= ' LIMIT ' . (int)$options['limit'];
        }
        return $this->query($sql);
    }

    /** @return array<string,mixed>|null */
    public function fetch_array($query)
    {
        $row = $query->fetch(PDO::FETCH_ASSOC);
        return $row === false ? null : $row;
    }

    public function fetch_field($query, $field, $row = false)
    {
        $found = $this->fetch_array($query);
        return $found[$field] ?? null;
    }

    /** @param array<string,mixed> $array Escaped values. */
    public function insert_query($table, $array)
    {
        $values = array_map(static function ($value): string {
            return "'" . $value . "'";
        }, array_values($array));
        $this->write_query('INSERT INTO ' . $this->table_prefix . $table . ' (' . implode(',', array_keys($array))
            . ') VALUES (' . implode(',', $values) . ')');
        return (int)$this->pdo->lastInsertId();
    }

    /** @param array<string,mixed> $array Escaped values. */
    public function update_query($table, $array, $where = '', $limit = '', $noQuote = false)
    {
        $set = [];
        foreach ($array as $column => $value) {
            $set[] = $column . "='" . $value . "'";
        }
        return $this->write_query('UPDATE ' . $this->table_prefix . $table . ' SET ' . implode(', ', $set)
            . ($where !== '' ? ' WHERE ' . $where : ''));
    }

    public function delete_query($table, $where = '', $limit = '')
    {
        return $this->write_query('DELETE FROM ' . $this->table_prefix . $table . ($where !== '' ? ' WHERE ' . $where : ''));
    }

    public function escape_string($string)
    {
        return str_replace("'", "''", (string)$string);
    }

    public function table_exists($table)
    {
        $query = $this->query("SELECT name FROM sqlite_master WHERE type='table' AND name='"
            . $this->escape_string($this->table_prefix . $table) . "'");
        return $this->fetch_array($query) !== null;
    }

    /** MyBB's SQLite driver: `PRAGMA table_info` of the prefixed table. */
    public function field_exists($field, $table)
    {
        $query = $this->query("PRAGMA table_info('" . $this->escape_string($this->table_prefix . $table) . "')");
        while ($row = $this->fetch_array($query)) {
            if ($row['name'] === $field) {
                return true;
            }
        }
        return false;
    }

    /** MyBB's SQLite driver: `ALTER TABLE <prefix><table> ADD <column> <definition>`. */
    public function add_column($table, $column, $definition)
    {
        return $this->write_query('ALTER TABLE ' . $this->table_prefix . $table . ' ADD ' . $column . ' ' . $definition);
    }

    public function drop_table($table, $hard = false, $tablePrefix = true)
    {
        $this->write_query('DROP TABLE IF EXISTS ' . ($tablePrefix ? $this->table_prefix : '') . $table);
    }

    public function affected_rows()
    {
        return $this->affected;
    }

    public function build_create_table_collation()
    {
        return '';
    }
}
