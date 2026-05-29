<?php
declare(strict_types=1);

use Soritune\Developers\Audit;
use Soritune\Developers\Validation;

$op = $_GET['op'] ?? $_POST['op'] ?? '';
if ($_SERVER['REQUEST_METHOD'] === 'POST') requireCsrfOrAbort();

switch ($op) {
    // NOTE: each case ends with `return;` after its terminal jsonSuccess/jsonError.
    // jsonResponse() exits in web mode but only returns in test mode (APP_ENV=test);
    // without `return` the switch would fall through and run later cases' DB writes.
    case 'get': {
        jsonSuccess(['user' => currentUser()]);
        return;
    }
    case 'change_password': {
        $old = $_POST['old_password'] ?? '';
        $new = $_POST['new_password'] ?? '';
        if (!Validation::isStrongPassword($new)) { jsonError('new_password must be 12+ chars with letter+digit'); return; }

        $db = getDB();
        $st = $db->prepare("SELECT password_hash FROM users WHERE id = ?");
        $st->execute([currentUser()['id']]);
        $hash = $st->fetchColumn();
        if (!$hash || !password_verify($old, $hash)) { jsonError('old_password incorrect'); return; }
        $newHash = password_hash($new, PASSWORD_BCRYPT, ['cost' => 10]);
        $db->prepare("UPDATE users SET password_hash = ?, must_change_password = 0 WHERE id = ?")
           ->execute([$newHash, currentUser()['id']]);
        $_SESSION['user']['must_change_password'] = false;
        session_regenerate_id(true); // rotate session id after credential change
        Audit::writeFromRequest(currentUser()['id'], 'user.self_change_password', 'user', currentUser()['id'], []);
        jsonSuccess([], 'changed');
        return;
    }
    case 'set_github_username': {
        $gh = trim((string)($_POST['github_username'] ?? '')) ?: null;
        if ($gh !== null && !\Soritune\Developers\Validation::isValidGithubLogin($gh)) { jsonError('invalid github_username'); return; }
        $db = getDB();
        $db->prepare("UPDATE users SET github_username = ? WHERE id = ?")->execute([$gh, currentUser()['id']]);
        $_SESSION['user']['github_username'] = $gh;
        Audit::writeFromRequest(currentUser()['id'], 'user.self_set_github_username', 'user', currentUser()['id'], ['github_username' => $gh]);
        jsonSuccess([], 'updated');
        return;
    }
    default:
        jsonError("unknown op: $op", 404);
        return;
}
