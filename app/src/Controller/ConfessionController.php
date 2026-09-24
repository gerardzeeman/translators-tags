<?php

namespace App\Controller;

use App\Repository\ConfessionRepository;
use App\Service\ScriptureReferenceFinder;
use App\Service\TranslationAccessService;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

/**
 * Public read-only browsing for the confessional documents ingested via the
 * Institutio pipeline's generic work/segment/token schema (Canones
 * Dordraceni, and future works). See ConfessionRepository's docblock for why
 * this is a separate controller/repository from InstitutioController rather
 * than a generalization of it.
 *
 * No Dutch translation exists yet for these works (see PROJECTDOSSIER.md),
 * so every page here shows Latin only, with the same word-hover lemma-gloss
 * popup the Institutio pages use (import institutio/_macros.html.twig).
 */
class ConfessionController extends AbstractController
{
    /**
     * Slug => display metadata for every confession work this controller
     * knows how to browse. A work only appears here once it actually has
     * ingested segments -- listing a not-yet-ingested slug would just 404
     * every link on the index page.
     */
    private const WORKS = [
        'canones-dordraceni' => [
            'title'    => 'Dordtse Leerregels',
            'subtitle' => 'Canones Synodi Dordrechtanae, 1619 — Latijnse tekst (oertaal van de synode)',
            'type'     => 'chapters',
            'voorwoord_prefix' => 'Canones voorwoord',
            'besluit_prefix'   => 'Canones besluit',
        ],
        'ngb' => [
            'title'    => 'Nederlandse Geloofsbelijdenis',
            'subtitle' => 'Confessio Belgica, 1561/1619 — Latijnse vertaling (Hommius, herzien door de Synode van Dordrecht)',
            'type'     => 'flat',
            'voorwoord_prefix' => null,
            'besluit_prefix'   => null,
        ],
        'heidelbergse-catechismus' => [
            'title'    => 'Heidelbergse Catechismus',
            'subtitle' => 'Catechesis Palatina — Latijnse tekst uit de academische commentaartraditie (o.a. Rudolph, 1697), met de editio princeps van Lagus & Pithopoeus (1563) als aanvullende laag',
            'type'     => 'chapters',
            'voorwoord_prefix' => 'HC voorwoord',
            'besluit_prefix'   => null,
        ],
    ];

    /**
     * Zondag (Lord's Day) number => the first HC question number it
     * covers. This is the catechism's fixed 16th-century structure -- the
     * 129 questions were divided into 52 "Zondagen" for weekly preaching
     * through the year -- not something derived from the ingest, so it's
     * hardcoded here rather than stored per segment. Verified against the
     * question ranges published at heidelbergse-catechismus.nl and
     * online-bijbel.nl (e.g. Zondag 30 = Q80-82, the Mass/papacy
     * questions; Zondag 45 = Q116-119, on prayer) -- the 52 start numbers
     * below partition all 129 questions with no gaps or overlaps.
     */
    private const HC_ZONDAG_STARTS = [
        1 => 1, 2 => 3, 3 => 6, 4 => 9, 5 => 12, 6 => 16, 7 => 20, 8 => 24,
        9 => 26, 10 => 27, 11 => 29, 12 => 31, 13 => 33, 14 => 35, 15 => 37,
        16 => 40, 17 => 45, 18 => 46, 19 => 50, 20 => 53, 21 => 54, 22 => 57,
        23 => 59, 24 => 62, 25 => 65, 26 => 69, 27 => 72, 28 => 75, 29 => 78,
        30 => 80, 31 => 83, 32 => 86, 33 => 88, 34 => 92, 35 => 96, 36 => 99,
        37 => 101, 38 => 103, 39 => 104, 40 => 105, 41 => 108, 42 => 110,
        43 => 112, 44 => 113, 45 => 116, 46 => 120, 47 => 122, 48 => 123,
        49 => 124, 50 => 125, 51 => 126, 52 => 127,
    ];

