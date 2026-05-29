<?php
declare(strict_types=1);

use Soritune\Developers\Audit;
use Soritune\Developers\Validation;

$op = $_GET['op'] ?? $_POST['op'] ?? '';
$db = getDB();

if ($_SERVER['REQUEST_METHOD'] === 'POST') requireCsrfOrAbort();

// NOTE: each case ends with `return;` after its terminal jsonSuccess/jsonError.
// jsonResponse() exits in web mode but only returns in test mode (APP_ENV=test);
// without `return` the switch would fall through and run later cases' DB writes.
switch ($op) {
    case 'list': {
        $rows = $db->query("SELECT * FROM projects ORDER BY name")->fetchAll(PDO::FETCH_ASSOC);
        jsonSuccess(['projects' => $rows]);
        return;
    }

    case 'get': {
        $id = (int)($_GET['id'] ?? 0);
        $st = $db->prepare("SELECT * FROM projects WHERE id = ?");
        $st->execute([$id]);
        $p = $st->fetch(PDO::FETCH_ASSOC);
        if (!$p) { jsonError('not found', 404); return; }

        $am = $db->prepare("SELECT u.id, u.username, u.display_name FROM users u INNER JOIN project_access pa ON pa.user_id = u.id WHERE pa.project_id = ? ORDER BY u.display_name");
        $am->execute([$id]);
        jsonSuccess(['project' => $p, 'members' => $am->fetchAll(PDO::FETCH_ASSOC)]);
        return;
    }

    case 'register': {
        $slug = trim((string)($_POST['slug'] ?? ''));
        $name = trim((string)($_POST['name'] ?? ''));
        $repo = trim((string)($_POST['github_repo'] ?? ''));
        $devSub = trim((string)($_POST['dev_subdomain'] ?? ''));
        $prodSub = trim((string)($_POST['prod_subdomain'] ?? ''));
        $devDir = trim((string)($_POST['dev_dir'] ?? ''));
        $prodDir = trim((string)($_POST['prod_dir'] ?? ''));
        $devDb = trim((string)($_POST['dev_db_name'] ?? ''));
        $prodDb = trim((string)($_POST['prod_db_name'] ?? ''));
        $desc = trim((string)($_POST['description'] ?? '')) ?: null;
        $tint = $_POST['card_tint'] ?? 'peach';

        if (!Validation::isValidSlug($slug)) { jsonError('slug must match ^[a-z][a-z0-9-]{1,38}$'); return; }
        if ($name === '') { jsonError('name required'); return; }
        if (!Validation::isValidGithubRepo($repo)) { jsonError('github_repo must be "org/name"'); return; }
        if (!Validation::isValidSubdomain($devSub)) { jsonError('dev_subdomain invalid'); return; }
        if (!Validation::isValidSubdomain($prodSub)) { jsonError('prod_subdomain invalid'); return; }
        if ($devDir === '' || $prodDir === '') { jsonError('dirs required'); return; }
        if ($devDb === '' || $prodDb === '') { jsonError('db names required'); return; }
        if (!in_array($tint, ['peach','rose','mint','lavender','sky','yellow'], true)) { jsonError('card_tint invalid'); return; }

        try {
            $st = $db->prepare(
                "INSERT INTO projects (slug, name, description, github_repo, dev_subdomain, prod_subdomain, dev_dir, prod_dir, dev_db_name, prod_db_name, card_tint, status, created_by)
                 VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 'active', ?)"
            );
            $st->execute([$slug, $name, $desc, $repo, $devSub, $prodSub, $devDir, $prodDir, $devDb, $prodDb, $tint, currentUser()['id']]);
        } catch (PDOException $e) {
            // 1062 = ER_DUP_ENTRY (slug UNIQUE). Check driver code, not message text.
            if (($e->errorInfo[1] ?? null) === 1062) { jsonError('slug already exists', 409); return; }
            throw $e;
        }
        $id = (int)$db->lastInsertId();
        Audit::writeFromRequest(currentUser()['id'], 'project.register', 'project', $id, ['slug' => $slug]);
        jsonSuccess(['project' => ['id' => $id, 'slug' => $slug, 'name' => $name]], 'registered');
        return;
    }

    case 'grant_access': {
        $pid = (int)($_POST['project_id'] ?? 0);
        $uid = (int)($_POST['user_id'] ?? 0);
        if ($pid <= 0 || $uid <= 0) { jsonError('project_id and user_id required'); return; }
        // INSERT IGNORE would swallow FK violations and report a false 'granted';
        // verify both rows exist first so a bad id returns 404 instead.
        $chk = $db->prepare("SELECT (SELECT COUNT(*) FROM projects WHERE id = ?) + (SELECT COUNT(*) FROM users WHERE id = ?)");
        $chk->execute([$pid, $uid]);
        if ((int)$chk->fetchColumn() !== 2) { jsonError('project or user not found', 404); return; }
        $db->prepare("INSERT IGNORE INTO project_access (project_id, user_id, granted_by) VALUES (?, ?, ?)")
           ->execute([$pid, $uid, currentUser()['id']]);
        Audit::writeFromRequest(currentUser()['id'], 'project.grant_access', 'project', $pid, ['user_id' => $uid]);
        jsonSuccess([], 'granted');
        return;
    }

    case 'revoke_access': {
        $pid = (int)($_POST['project_id'] ?? 0);
        $uid = (int)($_POST['user_id'] ?? 0);
        if ($pid <= 0 || $uid <= 0) { jsonError('project_id and user_id required'); return; }
        $db->prepare("DELETE FROM project_access WHERE project_id = ? AND user_id = ?")->execute([$pid, $uid]);
        Audit::writeFromRequest(currentUser()['id'], 'project.revoke_access', 'project', $pid, ['user_id' => $uid]);
        jsonSuccess([], 'revoked');
        return;
    }

    case 'archive': {
        $pid = (int)($_POST['project_id'] ?? 0);
        if ($pid <= 0) { jsonError('project_id required'); return; }
        $st = $db->prepare("UPDATE projects SET status = 'archived' WHERE id = ?");
        $st->execute([$pid]);
        if ($st->rowCount() === 0) { jsonError('project not found', 404); return; }
        Audit::writeFromRequest(currentUser()['id'], 'project.archive', 'project', $pid, []);
        jsonSuccess([], 'archived');
        return;
    }

    default:
        jsonError("unknown op: $op", 404);
        return;
}
