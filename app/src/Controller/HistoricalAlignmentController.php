<?php

namespace App\Controller;

use App\Entity\User;
use App\Repository\BookRepository;
use App\Repository\LinkingRepository;
use App\Repository\PassageRepository;
use App\Service\Alignment\HistoricalAlignmentScoreService;
use Symfony\Bundle\FrameworkBundle\Console\Application;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\Console\Input\ArrayInput;
use Symfony\Component\Console\Output\BufferedOutput;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\KernelInterface;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

/**
 * 4-way historical-spelling alignment review UI (plan sectie 6): SV1657 /
 * SV / SVGBS / HSV side by side with an SVG connection-line overlay,
 * click-to-link interaction, and an approve action.
 */
#[Route('/link/translations/historical')]
#[IsGranted('ROLE_LINKER')]
class HistoricalAlignmentController extends AbstractController
{
    private const CSRF_INTENT = 'historical_align_api';

    public function __construct(
        private readonly LinkingRepository $linkingRepo,
        private readonly BookRepository $bookRepo,
        private readonly PassageRepository $passageRepo,
        private readonly HistoricalAlignmentScoreService $scoreService,
    ) {
    }

    // ── Verse review screen ─────────────────────────────────────────────────

    #[Route('/{usfm<[A-Za-z0-9]{2,8}>}/{chapter<\d+>}/{verse<\d+>}', name: 'app_hist_align_verse')]
    public function verse(string $usfm, int $chapter, int $verse): Response
    {
        $book = $this->bookRepo->findByUsfmCode($usfm);
        if (!$book) {
            throw $this->createNotFoundException("Book '{$usfm}' not found.");
        }

        $data = $this->linkingRepo->fetchHistoricalAlignmentVerseData($book->getId(), $chapter, $verse);
        if ($data['pivot_code'] === '' || empty($data['words'][$data['pivot_code']])) {
            throw $this->createNotFoundException('Verse not found or has no SV1657 text.');
        }

        $score = $this->scoreService->computeVerseScore($data['words'], $data['links'], $data['pivot_code']);

        $chapterCounts = $this->passageRepo->getChapterVerseCounts($book->getId());
        $nav = $this->buildNav($chapter, $verse, $usfm, $chapterCounts);

        return $this->render('linking/historical_verse.html.twig', [
            'book' => $book,
            'usfm' => $usfm,
            'chapter' => $chapter,
            'verse' => $verse,
            'translations' => $data['translations'],
            'words' => $data['words'],
            'links' => $data['links'],
            'pivot_code' => $data['pivot_code'],
            'score' => $score,
            'nav' => $nav,
        ]);
    }

    // ── API: create a manual link ────────────────────────────────────────────

    #[Route('/api/link', name: 'app_hist_align_api_link', methods: ['POST'])]
    public function apiLink(Request $request): JsonResponse
    {
        $csrfError = $this->checkCsrf($request);
        if ($csrfError) {
            return $csrfError;
        }

        $data = json_decode($request->getContent(), true) ?? [];
        $wordAId = (int) ($data['word_a_id'] ?? 0);
        $wordBId = (int) ($data['word_b_id'] ?? 0);

        if ($wordAId <= 0 || $wordBId <= 0 || $wordAId === $wordBId) {
            return $this->json(['success' => false, 'error' => 'Invalid word IDs'], 422);
        }
        if (!$this->linkingRepo->wordsShareVerseInAlignmentFamily($wordAId, $wordBId)) {
            return $this->json(['success' => false, 'error' => 'Words do not share a verse'], 422);
        }

        /** @var User $user */
        $user = $this->getUser();
        $this->linkingRepo->saveInterTranslationLink($wordAId, $wordBId, 'manual', null, $user->getId(), 1.0);

        return $this->json(['success' => true]);
    }

    // ── API: delete a link ───────────────────────────────────────────────────

    #[Route('/api/unlink', name: 'app_hist_align_api_unlink', methods: ['POST'])]
    public function apiUnlink(Request $request): JsonResponse
    {
        $csrfError = $this->checkCsrf($request);
        if ($csrfError) {
            return $csrfError;
        }

        $data = json_decode($request->getContent(), true) ?? [];
        $wordAId = (int) ($data['word_a_id'] ?? 0);
        $wordBId = (int) ($data['word_b_id'] ?? 0);

        if ($wordAId <= 0 || $wordBId <= 0) {
            return $this->json(['success' => false, 'error' => 'Invalid word IDs'], 422);
        }

        $this->linkingRepo->deleteInterTranslationLink($wordAId, $wordBId);

        return $this->json(['success' => true]);
    }

