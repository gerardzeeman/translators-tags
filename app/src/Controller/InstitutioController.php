<?php

namespace App\Controller;

use App\Repository\InstitutioRepository;
use App\Service\CitationFormatter;
use App\Service\MorphologyParser;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

class InstitutioController extends AbstractController
{
    /**
     * Temporary validation aid: also show the raw "[...]" bracket text
     * inline in the Latin display, right before the citation marker,
     * instead of only the marker + hover note -- makes it easy to eyeball-
     * check the extracted note against the original bracket while the
     * citation-extraction feature is still being spot-checked. Uses the
     * already-stored note (which *is* the original bracket interior text),
     * so this is purely a rendering concern -- no re-parse/re-load needed,
     * and doesn't touch char offsets that sentence_alignment already
     * depends on. Flip to false once validated (matches the original
     * design: marker + hover note only, no visible duplicate text).
     */
    private const SHOW_RAW_CITATION_BRACKETS = true;

    public function __construct(
        private readonly InstitutioRepository $institutioRepository,
        private readonly MorphologyParser      $morphologyParser,
        private readonly CitationFormatter     $citationFormatter,
    ) {}

    /**
     * Home page — table of contents: front matter + every book's chapters
     * with their titles, each linking to the corresponding page.
     */
    #[Route('/institutio', name: 'app_institutio_home')]
    public function home(): Response
    {
        $toc = $this->institutioRepository->getTableOfContents();

        return $this->render('institutio/home.html.twig', [
            'front_matter'        => $toc['front_matter'],
            'books'               => $toc['books'],
            'book_chapter_counts' => $this->institutioRepository->getBookChapterCounts(),
            'has_front_matter'    => $toc['front_matter'] !== null,
        ]);
    }

    /**
     * Front matter — the dedicatory letter to Francis I (book/chapter are NULL).
     */
    #[Route('/institutio/voorwoord', name: 'app_institutio_front')]
    public function front(): Response
    {
        $data = $this->institutioRepository->getFrontMatter();
        if (!$data['sections']) {
            throw $this->createNotFoundException('Voorwoord niet gevonden.');
        }

        return $this->render('institutio/chapter.html.twig', [
            'book'                => null,
            'chapter'             => null,
            'heading'             => $data['heading'],
            'heading_nl'          => $data['heading_nl'],
            'sections'            => $this->withRenderParts($data['sections']),
            'book_chapter_counts' => $this->institutioRepository->getBookChapterCounts(),
            'has_front_matter'    => true,
            'nav'                 => $this->buildNavigation(null, null),
        ]);
    }

    /**
     * One chapter — heading plus its numbered sections (e.g. "1.2: Quid sit
     * Deum cognoscere..." followed by items 1, 2, ...).
     */
    #[Route('/institutio/{book<\d+>}/{chapter<\d+>}', name: 'app_institutio_chapter')]
    public function chapter(int $book, int $chapter): Response
    {
        $counts = $this->institutioRepository->getBookChapterCounts();
        if (!isset($counts[$book]) || $chapter < 1 || $chapter > $counts[$book]) {
            throw $this->createNotFoundException("Institutie {$book}.{$chapter} niet gevonden.");
        }

        $data = $this->institutioRepository->getChapter($book, $chapter);

        return $this->render('institutio/chapter.html.twig', [
            'book'                => $book,
            'chapter'             => $chapter,
            'heading'             => $data['heading'],
            'heading_nl'          => $data['heading_nl'],
            'sections'            => $this->withRenderParts($data['sections']),
            'book_chapter_counts' => $counts,
            'has_front_matter'    => $this->institutioRepository->hasFrontMatter(),
            'nav'                 => $this->buildNavigation($book, $chapter),
        ]);
    }

    private const LEMMAS_PER_PAGE = 100;

