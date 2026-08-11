<?php

namespace App\Controller;

use App\Repository\BookRepository;
use App\Repository\CrossReferenceRepository;
use App\Repository\LinkingRepository;
use App\Repository\PassageRepository;
use App\Repository\TranslationRepository;
use App\Service\MorphologyParser;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

class BibleController extends AbstractController
{
    public function __construct(
        private readonly BookRepository            $bookRepository,
        private readonly PassageRepository         $passageRepository,
        private readonly TranslationRepository     $translationRepository,
        private readonly MorphologyParser          $morphologyParser,
        private readonly LinkingRepository         $linkingRepository,
        private readonly CrossReferenceRepository  $crossReferenceRepository,
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
     * Chapter view — shows all verse-pairs for the chapter side by side.
     * This is the primary reading endpoint; replaces the old verse-list page.
     */
    #[Route('/book/{usfm}/{chapter<\d+>}', name: 'app_chapter_view')]
    public function chapterView(string $usfm, int $chapter): Response
    {
        $book = $this->bookRepository->findByUsfmCode($usfm);
        if (!$book) {
            throw $this->createNotFoundException("Book '{$usfm}' not found.");
        }

        $allTranslations = $this->translationRepository->findAllForDisplay();

        $svTranslation       = null;
        $translationIdToCode = [];
        foreach ($allTranslations as $t) {
            $translationIdToCode[$t->getId()] = $t->getCode();
            if ($t->getCode() === 'SV') {
                $svTranslation = $t;
            }
        }
        $allTranslationIds = array_keys($translationIdToCode);

        // All verse-pairs in the chapter in a small number of queries
        $passagesByVerseTid = $this->passageRepository->fetchChapterPassages(
            $book->getId(), $chapter, $allTranslationIds
        );

        // Determine testament from the first verse returned
        $testament = 'NT';
        foreach ($passagesByVerseTid as $vData) {
            foreach ($vData as $tData) {
                $testament = $tData['testament'];
                break 2;
            }
        }

        // Propagated links for the entire chapter in one query
        $propagatedByVerse = [];
        if ($svTranslation) {
            $targetIds = array_values(array_filter(
                $allTranslationIds,
                fn($id) => $translationIdToCode[$id] !== 'SV'
            ));
            $propagatedByVerse = $this->passageRepository->fetchPropagatedLinksForChapterBatch(
                $book->getId(), $chapter, $testament,
                $svTranslation->getId(), $targetIds,
            );
        }

        // Cross-references: one fetch per distinct source needed by the visible
        // translations (SV and SVGBS share the same source, so this is at most 2 queries).
        $crossRefsBySource = [];
        foreach ($allTranslations as $trans) {
            $source = $this->crossReferenceRepository->sourceForTranslationCode($trans->getCode());
            if ($source !== null && !isset($crossRefsBySource[$source])) {
                $crossRefsBySource[$source] = $this->crossReferenceRepository->fetchForChapter(
                    $book->getId(), $chapter, $source
                );
            }
        }

        // Build per-verse data, enriched with morphology and per-translation links
        $verses = [];
        foreach ($passagesByVerseTid as $verseNum => $dataByTid) {
            $svId    = $svTranslation?->getId();
            $svData  = $dataByTid[$svId] ?? reset($dataByTid);
            $sourceWords = $svData['source_words'];

            foreach ($sourceWords as $i => &$word) {
                if ($testament === 'NT' && !empty($word['parse_code'])) {
                    $word['morph_description'] = $this->morphologyParser->describeGreek($word['parse_code']);
                } elseif ($testament === 'OT' && !empty($word['morph_code'])) {
                    $word['morph_description'] = $this->morphologyParser->describeHebrew($word['morph_code']);
                } else {
                    $word['morph_description'] = '';
                }

                foreach ($allTranslations as $trans) {
                    $code = $trans->getCode();
                    if ($code === 'SV') {
                        $word['links_sv'] = $svData['source_words'][$i]['dutch_links'] ?? [];
                    } else {
                        $direct    = $dataByTid[$trans->getId()]['source_words'][$i]['dutch_links'] ?? [];
                        $propagated = $propagatedByVerse[$verseNum][$trans->getId()][$word['id']] ?? [];
                        $word['links_' . strtolower($code)] = !empty($direct) ? $direct : $propagated;
                    }
                }
                unset($word['dutch_links']);
            }
            unset($word);

            $verseData = [
                'testament'    => $testament,
                'source_words' => $sourceWords,
            ];
            foreach ($allTranslations as $trans) {
                $code = $trans->getCode();
                $dutchVerse = $dataByTid[$trans->getId()]['dutch_verse'] ?? [];

                $source = $this->crossReferenceRepository->sourceForTranslationCode($code);
                $markersByPos = ($source !== null) ? ($crossRefsBySource[$source][$verseNum] ?? []) : [];

                foreach ($dutchVerse as &$w) {
                    $w['cross_ref_markers'] = $markersByPos[(int) $w['word_position']] ?? [];
                }
                unset($w);

                $verseData['dutch_verse_' . strtolower($code)]  = $dutchVerse;
                // word_position 0 = "before the first word" -- no word to attach to, shown as a verse prefix.
                $verseData['cross_ref_prefix_' . strtolower($code)] = $markersByPos[0] ?? [];
            }

            $verses[$verseNum] = $verseData;
        }

        // Chapter navigation
        $chapterCounts = $this->passageRepository->getChapterVerseCounts($book->getId());
        $chapterCount  = count($chapterCounts);
        $nav = [
            'prev_chapter' => $chapter > 1 ? $chapter - 1 : null,
            'next_chapter' => $chapter < $chapterCount ? $chapter + 1 : null,
            'chapters'     => $chapterCounts,
        ];

        return $this->render('bible/chapter_view.html.twig', [
            'book'         => $book,
            'chapter'      => $chapter,
            'verses'       => $verses,
            'testament'    => $testament,
            'nav'          => $nav,
            'translations' => $allTranslations,
            'ot_books'     => $this->bookRepository->findAllOldTestament(),
            'nt_books'     => $this->bookRepository->findAllNewTestament(),
        ]);
    }

    /**
     * Verse view — the main comparison screen.
     * The {translation} segment is optional, defaults to 'SV'.
     */
    #[Route('/book/{usfm}/{chapter<\d+>}/{verse<\d+>}/{translation}', name: 'app_verse', defaults: ['translation' => 'SV'])]
    public function verse(string $usfm, int $chapter, int $verse, string $translation, Request $request): Response
    {
        $book = $this->bookRepository->findByUsfmCode($usfm);
        if (!$book) {
            throw $this->createNotFoundException("Book '{$usfm}' not found.");
        }

        $translationEntity = $this->translationRepository->findByCode($translation);
        if (!$translationEntity) {
            throw $this->createNotFoundException("Translation '{$translation}' not found.");
        }

        $allTranslations = $this->translationRepository->findAllForDisplay();

        // Build translation ID ↔ code maps and find SV
        $svTranslation      = null;
        $translationIdToCode = [];
        foreach ($allTranslations as $t) {
            $translationIdToCode[$t->getId()] = $t->getCode();
            if ($t->getCode() === 'SV') {
                $svTranslation = $t;
            }
        }
        $allTranslationIds = array_keys($translationIdToCode);

        // Fetch all passages in 2 queries instead of 2N
        $passagesByTid = $this->passageRepository->fetchPassageBatch(
            $book->getId(), $chapter, $verse, $allTranslationIds
        );
        $allPassages = [];
        foreach ($passagesByTid as $tid => $data) {
            $allPassages[$translationIdToCode[$tid]] = $data;
        }

        // SV is the canonical source for testament + Hebrew/Greek word list
        $basePassage = $allPassages['SV'] ?? reset($allPassages);

        // Propagate SV source links to all non-SV translations in 1 query instead of N-1
        $propagatedLinks = [];
        if ($svTranslation) {
            $targetIds = array_values(array_filter(
                $allTranslationIds,
                fn($id) => $translationIdToCode[$id] !== 'SV'
            ));
            $propagatedByTid = $this->passageRepository->fetchPropagatedLinksForVerseBatch(
                $book->getId(), $chapter, $verse,
                $basePassage['testament'],
                $svTranslation->getId(),
                $targetIds,
            );
            foreach ($propagatedByTid as $tid => $data) {
                $propagatedLinks[$translationIdToCode[$tid]] = $data;
            }
        }

        // Enrich source words with morphology + per-translation link data
        foreach ($basePassage['source_words'] as $i => &$word) {
            if ($basePassage['testament'] === 'NT' && !empty($word['parse_code'])) {
                $word['morph_description'] = $this->morphologyParser->describeGreek($word['parse_code']);
            } elseif ($basePassage['testament'] === 'OT' && !empty($word['morph_code'])) {
                $word['morph_description'] = $this->morphologyParser->describeHebrew($word['morph_code']);
            } else {
                $word['morph_description'] = '';
            }

            // Add per-translation link arrays (e.g. links_sv, links_hsv)
            foreach ($allTranslations as $trans) {
                $code = $trans->getCode();
                if ($code === 'SV') {
                    $word['links_sv'] = $allPassages['SV']['source_words'][$i]['dutch_links'] ?? [];
                } else {
                    // Prefer direct word_links (e.g. manually linked HSV words).
                    // Fall back to ITL-propagated links when no direct links exist.
                    $direct    = $allPassages[$code]['source_words'][$i]['dutch_links'] ?? [];
                    $propagated = $propagatedLinks[$code][$word['id']] ?? [];
                    $word['links_' . strtolower($code)] = !empty($direct) ? $direct : $propagated;
                }
            }
            unset($word['dutch_links']);
        }
        unset($word);

        // Build the combined passage structure
        $passage = [
            'testament'    => $basePassage['testament'],
            'source_words' => $basePassage['source_words'],
        ];
        foreach ($allTranslations as $trans) {
            $code = $trans->getCode();
            $dutchVerse = $allPassages[$code]['dutch_verse'] ?? [];

            $source = $this->crossReferenceRepository->sourceForTranslationCode($code);
            $markersByPos = ($source !== null)
                ? $this->crossReferenceRepository->fetchForVerse($book->getId(), $chapter, $verse, $source)
                : [];

            foreach ($dutchVerse as &$w) {
                $w['cross_ref_markers'] = $markersByPos[(int) $w['word_position']] ?? [];
            }
            unset($w);

            $passage['dutch_verse_' . strtolower($code)]  = $dutchVerse;
            $passage['cross_ref_prefix_' . strtolower($code)] = $markersByPos[0] ?? [];
        }

        // Navigation: reuse already-cached verse counts (getChapterVerseCounts is cached)
        $verseCounts = $this->passageRepository->getChapterVerseCounts($book->getId());
        $nav = $this->buildNavigation($book->getId(), $chapter, $verse, $usfm, $translation, $verseCounts);

        $template = $request->headers->get('Turbo-Frame')
            ? 'bible/verse_frame.html.twig'
            : 'bible/verse.html.twig';

        return $this->render($template, [
            'book'         => $book,
            'chapter'      => $chapter,
            'verse'        => $verse,
            'passage'      => $passage,
            'nav'          => $nav,
            'translation'  => $translationEntity,
            'translations' => $allTranslations,
        ]);
    }

    /**
     * Strong's lookup — AJAX/Turbo endpoint showing all words with a given number.
     */
    #[Route('/strongs/{number}', name: 'app_strongs', requirements: ['number' => '[HGhg]\d+[A-Za-z]?'])]
    public function strongs(string $number): Response
    {
        return $this->render('bible/strongs.html.twig', [
            'strongs_number' => $number,
            'strongs_entry'  => $this->linkingRepository->fetchStrongsEntry($number),
        ]);
    }

    /**
     * Cross-reference target preview — AJAX/Turbo endpoint (same side panel as
     * Strong's) showing every verse a single letter-marker points to, in the
     * SAME translation the marker was clicked from, with the specific entry
     * that was clicked highlighted.
     *
     * `refs` is a comma-separated list of "USFM.chapter.verse" (all targets
     * under the clicked letter); `active` is which one of those was clicked.
     * A query string rather than path segments because the target count is
     * unbounded (a single footnote can list many verses).
     */
    #[Route('/kruisverwijzing-paneel/{translation}', name: 'app_cross_ref_panel')]
    public function crossRefPanel(string $translation, Request $request): Response
    {
        $translationEntity = $this->translationRepository->findByCode($translation);
        if (!$translationEntity) {
            throw $this->createNotFoundException("Translation '{$translation}' not found.");
        }

        $active = (string) $request->query->get('active', '');
        $refs   = array_filter(explode(',', (string) $request->query->get('refs', '')));

        $verses = [];
        foreach ($refs as $ref) {
            $parts = explode('.', $ref);
            if (count($parts) !== 3) {
                continue;
            }
            [$usfm, $chapter, $verse] = $parts;
            $book = $this->bookRepository->findByUsfmCode($usfm);
            if (!$book || !ctype_digit($chapter) || !ctype_digit($verse)) {
                continue;
            }
            $chapter = (int) $chapter;
            $verse   = (int) $verse;

            $verses[] = [
                'book'      => $book,
                'chapter'   => $chapter,
                'verse'     => $verse,
                'text'      => $this->passageRepository->fetchVerseText(
                    $book->getId(), $chapter, $verse, $translationEntity->getId()
                ),
                'is_active' => $ref === $active,
            ];
        }

        return $this->render('bible/_cross_ref_panel.html.twig', [
            'translation' => $translationEntity,
            'verses'      => $verses,
        ]);
    }

    // ── helpers ──────────────────────────────────────────────────────────────

    private function buildNavigation(int $bookId, int $chapter, int $verse, string $usfm, string $translation, ?array $counts = null): array
    {
        $counts ??= $this->passageRepository->getChapterVerseCounts($bookId);
        $verseCount = collect_verse_count($counts, $chapter);

        $prev = $next = null;

        if ($verse > 1) {
            $prev = ['usfm' => $usfm, 'chapter' => $chapter, 'verse' => $verse - 1, 'translation' => $translation];
        } elseif ($chapter > 1) {
            $prevCount = collect_verse_count($counts, $chapter - 1);
            $prev = ['usfm' => $usfm, 'chapter' => $chapter - 1, 'verse' => $prevCount, 'translation' => $translation];
        }

        if ($verse < $verseCount) {
            $next = ['usfm' => $usfm, 'chapter' => $chapter, 'verse' => $verse + 1, 'translation' => $translation];
        } else {
            $chapterCount = count($counts);
            if ($chapter < $chapterCount) {
                $next = ['usfm' => $usfm, 'chapter' => $chapter + 1, 'verse' => 1, 'translation' => $translation];
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