    /**
     * Chapter number => the traditional Dutch title of that Pars, as used
     * across Dutch Reformed churches (matches the headings embedded in the
     * Den Heijer transcription -- see parse_hc_nl_denheijer.py's
     * _TRAILING_HEADING_RE, which strips these same three strings back out
     * of the answer text they get scraped into). Chapter 1 (Q1-2) isn't
     * itself a "deel" in this scheme -- it's the general introduction
     * before the three-part division starts at Q3 -- so it has no entry.
     */
    private const HC_CHAPTER_TITLE_NL = [
        2 => 'Het eerste deel: Van des mensen ellende',
        3 => 'Het tweede deel: Van des mensen verlossing',
        4 => 'Het derde deel: Van de dankbaarheid, die men Gode voor de verlossing schuldig is',
    ];

    /**
     * Chapter number => [first, last] global HC question number it spans
     * (chapter 1 is the Q1-2 introduction before the tripartite division;
     * 2/3/4 are Prima/Secunda/Tertia Pars). Used only to work out which
     * Zondagen fall in which chapter for the table-of-contents links --
     * the chapter page itself doesn't need this, since hcZondagStartingAt()
     * there works straight off each segment's own ref.
     */
    private const HC_CHAPTER_QUESTION_RANGES = [
        1 => [1, 2],
        2 => [3, 11],
        3 => [12, 85],
        4 => [86, 129],
    ];

    /**
     * The Dutch translation layers proof-text letters are placed in (the
     * layers segment_proof_text_anchor has anchors for).
     */
    private const PROOF_TEXT_LAYERS = ['denheijer', 'zwanepol-hsv'];

    public function __construct(
        private readonly ConfessionRepository $repository,
        private readonly TranslationAccessService $translationAccess,
        private readonly ScriptureReferenceFinder $referenceFinder,
    ) {}

    /**
     * Returns the Zondag number if $ref (e.g. "HC 34") is that Zondag's
     * first question, so the template can insert a "Zondag N" sub-heading
     * right before this segment -- null for every other question, and for
     * any non-HC ref (only the HC uses this structure).
     */
    private function hcZondagStartingAt(?string $ref): ?int
    {
        if ($ref === null || !preg_match('/^HC (\d+)$/', $ref, $m)) {
            return null;
        }
        $zondag = array_search((int) $m[1], self::HC_ZONDAG_STARTS, true);
        return $zondag === false ? null : $zondag;
    }

    /**
     * @return array<int, array<int, int>> chapter number => list of Zondag
     *     numbers that begin within it, for the table-of-contents page.
     */
    private function hcZondagenByChapter(): array
    {
        $byChapter = [];
        foreach (self::HC_CHAPTER_QUESTION_RANGES as $chapter => [$first, $last]) {
            $byChapter[$chapter] = array_values(array_filter(
                array_keys(self::HC_ZONDAG_STARTS),
                fn($zondag) => self::HC_ZONDAG_STARTS[$zondag] >= $first && self::HC_ZONDAG_STARTS[$zondag] <= $last
            ));
        }
        return $byChapter;
    }

    #[Route('/belijdenisgeschriften', name: 'app_confession_index')]
    public function index(): Response
    {
        $works = [];
        foreach (self::WORKS as $slug => $meta) {
            if ($this->repository->getWork($slug) !== null) {
                $works[] = ['slug' => $slug, ...$meta];
            }
        }

        return $this->render('confession/index.html.twig', ['works' => $works]);
    }