    /**
     * Lemma frequency list — number, word, occurrence count. Most frequent
     * first, paginated (11k+ unique lemmas is too many for one page).
     */
    #[Route('/institutio/lemmas/{page<\d+>}', name: 'app_institutio_lemmas', defaults: ['page' => 1])]
    public function lemmas(int $page): Response
    {
        $total = $this->institutioRepository->getLemmaCount();
        $lastPage = max(1, (int) ceil($total / self::LEMMAS_PER_PAGE));
        if ($page < 1 || $page > $lastPage) {
            throw $this->createNotFoundException("Pagina {$page} bestaat niet.");
        }

        $offset = ($page - 1) * self::LEMMAS_PER_PAGE;
        $lemmas = $this->institutioRepository->getLemmaPage(self::LEMMAS_PER_PAGE, $offset);
        $variantsByLemma = $this->institutioRepository->getLemmaVariants(
            array_column($lemmas, 'lemma')
        );

        $lemmas = array_map(function ($l) use ($variantsByLemma) {
            $l['variants'] = array_map(
                fn($v) => [
                    'norm'              => $v['norm'],
                    'freq'              => $v['freq'],
                    'morph_description' => $this->morphologyParser->describeLatin($v['morph']),
                ],
                $variantsByLemma[$l['lemma']] ?? []
            );
            return $l;
        }, $lemmas);

        return $this->render('institutio/lemmas.html.twig', [
            'lemmas'              => $lemmas,
            'offset'              => $offset,
            'total'               => $total,
            'page'                => $page,
            'last_page'           => $lastPage,
            'book_chapter_counts' => $this->institutioRepository->getBookChapterCounts(),
            'has_front_matter'    => $this->institutioRepository->hasFrontMatter(),
        ]);
    }

    /**
     * Bible verse popup — Turbo Frame endpoint for the "click a Scripture
     * citation" side panel. Re-parses the raw Latin citation note (the same
     * one already shown in the hover) via CitationFormatter to find which
     * verse(s) it refers to, then looks each one up in the Herziene
     * Statenvertaling (already in this app's main Bible corpus).
     */
    #[Route('/institutio/vers', name: 'app_institutio_verse')]
    public function verse(Request $request): Response
    {
        $note = $request->query->get('note', '');
        $refs = $this->citationFormatter->extractBibleRefs($note);

        $verses = array_map(
            fn($ref) => [
                'label' => $ref['label'],
                'text'  => $this->institutioRepository->getHsvVerseText($ref['usfm'], $ref['chapter'], $ref['verse']),
            ],
            $refs
        );

        return $this->render('institutio/verse_panel.html.twig', ['verses' => $verses]);
    }

    // ── helpers ──────────────────────────────────────────────────────────────

    /**
     * Builds the sentence-by-sentence Latin/Dutch pairs for each section,
     * using the LLM-produced grouping in sentence_alignment (see
     * align_sentences.py) rather than pairing sentences by position -- the
     * translation prompt explicitly allows Calvin's long periods to be split
     * into multiple Dutch sentences, which broke naive positional pairing on
     * real data (confirmed: Inst. 1.1.1 drifts from sentence 3 onward).
     *
     * @param array<int, array{
     *     id: int, section: int, text: string, text_nl: ?string, annotations: array, tokens: array,
     *     sentence_alignment: array<int, array{la_start: int, nl_text: string}>
     * }> $sections
     * @return array<int, array{id: int, section: int, sentence_pairs: array}>
     */
    private function withRenderParts(array $sections): array
    {
        return array_map(
            fn($s) => [
                'id'             => $s['id'],
                'section'        => $s['section'],
                'sentence_pairs' => $this->buildSentencePairs(
                    $s['text'], $s['tokens'], $s['annotations'], $s['text_nl'], $s['sentence_alignment']
                ),
            ],
            $sections
        );
    }

