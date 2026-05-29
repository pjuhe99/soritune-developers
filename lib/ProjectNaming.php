<?php
declare(strict_types=1);
namespace Soritune\Developers;

/** Derives site dir / DB name from a subdomain exactly like site_manager.sh
 *  (DERIVED = tr 'a-z-' 'A-Z_'). Pure, no side effects. */
final class ProjectNaming
{
    public static function fromSubdomain(string $subdomain): array
    {
        $bare = preg_replace('/\.soritune\.com$/', '', trim($subdomain));
        if (!preg_match('/^[a-z0-9]([a-z0-9-]*[a-z0-9])?$/', $bare)) {
            throw new \InvalidArgumentException("invalid subdomain: $subdomain");
        }
        $derived = strtr($bare, 'abcdefghijklmnopqrstuvwxyz-', 'ABCDEFGHIJKLMNOPQRSTUVWXYZ_');
        $siteDir = "/var/www/html/_______site_SORITUNECOM_{$derived}";
        return [
            'bare'     => $bare,
            'derived'  => $derived,
            'site_dir' => $siteDir,
            'code_dir' => "{$siteDir}/public_html",
            'db_name'  => "SORITUNECOM_{$derived}",
        ];
    }
}
