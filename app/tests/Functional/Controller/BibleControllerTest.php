<?php

namespace App\Tests\Functional\Controller;

use App\Entity\User;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;

/**
 * Functional tests for BibleController.
 *
 * These tests boot the full Symfony kernel and require a test database.
 * Run: php bin/phpunit --testsuite Functional
 *
 * Test DB setup:
 *   APP_ENV=test php bin/console doctrine:database:create --if-not-exists
 *   APP_ENV=test php bin/console doctrine:schema:create
 */
class BibleControllerTest extends WebTestCase
{
    // ── Access control ────────────────────────────────────────────────────────

    public function testUnauthenticatedUserIsRedirectedFromBookRoute(): void
    {
        $client = static::createClient();
        $client->request('GET', '/book/GEN/1/1');

        $this->assertResponseRedirects('/login');
    }

    public function testUserWithoutViewerRoleCannotAccessBookRoute(): void
    {
        $client = static::createClient();
        $user   = $this->createUser(['ROLE_USER']);
        $client->loginUser($user);

        $client->request('GET', '/book/GEN');

        // Symfony returns 403 when the user is authenticated but lacks the role
        $this->assertResponseStatusCodeSame(403);
    }

    public function testViewerRoleCanAccessBookIndex(): void
    {
        $client = static::createClient();
        $user   = $this->createUser(['ROLE_VIEWER']);
        $client->loginUser($user);

        $client->request('GET', '/book/GEN');

        // 200 or a redirect to login if DB is empty — both are acceptable;
        // the key assertion is NOT 403.
        $this->assertNotSame(403, $client->getResponse()->getStatusCode());
    }

    public function testLinkerRoleInheritsViewerAndCanAccessBookRoute(): void
    {
        $client = static::createClient();
        $user   = $this->createUser(['ROLE_LINKER']);
        $client->loginUser($user);

        $client->request('GET', '/book/GEN');

        $this->assertNotSame(403, $client->getResponse()->getStatusCode());
    }

    public function testHsvRoleInheritsViewerAndCanAccessBookRoute(): void
    {
        $client = static::createClient();
        $user   = $this->createUser(['ROLE_HSV']);
        $client->loginUser($user);

        $client->request('GET', '/book/GEN');

        $this->assertNotSame(403, $client->getResponse()->getStatusCode());
    }

    // ── Route resolution ──────────────────────────────────────────────────────

    public function testVerseRouteAcceptsDefaultTranslationSv(): void
    {
        $client = static::createClient();
        $user   = $this->createUser(['ROLE_VIEWER']);
        $client->loginUser($user);

        // Route defaults translation to 'SV' when omitted — must not 404
        $client->request('GET', '/book/GEN/1/1');

        $this->assertNotSame(404, $client->getResponse()->getStatusCode());
    }

    public function testVerseRouteWithExplicitTranslation(): void
    {
        $client = static::createClient();
        $user   = $this->createUser(['ROLE_VIEWER']);
        $client->loginUser($user);

        $client->request('GET', '/book/GEN/1/1/SV');

        $this->assertNotSame(404, $client->getResponse()->getStatusCode());
    }

    public function testVerseRouteWithUnknownTranslationReturns404(): void
    {
        $client = static::createClient();
        $user   = $this->createUser(['ROLE_VIEWER']);
        $client->loginUser($user);

        $client->request('GET', '/book/GEN/1/1/UNKNOWN');

        $this->assertResponseStatusCodeSame(404);
    }

    public function testUnknownBookReturns404(): void
    {
        $client = static::createClient();
        $user   = $this->createUser(['ROLE_VIEWER']);
        $client->loginUser($user);

        $client->request('GET', '/book/NOTABOOK');

        $this->assertResponseStatusCodeSame(404);
    }

    // ── Helpers ───────────────────────────────────────────────────────────────

    /**
     * Build an in-memory User without persisting to the DB.
     * WebTestCase's loginUser() accepts any UserInterface.
     */
    private function createUser(array $roles): User
    {
        $user = new User();
        $user->setEmail('test@example.com');
        $user->setDisplayName('Test');
        $user->setRoles($roles);
        $user->setIsVerified(true);

        /** @var UserPasswordHasherInterface $hasher */
        $hasher = static::getContainer()->get(UserPasswordHasherInterface::class);
        $user->setPassword($hasher->hashPassword($user, 'password'));

        return $user;
    }
}
