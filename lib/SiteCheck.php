<?php
declare(strict_types=1);
namespace Soritune\Developers;

final class SiteCheck
{
    /**
     * HTTP(S) HEAD with a short timeout. Returns {up, code?}.
     * Timeout/DNS failure => up=false (treated as "응답 없음", not a hard "down").
     */
    public static function ping(string $host, int $timeout = 5): array
    {
        $host = trim($host);
        if ($host === '') {
            return ['up' => false];
        }
        $ch = curl_init('https://' . $host . '/');
        curl_setopt_array($ch, [
            CURLOPT_NOBODY => true,            // HEAD
            CURLOPT_FOLLOWLOCATION => false,   // report the raw code (302 etc.)
            CURLOPT_TIMEOUT => $timeout,
            CURLOPT_CONNECTTIMEOUT => $timeout,
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_RETURNTRANSFER => true,
        ]);
        curl_exec($ch);
        $errno = curl_errno($ch);
        $code = (int)curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
        if ($errno !== 0 || $code === 0) {
            return ['up' => false];
        }
        return ['up' => true, 'code' => $code];
    }
}
