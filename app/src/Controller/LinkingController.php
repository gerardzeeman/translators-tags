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

#[Route('/link')]
class LinkingController extends AbstractController
{
    public function __construct(
        private readonly LinkingRepository     $linkingRepo,
        private readonly BookRepository        $bookRepo,
        private readonly PassageRepository     $passageRepo,
        private readonly TranslationRepository $translationRepo,
    ) {}

    // ── Screen 1: Passage linking ─────────────────────────────────────────────

    #[Route('', name: 'app_linking_home')]
    public function home(): Response
    {
        $otBooks      = $this->bookRepo->findAllOldTestament();
        $ntBooks      = $this->bookRepo->findAllNewTestament();
        $translations = $this->translationRepo->findAllOrderedById();

        return $this->render('linking/home.html.twig', [
            'ot_books'     => $otBooks,
            'nt_books'     => $ntBooks,
            'translations' => $translations,
        ]);
    }

    #[Route('/passage/{translation}/{usfm}/{chapter<\d+>}/{verse<\d+>}', name: 'app_linking_passage')]
    public function passage(string $translation, string $usfm, int $chapter, int $verse): Response
    {
        $book = $this->bookRepo->findByUsfmCode($usfm);
        if (!$book) {
            throw $this->createNotFoundException("Book '{$usfm}' not found.");
        }

        $translationEntity = $this->translationRepo->findByCode($translation);
        if (!$translationEntity) {
            throw $this->createNotFoundException("Translation '{$translation}' not found.");
        }

        // For non-authority translations (e.g. HSV), augment empty source-word
        // links with propagated ITL suggestions so the user can confirm them.
        $authorityId = $this->linkingRepo->findAuthorityTranslationId($translationEntity->getId());
        if ($authorityId !== null) {
            $passage = $this->linkingRepo->fetchPassageForLinkingWithPropagation(
                $book->getId(), $chapter, $verse, $translationEntity->getId(), $authorityId
            );
        } else {
            $passage = $this->linkingRepo->fetchPassageForLinking(
                $book->getId(), $chapter, $verse, $translationEntity->getId()
            );
        }

        $chapterCounts = $this->passageRepo->getChapterVerseCounts($book->getId());
        $nav = $this->buildNavigation($book->getId(), $chapter, $verse, $usfm, $translation, $chapterCounts);

        $translations = $this->translationRepo->findAllOrderedById();

        return $this->render('linking/passage.html.twig', [
            'book'         => $book,
            'chapter'      => $chapter,
            'verse'        => $verse,
            'passage'      => $passage,
            'nav'          => $nav,
            'translation'  => $translationEntity,
            'translations' => $translations,
        ]);
    }

    // ── Screen 2: Strong's linking ────────────────────────────────────────────

    #[Route('/strongs', name: 'app_linking_strongs_home')]
    public function strongsHome(): Response
    {
        $translations = $this->translationRepo->findAllOrderedById();

        return $this->render('linking/strongs_home.html.twig', [
            'translations' => $translations,
        ]);
    }

    #[Route('/strongs/{translation}/{strongs}', name: 'app_linking_strongs')]
    public function strongs(Request $request, string $translation, string $strongs): Response
    {
        $strongs = strtoupper($strongs);

        $translationEntity = $this->translationRepo->findByCode($translation);
        if (!$translationEntity) {
            throw $this->createNotFoundException("Translation '{$translation}' not found.");
        }

        $perPage       = 30;
        $totalVerses   = $this->linkingRepo->countStrongsVerses($strongs);
        $totalPages    = max(1, (int) ceil($totalVerses / $perPage));
        $page          = max(1, min($totalPages, (int) $request->query->get('page', 1)));

        $translationId    = $translationEntity->getId();
        $transliterations = $this->linkingRepo->fetchStrongsTransliterations($strongs);
        $progress         = $this->linkingRepo->fetchStrongsProgress($strongs, $translationId);
        $verses           = $this->linkingRepo->fetchStrongsVerses($strongs, $translationId, $page, $perPage);
        $strongsEntry     = $this->linkingRepo->fetchStrongsEntry($strongs);

        $translations = $this->translationRepo->findAllOrderedById();

        return $this->render('linking/strongs.html.twig', [
            'strongs'          => $strongs,
            'transliterations' => $transliterations,
            'progress'         => $progress,
            'verses'           => $verses,
            'strongs_entry'    => $strongsEntry,
            'translation'      => $translationEntity,
            'translations'     => $translations,
            'page'             => $page,
            'total_pages'      => $totalPages,
            'total_verses'     => $totalVerses,
        ]);
    }

    // ── API: save / delete links ──────────────────────────────────────────────