    #[Route('/belijdenisgeschriften/{werk}', name: 'app_confession_toc')]
    public function toc(string $werk): Response
    {
        $meta = self::WORKS[$werk] ?? null;
        $work = $meta !== null ? $this->repository->getWork($werk) : null;
        if ($work === null) {
            throw $this->createNotFoundException('Onbekend werk.');
        }

        if ($meta['type'] === 'flat') {
            return $this->render('confession/flat.html.twig', [
                'werk'     => $werk,
                'title'    => $meta['title'],
                'subtitle' => $meta['subtitle'],
                'articles' => $this->withWordParts($this->repository->getFlatArticles($werk), true),
            ]);
        }

        return $this->render('confession/toc.html.twig', [
            'werk'             => $werk,
            'title'            => $meta['title'],
            'subtitle'         => $meta['subtitle'],
            'chapters'         => $this->repository->getChapters($werk),
            'zondagen_by_chapter' => $werk === 'heidelbergse-catechismus' ? $this->hcZondagenByChapter() : [],
            'chapter_title_nl' => $werk === 'heidelbergse-catechismus' ? self::HC_CHAPTER_TITLE_NL : [],
            'has_voorwoord'    => $meta['voorwoord_prefix'] !== null
                && $this->repository->hasUnnumberedSection($werk, $meta['voorwoord_prefix']),
            'has_besluit'      => $meta['besluit_prefix'] !== null
                && $this->repository->hasUnnumberedSection($werk, $meta['besluit_prefix']),
        ]);
    }

    #[Route('/belijdenisgeschriften/{werk}/voorwoord', name: 'app_confession_front')]
    public function front(string $werk): Response
    {
        $this->assertKnownWork($werk);
        $prefix = self::WORKS[$werk]['voorwoord_prefix'] ?? null;
        $segments = $prefix !== null ? $this->repository->getUnnumberedSection($werk, $prefix) : [];
        if (!$segments) {
            throw $this->createNotFoundException('Voorwoord niet gevonden.');
        }
        return $this->render('confession/section.html.twig', [
            'werk' => $werk, 'title' => self::WORKS[$werk]['title'], 'heading' => 'Voorwoord',
            'segments' => $this->withWordParts($segments, $werk !== 'heidelbergse-catechismus'),
        ]);
    }

    #[Route('/belijdenisgeschriften/{werk}/besluit', name: 'app_confession_back')]
    public function back(string $werk): Response
    {
        $this->assertKnownWork($werk);
        $prefix = self::WORKS[$werk]['besluit_prefix'] ?? null;
        $segments = $prefix !== null ? $this->repository->getUnnumberedSection($werk, $prefix) : [];
        if (!$segments) {
            throw $this->createNotFoundException('Besluit niet gevonden.');
        }
        return $this->render('confession/section.html.twig', [
            'werk' => $werk, 'title' => self::WORKS[$werk]['title'], 'heading' => 'Besluit',
            'segments' => $this->withWordParts($segments, $werk !== 'heidelbergse-catechismus'),
        ]);
    }

    #[Route('/belijdenisgeschriften/{werk}/{chapter<\d+>}', name: 'app_confession_chapter')]
    public function chapter(string $werk, int $chapter): Response
    {
        $this->assertKnownWork($werk);
        $data = $this->repository->getChapter($werk, $chapter);
        if (!$data['articles'] && !$data['rejections']) {
            throw $this->createNotFoundException('Hoofdstuk niet gevonden.');
        }

        return $this->render('confession/chapter.html.twig', [
            'werk'       => $werk,
            'title'      => self::WORKS[$werk]['title'],
            'chapter'    => $chapter,
            'heading'    => $data['heading'],
            'articles'   => $this->withWordParts($data['articles'], $werk !== 'heidelbergse-catechismus'),
            'rejections' => $this->withWordParts($data['rejections'], $werk !== 'heidelbergse-catechismus'),
            'nav'        => $this->repository->getAdjacentChapters($werk, $chapter),
        ]);
    }

