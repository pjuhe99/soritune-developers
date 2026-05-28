<?php
declare(strict_types=1);

require_once __DIR__ . '/config.php';

function startSessionOnce(): void {
    if (session_status() === PHP_SESSION_ACTIVE) return;
    session_set_cookie_params([
        'lifetime' => 0,
        'path' => '/',
        'domain' => '',
        'secure' => !empty($_SERVER['HTTPS']),
        'httponly' => true,
        'samesite' => 'Lax',
    ]);
    session_name('SDDEVSESSID');
    session_start();
    // Touch last activity for idle timeout (8h)
    if (isset($_SESSION['last_activity']) && (time() - $_SESSION['last_activity']) > 28800) {
        $_SESSION = [];
        session_destroy();
        session_start();
    }
    $_SESSION['last_activity'] = time();
}

function currentUser(): ?array {
    return $_SESSION['user'] ?? null;
}

function isAdmin(): bool {
    return (currentUser()['role'] ?? null) === 'admin';
}

function isEmployee(): bool {
    return (currentUser()['role'] ?? null) === 'employee';
}

function loginUser(array $userRow): void {
    startSessionOnce();
    session_regenerate_id(true);
    $_SESSION['user'] = [
        'id' => (int)$userRow['id'],
        'username' => $userRow['username'],
        'display_name' => $userRow['display_name'],
        'role' => $userRow['role'],
        'must_change_password' => (bool)$userRow['must_change_password'],
    ];
}

function logoutUser(): void {
    startSessionOnce();
    $_SESSION = [];
    session_destroy();
}

function requireAuth(): array {
    startSessionOnce();
    $u = currentUser();
    if (!$u) {
        if (str_starts_with($_SERVER['REQUEST_URI'] ?? '', '/api')) {
            jsonError('login required', 401);
        }
        header('Location: /login.php');
        exit;
    }
    return $u;
}

function requireAdmin(): array {
    $u = requireAuth();
    if ($u['role'] !== 'admin') {
        jsonError('forbidden', 403);
    }
    return $u;
}

function requireEmployee(): array {
    $u = requireAuth();
    // Both admin and employee allowed on /api/user, gate is "logged in".
    return $u;
}

function requireProjectAccess(int $projectId): array {
    $u = requireAuth();
    if ($u['role'] === 'admin') return $u;
    $db = getDB();
    $st = $db->prepare("SELECT 1 FROM project_access WHERE project_id = ? AND user_id = ?");
    $st->execute([$projectId, $u['id']]);
    if (!$st->fetchColumn()) {
        jsonError('forbidden', 403);
    }
    return $u;
}
