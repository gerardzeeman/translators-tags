<?php

namespace App\Controller;

use App\Repository\ConfessionRepository;
use App\Repository\SpellingRuleRepository;
use App\Repository\TextFlagRepository;
use App\Service\SpellingContext;
use App\Service\SpellingModernizer;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

/**
 * Calvin's Bible commentaries, next to the Institutio -- read-only browsing.
 * Pilot: Genesis (work 'calvijn-genesis', ingested by
 * ingest/institutio/scripts/parse_calvin_genesis_la.py / _nl.py /
 * load_calvin_genesis.py).
 *
 * Data comes through ConfessionRepository, which already reads any work of
 * the Institutio schema by slug (segments with their tokens and
 * translation layers); a commentary's segments are of kind 'argument',
 * 'scripture' (Calvin's Latin rendering of a verse) and 'commentary' (his
 * comment on a verse) -- see db/migrate_add_commentary_segments.sql.
 */
class CommentaryController extends AbstractController
{
    /**
     * URL slug => the commentary work and its Bible book. Only works that
     * are actually ingested belong here.
     */
    private const COMMENTARIES = [
        'genesis' => [
            'work'     => 'calvijn-genesis',
            'usfm'     => 'GEN',
            'book_nl'  => 'Genesis',
            'title'    => 'Commentaar op Genesis',
            'subtitle' => 'Johannes Calvijn, 1554 — Latijnse tekst (Calvini Opera 23) met de Nederlandse vertaling van S.O. Los (1900)',
            'argument_prefix' => 'Comm. Gen. arg',
        ],
    ];

    /** Dutch translation layer of each commentary (historical translation). */
    private const DUTCH_LAYER = 'los1900';

    /** The Bible next to Calvin's text: the Statenvertaling (Jongbloed). */
    private const BIBLE_CODE = 'SV';

    public function __construct(
        private readonly ConfessionRepository $repository,
        private readonly SpellingModernizer $spelling,
        private readonly SpellingRuleRepository $spellingRules,
        private readonly TextFlagRepository $flags,
    ) {}

    #[Route('/commentaren', name: 'app_commentary_index')]
    public function index(): Response
    {
        return $this->render('commentary/index.html.twig', ['commentaries' => self::COMMENTARIES]);
    }

    #[Route('/commentaren/{boek}', name: 'app_commentary_toc')]
    public function toc(string $boek): Response
    {
        $meta = $this->meta($boek);
        return $this->render('commentary/toc.html.twig', [
            'boek'     => $boek,
            'meta'     => $meta,
            'chapters' => $this->repository->getChapters($meta['work']),
        ]);
    }

    #[Route('/commentaren/{boek}/argumentum', name: 'app_commentary_argument', priority: 10)]
    public function argument(string $boek): Response
    {
        $meta = $this->meta($boek);
        $segments = $this->repository->getUnnumberedSection($meta['work'], $meta['argument_prefix']);
        $ids = array_map(fn($s) => $s['id'], $segments);
        $notes = $this->repository->getSegmentAnnotations($ids);
        $strongs = $this->repository->getWorkWordStrongs($meta['work'], 0);
        $choices = $this->spellingRules->choicesFor(self::DUTCH_LAYER, $ids);
        return $this->render('commentary/argument.html.twig', [
            'boek'     => $boek,
            'meta'     => $meta,
            'segments' => array_map(fn($s) => $this->withParts($s, $notes[$s['id']] ?? [], $strongs, $choices), $segments),
            'layer'    => self::DUTCH_LAYER,
        ] + $this->editing($ids));
    }

    #[Route('/commentaren/{boek}/{chapter<\d+>}', name: 'app_commentary_chapter')]
    public function chapter(string $boek, int $chapter): Response
    {
        $meta = $this->meta($boek);
        $data = $this->repository->getChapter($meta['work'], $chapter);
        if (!$data['articles']) {
            throw $this->createNotFoundException('Hoofdstuk niet gevonden.');
        }
        $ids = array_map(fn($s) => $s['id'], $data['articles']);
        $notes = $this->repository->getSegmentAnnotations($ids);
        $strongs = $this->repository->getWorkWordStrongs($meta['work'], $chapter);
        $choices = $this->spellingRules->choicesFor(self::DUTCH_LAYER, $ids);
        $segments = array_map(fn($s) => $this->withParts($s, $notes[$s['id']] ?? [], $strongs, $choices), $data['articles']);

        // Next to Calvin's own Latin rendering of each verse: Los's Dutch of
        // it (the segment's translation) and the Statenvertaling (Jongbloed),
        // the Bible Los's readers knew. Where Calvin's text joins verses
        // (1:22-23), the Statenvertaling's are joined too, as Los's are.
        $scripture = array_values(array_filter($segments, fn($s) => $s['kind'] === 'scripture'));
        $sv = $this->repository->getChapterVerses($meta['usfm'], $chapter, self::BIBLE_CODE);
        $verses = [];
        foreach ($scripture as $i => $s) {
            $until = isset($scripture[$i + 1]) ? $scripture[$i + 1]['section'] : PHP_INT_MAX;
            $parts = [];
            foreach ($sv as $v => $text) {
                if ($v >= (int) $s['section'] && $v < $until) {
                    $parts[] = ($v === (int) $s['section'] ? '' : $v . '. ') . $text;
                }
            }
            $verses[$s['section']] = implode(' ', $parts);
        }

        return $this->render('commentary/chapter.html.twig', [
            'boek'       => $boek,
            'meta'       => $meta,
            'chapter'    => $chapter,
            'scripture'  => $scripture,
            'comments'   => array_values(array_filter($segments, fn($s) => $s['kind'] === 'commentary')),
            'verses'     => $verses,
            'layer'      => self::DUTCH_LAYER,
            'nav'        => $this->repository->getAdjacentChapters($meta['work'], $chapter),
        ] + $this->editing($ids));
    }

