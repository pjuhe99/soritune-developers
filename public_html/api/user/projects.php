<?php
declare(strict_types=1);

$db = getDB();
$uid = currentUser()['id'];
if (currentUser()['role'] === 'admin') {
    $rows = $db->query("SELECT * FROM projects WHERE status='active' ORDER BY name")->fetchAll(PDO::FETCH_ASSOC);
} else {
    $st = $db->prepare("
        SELECT p.* FROM projects p
        INNER JOIN project_access pa ON pa.project_id = p.id
        WHERE pa.user_id = ? AND p.status = 'active'
        ORDER BY p.name
    ");
    $st->execute([$uid]);
    $rows = $st->fetchAll(PDO::FETCH_ASSOC);
}
jsonSuccess(['projects' => $rows]);