    // ── API: approve verse (all links -> manual) ─────────────────────────────

    #[Route('/api/approve', name: 'app_hist_align_api_approve', methods: ['POST'])]
    public function apiApprove(Request $request): JsonResponse
    {
        $csrfError = $this->checkCsrf($request);
        if ($csrfError) {
            return $csrfError;
        }

        $data = json_decode($request->getContent(), true) ?? [];
        $wordIds = array_values(array_filter(array_map('intval', (array) ($data['word_ids'] ?? [])), fn($id) => $id > 0));
        if (!$wordIds) {
            return $this->json(['success' => false, 'error' => 'No word IDs given'], 422);
        }

        /** @var User $user */
        $user = $this->getUser();
        $updated = $this->linkingRepo->approveVerseLinks($wordIds, $user->getId());

        return $this->json(['success' => true, 'updated' => $updated]);
    }

    // ── API: recompute a scope via the CLI's historical engine ──────────────

    #[Route('/api/recompute', name: 'app_hist_align_api_recompute', methods: ['POST'])]
    public function apiRecompute(Request $request, KernelInterface $kernel): JsonResponse
    {
        $csrfError = $this->checkCsrf($request);
        if ($csrfError) {
            return $csrfError;
        }

        $data = json_decode($request->getContent(), true) ?? [];
        $scopeType = (string) ($data['scope_type'] ?? 'verse');
        $usfm = strtoupper((string) ($data['usfm'] ?? ''));
        $chapter = $data['chapter'] ?? null;
        $verse = $data['verse'] ?? null;

        if ($usfm === '' || !in_array($scopeType, ['verse', 'chapter', 'book'], true)) {
            return $this->json(['success' => false, 'error' => 'Invalid scope'], 422);
        }

        $options = ['command' => 'app:link:translations:auto', '--engine' => 'historical', '--no-interaction' => true];
        if ($scopeType === 'verse' && $chapter !== null && $verse !== null) {
            $options['--verse'] = "{$usfm}.{$chapter}.{$verse}";
        } elseif ($scopeType === 'chapter' && $chapter !== null) {
            $options['--chapter'] = "{$usfm}.{$chapter}";
        } else {
            $options['--book'] = $usfm;
        }

        $application = new Application($kernel);
        $application->setAutoExit(false);
        $output = new BufferedOutput();
        $exitCode = $application->run(new ArrayInput($options), $output);

        return $this->json(['success' => $exitCode === 0, 'output' => $output->fetch()]);
    }

    // ── Nav helper (mirrors TranslationLinkingController::buildNav) ─────────

    private function buildNav(int $chapter, int $verse, string $usfm, array $counts): array
    {
        $verseCount = $this->collectVerseCount($counts, $chapter);

        $prev = $next = null;
        $params = ['usfm' => $usfm];

        if ($verse > 1) {
            $prev = $params + ['chapter' => $chapter, 'verse' => $verse - 1];
        } elseif ($chapter > 1) {
            $pvc = $this->collectVerseCount($counts, $chapter - 1);
            $prev = $params + ['chapter' => $chapter - 1, 'verse' => $pvc];
        }

        if ($verse < $verseCount) {
            $next = $params + ['chapter' => $chapter, 'verse' => $verse + 1];
        } elseif ($chapter < count($counts)) {
            $next = $params + ['chapter' => $chapter + 1, 'verse' => 1];
        }

        return ['prev' => $prev, 'next' => $next, 'verse_count' => $verseCount, 'chapter_count' => count($counts)];
    }

    private function collectVerseCount(array $counts, int $chapter): int
    {
        foreach ($counts as $row) {
            if ((int) $row['chapter'] === $chapter) {
                return (int) $row['verse_count'];
            }
        }

        return 0;
    }

    private function checkCsrf(Request $request): ?JsonResponse
    {
        if (!$this->isCsrfTokenValid(self::CSRF_INTENT, $request->headers->get('X-CSRF-Token'))) {
            return $this->json(['success' => false, 'error' => 'Invalid request.'], 403);
        }

        return null;
    }
}
