<?php

namespace App\Service\Embed;

use App\Repository\InstitutioRepository;
use Twig\Environment;

/**
 * Renders a ```institutie fenced block: one or more sections of Calvin's
 * Institutio (Latin/Dutch), optionally restricted to a range of sentences
 * within a section, optionally further cropped to a character range within
 * a single language (see InstitutioEmbedRenderer::cropToCharacterRange()).
 */
class InstitutioEmbedRenderer implements BlogEmbedRendererInterface
{
    private const MAX_SECTIONS = 20;

    public function __construct(
        private readonly InstitutioRepository $institutioRepository,
        private readonly Environment          $twig,
    ) {}

    public function supports(string $infoString): bool
    {
        return $infoString === 'institutie';
    }

    public function render(array $config): string
    {
        $ref = EmbedConfigParser::str($config, 'referentie');
        if (!$ref) {
            return $this->renderError('Geen referentie opgegeven (bv. "Inst. 1.1.1").');
        }

        $segment = $this->institutioRepository->findSegmentByRef($ref);
        if (!$segment) {
            return $this->renderError(sprintf('"%s" niet gevonden.', $ref));
        }

        $aantal = max(1, min(self::MAX_SECTIONS, EmbedConfigParser::int($config, 'aantal', 1)));
        $taal   = EmbedConfigParser::str($config, 'taal', 'beide');
        $taal   = in_array($taal, ['latijn', 'nederlands', 'beide'], true) ? $taal : 'beide';
        $layout = EmbedConfigParser::str($config, 'layout', 'naast-elkaar') === 'onder-elkaar' ? 'onder-elkaar' : 'naast-elkaar';

        $segments = [$segment];
        for ($i = 1; $i < $aantal; $i++) {
            $next = $this->institutioRepository->findNextSegment($segment['book'], $segment['chapter'], $segment['section']);
            if (!$next) {
                break;
            }
            $segments[] = $next;
            $segment = $next;
        }

        $sections = [];
        foreach ($segments as $seg) {
            $rows = self::sentenceRows($seg);

            if ($aantal === 1) {
                $zinVan = EmbedConfigParser::int($config, 'zin_van', 0);
                $zinTot = EmbedConfigParser::int($config, 'zin_tot', 0);
                if ($zinTot > 0) {
                    $from = max(1, $zinVan ?: 1);
                    $to   = min(count($rows), $zinTot);
                    $rows = array_slice($rows, $from - 1, max(0, $to - $from + 1));
                }

                if ($taal !== 'beide') {
                    $tekenVan = EmbedConfigParser::int($config, 'teken_van', 0);
                    $tekenTot = EmbedConfigParser::int($config, 'teken_tot', 0);
                    if ($tekenTot > 0) {
                        $field = $taal === 'latijn' ? 'la_text' : 'nl_text';
                        $joined = implode(' ', array_column($rows, $field));
                        $cropped = $this->cropToCharacterRange($joined, $tekenVan ?: 1, $tekenTot);
                        $rows = [['la_text' => $cropped, 'nl_text' => $cropped]];
                    }
                }
            }

            if (empty($rows)) {
                continue;
            }

            $sections[] = [
                'ref'  => $this->refLabel($seg, $taal),
                'rows' => $rows,
            ];
        }

        if (empty($sections)) {
            return $this->renderError(sprintf('"%s" niet gevonden.', $ref));
        }

        return $this->twig->render('blog/embed/_institutie.html.twig', [
            'sections' => $sections,
            'taal'     => $taal,
            'layout'   => $layout,
        ]);
    }

    /**
     * Splits a segment's Latin text into {la_text, nl_text} rows using its
     * sentence_alignment boundaries (la_start offsets into the full text) --
     * the same rows shown sentence-by-sentence on /institutie. Falls back to
     * one whole-section row when no alignment exists yet (not translated).
     *
     * @return array<int, array{la_text: string, nl_text: string}>
     */
    public static function sentenceRows(array $segment): array
    {
        $alignment = $segment['sentence_alignment'];
        if (empty($alignment)) {
            return [['la_text' => trim($segment['text']), 'nl_text' => trim((string) $segment['text_nl'])]];
        }

        $textLength = mb_strlen($segment['text']);
        $count = count($alignment);
        $rows = [];
        foreach ($alignment as $i => $a) {
            $start = $a['la_start'];
            $end   = $i + 1 < $count ? $alignment[$i + 1]['la_start'] : $textLength;
            $rows[] = [
                'la_text' => trim(mb_substr($segment['text'], $start, $end - $start)),
                'nl_text' => trim($a['nl_text']),
            ];
        }
        return $rows;
    }

    /**
     * Extracts characters [$from, $to] (1-based, inclusive) from $text and
     * expands the selection outward to the nearest word boundary on both
     * sides, so a picker selection can never cut a word in half.
     */
    private function cropToCharacterRange(string $text, int $from, int $to): string
    {
        $len = mb_strlen($text);
        if ($len === 0) {
            return '';
        }
        $from = max(1, min($from, $len));
        $to   = max($from, min($to, $len));

        while ($from > 1 && mb_substr($text, $from - 2, 1) !== ' ') {
            $from--;
        }
        while ($to < $len && mb_substr($text, $to, 1) !== ' ') {
            $to++;
        }

        return trim(mb_substr($text, $from - 1, $to - $from + 1));
    }

    /**
     * The citation shown below each section, e.g. "Johannes Calvijn,
     * Institutie 1.1.1" -- with " - vrije vertaling" appended whenever the
     * Dutch translation is part of the display (taal: beide/nederlands),
     * since that text is the LLM's fluent Dutch rendering, not a literal
     * translation.
     */
    private function refLabel(array $segment, string $taal): string
    {
        $suffix = $taal !== 'latijn' ? ' - vrije vertaling' : '';

        if ($segment['book'] === null) {
            return "Johannes Calvijn, Institutie, Voorwoord {$segment['section']}{$suffix}";
        }
        return "Johannes Calvijn, Institutie {$segment['book']}.{$segment['chapter']}.{$segment['section']}{$suffix}";
    }

    private function renderError(string $message): string
    {
        return $this->twig->render('blog/embed/_error.html.twig', ['message' => $message]);
    }
}
