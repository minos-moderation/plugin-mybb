<?php

/*
 * Compares a live run's answers with the recorded fixtures (tests/fixtures/mybb-1.8.41/)
 * and reports every difference as a GitHub Actions warning: another PHP version or engine
 * that answers a question differently shows on the run's summary. The live tests
 * themselves assert what must hold; this only reports. Every answer of either side is
 * compared, so one missing from a run shows too. Timings and the environment are not
 * compared.
 *
 * Usage: php compare-answers.php <answers-dir> <fixtures-dir>
 */

declare(strict_types=1);

[$self, $answersDir, $fixturesDir] = $argv + [null, '', ''];
$volatile = ['c39_webhook_request_ms', 'q7_schema'];
$differences = 0;
foreach (glob($answersDir . '/*.json') ?: [] as $file) {
    $recorded = $fixturesDir . '/' . basename($file);
    if (!is_file($recorded)) {
        echo "::warning::no recorded fixture for " . basename($file) . "\n";
        continue;
    }
    $now = json_decode((string)file_get_contents($file), true);
    $then = json_decode((string)file_get_contents($recorded), true);
    $key = isset($now['answers']) ? 'answers' : 'cases';
    // Every answer of either side: one the run no longer gives is a difference too.
    $names = array_unique(array_merge(array_keys($now[$key] ?? []), array_keys($then[$key] ?? [])));
    foreach ($names as $name) {
        if (in_array($name, $volatile, true)) {
            continue;
        }
        if (!array_key_exists($name, $now[$key] ?? [])) {
            $differences++;
            echo '::warning::' . basename($file) . " {$name} is recorded in the fixture but missing from this run\n";
            continue;
        }
        $value = $now[$key][$name];
        if (!array_key_exists($name, $then[$key] ?? []) || $then[$key][$name] !== $value) {
            $differences++;
            echo '::warning::' . basename($file) . " {$name} differs from the fixture (PHP {$now['php']}, recorded on PHP {$then['php']}): "
                . json_encode($value, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . "\n";
        }
    }
}
echo $differences === 0 ? "answers as recorded\n" : "{$differences} answer(s) differ from the fixtures\n";