    #[Route('/api/save', name: 'api_linking_save', methods: ['POST'])]
    public function save(Request $request): JsonResponse
    {
        $data = json_decode($request->getContent(), true);

        $lang           = $data['lang']            ?? null;
        $sourceWordId   = (int) ($data['source_word_id']  ?? 0);
        $twIds          = $data['tw_ids']           ?? [];
        $translationId  = (int) ($data['translation_id']  ?? 0);

        if (!in_array($lang, ['HE', 'GR'], true) || !$sourceWordId || !$translationId) {
            return $this->json(['error' => 'Invalid parameters.'], 400);
        }

        // Verify source word exists in the appropriate language table (V-03)
        if (!$this->linkingRepo->sourceWordExists($lang, $sourceWordId)) {
            return $this->json(['error' => 'Invalid parameters.'], 400);
        }

        // Non-SV translations require ROLE_HSV
        $translationEntity = $this->translationRepo->find($translationId);
        if ($translationEntity && $translationEntity->getCode() !== 'SV') {
            if (!$this->isGranted('ROLE_HSV')) {
                return $this->json(['error' => 'Access denied.'], 403);
            }
        }

        // $twIds may be empty — that means "intentionally no Dutch translation"
        if (!is_array($twIds)) {
            $twIds = [];
        }

        $this->linkingRepo->saveManualLinks($lang, $sourceWordId, $twIds, $translationId);

        return $this->json(['success' => true, 'linked' => count($twIds), 'empty' => empty($twIds)]);
    }

    #[Route('/api/delete/{linkId<\d+>}', name: 'api_linking_delete', methods: ['DELETE'])]
    public function delete(int $linkId): JsonResponse
    {
        // Non-SV translation links require ROLE_HSV
        $translationCode = $this->linkingRepo->findTranslationCodeByLinkId($linkId);
        if ($translationCode && $translationCode !== 'SV') {
            if (!$this->isGranted('ROLE_HSV')) {
                return $this->json(['error' => 'Access denied.'], 403);
            }
        }

        $this->linkingRepo->deleteLink($linkId);
        return $this->json(['success' => true]);
    }

    /**
     * Render a single verse block partial for in-place DOM replacement after save.
     * GET /link/api/verse-block/{translation}/{strongs}/{usfm}/{chapter}/{verse}
     */
    #[Route('/api/verse-block/{translation}/{strongs}/{usfm}/{chapter<\d+>}/{verse<\d+>}', name: 'api_linking_verse_block', methods: ['GET'])]
    public function verseBlock(string $translation, string $strongs, string $usfm, int $chapter, int $verse): Response
    {
        $book = $this->bookRepo->findByUsfmCode($usfm);
        if (!$book) {
            throw $this->createNotFoundException("Book '{$usfm}' not found.");
        }

        $translationEntity = $this->translationRepo->findByCode($translation);
        if (!$translationEntity) {
            throw $this->createNotFoundException("Translation '{$translation}' not found.");
        }

        $passage = $this->linkingRepo->fetchPassageForLinking(
            $book->getId(), $chapter, $verse, $translationEntity->getId()
        );

        $v = [
            'book_id'      => $book->getId(),
            'chapter'      => $chapter,
            'verse'        => $verse,
            'book_name'    => $book->getNameNl(),
            'usfm_code'    => strtoupper($usfm),
            'testament'    => $passage['testament'],
            'source_words' => $passage['source_words'],
            'dutch_words'  => $passage['dutch_words'],
        ];

        return $this->render('linking/_strongs_verse_block.html.twig', [
            'v'           => $v,
            'strongs'     => strtoupper($strongs),
            'translation' => $translationEntity,
        ]);
    }

    /**
     * Return linking progress for a Strong's number as JSON.
     * GET /link/api/progress/{translation}/{strongs}
     */
    #[Route('/api/progress/{translation}/{strongs}', name: 'api_linking_progress', methods: ['GET'])]
    public function progress(string $translation, string $strongs): JsonResponse
    {
        $translationEntity = $this->translationRepo->findByCode($translation);
        if (!$translationEntity) {
            return $this->json(['error' => 'Translation not found.'], 404);
        }

        $progress = $this->linkingRepo->fetchStrongsProgress(
            strtoupper($strongs), $translationEntity->getId()
        );
        return $this->json($progress);
    }

    // ── Navigation helper ─────────────────────────────────────────────────────

    private function buildNavigation(int $bookId, int $chapter, int $verse,
                                     string $usfm, string $translation, array $counts): array
    {
        $verseCount = 0;
        foreach ($counts as $row) {
            if ((int) $row['chapter'] === $chapter) {
                $verseCount = (int) $row['verse_count'];
                break;
            }
        }

        $prev = $next = null;

        if ($verse > 1) {
            $prev = ['translation' => $translation, 'usfm' => $usfm, 'chapter' => $chapter, 'verse' => $verse - 1];
        } elseif ($chapter > 1) {
            $prevCount = 0;
            foreach ($counts as $row) {
                if ((int) $row['chapter'] === $chapter - 1) {
                    $prevCount = (int) $row['verse_count'];
                    break;
                }
            }
            $prev = ['translation' => $translation, 'usfm' => $usfm, 'chapter' => $chapter - 1, 'verse' => $prevCount];
        }

        if ($verse < $verseCount) {
            $next = ['translation' => $translation, 'usfm' => $usfm, 'chapter' => $chapter, 'verse' => $verse + 1];
        } elseif ($chapter < count($counts)) {
            $next = ['translation' => $translation, 'usfm' => $usfm, 'chapter' => $chapter + 1, 'verse' => 1];
        }

        return ['prev' => $prev, 'next' => $next, 'verse_count' => $verseCount];
    }
}
