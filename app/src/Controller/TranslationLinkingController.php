<?php

namespace App\Controller;

use App\Repository\BookRepository;
use App\Repository\LinkingRepository;
use App\Repository\PassageRepository;
use App\Repository\TranslationRepository;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

#[Route('/link/translations')]
#[IsGranted('ROLE_LINKER')]
class TranslationLinkingController extends AbstractController
{
    public function __construct(
        private readonly LinkingRepository     $linkingRepo,
        private readonly BookRepository        $bookRepo,
        private readonly PassageRepository     $passageRepo,
        private readonly TranslationRepository $translationRepo,
    ) {}

    // ── Home: pair selector ───────────────────────────────────────────────────

    #[Route('', name: 'app_trans_linking_home')]
    public function home(): Response
    {
        $pairs = $this->linkingRepo->fetchTranslationPairs();

        return $this->render('linking/translations_home.html.twig', [
            'pairs' => $pairs,
        ]);
    }

    // ── Verse linking UI ──────────────────────────────────────────────────────

    #[Route('/{codeA}/{codeB}/{usfm}/{chapter<\d+>}/{verse<\d+>}', name: 'app_trans_linking_verse')]
    public function verse(
        string $codeA, string $codeB,
        string $usfm, int $chapter, int $verse
    ): Response {
        $book = $this->bookRepo->findByUsfmCode($usfm);
        if (!$book) throw $this->createNotFoundException("Book '{$usfm}' not found.");

        $transA = $this->translationRepo->findByCode($codeA);
        $transB = $this->translationRepo->findByCode($codeB);
        if (!$transA || !$transB) throw $this->createNotFoundException('Translation not found.');

        $data = $this->linkingRepo->fetchInterTranslationVerseData(
            $book->getId(), $chapter, $verse,
            $transA->getId(), $transB->getId()
        );

        $chapterCounts = $this->passageRepo->getChapterVerseCounts($book->getId());
        $nav = $this->buildNav($book->getId(), $chapter, $verse, $usfm, $codeA, $codeB, $chapterCounts);

        // Build linked-ID set with string keys so Twig can look up by word ID.
        // (PHP array_merge renumbers integer keys, making Twig |merge unusable here.)
        $linkedIds = [];
        foreach ($data['links'] as $link) {
            $linkedIds[(string) $link['word_a_id']] = true;
            $linkedIds[(string) $link['word_b_id']] = true;
        }

        return $this->render('linking/translations_verse.html.twig', [
            'book'        => $book,
            'chapter'     => $chapter,
            'verse'       => $verse,
            'code_a'      => $codeA,
            'code_b'      => $codeB,
            'trans_a'     => $transA,
            'trans_b'     => $transB,
            'words_a'     => $data['words_a'],
            'words_b'     => $data['words_b'],
            'links'       => $data['links'],
            'word_map'    => $data['word_map'],
            'linked_ids'  => $linkedIds,
            'nav'         => $nav,
            'save_url'    => $this->generateUrl('app_trans_linking_api_save'),
            'delete_url'  => $this->generateUrl('app_trans_linking_api_delete'),
            'reset_url'   => $this->generateUrl('app_trans_linking_api_reset'),
        ]);
    }

    // ── API: save link ────────────────────────────────────────────────────────

    #[Route('/api/save', name: 'app_trans_linking_api_save', methods: ['POST'])]
    public function apiSave(Request $request): JsonResponse
    {
        if (!$this->isCsrfTokenValid('linking_api', $request->headers->get('X-CSRF-Token'))) {
            return $this->json(['error' => 'Invalid request.'], 403);
        }

        $data    = json_decode($request->getContent(), true);
        $wordAId = (int) ($data['word_a_id'] ?? 0);
        $wordBId = (int) ($data['word_b_id'] ?? 0);
        $transAId = (int) ($data['trans_a_id'] ?? 0);
        $transBId = (int) ($data['trans_b_id'] ?? 0);
        $method  = $data['method'] ?? 'manual';

        if ($wordAId <= 0 || $wordBId <= 0 || $wordAId === $wordBId) {
            return $this->json(['success' => false, 'error' => 'Invalid word IDs'], 422);
        }

        if (!$transAId || !$transBId
            || !$this->linkingRepo->translationWordsBelongToTranslation([$wordAId], $transAId)
            || !$this->linkingRepo->translationWordsBelongToTranslation([$wordBId], $transBId)
        ) {
            return $this->json(['success' => false, 'error' => 'Invalid word IDs'], 422);
        }

        $allowed = ['manual', 'manual_empty'];
        if (!in_array($method, $allowed, true)) {
            $method = 'manual';
        }

        $this->linkingRepo->saveInterTranslationLink($wordAId, $wordBId, $method, null);

        return $this->json(['success' => true]);
    }

