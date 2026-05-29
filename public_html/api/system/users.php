<?php
declare(strict_types=1);

use Soritune\Developers\Audit;
use Soritune\Developers\Validation;

$op = $_GET['op'] ?? $_POST['op'] ?? '';
$db = getDB();

if ($_SERVER['REQUEST_METHOD'] === 'POST') requireCsrfOrAbort();

switch ($op) {
    // NOTE: each case ends with `return;` after its terminal jsonSuccess/jsonError.
    // jsonResponse() exits in web mode but only returns in test mode (APP_ENV=test);
    // without `return` the switch would fall through and run later cases' DB writes.
    case 'list': {
        $rows = $db->query("SELECT id, username, display_name, role, github_username, active, last_login_at, created_at FROM users ORDER BY id")->fetchAll(PDO::FETCH_ASSOC);
        jsonSuccess(['users' => $rows]);
        return;
    }

    case 'create': {
        $u = trim((string)($_POST['username'] ?? ''));
        $name = trim((string)($_POST['display_name'] ?? ''));
        $role = $_POST['role'] ?? '';
        $pw = $_POST['temp_password'] ?? '';
        $gh = trim((string)($_POST['github_username'] ?? ''));

        if (!Validation::isValidUsername($u)) { jsonError('username must match ^[a-z][a-z0-9_]{2,63}$'); return; }
        if ($name === '') { jsonError('display_name required'); return; }
        if (!in_array($role, ['admin','employee'], true)) { jsonError('role must be admin|employee'); return; }
        if (!Validation::isStrongPassword($pw)) { jsonError('temp_password must be 12+ chars with letter+digit'); return; }
        if ($gh !== '' && !\Soritune\Developers\Validation::isValidGithubLogin($gh)) { jsonError('invalid github_username'); return; }

        try {
            $st = $db->prepare(
                "INSERT INTO users (username, password_hash, display_name, role, github_username, must_change_password, active) VALUES (?, ?, ?, ?, ?, 1, 1)"
            );
            $st->execute([$u, password_hash($pw, PASSWORD_BCRYPT, ['cost' => 10]), $name, $role, $gh ?: null]);
        } catch (PDOException $e) {
            // 1062 = ER_DUP_ENTRY (SQLSTATE 23000). Check the driver error code rather
            // than sniffing the message text, which is locale/version-dependent.
            if (($e->errorInfo[1] ?? null) === 1062) { jsonError('username already exists', 409); return; }
            throw $e;
        }
        $id = (int)$db->lastInsertId();
        Audit::writeFromRequest(currentUser()['id'], 'user.create', 'user', $id, ['username' => $u, 'role' => $role]);
        jsonSuccess(['user' => ['id' => $id, 'username' => $u, 'role' => $role]], 'created');
        return;
    }

    case 'reset_password': {
        $uid = (int)($_POST['user_id'] ?? 0);
        $pw = $_POST['new_password'] ?? '';
        if ($uid <= 0) { jsonError('user_id required'); return; }
        if (!Validation::isStrongPassword($pw)) { jsonError('new_password must be 12+ with letter+digit'); return; }
        $hash = password_hash($pw, PASSWORD_BCRYPT, ['cost' => 10]);
        $db->prepare("UPDATE users SET password_hash = ?, must_change_password = 1, failed_attempts = 0, locked_until = NULL WHERE id = ?")
           ->execute([$hash, $uid]);
        Audit::writeFromRequest(currentUser()['id'], 'user.reset_password', 'user', $uid, []);
        jsonSuccess([], 'reset');
        return;
    }

    case 'set_active': {
        $uid = (int)($_POST['user_id'] ?? 0);
        $active = (int)($_POST['active'] ?? 1);
        if ($uid <= 0) { jsonError('user_id required'); return; }
        // Don't let an admin lock themselves out of the tool.
        if (!$active && $uid === (int)currentUser()['id']) { jsonError('cannot deactivate yourself', 422); return; }
        $db->prepare("UPDATE users SET active = ? WHERE id = ?")->execute([$active, $uid]);
        Audit::writeFromRequest(currentUser()['id'], 'user.set_active', 'user', $uid, ['active' => $active]);
        jsonSuccess([], $active ? 'activated' : 'deactivated');
        return;
    }

    case 'set_github_username': {
        $uid = (int)($_POST['user_id'] ?? 0);
        if ($uid <= 0) { jsonError('user_id required'); return; }
        $gh = trim((string)($_POST['github_username'] ?? '')) ?: null;
        if ($gh !== null && !\Soritune\Developers\Validation::isValidGithubLogin($gh)) { jsonError('invalid github_username'); return; }
        $db->prepare("UPDATE users SET github_username = ? WHERE id = ?")->execute([$gh, $uid]);
        Audit::writeFromRequest(currentUser()['id'], 'user.set_github_username', 'user', $uid, ['github_username' => $gh]);
        jsonSuccess([], 'updated');
        return;
    }

    default:
        jsonError("unknown op: $op", 404);
        return;
}