    /**
     * For an editor of the commentary (ROLE_EDIT_SPELLING) only: the flags
     * on these segments, and what they can be (TextFlagRepository), for the
     * flags and the per-spot choices on the page -- shown when the marks
     * are switched on (spelling_marks_controller.js).
     *
     * @param list<int> $segmentIds
     * @return array{can_edit: bool, flags: array<int, list<array<string, mixed>>>, flag_categories: array<string, string>}
     */
    private function editing(array $segmentIds): array
    {
        $canEdit = $this->isGranted('ROLE_EDIT_SPELLING');
        return [
            'can_edit'        => $canEdit,
            'flags'           => $canEdit ? $this->flags->forSegments($segmentIds) : [],
            'flag_categories' => TextFlagRepository::CATEGORIES,
        ];
    }

    /** @return array<string, string> */
    private function meta(string $boek): array
    {
        $meta = self::COMMENTARIES[$boek] ?? null;
        if ($meta === null || $this->repository->getWork($meta['work']) === null) {
            throw $this->createNotFoundException('Onbekend commentaar.');
        }
        return $meta;
    }

    /**
     * A segment with its Latin word-hover parts (plain text until the
     * segment is tokenized) -- the edition's footnotes placed in them as
     * hover markers -- and its Dutch translation, if any, with the
     * Hebrew/Greek words of $strongs (surface => entry) marked.
     */
    private function withParts(array $s, array $notes = [], array $strongs = [], array $choices = []): array
    {
        return [
            'id'        => $s['id'],
            'ref'       => $s['ref'] ?? null,
            'kind'      => $s['kind'] ?? null,
            'section'   => $s['section'],
            'print_ref' => $s['print_ref'] ?? null,
            'parts'     => $this->repository->insertNoteParts(
                $this->repository->splitTextIntoWordParts($s['text_la'], $s['tokens']), $notes),
            'text_nl'   => $s['translations'][self::DUTCH_LAYER] ?? null,
            'nl_paras'  => $paras = self::dutchParagraphs($s['translations'][self::DUTCH_LAYER] ?? null, $strongs),
            // Los's comment in modern spelling (the rules of /commentaren/spelling);
            // not his Bible text
            // (with the choices made at single spots of this segment)
            'nl_modern' => ($s['kind'] ?? null) === 'scripture' ? [] : $this->spelling->paragraphs(
                $paras, self::DUTCH_LAYER, new SpellingContext($choices[$s['id']] ?? [])),
        ];
    }

    /**
     * The Dutch text as paragraphs of parts: plain text, and the places
     * where the translator's own print has a misprint -- marked in the text
     * by parse_calvin_genesis_nl.py as ⟦C:intended‖printed‖note⟧ -- which
     * show the intended word, marked, with what was printed and why on hover;
     * and the Hebrew/Greek words quoted that have a Strong's entry
     * ($strongs, surface => entry: ConfessionRepository::getWorkWordStrongs),
     * as 'word' parts with the same popup as the Latin text's.
     *
     * @param array<string, array{id: string, transliteration: ?string, meaning: ?string}> $strongs
     * @return list<list<array{type: string, content: string, printed?: string, note?: string, strongs?: array}>>
     */
    public static function dutchParagraphs(?string $text, array $strongs = []): array
    {
        if ($text === null || $text === '') {
            return [];
        }
        $paragraphs = [];
        foreach (explode("\n\n", $text) as $paragraph) {
            $parts = [];
            foreach (preg_split('/(⟦C:[^‖⟧]*‖[^‖⟧]*‖[^⟧]*⟧)/u', $paragraph, -1, PREG_SPLIT_DELIM_CAPTURE | PREG_SPLIT_NO_EMPTY) as $piece) {
                if (preg_match('/^⟦C:([^‖⟧]*)‖([^‖⟧]*)‖([^⟧]*)⟧$/u', $piece, $m)) {
                    $parts[] = ['type' => 'correction', 'content' => $m[1], 'printed' => $m[2], 'note' => $m[3]];
                } else {
                    // a Hebrew word (letters and points, maqaf ends it) or a
                    // Greek one -- as link_commentary_strongs.py finds them
                    $words = '/([\x{05D0}-\x{05EA}\x{0591}-\x{05BD}\x{05BF}-\x{05C7}]*[\x{05D0}-\x{05EA}]'
                           . '[\x{05D0}-\x{05EA}\x{0591}-\x{05BD}\x{05BF}-\x{05C7}]*|[\x{0370}-\x{03FF}\x{1F00}-\x{1FFF}]+)/u';
                    foreach (preg_split($words, $piece, -1, PREG_SPLIT_DELIM_CAPTURE | PREG_SPLIT_NO_EMPTY) as $bit) {
                        $parts[] = isset($strongs[$bit])
                            ? ['type' => 'word', 'content' => $bit, 'strongs' => $strongs[$bit]]
                            : ['type' => 'text', 'content' => $bit];
                    }
                }
            }
            $paragraphs[] = $parts;
        }
        return $paragraphs;
    }
}
