<?php
declare(strict_types=1);
namespace Soritune\Developers;

final class GithubAdmin
{
    public function __construct(
        private string $token,
        private string $account,
        private string $accountType,   // 'user' | 'org'
        private CliRunner $runner
    ) {}

    /** gh api wrapper. PAT passed via env (never in argv). $args already shell-safe. */
    private function gh(string $args): array
    {
        $env = 'GH_TOKEN=' . escapeshellarg($this->token);
        return $this->runner->run("$env gh api $args 2>&1");
    }

    public function createRepo(string $slug, string $description): array
    {
        $slug = trim($slug);
        $endpoint = $this->accountType === 'org'
            ? '/orgs/' . escapeshellarg($this->account) . '/repos'
            : '/user/repos';
        $r = $this->gh("-X POST $endpoint "
            . '-f name=' . escapeshellarg($slug) . ' '
            . '-f description=' . escapeshellarg($description) . ' '
            . '-F private=true -F auto_init=true');
        if ($r['code'] === 0) {
            $j = json_decode($r['out'], true) ?: [];
            return ['ok'=>true,'created'=>true,'full_name'=>$j['full_name'] ?? "{$this->account}/$slug",
                    'repo_url'=>$j['html_url'] ?? ''];
        }
        // exists? recover via GET, only if it's OUR account's repo
        $g = $this->gh('-X GET /repos/' . rawurlencode($this->account) . '/' . rawurlencode($slug));
        if ($g['code'] === 0) {
            $j = json_decode($g['out'], true) ?: [];
            $owner = $j['owner']['login'] ?? '';
            if ($owner === $this->account) {
                return ['ok'=>true,'created'=>false,'full_name'=>$j['full_name'],'repo_url'=>$j['html_url'] ?? ''];
            }
            return ['ok'=>false,'error'=>'repo name taken by other owner'];
        }
        return ['ok'=>false,'error'=>'createRepo failed: ' . trim($r['out'])];
    }

    public function createDevBranch(string $fullName): array
    {
        $sha = $this->gh("-X GET /repos/$fullName/git/ref/heads/main --jq .object.sha");
        if ($sha['code'] !== 0) return ['ok'=>false,'error'=>'main ref not found'];
        $main = trim($sha['out']);
        $r = $this->gh("-X POST /repos/$fullName/git/refs -f ref=refs/heads/dev -f sha=" . escapeshellarg($main));
        if ($r['code'] === 0) return ['ok'=>true,'existed'=>false];
        if (str_contains($r['out'], 'already exists') || str_contains($r['out'], '422')) return ['ok'=>true,'existed'=>true];
        return ['ok'=>false,'error'=>'createDevBranch failed: ' . trim($r['out'])];
    }

    /** 2 rulesets: main protected + dev protected. NO ref-name restriction (allows feature branches). */
    public function addRulesets(string $fullName): array
    {
        $ids = [];
        foreach (['main','dev'] as $branch) {
            $rules = $branch === 'main'
                ? [['type'=>'deletion'],['type'=>'non_fast_forward'],['type'=>'required_linear_history']]
                : [['type'=>'deletion'],['type'=>'non_fast_forward']];
            $payload = json_encode([
                'name' => "protect-$branch",
                'target' => 'branch',
                'enforcement' => 'active',
                'conditions' => ['ref_name' => ['include' => ["refs/heads/$branch"], 'exclude' => []]],
                'rules' => $rules,
            ], JSON_UNESCAPED_SLASHES);
            $env = 'GH_TOKEN=' . escapeshellarg($this->token);
            $cmd = 'printf %s ' . escapeshellarg($payload)
                 . " | $env gh api -X POST /repos/$fullName/rulesets --input - 2>&1";
            $r = $this->runner->run($cmd);
            if ($r['code'] !== 0) {
                return ['ok'=>false,'error'=>"ruleset $branch failed: " . trim($r['out']), 'ruleset_ids'=>$ids];
            }
            $j = json_decode($r['out'], true) ?: [];
            $ids[] = $j['id'] ?? null;
        }
        return ['ok'=>true,'ruleset_ids'=>$ids];
    }

    public function addCollaborators(string $fullName, array $usernames, string $role = 'push'): array
    {
        $added = []; $skipped = [];
        foreach ($usernames as $u) {
            if (trim((string)$u) === '') { $skipped[] = $u; continue; }
            $r = $this->gh("-X PUT /repos/$fullName/collaborators/" . escapeshellarg($u)
                . ' -f permission=' . escapeshellarg($role));
            if ($r['code'] === 0) $added[] = $u; else $skipped[] = $u;
        }
        return ['ok'=>true,'added'=>$added,'skipped'=>$skipped];
    }
}
