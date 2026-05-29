<?php
declare(strict_types=1);
namespace Soritune\Developers;

final class Route53
{
    public function __construct(private string $ip, private CliRunner $runner) {}

    public function upsertA(string $fqdn, int $ttl = 300): array
    {
        $fqdn = rtrim(trim($fqdn), '.');
        $zr = $this->runner->run('aws route53 list-hosted-zones --output json 2>&1');
        if ($zr['code'] !== 0) return ['ok'=>false,'error'=>'list-hosted-zones failed'];
        $zones = json_decode($zr['out'], true)['HostedZones'] ?? [];
        $zoneId = null;
        foreach ($zones as $z) {
            if (rtrim($z['Name'], '.') === 'soritune.com') { $zoneId = $z['Id']; break; }
        }
        if ($zoneId === null) return ['ok'=>false,'error'=>'soritune.com zone not found'];

        $batch = json_encode([
            'Changes' => [[
                'Action' => 'UPSERT',
                'ResourceRecordSet' => [
                    'Name' => $fqdn . '.', 'Type' => 'A', 'TTL' => $ttl,
                    'ResourceRecords' => [['Value' => $this->ip]],
                ],
            ]],
        ], JSON_UNESCAPED_SLASHES);

        $cmd = 'aws route53 change-resource-record-sets '
             . '--hosted-zone-id ' . escapeshellarg($zoneId) . ' '
             . '--change-batch ' . escapeshellarg($batch) . ' --output json 2>&1';
        $r = $this->runner->run($cmd);
        if ($r['code'] !== 0) return ['ok'=>false,'error'=>'change failed: ' . trim($r['out'])];
        return ['ok'=>true,'fqdn'=>$fqdn];
    }
}
