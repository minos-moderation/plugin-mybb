<?php

declare(strict_types=1);

namespace Minos\MyBB\Tests\Repo;

use PHPUnit\Framework\TestCase;

/**
 * The rules for Claude Code sessions stay findable and cheap: the root `CLAUDE.md`, read by
 * every session, keeps to a size budget and names every scoped file, and every project
 * agent pins its model. An agent that may not write lists its tools, none of them a writing
 * one: without a `tools:` line an agent inherits every tool, Edit and Write included. A
 * Sonnet agent that edits files works from a list of allowed paths, so the "Risk list" of its
 * file must name the paths it may not touch, and its "You may change" list none of them.
 *
 * The checks are functions of a directory of agents, so the same checks run on the real
 * agents (they must pass) and on `fixtures/agents/` (they must fail, for the reason each
 * fixture was written for).
 */
final class ClaudeRulesTest extends TestCase
{
    /** Bytes. Lowering is free; raising needs a sentence in the commit. */
    private const ROOT_LIMIT = 3100;

    /** Bytes, for every `CLAUDE.md` below the root. */
    private const SCOPED_LIMIT = 1600;

    /** Agents allowed to edit files. Every other agent is read-only. */
    private const WRITERS = ['programista', 'programista-prosty'];

    /** The tools that write files. */
    private const WRITING_TOOLS = ['Edit', 'Write', 'NotebookEdit'];

    /** What a Sonnet agent that edits files must name in its risk list, and never allow. */
    private const SONNET_RISK_MARKERS = [
        'minos-webhook.php', 'Receiver.php', 'inc/plugins/minos.php', 'Submitter.php', 'Applier.php', 'Gateway.php',
        'Text.php', 'inc/tasks/minos.php', 'Task.php', 'Settings.php', 'Installer.php', 'Admin.php', 'Platform.php',
        'autoload.php', 'bin/build-zip.sh', '.github/', '.claude/', 'CLAUDE.md', 'composer.json', 'composer.lock',
        'phpunit.xml.dist', 'tests/Stubs/', 'tests/Support/',
    ];

    private const MODELS = ['opus', 'sonnet', 'haiku', 'fable'];

    private const EFFORTS = ['low', 'medium', 'high', 'xhigh', 'max'];

    private const ROOT = __DIR__ . '/../..';

    private const FIXTURES = __DIR__ . '/fixtures/agents';

    public function testTheRootFileStaysWithinItsBudget(): void
    {
        $size = strlen((string)file_get_contents(self::ROOT . '/CLAUDE.md'));
        self::assertLessThanOrEqual(self::ROOT_LIMIT, $size,
            'move a rule that one directory needs into that directory\'s CLAUDE.md');
    }

    public function testEveryScopedFileStaysWithinItsBudgetAndIsNamedInTheRoot(): void
    {
        $root = (string)file_get_contents(self::ROOT . '/CLAUDE.md');
        foreach (self::scopedFiles() as $path) {
            self::assertLessThanOrEqual(self::SCOPED_LIMIT,
                strlen((string)file_get_contents(self::ROOT . '/' . $path)), $path);
            self::assertStringContainsString("`{$path}`", $root, "the root CLAUDE.md must name {$path}");
        }
    }

    public function testEveryAgentPinsAModelAndAnEffort(): void
    {
        self::assertSame([], self::pinProblems(self::agents(self::ROOT . '/.claude/agents')));
    }

    public function testOnlyAnImplementingAgentMayWriteAndItNeverRunsOnFable(): void
    {
        self::assertSame([], self::toolProblems(self::agents(self::ROOT . '/.claude/agents')));
    }

    public function testASonnetAgentThatWritesKeepsTheRiskPathsOnItsRiskList(): void
    {
        $agents = self::agents(self::ROOT . '/.claude/agents');
        self::assertGreaterThan(0, count(self::sonnetWriters($agents)),
            'no Sonnet agent that writes was found: the check checks nothing');
        self::assertSame([], self::riskListProblems($agents));
    }

    /**
     * Each fixture breaks one rule, and the check of that rule names it: a reviewer with no
     * `tools:` line, a reviewer that lists a writing tool, and a Sonnet writer whose "You may
     * change" list names a risk path. Every fixture is otherwise valid.
     */
    public function testTheChecksRejectEachBadFixture(): void
    {
        $fixtures = self::agents(self::FIXTURES);
        self::assertSame([], self::pinProblems($fixtures), 'a fixture must break only the rule it was written for');

        $tools = implode("\n", self::toolProblems($fixtures));
        self::assertStringContainsString('przeglad-mechaniczny: no tools: line', $tools);
        self::assertStringContainsString('przeglad-bezpieczenstwa: lists a writing tool', $tools);

        self::assertSame(['programista-prosty'], array_keys(self::sonnetWriters($fixtures)));
        self::assertSame(['programista-prosty: its "You may change" list names Text.php'],
            self::riskListProblems($fixtures));
    }

    /**
     * @param array<string,array{fields:array<string,string>,body:string}> $agents
     * @return array<int,string>
     */
    private static function pinProblems(array $agents): array
    {
        $problems = [];
        foreach ($agents as $name => $agent) {
            $fields = $agent['fields'];
            if (($fields['name'] ?? null) !== $name) {
                $problems[] = "{$name}: the name must match the file";
            }
            if (!in_array($fields['model'] ?? null, self::MODELS, true)) {
                $problems[] = "{$name}: no pinned model";
            }
            if (!in_array($fields['effort'] ?? null, self::EFFORTS, true)) {
                $problems[] = "{$name}: no pinned effort";
            }
            if (($fields['description'] ?? '') === '') {
                $problems[] = "{$name}: no description";
            }
        }
        return $problems;
    }

