<?php

/*
 * Router for `php -S`: stores every request (headers + raw body) so the
 * integration tests can feed real emulator deliveries to parseWebhook().
 * The directory comes from WEBHOOK_SINK_DIR.
 */
$dir = getenv('WEBHOOK_SINK_DIR') ?: sys_get_temp_dir() . '/payment-gateways-sink';
@mkdir($dir, 0777, true);

$headers = function_exists('getallheaders') ? getallheaders() : [];
$record = [
    'path' => parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH),
    'headers' => $headers,
    'body' => file_get_contents('php://input'),
    'received_at' => microtime(true),
];

file_put_contents($dir . '/' . sprintf('%.6f', microtime(true)) . '-' . bin2hex(random_bytes(3)) . '.json', json_encode($record));
http_response_code(200);
header('Content-Type: application/json');
echo '{"received":true}';
