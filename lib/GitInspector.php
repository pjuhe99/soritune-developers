<?php
declare(strict_types=1);
namespace Soritune\Developers;

/**
 * Read-only git inspection. NEVER runs a writing subcommand
 * (no pull/fetch/merge/checkout/reset/push). All calls go through run()
 * which hardcodes `-c safe.directory=<dir>` to avoid dubious-ownership errors.
 */
final class GitInspector
{
    /** Run a read-only git subcommand in $dir; returns trimmed stdout or null on failure. */
    private static function run(string $dir, array $args): ?string
    {
        $cmd = 'git -C ' . escapeshellarg($dir)
             . ' -c safe.directory=' . escapeshellarg($dir);
        foreach ($args as $a) {
            $cmd .= ' ' . escapeshellarg($a);
        }
        $cmd .= ' 2>/dev/null';
        $out = shell_exec($cmd);
        return $out === null ? null : trim($out);
    }

    public static function inspect(string $dir): array
    {
        if (!is_dir($dir)) {
            return ['ok' => false, 'error' => '경로 없음'];
        }
        if (!is_dir($dir . '/.git')) {
            return ['ok' => false, 'error' => 'git 저장소 아님'];
        }
        $head = self::run($dir, ['rev-parse', '--short', 'HEAD']);
        if ($head === null || $head === '') {
            return ['ok' => false, 'error' => 'git 읽기 실패'];
        }
        $branch  = self::run($dir, ['rev-parse', '--abbrev-ref', 'HEAD']) ?? '';
        $top = self::run($dir, ['log', '-1', '--pretty=%s%x1f%an%x1f%cI']) ?? '';
        [$subject, $author, $date] = array_pad(explode("\x1f", $top), 3, '');
        $log = [];
        $raw = self::run($dir, ['log', '-10', '--pretty=%h%x1f%s%x1f%cI']) ?? '';
        foreach (array_filter(explode("\n", $raw)) as $line) {
            [$sha, $sub, $d] = array_pad(explode("\x1f", $line), 3, '');
            $log[] = ['sha' => $sha, 'subject' => $sub, 'date' => $d];
        }
        return [
            'ok' => true, 'head' => $head, 'branch' => $branch,
            'subject' => $subject, 'author' => $author, 'date' => $date, 'log' => $log,
        ];
    }

    /** Commits in $head not yet in $base (e.g. base=main, head=dev = "미배포"). */
    public static function countAhead(string $dir, string $base, string $head): array
    {
        if (!is_dir($dir . '/.git')) {
            return ['ok' => false, 'error' => 'git 저장소 아님'];
        }
        $baseRef = $base;
        $baseChk = self::run($dir, ['rev-parse', '--verify', '--quiet', $base]);
        if ($baseChk === null || $baseChk === '') {
            $alt = 'origin/' . $base;
            $ok = self::run($dir, ['rev-parse', '--verify', '--quiet', $alt]);
            if ($ok === null || $ok === '') {
                return ['ok' => false, 'error' => '비교 불가'];
            }
            $baseRef = $alt;
        }
        $headOk = self::run($dir, ['rev-parse', '--verify', '--quiet', $head]);
        if ($headOk === null || $headOk === '') {
            return ['ok' => false, 'error' => '비교 불가'];
        }
        $range = $baseRef . '..' . $head;
        $count = self::run($dir, ['rev-list', '--count', $range]);
        if ($count === null) {
            return ['ok' => false, 'error' => '비교 불가'];
        }
        $commits = [];
        $raw = self::run($dir, ['log', '--pretty=%h%x1f%s', $range]) ?? '';
        foreach (array_filter(explode("\n", $raw)) as $line) {
            [$sha, $sub] = array_pad(explode("\x1f", $line), 2, '');
            $commits[] = ['sha' => $sha, 'subject' => $sub];
        }
        return ['ok' => true, 'count' => (int)$count, 'commits' => $commits];
    }
}
