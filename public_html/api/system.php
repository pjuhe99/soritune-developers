<?php
declare(strict_types=1);

require_once __DIR__ . '/../auth.php';
require_once __DIR__ . '/../csrf.php';

requireAdmin();

$action = $_GET['action'] ?? $_POST['action'] ?? '';
$handlerMap = [
    'auth'     => __DIR__ . '/system/auth.php',
    'users'    => __DIR__ . '/system/users.php',
    'projects' => __DIR__ . '/system/projects.php',
    'jobs'     => __DIR__ . '/system/jobs.php',
];
if (!isset($handlerMap[$action])) {
    jsonError("unknown action: $action", 404);
}
// Some handlers (users/projects/jobs) land in later tasks. Until then a clean
// 404 beats an uncatchable require_once fatal (raw 500) on the live endpoint.
if (!file_exists($handlerMap[$action])) {
    jsonError('action not implemented', 404);
}
require_once $handlerMap[$action];
