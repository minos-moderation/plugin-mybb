<?php

/*
 * Reads one post with `Minos\MyBB\Text` under PCRE backtrack limits and prints, as JSON,
 * what each call gave under each limit: its answer, or `"error"` for a `TextError`.
 *
 * Input on stdin, JSON: `message`, `subject` (for `forPost`), `limits` (a list, or null for
 * the configured limit only). Output: by limit (`"default"` for null), by call.
 *
 * Run by TextTest in a process of its own started with `pcre.jit=0`: JIT ignores the
 * backtrack limit, and PHP keeps every pattern as it was first compiled, so in the test
 * process (where earlier tests compiled the patterns with JIT) JIT could not be switched off.
 */

declare(strict_types=1);

require __DIR__ . '/../../../vendor/autoload.php';

use Minos\MyBB\Text;
use Minos\MyBB\TextError;

$job = (array)json_decode((string)stream_get_contents(STDIN), true);
$message = (string)($job['message'] ?? '');
$subject = (string)($job['subject'] ?? '');
$results = [];
foreach ($job['limits'] ?? [null] as $limit) {
    if ($limit !== null) {
        ini_set('pcre.backtrack_limit', (string)$limit);
    }
    foreach (['links', 'linkDomains', 'forPost'] as $method) {
        try {
            $answer = $method === 'forPost' ? Text::forPost($message, $subject) : Text::$method($message);
        } catch (TextError $e) {
            $answer = 'error';
        }
        $results[$limit === null ? 'default' : (string)$limit][$method] = $answer;
    }
}
echo json_encode($results, JSON_UNESCAPED_UNICODE);
