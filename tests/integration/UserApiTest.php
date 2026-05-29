<?php
declare(strict_types=1);
namespace Soritune\Developers\Tests\Integration;

use PHPUnit\Framework\TestCase;

final class UserApiTest extends TestCase
{
    public function testUnauthenticatedRequestRejected(): void
    {
        // Direct curl to the router endpoint (assumes vhost up)
        $url = 'https://developers.soritune.com/api/system.php?action=auth&op=me';
        $ctx = stream_context_create(['http' => ['ignore_errors' => true, 'header' => 'Accept: application/json']]);
        $resp = file_get_contents($url, false, $ctx);
        $code = (int)preg_replace('/^HTTP\/[\d.]+ (\d+).*/', '$1', $http_response_header[0] ?? 'HTTP/1.1 500');
        $this->assertContains($code, [401, 302, 303], "Expected redirect/401, got $code");
    }
}
