<?php
declare(strict_types=1);
namespace Soritune\Developers\Tests\Unit;

use PHPUnit\Framework\TestCase;
use Soritune\Developers\Validation;

final class ValidationTest extends TestCase
{
    public function testSlugAccepts(): void
    {
        $this->assertTrue(Validation::isValidSlug('camp'));
        $this->assertTrue(Validation::isValidSlug('camp-app'));
        $this->assertTrue(Validation::isValidSlug('a' . str_repeat('b', 38)));
    }

    public function testSlugRejects(): void
    {
        $this->assertFalse(Validation::isValidSlug(''));
        $this->assertFalse(Validation::isValidSlug('Camp'));         // uppercase
        $this->assertFalse(Validation::isValidSlug('1camp'));        // leading digit
        $this->assertFalse(Validation::isValidSlug('-camp'));        // leading hyphen
        $this->assertFalse(Validation::isValidSlug('camp-'));        // trailing hyphen
        $this->assertFalse(Validation::isValidSlug('a'));            // too short (min 2)
        $this->assertFalse(Validation::isValidSlug('ca/mp'));        // slash
        $this->assertFalse(Validation::isValidSlug(str_repeat('a', 40))); // too long
        $this->assertFalse(Validation::isValidSlug("camp\n"));       // trailing newline bypass
    }

    public function testGithubLogin(): void
    {
        // valid
        $this->assertTrue(Validation::isValidGithubLogin('alice'));
        $this->assertTrue(Validation::isValidGithubLogin('a-b-c'));
        $this->assertTrue(Validation::isValidGithubLogin('Octocat99'));
        // invalid
        $this->assertFalse(Validation::isValidGithubLogin(''));           // empty
        $this->assertFalse(Validation::isValidGithubLogin('-bad'));       // leading hyphen
        $this->assertFalse(Validation::isValidGithubLogin('bad-'));       // trailing hyphen
        $this->assertFalse(Validation::isValidGithubLogin('a/../b'));     // path traversal
        $this->assertFalse(Validation::isValidGithubLogin('a b'));        // space
        $this->assertFalse(Validation::isValidGithubLogin('a--b'));       // double hyphen (lookahead fails)
        $this->assertFalse(Validation::isValidGithubLogin(str_repeat('a', 40))); // too long (>39)
    }

    public function testGithubRepo(): void
    {
        $this->assertTrue(Validation::isValidGithubRepo('pjuhe99/soritune-camp'));
        $this->assertTrue(Validation::isValidGithubRepo('org/repo.js'));
        $this->assertFalse(Validation::isValidGithubRepo('pjuhe99'));
        $this->assertFalse(Validation::isValidGithubRepo('a/b/c'));
        $this->assertFalse(Validation::isValidGithubRepo('a../b'));  // path traversal in owner
        $this->assertFalse(Validation::isValidGithubRepo('org/repo' . "\n")); // newline bypass
        $this->assertFalse(Validation::isValidGithubRepo(''));
    }

    public function testSubdomain(): void
    {
        $this->assertTrue(Validation::isValidSubdomain('camp.soritune.com'));
        $this->assertTrue(Validation::isValidSubdomain('camp-dev.soritune.com'));
        $this->assertFalse(Validation::isValidSubdomain('CAMP.soritune.com'));
        $this->assertFalse(Validation::isValidSubdomain('soritune.com'));      // bare
        $this->assertFalse(Validation::isValidSubdomain("camp.soritune.com\n")); // newline bypass
    }

    public function testUsername(): void
    {
        $this->assertTrue(Validation::isValidUsername('abc'));
        $this->assertTrue(Validation::isValidUsername('a' . str_repeat('b', 63)));
        $this->assertFalse(Validation::isValidUsername(''));
        $this->assertFalse(Validation::isValidUsername('ab'));            // too short
        $this->assertFalse(Validation::isValidUsername('Abc'));           // uppercase
        $this->assertFalse(Validation::isValidUsername('1abc'));          // leading digit
        $this->assertFalse(Validation::isValidUsername('a' . str_repeat('b', 64))); // too long
        $this->assertFalse(Validation::isValidUsername("abc\n"));         // newline bypass
    }

    public function testStrongPassword(): void
    {
        $this->assertTrue(Validation::isStrongPassword('password1234'));
        $this->assertFalse(Validation::isStrongPassword('password'));       // no digit
        $this->assertFalse(Validation::isStrongPassword('123456789012'));   // no alpha
        $this->assertFalse(Validation::isStrongPassword('Abc12'));          // too short
    }
}
