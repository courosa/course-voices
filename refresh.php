<?php
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
require __DIR__ . '/lib.php';
foreach (read_data('courses')['courses'] as $c) {
    echo $c['id'] . ': ' . json_encode(refresh_course($c['id'], in_array('--force', $argv, true))) . PHP_EOL;
}
