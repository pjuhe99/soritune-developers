<?php
declare(strict_types=1);
require_once __DIR__ . '/auth.php';
$u = requireAuth();
if ($u['role'] === 'admin') {
    header('Location: /admin/');
} else {
    header('Location: /p/');
}
exit;