    /**
     * Verse side panel for one proof-text letter -- Turbo Frame endpoint,
     * same frame id and side panel as the Institutio's Scripture-citation
     * panel (verse_panel_controller.js). Shows the HSV to users allowed to
     * see it (it's copyrighted, see TranslationAccessService), the
     * unrestricted Statenvertaling to everyone else.
     */
    #[Route('/belijdenisgeschriften/bewijstekst/{id<\d+>}', name: 'app_confession_proof_text', priority: 10)]
    public function proofText(int $id): Response
    {
        $translationCode = $this->translationAccess->isVisible('HSV') ? 'HSV' : 'SV';
        $proofText = $this->repository->getProofTextVerses($id, $translationCode);
        if ($proofText === null) {
            throw $this->createNotFoundException('Bewijstekst niet gevonden.');
        }
        return $this->render('confession/verse_panel.html.twig', [
            'context'         => $proofText['ref'] . ' (' . $proofText['glyph'] . ')',
            'refs'            => $proofText['refs'],
            'translationCode' => $translationCode,
        ]);
    }

    /**
     * Verse side panel for a Bible reference written inline in a confession
     * text (made clickable via ScriptureReferenceFinder): ?r= is its encoded
     * reference list, e.g. "ROM.3.19-19;ROM.3.23-23". Same frame and panel
     * as proofText() above.
     */
    #[Route('/belijdenisgeschriften/vers', name: 'app_confession_verse', priority: 10)]
    public function verse(Request $request): Response
    {
        $refs = $this->referenceFinder->decode((string) $request->query->get('r', ''));
        $translationCode = $this->translationAccess->isVisible('HSV') ? 'HSV' : 'SV';
        return $this->render('confession/verse_panel.html.twig', [
            'context'         => null,
            'refs'            => $this->repository->getVersesForRefs($refs, $translationCode),
            'translationCode' => $translationCode,
        ]);
    }

    // priority: 10 -- otherwise this and saveText() below are shadowed by the
    // {werk}/{chapter<\d+>} route above, which happily matches "bewerk/5" as
    // werk="bewerk", chapter=5 (confirmed by browser-testing: without this,
    // GET /belijdenisgeschriften/bewerk/5 404s from assertKnownWork('bewerk')
    // instead of reaching this method).
    #[Route('/belijdenisgeschriften/bewerk/{id<\d+>}', name: 'app_confession_edit_text', methods: ['GET'], priority: 10)]
    #[IsGranted('ROLE_EDIT_INSTITUTIO_TRNL')]
    public function editText(int $id): Response
    {
        $segment = $this->repository->getSegmentText($id);
        if ($segment === null) {
            throw $this->createNotFoundException('Segment niet gevonden.');
        }
        return $this->render('confession/edit_text.html.twig', [
            'segment' => $segment,
            'history' => $this->repository->getTextCorrectionHistory($id),
        ]);
    }

    #[Route('/belijdenisgeschriften/bewerk/{id<\d+>}', name: 'app_confession_save_text', methods: ['POST'], priority: 10)]
    #[IsGranted('ROLE_EDIT_INSTITUTIO_TRNL')]
    public function saveText(int $id, Request $request): Response
    {
        $segment = $this->repository->getSegmentText($id);
        if ($segment === null) {
            throw $this->createNotFoundException('Segment niet gevonden.');
        }

        if (!$this->isCsrfTokenValid('confession_edit_text', (string) $request->request->get('_csrf_token'))) {
            throw $this->createAccessDeniedException('Ongeldig CSRF-token.');
        }

        $newText = trim((string) $request->request->get('text_la'));
        $note = trim((string) $request->request->get('note')) ?: null;
        if ($newText === '') {
            $this->addFlash('error', 'De tekst mag niet leeg zijn.');
            return $this->redirectToRoute('app_confession_edit_text', ['id' => $id]);
        }

        $user = $this->getUser();
        $this->repository->saveTextCorrection($id, $newText, $user?->getId(), $note);

        $this->addFlash('success', 'Correctie opgeslagen. Dit segment moet opnieuw getokeniseerd worden (tokenize_latin.py) voordat woord-hover weer klopt.');
        return $this->redirectToRoute('app_confession_edit_text', ['id' => $id]);
    }

