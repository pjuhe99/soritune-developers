<?php
declare(strict_types=1);
require_once __DIR__ . '/../auth.php';
require_once __DIR__ . '/../csrf.php';

requireAuth();

$action = $_GET['action'] ?? $_POST['action'] ?? '';
$handlerMap = [
    'me'       => __DIR__ . '/user/me.php',
    'projects' => __DIR__ . '/user/projects.php',
];
if (!isset($handlerMap[$action])) { jsonError("unknown action: $action", 404); }
if (!file_exists($handlerMap[$action])) { jsonError('action not implemented', 404); }
require_once $handlerMap[$action];
