<?php
declare(strict_types=1);
namespace Soritune\Developers;

final class Validation
{
    // NOTE: patterns end with \z (not $) on purpose. PHP's $ matches before a
    // trailing "\n", so "camp\n" would otherwise pass and could smuggle a payload
    // into a value later used in shell/SQL/filesystem context. \z = true end-of-string.

    public static function isValidSlug(string $s): bool
    {
        // 2–39 chars, lowercase, starts with a letter, kebab-case, no trailing hyphen
        // (slugs become subdomain labels / directory names downstream).
        return (bool)preg_match('/^[a-z][a-z0-9-]{0,37}[a-z0-9]\z/', $s);
    }

    public static function isValidGithubRepo(string $s): bool
    {
        // owner: GitHub-style (alphanumeric + hyphens, no dots/underscores — prevents
        // "../" traversal in the owner segment). repo: may contain . _ - but not start with them.
        return (bool)preg_match('/^[a-zA-Z0-9][a-zA-Z0-9-]{0,38}\/[a-zA-Z0-9][a-zA-Z0-9_.-]{0,99}\z/', $s)
            && substr_count($s, '/') === 1;
    }

    public static function isValidSubdomain(string $s): bool
    {
        // Must be <something>.<something>.<TLD>, all lowercase, allow hyphens within labels
        return (bool)preg_match('/^[a-z0-9]([a-z0-9-]{0,61}[a-z0-9])?(\.[a-z0-9]([a-z0-9-]{0,61}[a-z0-9])?){2,}\z/', $s);
    }

    public static function isValidUsername(string $s): bool
    {
        return (bool)preg_match('/^[a-z][a-z0-9_]{2,63}\z/', $s);
    }

    public static function isStrongPassword(string $s): bool
    {
        if (strlen($s) < 12) return false;
        $hasAlpha = (bool)preg_match('/[A-Za-z]/', $s);
        $hasDigit = (bool)preg_match('/\d/', $s);
        return $hasAlpha && $hasDigit;
    }
}
