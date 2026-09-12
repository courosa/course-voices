<?php
require __DIR__ . '/lib.php';
header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');
header('X-Content-Type-Options: nosniff');
try {
    $id = (string)($_GET['course'] ?? '');
    if (($_GET['action'] ?? '') === 'refresh') {
        if ($_SERVER['REQUEST_METHOD'] !== 'POST') { http_response_code(405); exit; }
        // Cross-origin scripts cannot set this header without a successful CORS preflight.
        if (($_SERVER['HTTP_X_COURSE_VOICES'] ?? '') !== '1') { http_response_code(403); exit; }
        @set_time_limit(480);
        echo json_encode(refresh_course(course($id)['id']));
    } else echo json_encode(public_feed($id), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
} catch (Throwable $e) { http_response_code(400); echo json_encode(['error'=>$e->getMessage()]); }
