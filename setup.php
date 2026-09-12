<?php
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
require __DIR__ . '/lib.php';
if (read_data('access')) { fwrite(STDERR, "Access is already configured.\n"); exit(1); }
$key = bin2hex(random_bytes(16));
write_data('access', ['hash'=>password_hash($key, PASSWORD_DEFAULT)]);
echo "Instructor access key (save in your password manager): " . $key . PHP_EOL;
