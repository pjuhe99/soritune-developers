<?php
declare(strict_types=1);

require_once __DIR__ . '/auth.php';
require_once __DIR__ . '/csrf.php';

/**
 * Attempts a login. Returns ['ok' => bool, 'reason' => ?string, 'user' => ?array].
 * Side-effects: updates failed_attempts/locked_until/last_login_at, calls loginUser() on success.
 */
function attemptLogin(string $username, string $password): array {
    $db = getDB();
    $st = $db->prepare("SELECT * FROM users WHERE username = ? AND active = 1");
    $st->execute([$username]);
    $u = $st->fetch(PDO::FETCH_ASSOC);
    if (!$u) {
        return ['ok' => false, 'reason' => 'invalid', 'user' => null];
    }
    // Locked?
    if ($u['locked_until'] && strtotime($u['locked_until']) > time()) {
        return ['ok' => false, 'reason' => 'locked', 'user' => null];
    }
    if (!password_verify($password, $u['password_hash'])) {
        $newAttempts = (int)$u['failed_attempts'] + 1;
        $lockedUntil = $newAttempts >= 5 ? date('Y-m-d H:i:s', time() + 900) : null;
        $db->prepare("UPDATE users SET failed_attempts = ?, locked_until = ? WHERE id = ?")
           ->execute([$newAttempts, $lockedUntil, $u['id']]);
        return ['ok' => false, 'reason' => $lockedUntil ? 'locked' : 'invalid', 'user' => null];
    }
    // Success
    $db->prepare("UPDATE users SET failed_attempts = 0, locked_until = NULL, last_login_at = NOW() WHERE id = ?")
       ->execute([$u['id']]);
    loginUser($u);
    return ['ok' => true, 'reason' => null, 'user' => $u];
}

// HTTP entry
if (PHP_SAPI !== 'cli' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    startSessionOnce();
    requireCsrfOrAbort();
    $u = $_POST['username'] ?? '';
    $p = $_POST['password'] ?? '';
    if (!is_string($u) || !is_string($p) || $u === '' || $p === '') {
        $err = '아이디·비밀번호를 입력하세요.';
    } else {
        $res = attemptLogin($u, $p);
        if ($res['ok']) {
            $next = !empty($res['user']['must_change_password']) ? '/me.php?force_change=1' : '/';
            header('Location: ' . $next);
            exit;
        }
        $err = match ($res['reason']) {
            'locked' => '로그인 시도가 너무 많습니다. 15분 후 다시 시도하세요.',
            default  => '아이디 또는 비밀번호가 올바르지 않습니다.',
        };
    }
}

if (PHP_SAPI === 'cli') return;

startSessionOnce();
$token = csrfToken();
?>
<!doctype html>
<html lang="ko">
<head>
<meta charset="utf-8">
<title>로그인 — developers.soritune.com</title>
<link rel="stylesheet" href="/assets/style.css">
</head>
<body>
<main class="login-shell">
  <h1>developers.soritune.com</h1>
  <p>로그인하세요.</p>
  <?php if (!empty($err)): ?><div class="error"><?= e($err) ?></div><?php endif; ?>
  <form method="post" autocomplete="off">
    <input type="hidden" name="_csrf" value="<?= e($token) ?>">
    <label>아이디 <input name="username" required></label>
    <label>비밀번호 <input name="password" type="password" required></label>
    <button type="submit">로그인</button>
  </form>
</main>
</body>
</html>
