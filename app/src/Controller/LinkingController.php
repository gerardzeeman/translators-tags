<?php

namespace App\Controller;

use App\Repository\BookRepository;
use App\Repository\LinkingRepository;
use App\Repository\PassageRepository;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

#[Route('/link')]
class LinkingController extends AbstractController
{
    public function __construct(
        private readonly LinkingRepository $linkingRepo,
        private readonly BookRepository    $bookRepo,
        private readonly PassageRepository $passageRepo,
    ) {}

    // ── Screen 1: Passage linking ─────────────────────────────────────────────

    #[Route('', name: 'app_linking_home')]
    public function home(): Response
    {
        $otBooks = $this->bookRepo->findAllOldTestament();
        $ntBooks = $this->bookRepo->findAllNewTestament();

        return $this->render('linking/home.html.twig', [
            'ot_books' => $otBooks,
            'nt_books' => $ntBooks,
        ]);
    }

    #[Route('/passage/{usfm}/{chapter<\d+>}/{verse<\d+>}', name: 'app_linking_passage')]
    public function passage(string $usfm, int $chapter, int $verse): Response
    {
        $book = $this->bookRepo->findByUsfmCode($usfm);
        if (!$book) {
            throw $this->createNotFoundException("Book '{$usfm}' not found.");
        }

        $passage = $this->linkingRepo->fetchPassageForLinking(
            $book->getId(), $chapter, $verse
        );

        $chapterCounts = $this->passageRepo->getChapterVerseCounts($book->getId());
        $nav = $this->buildNavigation($book->getId(), $chapter, $verse, $usfm, $chapterCounts);

        return $this->render('linking/passage.html.twig', [
            'book'    => $book,
            'chapter' => $chapter,
            'verse'   => $verse,
            'passage' => $passage,
            'nav'     => $nav,
        ]);
    }

    // ── Screen 2: Strong's linking ────────────────────────────────────────────

    #[Route('/strongs', name: 'app_linking_strongs_home')]
    public function strongsHome(): Response
    {
        return $this->render('linking/strongs_home.html.twig');
    }

    #[Route('/strongs/{strongs}', name: 'app_linking_strongs')]
    public function strongs(string $strongs): Response
    {
        $strongs = strtoupper($strongs);

        $transliterations = $this->linkingRepo->fetchStrongsTransliterations($strongs);
        $progress         = $this->linkingRepo->fetchStrongsProgress($strongs);
        $verses           = $this->linkingRepo->fetchStrongsVerses($strongs);

        return $this->render('linking/strongs.html.twig', [
            'strongs'          => $strongs,
            'transliterations' => $transliterations,
            'progress'         => $progress,
            'verses'           => $verses,
        ]);
    }

    // ── API: save / delete links ──────────────────────────────────────────────

    #[Route('/api/save', name: 'api_linking_save', methods: ['POST'])]
    public function save(Request $request): JsonResponse
    {
        $data = json_decode($request->getContent(), true);

        $lang         = $data['lang']           ?? null;
        $sourceWordId = (int) ($data['source_word_id'] ?? 0);
        $twIds        = $data['tw_ids']          ?? [];

        if (!in_array($lang, ['HE', 'GR'], true) || !$sourceWordId) {
            return $this->json(['error' => 'Invalid parameters.'], 400);
        }

        // $twIds may be empty — that means "intentionally no Dutch translation"
        if (!is_array($twIds)) {
            $twIds = [];
        }

        $this->linkingRepo->saveManualLinks($lang, $sourceWordId, $twIds);

        return $this->json(['success' => true, 'linked' => count($twIds), 'empty' => empty($twIds)]);
    }

    #[Route('/api/delete/{linkId<\d+>}', name: 'api_linking_delete', methods: ['DELETE'])]
    public function delete(int $linkId): JsonResponse
    {
        $this->linkingRepo->deleteLink($linkId);
        return $this->json(['success' => true]);
    }

    /**
     * Render a single verse block partial for in-place DOM replacement after save.
     * GET /link/api/verse-block/{strongs}/{usfm}/{chapter}/{verse}
     */
    #[Route('/api/verse-block/{strongs}/{usfm}/{chapter<\d+>}/{verse<\d+>}', name: 'api_linking_verse_block', methods: ['GET'])]
    public function verseBlock(string $strongs, string $usfm, int $chapter, int $verse): Response
    {
        $book = $this->bookRepo->findByUsfmCode($usfm);
        if (!$book) {
            throw $this->createNotFoundException("Book '{$usfm}' not found.");
        }

        $passage = $this->linkingRepo->fetchPassageForLinking($book->getId(), $chapter, $verse);

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
            'v'       => $v,
            'strongs' => strtoupper($strongs),
        ]);
    }

    /**
     * Return linking progress for a Strong's number as JSON.
     * GET /link/api/progress/{strongs}
     */
    #[Route('/api/progress/{strongs}', name: 'api_linking_progress', methods: ['GET'])]
    public function progress(string $strongs): JsonResponse
    {
        $progress = $this->linkingRepo->fetchStrongsProgress(strtoupper($strongs));
        return $this->json($progress);
    }

    // ── Navigation helper ─────────────────────────────────────────────────────

    private function buildNavigation(int $bookId, int $chapter, int $verse,
                                     string $usfm, array $counts): array
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
            $prev = ['usfm' => $usfm, 'chapter' => $chapter, 'verse' => $verse - 1];
        } elseif ($chapter > 1) {
            $prevCount = 0;
            foreach ($counts as $row) {
                if ((int) $row['chapter'] === $chapter - 1) {
                    $prevCount = (int) $row['verse_count'];
                    break;
                }
            }
            $prev = ['usfm' => $usfm, 'chapter' => $chapter - 1, 'verse' => $prevCount];
        }

        if ($verse < $verseCount) {
            $next = ['usfm' => $usfm, 'chapter' => $chapter, 'verse' => $verse + 1];
        } elseif ($chapter < count($counts)) {
            $next = ['usfm' => $usfm, 'chapter' => $chapter + 1, 'verse' => 1];
        }

        return ['prev' => $prev, 'next' => $next, 'verse_count' => $verseCount];
    }
}
