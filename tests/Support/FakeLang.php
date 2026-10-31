<?php

declare(strict_types=1);

namespace Minos\MyBB\Tests\Support;

/**
 * MyBB's `$lang` (`MyLanguage`): `load()` reads `inc/languages/<language>/<section>.lang.php`
 * of this repository into properties, falling back to `english`, as `MyLanguage::load` does;
 * MyBB's own sections the plugin relies on (`global`, `messages`) come from {@see MYBB},
 * a few of MyBB 1.8.41's English strings (the subjects word for word, the bodies shortened
 * with every placeholder kept). `set_language`, `set_path`, `language_exists` and `sprintf`
 * as MyBB has them; a language "exists" when this repository has its directory.
 *
 * Not final: the stubs' `MyLanguage` is this class.
 */
class FakeLang
{
    /** MyBB's strings by section, for the sections this repository does not ship. */
    private const MYBB = [
        'global' => [
            'guest' => 'Guest', 'mybb_engine' => 'MyBB Engine', 'quote' => 'Quote:', 'wrote' => 'Wrote:',
            'code' => 'Code:', 'php_code' => 'PHP Code:', 'comma' => ', ',
        ],
        'messages' => [
            'emailsubject_subscription' => 'New Reply to {1}',
            'email_subscription' => "{1},\n\n{2} has just replied to a thread which you have subscribed to at {3}. This thread is titled {4}.\n\n{5}\n\n{6}/{7}\n\n{6}/usercp.php?action=removesubscription&tid={8}",
            'emailsubject_forumsubscription' => 'New Thread in {1}',
            'email_forumsubscription' => "{1},\n\n{2} has just started a new thread in {3}. This is a forum you have subscribed to at {4}.\n\nThe thread is titled {5}\n\n{6}\n\n{7}/{8}\n\n{7}/usercp.php?action=removesubscription&type=forum&fid={9}",
            'emailbit_viewthread' => '... (visit the thread to read more...)',
            'pmsubject_subscription' => 'New Reply to {1}',
            'pm_subscription' => "{1},\n\n{2} has just replied to a thread which you have subscribed to. This thread is titled {3}.\n\n{4}\n\n[url]{5}/{6}[/url]\n\n[url]{5}/usercp.php?action=removesubscription&tid={7}[/url]",
        ],
    ];

    /** @var string */
    public $language;

    /** @var array<int,string> Sections loaded, in order. */
    public $loaded = [];

    /** @var array<string,string> */
    private $strings = [];

    /** @var string */
    private $path;

    public function __construct(string $path = '', string $language = 'polish')
    {
        $this->path = $path;
        $this->language = $language;
    }

    public function set_path($path)
    {
        $this->path = (string)$path;
    }

    /** MyBB dies on a language it does not have; here that is an error. */
    public function set_language($language = '', $area = 'user')
    {
        $language = (string)preg_replace('#[^a-z0-9\-_]#i', '', (string)$language);
        if ($language === '') {
            $language = (string)($GLOBALS['mybb']->settings['bblanguage'] ?? 'english');
        }
        if (!$this->language_exists($language)) {
            throw new \RuntimeException("Language {$language} is not installed");
        }
        $this->language = $language;
    }

    public function language_exists($language)
    {
        $language = (string)preg_replace('#[^a-z0-9\-_]#i', '', (string)$language);
        return $language !== '' && is_dir($this->path . '/' . $language);
    }

    public function load($section, $forceUserArea = false, $suppressError = false)
    {
        $file = $this->path . '/' . $this->language . '/' . $section . '.lang.php';
        if (!is_file($file)) {
            $file = $this->path . '/english/' . $section . '.lang.php';
        }
        $l = [];
        if (is_file($file)) {
            require $file;
        } elseif (isset(self::MYBB[$section])) {
            $l = self::MYBB[$section];
        }
        $this->strings = $l + $this->strings;
        $this->loaded[] = $section;
    }

    public function sprintf($string)
    {
        $arguments = func_get_args();
        for ($i = 1; $i < count($arguments); $i++) {
            $string = str_replace('{' . $i . '}', (string)$arguments[$i], (string)$string);
        }
        return $string;
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
