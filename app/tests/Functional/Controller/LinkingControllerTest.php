<?php

namespace App\Tests\Functional\Controller;

use App\Entity\Translation;
use App\Entity\User;
use App\Repository\LinkingRepository;
use App\Repository\TranslationRepository;
use PHPUnit\Framework\MockObject\MockObject;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;

/**
 * Functional tests for LinkingController.
 *
 * Covers role-based access control for both the page routes and the
 * save/delete JSON API endpoints.
 *
 * The Doctrine repositories that would require a live database are replaced
 * with PHPUnit mocks via the Symfony test container.
 *
 * Run:  php bin/phpunit --testsuite Functional
 */
class LinkingControllerTest extends WebTestCase
{
    // ── Page-level access control ─────────────────────────────────────────────

    public function testUnauthenticatedUserIsRedirectedFromLinkRoute(): void
    {
        $client = static::createClient();
        $client->request('GET', '/link');

        $this->assertResponseRedirects('/login');
    }

    public function testUserWithoutLinkerRoleCannotAccessLinkRoute(): void
    {
        $client = static::createClient();
        $client->loginUser($this->createUser(['ROLE_USER']));

        $client->request('GET', '/link');

        // Symfony returns 403 for authenticated users lacking the required role
        $this->assertResponseStatusCodeSame(403);
    }

    public function testLinkerRoleCanAccessLinkHome(): void
    {
        $client = static::createClient();
        $client->loginUser($this->createUser(['ROLE_LINKER']));

        $client->request('GET', '/link');

        // 200 or a possible DB error, but definitely not 403
        $this->assertNotSame(403, $client->getResponse()->getStatusCode());
    }

    public function testHsvRoleInheritsLinkerAndCanAccessLinkRoute(): void
    {
        $client = static::createClient();
        $client->loginUser($this->createUser(['ROLE_HSV']));

        $client->request('GET', '/link');

        $this->assertNotSame(403, $client->getResponse()->getStatusCode());
    }

    // ── /link/api/save — authentication ──────────────────────────────────────

    public function testSaveApiRedirectsUnauthenticatedRequest(): void
    {
        $client = static::createClient();
        $client->request('POST', '/link/api/save', [], [], ['CONTENT_TYPE' => 'application/json'], '{}');

        $this->assertResponseRedirects('/login');
    }

    // ── /link/api/save — ROLE_LINKER with SV translation ─────────────────────

    public function testSaveApiWithSvTranslationAndLinkerRoleReturns200(): void
    {
        $client = static::createClient();
        $client->loginUser($this->createUser(['ROLE_LINKER']));

        [$transRepo, $linkRepo] = $this->mockRepositories(svTranslationId: 1);
        $linkRepo->expects($this->once())->method('saveManualLinks');

        $this->injectMocks($transRepo, $linkRepo);

        $client->request(
            'POST',
            '/link/api/save',
            [], [],
            ['CONTENT_TYPE' => 'application/json'],
            json_encode(['lang' => 'HE', 'source_word_id' => 5, 'tw_ids' => [10], 'translation_id' => 1])
        );

        $this->assertResponseIsSuccessful();
        $body = json_decode($client->getResponse()->getContent(), true);
        $this->assertTrue($body['success']);
    }

    // ── /link/api/save — HSV translation role check ───────────────────────────

    public function testSaveApiWithHsvTranslationWithoutHsvRoleReturns403(): void
    {
        $client = static::createClient();
        $client->loginUser($this->createUser(['ROLE_LINKER']));

        [$transRepo, $linkRepo] = $this->mockRepositories(hsvTranslationId: 2);
        $this->injectMocks($transRepo, $linkRepo);

        $client->request(
            'POST',
            '/link/api/save',
            [], [],
            ['CONTENT_TYPE' => 'application/json'],
            json_encode(['lang' => 'HE', 'source_word_id' => 5, 'tw_ids' => [10], 'translation_id' => 2])
        );

        $this->assertResponseStatusCodeSame(403);
    }

    public function testSaveApiWithHsvTranslationWithHsvRoleReturns200(): void
    {
        $client = static::createClient();
        $client->loginUser($this->createUser(['ROLE_HSV']));

        [$transRepo, $linkRepo] = $this->mockRepositories(hsvTranslationId: 2);
        $linkRepo->expects($this->once())->method('saveManualLinks');

        $this->injectMocks($transRepo, $linkRepo);

        $client->request(
            'POST',
            '/link/api/save',
            [], [],
            ['CONTENT_TYPE' => 'application/json'],
            json_encode(['lang' => 'HE', 'source_word_id' => 5, 'tw_ids' => [10], 'translation_id' => 2])
        );

        $this->assertResponseIsSuccessful();
        $body = json_decode($client->getResponse()->getContent(), true);
        $this->assertTrue($body['success']);
    }

