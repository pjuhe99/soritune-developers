<?php
declare(strict_types=1);
// Already requireAdmin()'d by api/system.php

$op = $_GET['op'] ?? '';
if ($op === 'me') {
    $u = currentUser();
    jsonSuccess(['user' => $u]);
}
jsonError("unknown op: $op", 404);
