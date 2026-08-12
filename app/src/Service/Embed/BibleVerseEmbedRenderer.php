<?php

namespace App\Service\Embed;

use App\Entity\Book;
use App\Repository\BookRepository;
use App\Repository\PassageRepository;
use App\Repository\TranslationRepository;
use App\Service\MorphologyParser;
use App\Service\TranslationAccessService;
use Twig\Environment;

/**
 * Renders a ```bijbelvers fenced block: one or more verses in the source
 * language (Hebrew/Greek), optionally with a translation shown alongside
 * or below, optionally restricted to a word range within a single verse.
 */
class BibleVerseEmbedRenderer implements BlogEmbedRendererInterface
{
    private const MAX_VERSES = 30;

    /**
     * The CURRENT blog's author's (not the visitor viewing it) reachable
     * roles, resolved with role-hierarchy inheritance and set by
     * BlogMarkdownRenderer immediately before each render pass. Defaults to
     * an empty array (fail closed): without this being explicitly set, no
     * role-gated translation may be embedded. This gates authoring, not
     * viewing -- once a blog embeds e.g. HSV text legitimately, published
     * readers (including anonymous ones) still see it, matching how the
     * rest of the app treats published content. What it prevents is a
     * ROLE_BLOGGER account that was never granted the matching viewer role
     * using their own draft preview as a side channel to read a restricted
     * translation despite that.
     *
     * @var string[]
     */
    private array $authorRoles = [];

    public function __construct(
        private readonly BookRepository            $bookRepository,
        private readonly TranslationRepository     $translationRepository,
        private readonly PassageRepository         $passageRepository,
        private readonly MorphologyParser          $morphologyParser,
        private readonly TranslationAccessService  $translationAccess,
        private readonly Environment               $twig,
    ) {}

    /** @param string[] $authorRoles */
    public function setAuthorRoles(array $authorRoles): void
    {
        $this->authorRoles = $authorRoles;
    }

    public function supports(string $infoString): bool
    {
        return $infoString === 'bijbelvers';
    }