    // Dutch-translation counterpart to editText/saveText above -- same
    // direct-write-with-audit-trail pattern, applied to a translation
    // row's text_nl instead of the segment's text_la. Keyed by
    // segment+layer rather than translation.id: ConfessionRepository::
    // attachTokens() only ever hands templates a layer => text_nl map, so
    // building edit links doesn't require threading translation.id through
    // every template that renders a translation (see chapter/flat/section
    // templates' "translations" loops).
    #[Route('/belijdenisgeschriften/vertaling/{segmentId<\d+>}/{layer}/bewerk', name: 'app_confession_edit_translation', methods: ['GET'])]
    #[IsGranted('ROLE_EDIT_INSTITUTIO_TRNL')]
    public function editTranslation(int $segmentId, string $layer): Response
    {
        $translation = $this->repository->getTranslationForEdit($segmentId, $layer);
        if ($translation === null) {
            throw $this->createNotFoundException('Vertaling niet gevonden.');
        }
        return $this->render('confession/edit_translation.html.twig', [
            'translation' => $translation,
            'history'     => $this->repository->getTranslationCorrectionHistory($translation['id']),
        ]);
    }

    #[Route('/belijdenisgeschriften/vertaling/{segmentId<\d+>}/{layer}/bewerk', name: 'app_confession_save_translation', methods: ['POST'])]
    #[IsGranted('ROLE_EDIT_INSTITUTIO_TRNL')]
    public function saveTranslation(int $segmentId, string $layer, Request $request): Response
    {
        $translation = $this->repository->getTranslationForEdit($segmentId, $layer);
        if ($translation === null) {
            throw $this->createNotFoundException('Vertaling niet gevonden.');
        }

        if (!$this->isCsrfTokenValid('confession_edit_translation', (string) $request->request->get('_csrf_token'))) {
            throw $this->createAccessDeniedException('Ongeldig CSRF-token.');
        }

        $newText = trim((string) $request->request->get('text_nl'));
        $note = trim((string) $request->request->get('note')) ?: null;
        if ($newText === '') {
            $this->addFlash('error', 'De tekst mag niet leeg zijn.');
            return $this->redirectToRoute('app_confession_edit_translation', ['segmentId' => $segmentId, 'layer' => $layer]);
        }

        $user = $this->getUser();
        $this->repository->saveTranslationCorrection($translation['id'], $newText, $user?->getId(), $note);

        $this->addFlash('success', 'Correctie opgeslagen.');
        return $this->redirectToRoute('app_confession_edit_translation', ['segmentId' => $segmentId, 'layer' => $layer]);
    }

    /**
     * ScriptureReferenceFinder spans with their refs encoded for the verse
     * panel link (ConfessionRepository::applyReferenceSpans()).
     */
    private function encodedSpans(array $spans): array
    {
        return array_map(
            fn($s) => ['start' => $s['start'], 'end' => $s['end'], 'refs' => $this->referenceFinder->encode($s['refs'])],
            $spans
        );
    }

    private function assertKnownWork(string $werk): void
    {
        if (!isset(self::WORKS[$werk]) || $this->repository->getWork($werk) === null) {
            throw $this->createNotFoundException('Onbekend werk.');
        }
    }