    /**
     * Splices a Latin text range into render parts: word tokens (wrapped for
     * the lemma+gloss hover) and annotation markers (variant/citation
     * superscripts), interleaved in true character-position order, with
     * plain text runs (punctuation, whitespace) filling the gaps between.
     *
     * @param array<int, array{char_start: int, char_end: int, lemma: ?string, gloss: ?string}> $tokens
     *   Must be ordered by char_start ascending (guaranteed by the repository's SQL).
     * @param array<int, array{char_position: int, glyph: string, kind: string, note: string}> $annotations
     *   Must be ordered by char_position ascending (guaranteed by the repository's SQL).
     * @return array<int, array{type: string, content?: string, glyph?: string, kind?: string, note?: string, verse_href?: ?string, lemma?: ?string, gloss?: ?string}>
     */
    private function splitTextWithTokensAndAnnotations(string $text, array $tokens, array $annotations): array
    {
        $parts = [];
        $cursor = 0;
        $ai = 0;
        $aCount = count($annotations);

        $emitAnnotationsUpTo = function (int $limit) use (&$parts, &$cursor, &$ai, $annotations, $aCount, $text): void {
            while ($ai < $aCount && $annotations[$ai]['char_position'] <= $limit) {
                $a = $annotations[$ai];
                $pos = $a['char_position'];
                if ($pos > $cursor) {
                    $parts[] = ['type' => 'text', 'content' => mb_substr($text, $cursor, $pos - $cursor)];
                    $cursor = $pos;
                }
                if (self::SHOW_RAW_CITATION_BRACKETS && $a['kind'] === 'citation') {
                    $parts[] = ['type' => 'text', 'content' => '[' . $a['note'] . ']'];
                }
                $parts[] = [
                    'type'       => 'note',
                    'glyph'      => $a['glyph'],
                    'kind'       => $a['kind'],
                    'note'       => $a['note'],
                    'verse_href' => $this->citationVerseHref($a),
                ];
                $ai++;
            }
        };

        foreach ($tokens as $tok) {
            $start = $tok['char_start'];
            $end = $tok['char_end'];
            if ($start < $cursor) {
                continue; // overlapping/out-of-order token -- shouldn't happen, skip defensively
            }
            $emitAnnotationsUpTo($start);
            if ($start > $cursor) {
                $parts[] = ['type' => 'text', 'content' => mb_substr($text, $cursor, $start - $cursor)];
                $cursor = $start;
            }
            $parts[] = [
                'type'    => 'word',
                'content' => mb_substr($text, $start, $end - $start),
                'lemma'   => $tok['lemma'],
                'gloss'   => $tok['gloss'],
            ];
            $cursor = $end;
        }

        $emitAnnotationsUpTo(PHP_INT_MAX);

        $remaining = mb_substr($text, $cursor);
        if ($remaining !== '') {
            $parts[] = ['type' => 'text', 'content' => $remaining];
        }
        return $parts;
    }

