<?php
declare(strict_types=1);

require_once __DIR__ . '/auth.php';

function csrfToken(): string {
    startSessionOnce();
    if (empty($_SESSION['csrf'])) {
        $_SESSION['csrf'] = bin2hex(random_bytes(32));
    }
    return $_SESSION['csrf'];
}

function csrfVerify(string $supplied): bool {
    startSessionOnce();
    $expected = $_SESSION['csrf'] ?? '';
    if ($expected === '' || $supplied === '') return false;
    return hash_equals($expected, $supplied);
}

function requireCsrfOrAbort(): void {
    $supplied = $_POST['_csrf'] ?? $_SERVER['HTTP_X_CSRF_TOKEN'] ?? '';
    if (!csrfVerify($supplied)) {
        jsonError('csrf token invalid', 419);
    }
}
