<?php
// Tiny local webhook receiver used by tests/features.php
file_put_contents(sys_get_temp_dir() . '/tigger_recv.json', json_encode([
    'headers' => array_filter($_SERVER, static fn ($k) => str_starts_with((string) $k, 'HTTP_'), ARRAY_FILTER_USE_KEY),
    'body' => file_get_contents('php://input'),
]));
http_response_code(200);
echo 'ok';