    /**
     * @param array<string,array{fields:array<string,string>,body:string}> $agents
     * @return array<int,string>
     */
    private static function toolProblems(array $agents): array
    {
        $problems = [];
        foreach ($agents as $name => $agent) {
            $fields = $agent['fields'];
            if (in_array($name, self::WRITERS, true)) {
                if (($fields['model'] ?? null) === 'fable') {
                    $problems[] = "{$name}: Fable does not implement";
                }
            } elseif (!isset($fields['tools']) || trim($fields['tools']) === '') {
                $problems[] = "{$name}: no tools: line, so it inherits every tool and may write";
            } elseif (self::writes($fields)) {
                $problems[] = "{$name}: lists a writing tool, but it is read-only";
            }
        }
        return $problems;
    }

    /**
     * For every Sonnet agent that writes: each risk marker is named in its "Risk list" block,
     * and none in its "You may change" block.
     *
     * @param array<string,array{fields:array<string,string>,body:string}> $agents
     * @return array<int,string>
     */
    private static function riskListProblems(array $agents): array
    {
        $problems = [];
        foreach (self::sonnetWriters($agents) as $name => $agent) {
            $allowed = self::block($agent['body'], 'You may change');
            $risks = self::block($agent['body'], 'Risk list');
            if ($allowed === '' || $risks === '') {
                $problems[] = "{$name}: a \"You may change\" and a \"Risk list\" block are both needed";
                continue;
            }
            foreach (self::SONNET_RISK_MARKERS as $marker) {
                if (strpos($risks, $marker) === false) {
                    $problems[] = "{$name}: its Risk list does not name {$marker}";
                }
                if (strpos($allowed, $marker) !== false) {
                    $problems[] = "{$name}: its \"You may change\" list names {$marker}";
                }
            }
        }
        return $problems;
    }

    /**
     * @param array<string,array{fields:array<string,string>,body:string}> $agents
     * @return array<string,array{fields:array<string,string>,body:string}>
     */
    private static function sonnetWriters(array $agents): array
    {
        return array_filter($agents, static function (array $agent): bool {
            return ($agent['fields']['model'] ?? null) === 'sonnet' && self::writes($agent['fields']);
        });
    }

    /**
     * The list that follows the line holding `$heading`: its bullets (`- …`) and their
     * continuation lines, up to the first other line.
     *
     * @return string The list; empty when the heading or its list is missing.
     */
    private static function block(string $body, string $heading): string
    {
        $lines = explode("\n", $body);
        foreach ($lines as $i => $line) {
            if (strpos($line, $heading) === false) {
                continue;
            }
            $block = [];
            for ($j = $i + 1; isset($lines[$j]) && preg_match('/^(- |  )/', $lines[$j]); $j++) {
                $block[] = $lines[$j];
            }
            return implode("\n", $block);
        }
        return '';
    }

    /** @param array<string,string> $fields */
    private static function writes(array $fields): bool
    {
        $tools = array_map('trim', explode(',', $fields['tools'] ?? ''));
        return array_intersect(self::WRITING_TOOLS, $tools) !== [];
    }

    /**
     * Every `CLAUDE.md` below the root, as paths relative to it.
     *
     * @return array<int,string>
     */
    private static function scopedFiles(): array
    {
        $root = realpath(self::ROOT);
        $skip = ['.git', 'vendor', 'node_modules', 'build'];
        $files = new \RecursiveIteratorIterator(new \RecursiveCallbackFilterIterator(
            new \RecursiveDirectoryIterator((string)$root, \FilesystemIterator::SKIP_DOTS),
            static function (\SplFileInfo $file) use ($skip): bool {
                return !($file->isDir() && in_array($file->getFilename(), $skip, true));
            }
        ));
        $found = [];
        foreach ($files as $file) {
            $path = substr($file->getPathname(), strlen((string)$root) + 1);
            if ($file->getFilename() === 'CLAUDE.md' && $path !== 'CLAUDE.md') {
                $found[] = $path;
            }
        }
        self::assertNotEmpty($found, 'no scoped CLAUDE.md found: the search is broken');
        sort($found);
        return $found;
    }

    /**
     * Every agent of a directory by file name: its front matter and its body.
     *
     * @param string $dir The directory (`.claude/agents`, or the fixtures).
     * @return array<string,array{fields:array<string,string>,body:string}>
     */
    private static function agents(string $dir): array
    {
        $agents = [];
        foreach (glob($dir . '/*.md') ?: [] as $file) {
            $text = (string)file_get_contents($file);
            $fields = [];
            $body = $text;
            if (preg_match('/\A---\n(.*?)\n---\n(.*)\z/s', $text, $m)) {
                $body = $m[2];
                foreach (explode("\n", $m[1]) as $line) {
                    $pair = explode(':', $line, 2);
                    if (count($pair) === 2) {
                        $fields[trim($pair[0])] = trim($pair[1]);
                    }
                }
            }
            $agents[basename($file, '.md')] = ['fields' => $fields, 'body' => $body];
        }
        self::assertNotEmpty($agents, "no agents found in {$dir}");
        return $agents;
    }
}