    public function render(array $config): string
    {
        $bookName = EmbedConfigParser::str($config, 'boek');
        $chapter  = EmbedConfigParser::int($config, 'hoofdstuk', 0);
        $verse    = EmbedConfigParser::int($config, 'vers', 0);

        if (!$bookName || $chapter < 1 || $verse < 1) {
            return $this->renderError('Onvolledige verswijzing (boek/hoofdstuk/vers ontbreekt).');
        }

        $book = $this->bookRepository->findByNameNl($bookName);
        if (!$book) {
            return $this->renderError(sprintf('Bijbelboek "%s" niet gevonden.', $bookName));
        }

        $aantalVerzen = max(1, min(self::MAX_VERSES, EmbedConfigParser::int($config, 'aantal_verzen', 1)));
        $toonVertaling   = EmbedConfigParser::bool($config, 'toon_vertaling', false);
        $alleenVertaling = EmbedConfigParser::bool($config, 'alleen_vertaling', false);
        $highlightLinks  = EmbedConfigParser::bool($config, 'highlight_links', true);
        $layout          = EmbedConfigParser::str($config, 'layout', 'naast-elkaar') === 'onder-elkaar' ? 'onder-elkaar' : 'naast-elkaar';

        // 'vertaling' is a comma-separated list of codes (one or more). A bare
        // single code (the historical format, e.g. 'vertaling: SV') still
        // works unchanged since splitting a comma-less string just yields one.
        $translationCodes = array_values(array_filter(array_map(
            'trim',
            explode(',', EmbedConfigParser::str($config, 'vertaling', 'SV'))
        )));
        if (empty($translationCodes)) {
            $translationCodes = ['SV'];
        }

        $translations = [];
        if ($toonVertaling || $alleenVertaling) {
            foreach (array_unique($translationCodes) as $code) {
                $t = $this->translationRepository->findByCode($code);
                if (!$t) {
                    return $this->renderError(sprintf('Vertaling "%s" niet gevonden.', $code));
                }
                // Same convention as the rest of the app (e.g. linking/passage.html.twig,
                // via TranslationAccessService): SV is unrestricted, every other
                // translation requires its own viewer role -- here, on the blog's
                // author, not the current visitor (see property doc above).
                if (!$this->translationAccess->isVisibleForRoles($t->getCode(), $this->authorRoles)) {
                    return $this->renderError(sprintf(
                        'Vertaling "%s" is niet beschikbaar voor deze blog.',
                        $t->getCode()
                    ));
                }
                $translations[] = $t;
            }
        }

        // SV is the authority translation: word_links are entered against it directly, and
        // every other translation's links are propagated from SV via inter_translation_links
        // (see PassageRepository::fetchPropagatedLinksForVerseBatch, same as BibleController::verse()).
        $svTranslation = $this->translationRepository->findByCode('SV');
        if (!$svTranslation) {
            return $this->renderError('Vertaling "SV" niet gevonden.');
        }

        // Translations to actually query per verse: the ones being displayed,
        // or just SV when this is a source-only embed (no Dutch panel at all).
        $queryTranslations = $translations ?: [$svTranslation];

        $wordFrom = $aantalVerzen === 1 ? EmbedConfigParser::int($config, 'woord_van', 0) : 0;
        $wordTo   = $aantalVerzen === 1 ? EmbedConfigParser::int($config, 'woord_tot', 0) : 0;

        $verseCounts = $this->passageRepository->getChapterVerseCounts($book->getId());
        $chapterCount = count($verseCounts);

        $verses = [];
        $curChapter = $chapter;
        $curVerse   = $verse;

        for ($i = 0; $i < $aantalVerzen; $i++) {
            $verseCount = $this->verseCountForChapter($verseCounts, $curChapter);
            if ($verseCount === null || $curVerse > $verseCount) {
                break;
            }

            // Fetch every selected translation for this verse. source_words (the
            // Hebrew/Greek text) is testament-only, identical across
            // translations, so it's taken from the first fetch; each
            // translation's own dutch_links are merged into it by word id so
            // hovering a source word highlights the right word in every
            // translation panel (ids are globally unique across
            // translation_words, so a plain merge can't collide).
            $sourceWords = null;
            $testament   = null;
            $dutchVersesByCode = [];

            foreach ($queryTranslations as $t) {
                $passage = $this->passageRepository->fetchPassage($book->getId(), $curChapter, $curVerse, $t->getId());

                $isFirst = $sourceWords === null;
                if ($isFirst) {
                    if (empty($passage['source_words'])) {
                        break 2;
                    }
                    $sourceWords = $passage['source_words'];
                    $testament   = $passage['testament'];
                }
                $dutchVersesByCode[$t->getCode()] = $passage['dutch_verse'] ?? [];

                // This translation's own per-word links: direct word_links (SV
                // only) or, for every other translation, links propagated from
                // SV via inter_translation_links.
                $tLinksById = [];
                foreach ($passage['source_words'] ?? [] as $w) {
                    $tLinksById[$w['id']] = $w['dutch_links'] ?? [];
                }
                $propagated = [];
                if ($t->getCode() !== 'SV') {
                    $batch = $this->passageRepository->fetchPropagatedLinksForVerseBatch(
                        $book->getId(), $curChapter, $curVerse, $testament,
                        $svTranslation->getId(), [$t->getId()],
                    );
                    $propagated = $batch[$t->getId()] ?? [];
                }

                // The first translation seeds source_words, so its links replace
                // the (possibly empty, for non-SV) placeholder fetchPassage put
                // there; every subsequent translation's links are merged in on
                // top (ids are globally unique across translation_words, so a
                // plain merge can't collide) -- this is what lets hovering a
                // source word highlight the right word in every panel.
                foreach ($sourceWords as &$word) {
                    $direct = $tLinksById[$word['id']] ?? [];
                    $links  = !empty($direct) ? $direct : ($propagated[$word['id']] ?? []);
                    $word['dutch_links'] = $isFirst
                        ? $links
                        : array_merge($word['dutch_links'] ?? [], $links);
                }
                unset($word);
            }

            if ($wordTo > 0) {
                $from = max(1, $wordFrom ?: 1);
                $to   = min(count($sourceWords), $wordTo);
                $sourceWordsInRange = array_slice($sourceWords, $from - 1, max(0, $to - $from + 1));

                $linkedIds = [];
                foreach ($sourceWordsInRange as $w) {
                    foreach ($w['dutch_links'] as $link) {
                        $linkedIds[$link['tw_id']] = true;
                    }
                }
                foreach ($dutchVersesByCode as $code => $dv) {
                    $dutchVersesByCode[$code] = array_values(array_filter($dv, fn($w) => isset($linkedIds[$w['word_id']])));
                }
                $sourceWords = $sourceWordsInRange;
            }

            foreach ($sourceWords as &$word) {
                if ($testament === 'NT' && !empty($word['parse_code'])) {
                    $word['morph_description'] = $this->morphologyParser->describeGreek($word['parse_code']);
                } elseif ($testament === 'OT' && !empty($word['morph_code'])) {
                    $word['morph_description'] = $this->morphologyParser->describeHebrew($word['morph_code']);
                } else {
                    $word['morph_description'] = '';
                }
            }
            unset($word);

            $verses[] = [
                'book'          => $book,
                'chapter'       => $curChapter,
                'verse'         => $curVerse,
                'testament'     => $testament,
                'source_words'  => $sourceWords,
                'dutch_verses'  => $dutchVersesByCode,
            ];

            if ($curVerse < $verseCount) {
                $curVerse++;
            } elseif ($curChapter < $chapterCount) {
                $curChapter++;
                $curVerse = 1;
            } else {
                break;
            }
        }

        if (empty($verses)) {
            return $this->renderError(sprintf('%s %d:%d niet gevonden.', $book->getNameNl(), $chapter, $verse));
        }

        return $this->twig->render('blog/embed/_bijbelvers.html.twig', [
            'verses'           => $verses,
            'translations'     => $translations,
            'toon_vertaling'   => $toonVertaling,
            'alleen_vertaling' => $alleenVertaling,
            'highlight_links'  => $highlightLinks,
            'layout'           => $layout,
        ]);
    }

    private function verseCountForChapter(array $verseCounts, int $chapter): ?int
    {
        foreach ($verseCounts as $row) {
            if ((int) $row['chapter'] === $chapter) {
                return (int) $row['verse_count'];
            }
        }
        return null;
    }

    private function renderError(string $message): string
    {
        return $this->twig->render('blog/embed/_error.html.twig', ['message' => $message]);
    }
}