    public function testSaveApiWithHsvTranslationWithAdminRoleReturns200(): void
    {
        $client = static::createClient();
        $client->loginUser($this->createUser(['ROLE_ADMIN']));

        [$transRepo, $linkRepo] = $this->mockRepositories(hsvTranslationId: 2);
        $linkRepo->expects($this->once())->method('saveManualLinks');

        $this->injectMocks($transRepo, $linkRepo);

        $client->request(
            'POST',
            '/link/api/save',
            [], [],
            ['CONTENT_TYPE' => 'application/json'],
            json_encode(['lang' => 'HE', 'source_word_id' => 5, 'tw_ids' => [10], 'translation_id' => 2])
        );

        $this->assertResponseIsSuccessful();
    }

    // ── /link/api/save — validation ───────────────────────────────────────────

    public function testSaveApiWithMissingLangReturns400(): void
    {
        $client = static::createClient();
        $client->loginUser($this->createUser(['ROLE_LINKER']));

        [$transRepo, $linkRepo] = $this->mockRepositories();
        $this->injectMocks($transRepo, $linkRepo);

        $client->request(
            'POST',
            '/link/api/save',
            [], [],
            ['CONTENT_TYPE' => 'application/json'],
            json_encode(['source_word_id' => 5, 'tw_ids' => [], 'translation_id' => 1])
        );

        $this->assertResponseStatusCodeSame(400);
    }

    public function testSaveApiWithInvalidLangReturns400(): void
    {
        $client = static::createClient();
        $client->loginUser($this->createUser(['ROLE_LINKER']));

        [$transRepo, $linkRepo] = $this->mockRepositories();
        $this->injectMocks($transRepo, $linkRepo);

        $client->request(
            'POST',
            '/link/api/save',
            [], [],
            ['CONTENT_TYPE' => 'application/json'],
            json_encode(['lang' => 'XX', 'source_word_id' => 5, 'tw_ids' => [], 'translation_id' => 1])
        );

        $this->assertResponseStatusCodeSame(400);
    }

    public function testSaveApiWithZeroSourceWordIdReturns400(): void
    {
        $client = static::createClient();
        $client->loginUser($this->createUser(['ROLE_LINKER']));

        [$transRepo, $linkRepo] = $this->mockRepositories();
        $this->injectMocks($transRepo, $linkRepo);

        $client->request(
            'POST',
            '/link/api/save',
            [], [],
            ['CONTENT_TYPE' => 'application/json'],
            json_encode(['lang' => 'HE', 'source_word_id' => 0, 'tw_ids' => [], 'translation_id' => 1])
        );

        $this->assertResponseStatusCodeSame(400);
    }

    // ── /link/api/save — empty tw_ids (intentional "no link") ─────────────────

    public function testSaveApiWithEmptyTwIdsReturns200WithEmptyFlag(): void
    {
        $client = static::createClient();
        $client->loginUser($this->createUser(['ROLE_LINKER']));

        [$transRepo, $linkRepo] = $this->mockRepositories(svTranslationId: 1);
        $linkRepo->expects($this->once())->method('saveManualLinks')
            ->with('HE', 5, [], 1);

        $this->injectMocks($transRepo, $linkRepo);

        $client->request(
            'POST',
            '/link/api/save',
            [], [],
            ['CONTENT_TYPE' => 'application/json'],
            json_encode(['lang' => 'HE', 'source_word_id' => 5, 'tw_ids' => [], 'translation_id' => 1])
        );

        $this->assertResponseIsSuccessful();
        $body = json_decode($client->getResponse()->getContent(), true);
        $this->assertTrue($body['empty']);
    }

    // ── /link/api/delete — authentication ────────────────────────────────────

    public function testDeleteApiRedirectsUnauthenticatedRequest(): void
    {
        $client = static::createClient();
        $client->request('DELETE', '/link/api/delete/99');

        $this->assertResponseRedirects('/login');
    }

    // ── /link/api/delete — HSV link role check ────────────────────────────────

    public function testDeleteApiWithHsvLinkWithoutHsvRoleReturns403(): void
    {
        $client = static::createClient();
        $client->loginUser($this->createUser(['ROLE_LINKER']));

        [$transRepo, $linkRepo] = $this->mockRepositories();
        $linkRepo->method('findTranslationCodeByLinkId')->with(13)->willReturn('HSV');

        $this->injectMocks($transRepo, $linkRepo);

        $client->request('DELETE', '/link/api/delete/13');

        $this->assertResponseStatusCodeSame(403);
    }

