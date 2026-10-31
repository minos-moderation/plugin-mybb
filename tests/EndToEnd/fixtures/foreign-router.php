<?php

/*
 * What may answer at a gateway's address without being the gateway (a hosting page, a proxy
 * in front of it, a wrong address), served by PHP's built-in server for EndToEndTest:
 *
 *   php -S 127.0.0.1:<port> foreign-router.php
 *
 * The n-th request gets the n-th status of MINOS_E2E_STATUSES (comma-separated; the last one
 * repeats); the requests are counted in the file MINOS_E2E_COUNT. A 2xx carries a body that
 * names every item sent in `przyjete`, as the gateway's acceptance does — but not its status.
 * The mock gateway answers none of these, so this stands in for them. Test data only.
 */

$minosCountFile = (string)getenv('MINOS_E2E_COUNT');
$minosCount = is_file($minosCountFile) ? (int)file_get_contents($minosCountFile) : 0;
file_put_contents($minosCountFile, (string)($minosCount + 1), LOCK_EX);
$minosStatuses = array_map('intval', explode(',', (string)getenv('MINOS_E2E_STATUSES')));
$minosStatus = $minosStatuses[min($minosCount, count($minosStatuses) - 1)];

$minosIds = [];
$minosRequest = json_decode((string)file_get_contents('php://input'), true);
foreach (is_array($minosRequest) && is_array($minosRequest['elementy'] ?? null) ? $minosRequest['elementy'] : [] as $minosItem) {
    if (is_array($minosItem) && isset($minosItem['id'])) {
        $minosIds[] = $minosItem['id'];
    }
}

http_response_code($minosStatus);
if ($minosStatus >= 200 && $minosStatus < 300 && $minosStatus !== 204) {
    header('Content-Type: application/json');
    echo json_encode(['przyjete' => $minosIds]);
} elseif ($minosStatus !== 204) {
    header('Content-Type: text/html');
    echo '<html><body>Request Timeout</body></html>';
}
return true;
