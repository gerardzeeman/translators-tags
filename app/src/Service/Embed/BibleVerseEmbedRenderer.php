<?php

namespace App\Service\Embed;

use App\Entity\Book;
use App\Repository\BookRepository;
use App\Repository\PassageRepository;
use App\Repository\TranslationRepository;
use App\Service\MorphologyParser;
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
     * Whether the CURRENT blog's author (not the visitor viewing it) has
     * ROLE_VIEWER_HSV, resolved with role-hierarchy inheritance and set by
     * BlogMarkdownRenderer immediately before each render pass. Defaults to
     * false (fail closed): without this being explicitly set, no non-SV
     * translation may be embedded. This gates authoring, not viewing --
     * once a blog embeds HSV text legitimately, published readers (including
     * anonymous ones) still see it, matching how the rest of the app treats
     * published content. What it prevents is a ROLE_BLOGGER account that was
     * never granted ROLE_VIEWER_HSV using their own draft preview as a
     * side channel to read the full HSV translation despite that.
     */
    private bool $authorHasHsvAccess = false;

    public function __construct(
        private readonly BookRepository        $bookRepository,
        private readonly TranslationRepository $translationRepository,
        private readonly PassageRepository     $passageRepository,
        private readonly MorphologyParser      $morphologyParser,
        private readonly Environment           $twig,
    ) {}

    public function setAuthorHasHsvAccess(bool $authorHasHsvAccess): void
    {
        $this->authorHasHsvAccess = $authorHasHsvAccess;
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
        $translationCode = EmbedConfigParser::str($config, 'vertaling', 'SV');

        $translation = ($toonVertaling || $alleenVertaling)
            ? $this->translationRepository->findByCode($translationCode)
            : $this->translationRepository->findByCode('SV');
        if (!$translation) {
            return $this->renderError(sprintf('Vertaling "%s" niet gevonden.', $translationCode));
        }

        // Same convention as the rest of the app (e.g. linking/passage.html.twig):
        // SV is unrestricted, every other translation requires ROLE_VIEWER_HSV --
        // here, on the blog's author, not the current visitor (see property doc above).
        if ($translation->getCode() !== 'SV' && !$this->authorHasHsvAccess) {
            return $this->renderError(sprintf(
                'Vertaling "%s" is niet beschikbaar voor deze blog.',
                $translation->getCode()
            ));
        }

        // SV is the authority translation: word_links are entered against it directly, and
        // every other translation's links are propagated from SV via inter_translation_links
        // (see PassageRepository::fetchPropagatedLinksForVerseBatch, same as BibleController::verse()).
        $svTranslation = $translation->getCode() === 'SV' ? $translation : $this->translationRepository->findByCode('SV');

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

            $passage = $this->passageRepository->fetchPassage($book->getId(), $curChapter, $curVerse, $translation->getId());
            if (empty($passage['source_words'])) {
                break;
            }

            $sourceWords = $passage['source_words'];
            $dutchVerse  = $passage['dutch_verse'];

            if ($svTranslation && $translation->getCode() !== 'SV') {
                $propagated = $this->passageRepository->fetchPropagatedLinksForVerseBatch(
                    $book->getId(), $curChapter, $curVerse, $passage['testament'],
                    $svTranslation->getId(), [$translation->getId()],
                );
                foreach ($sourceWords as &$word) {
                    if (empty($word['dutch_links'])) {
                        $word['dutch_links'] = $propagated[$translation->getId()][$word['id']] ?? [];
                    }
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
                $dutchVerse = array_values(array_filter($dutchVerse, fn($w) => isset($linkedIds[$w['word_id']])));
                $sourceWords = $sourceWordsInRange;
            }

            foreach ($sourceWords as &$word) {
                if ($passage['testament'] === 'NT' && !empty($word['parse_code'])) {
                    $word['morph_description'] = $this->morphologyParser->describeGreek($word['parse_code']);
                } elseif ($passage['testament'] === 'OT' && !empty($word['morph_code'])) {
                    $word['morph_description'] = $this->morphologyParser->describeHebrew($word['morph_code']);
                } else {
                    $word['morph_description'] = '';
                }
            }
            unset($word);

            $verses[] = [
                'book'         => $book,
                'chapter'      => $curChapter,
                'verse'        => $curVerse,
                'testament'    => $passage['testament'],
                'source_words' => $sourceWords,
                'dutch_verse'  => $dutchVerse,
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
            'translation'      => $translation,
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