    public function testDeleteApiWithHsvLinkWithHsvRoleSucceeds(): void
    {
        $client = static::createClient();
        $client->loginUser($this->createUser(['ROLE_HSV']));

        [$transRepo, $linkRepo] = $this->mockRepositories();
        $linkRepo->method('findTranslationCodeByLinkId')->with(13)->willReturn('HSV');
        $linkRepo->expects($this->once())->method('deleteLink')->with(13);

        $this->injectMocks($transRepo, $linkRepo);

        $client->request('DELETE', '/link/api/delete/13');

        $this->assertResponseIsSuccessful();
        $body = json_decode($client->getResponse()->getContent(), true);
        $this->assertTrue($body['success']);
    }

    public function testDeleteApiWithSvLinkWithLinkerRoleSucceeds(): void
    {
        $client = static::createClient();
        $client->loginUser($this->createUser(['ROLE_LINKER']));

        [$transRepo, $linkRepo] = $this->mockRepositories();
        $linkRepo->method('findTranslationCodeByLinkId')->with(7)->willReturn('SV');
        $linkRepo->expects($this->once())->method('deleteLink')->with(7);

        $this->injectMocks($transRepo, $linkRepo);

        $client->request('DELETE', '/link/api/delete/7');

        $this->assertResponseIsSuccessful();
        $body = json_decode($client->getResponse()->getContent(), true);
        $this->assertTrue($body['success']);
    }

    public function testDeleteApiWithAdminRoleCanDeleteHsvLink(): void
    {
        $client = static::createClient();
        $client->loginUser($this->createUser(['ROLE_ADMIN']));

        [$transRepo, $linkRepo] = $this->mockRepositories();
        $linkRepo->method('findTranslationCodeByLinkId')->with(20)->willReturn('HSV');
        $linkRepo->expects($this->once())->method('deleteLink')->with(20);

        $this->injectMocks($transRepo, $linkRepo);

        $client->request('DELETE', '/link/api/delete/20');

        $this->assertResponseIsSuccessful();
    }

    // ── When link is not found (null code), delete proceeds without role check ─

    public function testDeleteApiWithUnknownLinkIdProceedsWithoutRoleCheck(): void
    {
        $client = static::createClient();
        $client->loginUser($this->createUser(['ROLE_LINKER']));

        [$transRepo, $linkRepo] = $this->mockRepositories();
        // findTranslationCodeByLinkId returns null — no HSV gate triggered
        $linkRepo->method('findTranslationCodeByLinkId')->willReturn(null);
        $linkRepo->expects($this->once())->method('deleteLink');

        $this->injectMocks($transRepo, $linkRepo);

        $client->request('DELETE', '/link/api/delete/999');

        $this->assertResponseIsSuccessful();
    }

    // ── Helpers ───────────────────────────────────────────────────────────────

    /**
     * Build an in-memory User without persisting to the database.
     * Symfony's loginUser() accepts any UserInterface.
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

    /**
     * Build a Translation mock that returns the given code.
     */
    private function makeTranslationMock(string $code): Translation&MockObject
    {
        $t = $this->createMock(Translation::class);
        $t->method('getCode')->willReturn($code);
        return $t;
    }

    /**
     * Create mocks for TranslationRepository and LinkingRepository.
     *
     * Pass svTranslationId / hsvTranslationId to configure what find() returns
     * for those IDs. Any other ID returns null by default.
     *
     * @return array{TranslationRepository&MockObject, LinkingRepository&MockObject}
     */
    private function mockRepositories(
        ?int $svTranslationId = null,
        ?int $hsvTranslationId = null,
    ): array {
        $transRepo = $this->createMock(TranslationRepository::class);

        if ($svTranslationId !== null || $hsvTranslationId !== null) {
            $transRepo->method('find')->willReturnCallback(
                function (int $id) use ($svTranslationId, $hsvTranslationId): ?Translation {
                    if ($svTranslationId !== null && $id === $svTranslationId) {
                        return $this->makeTranslationMock('SV');
                    }
                    if ($hsvTranslationId !== null && $id === $hsvTranslationId) {
                        return $this->makeTranslationMock('HSV');
                    }
                    return null;
                }
            );
        }

        $linkRepo = $this->createMock(LinkingRepository::class);

        return [$transRepo, $linkRepo];
    }

    /**
     * Register the mocks into the Symfony test container so the controller
     * receives them via dependency injection.
     */
    private function injectMocks(
        TranslationRepository&MockObject $transRepo,
        LinkingRepository&MockObject     $linkRepo,
    ): void {
        static::getContainer()->set(TranslationRepository::class, $transRepo);
        static::getContainer()->set(LinkingRepository::class, $linkRepo);
    }
}