    /**
     * @param array<int, array{text_la: string, tokens: array}> $segments
     * @return array<int, array{id: int, section: int, kind: ?string, heading: ?string, parts: array, qa: array}>
     */
    private function withWordParts(array $segments, bool $linkScripture = false): array
    {
        return array_map(
            function ($s) use ($linkScripture) {
                $parts = $this->repository->splitTextIntoWordParts($s['text_la'], $s['tokens']);
                $translations = $s['translations'] ?? [];
                // Bible references written inline in the text (Canones,
                // NGB -- not the HC, whose Scripture comes through the
                // proof-text letters) become links to the verse panel:
                // in the Latin word parts, and per translation layer as
                // layer => parts (Latin notation for the 1563 layer).
                $translationParts = [];
                if ($linkScripture) {
                    $parts = $this->repository->applyReferenceSpans($parts, $this->encodedSpans($this->referenceFinder->findLatin($s['text_la'])));
                    foreach ($translations as $layer => $text) {
                        $spans = $layer === 'editio-princeps-1563'
                            ? $this->referenceFinder->findLatin($text)
                            : $this->referenceFinder->findDutch($text);
                        $translationParts[$layer] = $this->repository->applyReferenceSpans(
                            [['type' => 'text', 'content' => $text]], $this->encodedSpans($spans));
                    }
                }
                // Toggleable 1563-vs-1697 diff highlighting (see the HC
                // chapter template): mark which words in the main Latin
                // text differ from the editio-princeps-1563 layer, when
                // that layer exists for this segment. Computed
                // unconditionally (cheap: an immediate equality check
                // short-circuits the 121 identical questions) so the
                // client-side toggle needs no round-trip.
                $editioPrinceps1563 = $translations['editio-princeps-1563'] ?? null;
                if ($editioPrinceps1563 !== null) {
                    $diffRanges = $this->repository->computeLatinDiffRanges($s['text_la'], $editioPrinceps1563);
                    $parts = $this->repository->markWordPartsDiffering($parts, $diffRanges);
                }
                // Word-hover for the 1563 column itself: built from the
                // layer's own LatinCy tokens (translation_token, via
                // ConfessionRepository::attachTokens()), not reused from
                // the main text -- so a question whose 1563 wording
                // differs still gets correct lemmas, not a plain-text
                // fallback. Only null if that pipeline step hasn't been
                // run for this segment yet.
                $editioPrincepsQa = isset($s['latinTranslationParts'])
                    ? $this->repository->splitPartsAtQuestionMark($s['latinTranslationParts'])
                    : null;
                // Proof-text letters ("bewijsteksten") inline in each Dutch
                // layer they're anchored in (see parse_hc_prooftexts_cgk.py),
                // as layer => {question, answer} parts. A layer is absent
                // when this segment has no proof texts (every non-HC work,
                // and the few HC questions without any), so the template
                // falls back to that layer's plain text.
                $proofTexts = $s['proofTexts'] ?? [];
                $proofParts = [];
                foreach ($proofTexts ? self::PROOF_TEXT_LAYERS : [] as $layer) {
                    if (isset($translations[$layer])) {
                        $proofParts[$layer] = $this->repository->splitPartsAtQuestionMark(
                            $this->repository->placeProofTextMarkers($translations[$layer], $proofTexts, $layer));
                    }
                }
                return [
                    'id'      => $s['id'],
                    'section' => $s['section'],
                    'kind'    => $s['kind'] ?? null,
                    'heading' => $s['heading'] ?? null,
                    'zondag'  => $this->hcZondagStartingAt($s['ref'] ?? null),
                    'parts'   => $parts,
                    // Vraag/Antwoord split for works that store one Q+A pair
                    // per segment (currently the HC) -- see
                    // ConfessionRepository::splitPartsAtQuestionMark(). Cheap
                    // to compute unconditionally; the template decides
                    // per-work whether to render this or the combined 'parts'.
                    'qa' => [
                        'latin'              => $this->repository->splitPartsAtQuestionMark($parts),
                        'editioPrinceps1563' => $editioPrincepsQa,
                        'nl'    => array_map(
                            fn($text) => $this->repository->splitTextAtQuestionMark($text),
                            $translations
                        ),
                    ],
                    'translations' => $translations,
                    'translationParts' => $translationParts,
                    'proofTexts'   => $proofTexts,
                    'proofParts'   => $proofParts,
                ];
            },
            $segments
        );
    }
}
