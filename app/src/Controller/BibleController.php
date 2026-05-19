<?php

namespace App\Controller;

use App\Repository\BookRepository;
use App\Repository\PassageRepository;
use App\Service\MorphologyParser;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

class BibleController extends AbstractController
{
    public function __construct(
        private readonly BookRepository   $bookRepository,
        private readonly PassageRepository $passageRepository,
        private readonly MorphologyParser $morphologyParser,
    ) {}

    /**
     * Home page — show the book list and coverage stats.
     */
    #[Route('/', name: 'app_home')]
    public function home(): Response
    {
        $otBooks  = $this->bookRepository->findAllOldTestament();
        $ntBooks  = $this->bookRepository->findAllNewTestament();
        $coverage = $this->passageRepository->getCoverageStats();

        return $this->render('bible/home.html.twig', [
            'ot_books'  => $otBooks,
            'nt_books'  => $ntBooks,
            'coverage'  => $coverage,
        ]);
    }

    /**
     * Book overview — list chapters.
     */
    #[Route('/book/{usfm}', name: 'app_book')]
    public function book(string $usfm): Response
    {
        $book = $this->bookRepository->findByUsfmCode($usfm);
        if (!$book) {
            throw $this->createNotFoundException("Book '{$usfm}' not found.");
        }

        $chapterCounts = $this->passageRepository->getChapterVerseCounts($book->getId());

        return $this->render('bible/book.html.twig', [
            'book'           => $book,
            'chapter_counts' => $chapterCounts,
        ]);
    }

    /**
     * Chapter view — list all verses.
     */
    #[Route('/book/{usfm}/{chapter<\d+>}', name: 'app_chapter')]
    public function chapter(string $usfm, int $chapter): Response
    {
        $book = $this->bookRepository->findByUsfmCode($usfm);
        if (!$book) {
            throw $this->createNotFoundException("Book '{$usfm}' not found.");
        }

        $chapterData = $this->passageRepository->getChapterVerseCounts($book->getId());
        $verseCount  = collect_verse_count($chapterData, $chapter);

        return $this->render('bible/chapter.html.twig', [
            'book'        => $book,
            'chapter'     => $chapter,
            'verse_count' => $verseCount,
        ]);
    }

    /**
     * Verse view — the main comparison screen.
     */
    #[Route('/book/{usfm}/{chapter<\d+>}/{verse<\d+>}', name: 'app_verse')]
    public function verse(string $usfm, int $chapter, int $verse, Request $request): Response
    {
        $book = $this->bookRepository->findByUsfmCode($usfm);
        if (!$book) {
            throw $this->createNotFoundException("Book '{$usfm}' not found.");
        }

        $passage = $this->passageRepository->fetchPassage($book->getId(), $chapter, $verse);

        // Enrich source words with decoded morphology
        foreach ($passage['source_words'] as &$word) {
            if ($passage['testament'] === 'NT' && !empty($word['parse_code'])) {
                $word['morph_description'] = $this->morphologyParser->describeGreek($word['parse_code']);
            } elseif ($passage['testament'] === 'OT' && !empty($word['morph_code'])) {
                $word['morph_description'] = $this->morphologyParser->describeHebrew($word['morph_code']);
            } else {
                $word['morph_description'] = '';
            }
        }
        unset($word);

        // Navigation: prev/next verse
        $nav = $this->buildNavigation($book->getId(), $chapter, $verse, $usfm);

        $template = $request->headers->get('Turbo-Frame')
            ? 'bible/verse_frame.html.twig'
            : 'bible/verse.html.twig';

        return $this->render($template, [
            'book'    => $book,
            'chapter' => $chapter,
            'verse'   => $verse,
            'passage' => $passage,
            'nav'     => $nav,
        ]);
    }

    /**
     * Strong's lookup — AJAX/Turbo endpoint showing all words with a given number.
     */
    #[Route('/strongs/{number}', name: 'app_strongs')]
    public function strongs(string $number): Response
    {
        return $this->render('bible/strongs.html.twig', [
            'strongs_number' => $number,
        ]);
    }

    // ── helpers ──────────────────────────────────────────────────────────────

    private function buildNavigation(int $bookId, int $chapter, int $verse, string $usfm): array
    {
        $counts = $this->passageRepository->getChapterVerseCounts($bookId);
        $verseCount = collect_verse_count($counts, $chapter);

        $prev = $next = null;

        if ($verse > 1) {
            $prev = ['usfm' => $usfm, 'chapter' => $chapter, 'verse' => $verse - 1];
        } elseif ($chapter > 1) {
            $prevCount = collect_verse_count($counts, $chapter - 1);
            $prev = ['usfm' => $usfm, 'chapter' => $chapter - 1, 'verse' => $prevCount];
        }

        if ($verse < $verseCount) {
            $next = ['usfm' => $usfm, 'chapter' => $chapter, 'verse' => $verse + 1];
        } else {
            $chapterCount = count($counts);
            if ($chapter < $chapterCount) {
                $next = ['usfm' => $usfm, 'chapter' => $chapter + 1, 'verse' => 1];
            }
        }

        return ['prev' => $prev, 'next' => $next, 'verse_count' => $verseCount];
    }
}

/**
 * Helper: extract verse count for a given chapter from the counts array.
 */
function collect_verse_count(array $chapterCounts, int $chapter): int
{
    foreach ($chapterCounts as $row) {
        if ((int) $row['chapter'] === $chapter) {
            return (int) $row['verse_count'];
        }
    }
    return 0;
}