    /**
     * Slices the Latin text at each alignment row's char offset (la_start)
     * and re-splices the tokens/annotations that fall within that slice,
     * pairing it with the row's ready-made Dutch text. If no alignment
     * exists yet (not translated, or translated but not yet aligned), the
     * whole section is returned as a single unsplit "pair".
     *
     * Each pair's annotations are also exposed as a flat list (without
     * char_position, which is meaningless on the Dutch side) so the
     * template can render citation markers after the Dutch sentence too --
     * variant readings (textual differences between print editions) don't
     * apply to a modern translation, so only citations are carried over.
     * There's no word-level Latin/Dutch alignment yet (that's SimAlign,
     * phase 4, not run), so a marker can't be placed at its exact position
     * in the translation; attaching it to the row's Dutch sentence(s) is the
     * coarsest correct placement available.
     *
     * @param array<int, array{char_start: int, char_end: int, lemma: ?string, gloss: ?string}> $tokens
     * @param array<int, array{char_position: int, glyph: string, kind: string, note: string}> $annotations
     * @param array<int, array{la_start: int, nl_text: string}> $alignmentRows Ordered by row_seq.
     * @return array<int, array{la_parts: array, nl_text: ?string, annotations: array}>
     */
    private function buildSentencePairs(string $text, array $tokens, array $annotations, ?string $textNl, array $alignmentRows): array
    {
        $citationsOnly = fn($ann) => array_map(
            $this->renderNlAnnotation(...),
            array_values(array_filter($ann, fn($a) => $a['kind'] === 'citation'))
        );

        if (!$alignmentRows) {
            return [[
                'la_parts'    => $this->splitTextWithTokensAndAnnotations($text, $tokens, $annotations),
                'nl_text'     => $textNl !== null ? trim($textNl) : null,
                'annotations' => $citationsOnly($annotations),
            ]];
        }

        $count = count($alignmentRows);
        $textLength = mb_strlen($text);
        $pairs = [];
        for ($i = 0; $i < $count; $i++) {
            $start = $alignmentRows[$i]['la_start'];
            $end = $i + 1 < $count ? $alignmentRows[$i + 1]['la_start'] : $textLength;

            $rowAnnotations = [];
            foreach ($annotations as $a) {
                if ($a['char_position'] >= $start && $a['char_position'] < $end) {
                    $rowAnnotations[] = [
                        'char_position' => $a['char_position'] - $start,
                        'glyph'         => $a['glyph'],
                        'kind'          => $a['kind'],
                        'note'          => $a['note'],
                    ];
                }
            }

            $rowTokens = [];
            foreach ($tokens as $t) {
                if ($t['char_start'] >= $start && $t['char_start'] < $end) {
                    $rowTokens[] = [
                        'char_start' => $t['char_start'] - $start,
                        'char_end'   => min($t['char_end'], $end) - $start,
                        'lemma'      => $t['lemma'],
                        'gloss'      => $t['gloss'],
                    ];
                }
            }

            $pairs[] = [
                'la_parts'    => $this->splitTextWithTokensAndAnnotations(
                    mb_substr($text, $start, $end - $start), $rowTokens, $rowAnnotations
                ),
                'nl_text'     => $alignmentRows[$i]['nl_text'],
                'annotations' => $citationsOnly($rowAnnotations),
            ];
        }
        return $pairs;
    }

    /**
     * Citation notes are still in the old print-apparatus form Calvin's own
     * text uses ("Iesa. 24. d. 23", the middle letter being a page-margin
     * marker, not a verse). CitationFormatter converts recognized Scripture
     * references to modern Dutch form ("Jes. 24:23") for display next to the
     * translation; anything it doesn't recognize (patristic/classical
     * citations, unusual formats) falls back to the original Latin note.
     *
     * @param array{glyph: string, kind: string, note: string} $a
     * @return array{glyph: string, kind: string, note: string, verse_href: ?string}
     */
    private function renderNlAnnotation(array $a): array
    {
        $note = $a['kind'] === 'citation'
            ? ($this->citationFormatter->toDutch($a['note']) ?? $a['note'])
            : $a['note'];
        return [
            'glyph'      => $a['glyph'],
            'kind'       => $a['kind'],
            'note'       => $note,
            'verse_href' => $this->citationVerseHref($a),
        ];
    }

    /**
     * URL to the "click a Scripture citation" verse popup (see verse()), or
     * null if this isn't a citation or CitationFormatter doesn't recognize a
     * Bible reference in it (patristic/classical citations, unusual formats
     * -- no false-positive links to a verse that isn't really there).
     *
     * @param array{glyph: string, kind: string, note: string} $a
     */
    private function citationVerseHref(array $a): ?string
    {
        if ($a['kind'] !== 'citation' || !$this->citationFormatter->extractBibleRefs($a['note'])) {
            return null;
        }
        return $this->generateUrl('app_institutio_verse', ['note' => $a['note']]);
    }

    private function buildNavigation(?int $book, ?int $chapter): array
    {
        $sequence = $this->institutioRepository->getChapterSequence();

        $currentIndex = null;
        foreach ($sequence as $i => $entry) {
            if ($entry['book'] === $book && $entry['chapter'] === $chapter) {
                $currentIndex = $i;
                break;
            }
        }

        $prev = $next = null;
        if ($currentIndex !== null) {
            if ($currentIndex > 0) {
                $prev = $sequence[$currentIndex - 1];
            }
            if ($currentIndex < count($sequence) - 1) {
                $next = $sequence[$currentIndex + 1];
            }
        }

        return ['prev' => $prev, 'next' => $next];
    }
}