    // ── API: delete link ──────────────────────────────────────────────────────

    #[Route('/api/delete', name: 'app_trans_linking_api_delete', methods: ['POST'])]
    public function apiDelete(Request $request): JsonResponse
    {
        if (!$this->isCsrfTokenValid('linking_api', $request->headers->get('X-CSRF-Token'))) {
            return $this->json(['error' => 'Invalid request.'], 403);
        }

        $data     = json_decode($request->getContent(), true);
        $wordAId  = (int) ($data['word_a_id']  ?? 0);
        $wordBId  = (int) ($data['word_b_id']  ?? 0);
        $transAId = (int) ($data['trans_a_id'] ?? 0);
        $transBId = (int) ($data['trans_b_id'] ?? 0);

        if ($wordAId <= 0 || $wordBId <= 0) {
            return $this->json(['success' => false, 'error' => 'Invalid IDs'], 422);
        }

        if (!$transAId || !$transBId
            || !$this->linkingRepo->translationWordsBelongToTranslation([$wordAId], $transAId)
            || !$this->linkingRepo->translationWordsBelongToTranslation([$wordBId], $transBId)
        ) {
            return $this->json(['success' => false, 'error' => 'Invalid IDs'], 422);
        }

        $this->linkingRepo->deleteInterTranslationLink($wordAId, $wordBId);

        return $this->json(['success' => true]);
    }

    // ── API: reset auto links for a verse ─────────────────────────────────────

    #[Route('/api/reset', name: 'app_trans_linking_api_reset', methods: ['POST'])]
    public function apiReset(Request $request): JsonResponse
    {
        if (!$this->isCsrfTokenValid('linking_api', $request->headers->get('X-CSRF-Token'))) {
            return $this->json(['error' => 'Invalid request.'], 403);
        }

        $data     = json_decode($request->getContent(), true);
        $idsA     = array_values(array_filter(array_map('intval', (array) ($data['ids_a'] ?? [])), fn($id) => $id > 0));
        $idsB     = array_values(array_filter(array_map('intval', (array) ($data['ids_b'] ?? [])), fn($id) => $id > 0));
        $transAId = (int) ($data['trans_a_id'] ?? 0);
        $transBId = (int) ($data['trans_b_id'] ?? 0);

        if (!$idsA || !$idsB) {
            return $this->json(['success' => false, 'error' => 'Invalid IDs'], 422);
        }

        if (!$transAId || !$transBId
            || !$this->linkingRepo->translationWordsBelongToTranslation($idsA, $transAId)
            || !$this->linkingRepo->translationWordsBelongToTranslation($idsB, $transBId)
        ) {
            return $this->json(['success' => false, 'error' => 'Invalid IDs'], 422);
        }

        $deleted = $this->linkingRepo->resetVerseAutoLinks($idsA, $idsB);

        return $this->json(['success' => true, 'deleted' => $deleted]);
    }

    // ── Nav helper ────────────────────────────────────────────────────────────

    private function buildNav(
        int $bookId, int $chapter, int $verse,
        string $usfm, string $codeA, string $codeB,
        array $counts
    ): array {
        $verseCount = $this->collectVerseCount($counts, $chapter);

        $prev = $next = null;
        $params = ['codeA' => $codeA, 'codeB' => $codeB, 'usfm' => $usfm];

        if ($verse > 1) {
            $prev = $params + ['chapter' => $chapter, 'verse' => $verse - 1];
        } elseif ($chapter > 1) {
            $pvc  = $this->collectVerseCount($counts, $chapter - 1);
            $prev = $params + ['chapter' => $chapter - 1, 'verse' => $pvc];
        }

        if ($verse < $verseCount) {
            $next = $params + ['chapter' => $chapter, 'verse' => $verse + 1];
        } elseif ($chapter < count($counts)) {
            $next = $params + ['chapter' => $chapter + 1, 'verse' => 1];
        }

        return ['prev' => $prev, 'next' => $next, 'verse_count' => $verseCount];
    }

    private function collectVerseCount(array $counts, int $chapter): int
    {
        foreach ($counts as $row) {
            if ((int) $row['chapter'] === $chapter) return (int) $row['verse_count'];
        }
        return 0;
    }
}
