<?php
declare(strict_types=1);
namespace Soritune\Developers;

final class SiteManagerClient
{
    public const SEQUENCE = ['check_conflict','create_folders','create_database','create_vhost_http','issue_ssl','create_vhost_ssl'];
    /** @var callable|null */ private $onEnqueued = null;

    public function __construct(
        private string $pendingDir,
        private string $doneDir,
        private int $timeoutSec = 300,
        private int $pollUs = 2_000_000
    ) {}

    public function setOnEnqueued(?callable $cb): void { $this->onEnqueued = $cb; }

    /** Enqueue one action, poll done until result or timeout. */
    public function runAction(string $action, string $bareSubdomain): array
    {
        $id = 'sm_' . bin2hex(random_bytes(6));
        $job = ['action' => $action, 'subdomain' => $bareSubdomain];
        file_put_contents($this->pendingDir . "/$id.json", json_encode($job, JSON_UNESCAPED_SLASHES));
        if ($this->onEnqueued !== null) { ($this->onEnqueued)(); }

        $deadline = time() + $this->timeoutSec;
        $donePath = $this->doneDir . "/$id.json";
        while (time() <= $deadline) {
            if (is_file($donePath)) { return $this->parseDone($donePath); }
            if ($this->pollUs > 0) usleep($this->pollUs); else break;
        }
        if (is_file($donePath)) { return $this->parseDone($donePath); }
        return ['ok'=>false,'error'=>'timeout waiting for site_manager'];
    }

    private function parseDone(string $donePath): array
    {
        $res = json_decode((string)file_get_contents($donePath), true) ?: [];
        if (($res['success'] ?? false) === true) return ['ok'=>true,'result'=>$res];
        return ['ok'=>false,'error'=>$res['error'] ?? 'unknown','result'=>$res,'exists'=>$res['exists'] ?? false];
    }

    /** Full provision sequence for one site. If check_conflict says it already exists,
     *  treat the site as already provisioned (idempotent rerun) and skip. */
    public function provision(string $bareSubdomain): array
    {
        $first = $this->runAction('check_conflict', $bareSubdomain);
        if (!$first['ok']) {
            if (($first['exists'] ?? false) === true || str_contains((string)($first['error'] ?? ''), 'already exists')) {
                return ['ok'=>true,'skipped'=>true];
            }
            return ['ok'=>false,'step'=>'check_conflict','error'=>$first['error']];
        }
        foreach (['create_folders','create_database','create_vhost_http','issue_ssl','create_vhost_ssl'] as $action) {
            $r = $this->runAction($action, $bareSubdomain);
            if (!$r['ok']) return ['ok'=>false,'step'=>$action,'error'=>$r['error']];
        }
        return ['ok'=>true,'skipped'=>false];
    }
}
