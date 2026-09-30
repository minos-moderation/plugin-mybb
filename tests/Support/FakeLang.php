<?php

declare(strict_types=1);

namespace Minos\MyBB\Tests\Support;

/**
 * MyBB's `$lang`: `load()` reads `inc/languages/<language>/<section>.lang.php` of this
 * repository into properties, falling back to `english`, as `MyLanguage::load` does.
 */
final class FakeLang
{
    /** @var string */
    public $language;

    /** @var array<int,string> Sections loaded, in order. */
    public $loaded = [];

    /** @var array<string,string> */
    private $strings = [];

    /** @var string */
    private $path;

    public function __construct(string $path, string $language = 'polish')
    {
        $this->path = $path;
        $this->language = $language;
    }

    public function load($section, $forceUserArea = false, $suppressError = false)
    {
        $file = $this->path . '/' . $this->language . '/' . $section . '.lang.php';
        if (!is_file($file)) {
            $file = $this->path . '/english/' . $section . '.lang.php';
        }
        $l = [];
        require $file;
        $this->strings = $l + $this->strings;
        $this->loaded[] = $section;
    }

    public function __get($name)
    {
        return $this->strings[$name] ?? null;
    }

    public function __isset($name)
    {
        return isset($this->strings[$name]);
    }

    public function __set($name, $value)
    {
        $this->strings[$name] = $value;
    }
}
