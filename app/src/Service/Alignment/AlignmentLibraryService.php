<?php

namespace App\Service\Alignment;

use App\Repository\AlignmentLibraryRepository;

/**
 * Promotes a manually-made inter-translation link into a reusable rule for
 * HistoricalAlignmentService's matching pipeline (see the alignment-library
 * tables added by Version20260905140000). Two kinds can be chosen in the UI:
 *
 *  - 'lexicon': the two words are the same word, just spelled differently
 *    across editions (-> alignment_lexicon). Only valid for a clean 1:1
 *    word link.
 *  - 'synonym': the two words genuinely differ but mean the same thing. The
 *    specific rule table (bridge / multi / phrase) is never asked of the
 *    user -- it's derived purely from how many words sit on each side of
 *    the connected group this link belongs to (see determineSynonymType()),
 *    matching exactly how HistoricalAlignmentService::bridgeSynonyms() /
 *    bridgeMultiSynonyms() / bridgePhrases() consume these tables.
 */
class AlignmentLibraryService
{
    public function __construct(
        private readonly AlignmentLibraryRepository $repo,
        private readonly HistoricalAlignmentService $alignment,
    ) {}

    /**
     * Given a source-word-count and target-word-count for one connected
     * group, decides which rule table a 'synonym' choice should write to.
     * Pure/stateless so it's directly unit-testable:
     *   1 <-> 1        => 'bridge'  (SYNONYM_BRIDGE:        1 word ~ 1 alternative)
     *   1 <-> N (N>1)  => 'multi'   (MULTI_SYNONYM_BRIDGE:  1 word ~ several words, all required)
     *   M>1 <-> anything => 'phrase' (PHRASE_BRIDGE:        multi-word source, matches
     *                                 the existing table's 2<->2, 3<->1 and 2<->1 entries alike)
     */
    public static function determineSynonymType(int $sourceCount, int $targetCount): string
    {
        if ($sourceCount > 1) {
            return 'phrase';
        }
        return $targetCount > 1 ? 'multi' : 'bridge';
    }

    /**
     * @param string $kind 'lexicon' or 'synonym'
     * @return array{status: string, type: ?string, message: string}
     *   status: 'added'|'exists'|'noop'|'conflict'|'error'
     */
    public function addToLibrary(int $wordAId, int $wordBId, string $kind): array
    {
        if (!in_array($kind, ['lexicon', 'synonym'], true)) {
            return ['status' => 'error', 'type' => null, 'message' => 'Onbekend type.'];
        }

        $group = $this->repo->findPairGroup($wordAId, $wordBId);
        if ($group === null) {
            return ['status' => 'error', 'type' => null, 'message' => 'Kon de vertalingen/richting niet bepalen.'];
        }

        $sourceWords = $group['source_words'];
        $targetWords = $group['target_words'];
        if (!$sourceWords || !$targetWords) {
            return ['status' => 'error', 'type' => null, 'message' => 'Geen woorden gevonden voor deze koppeling.'];
        }

        if ($kind === 'lexicon') {
            return $this->addLexicon($sourceWords, $targetWords, $group['source_link_id']);
        }

        return $this->addSynonym($sourceWords, $targetWords, $group['source_link_id']);
    }

    private function addLexicon(array $sourceWords, array $targetWords, ?int $linkId): array
    {
        if (count($sourceWords) !== 1 || count($targetWords) !== 1) {
            return [
                'status' => 'error',
                'type' => 'lexicon',
                'message' => 'Lexicon is alleen mogelijk voor een 1-op-1 koppeling. Kies "Synoniem" voor deze groep.',
            ];
        }

        $sourceText = $sourceWords[0]['word_text'];
        $targetText = $targetWords[0]['word_text'];

        $normSource = $this->alignment->normalize($sourceText);
        $normTarget = $this->alignment->normalize($targetText);
        if ($normSource === $normTarget) {
            return ['status' => 'noop', 'type' => 'lexicon', 'message' => 'Geen actie nodig — deze woorden zijn al gelijk na normalisatie.'];
        }

        $rawSource = $this->alignment->rawForm($sourceText);
        $result = $this->repo->addLexiconEntry($rawSource, $normTarget, $linkId);

        return match ($result) {
            'added' => ['status' => 'added', 'type' => 'lexicon', 'message' => "Toegevoegd aan het lexicon: \"{$rawSource}\" → \"{$normTarget}\"."],
            'conflict' => ['status' => 'conflict', 'type' => 'lexicon', 'message' => "Er bestaat al een ander lexicon-item voor \"{$rawSource}\". Pas het bestaande item aan via de bibliotheek."],
        };
    }

    private function addSynonym(array $sourceWords, array $targetWords, ?int $linkId): array
    {
        $type = self::determineSynonymType(count($sourceWords), count($targetWords));

        $normalize = fn(array $words) => array_map(fn($w) => $this->alignment->normalize($w['word_text']), $words);
        $normSource = $normalize($sourceWords);
        $normTarget = $normalize($targetWords);

        if ($type === 'bridge') {
            if ($normSource[0] === $normTarget[0]) {
                return ['status' => 'noop', 'type' => 'bridge', 'message' => 'Geen actie nodig — deze woorden zijn al gelijk na normalisatie.'];
            }
            $result = $this->repo->addSynonymBridgeEntry($normSource[0], $normTarget[0], $linkId);
            $note = $this->rewriteNote($sourceWords[0]['word_text'], $normSource[0]);
            return [
                'status' => $result,
                'type' => 'bridge',
                'message' => $result === 'added'
                    ? "Toegevoegd als synoniem: \"{$normSource[0]}\" ↔ \"{$normTarget[0]}\".{$note}"
                    : 'Dit synoniem staat al in de bibliotheek.',
            ];
        }

        if ($type === 'multi') {
            $result = $this->repo->addMultiSynonymBridgeEntry($normSource[0], $normTarget, $linkId);
            $note = $this->rewriteNote($sourceWords[0]['word_text'], $normSource[0]);
            return match ($result) {
                'added' => ['status' => 'added', 'type' => 'multi', 'message' => "Toegevoegd als multi-synoniem: \"{$normSource[0]}\" → \"" . implode(' + ', $normTarget) . "\".{$note}"],
                'conflict' => ['status' => 'conflict', 'type' => 'multi', 'message' => "Er bestaat al een ander multi-synoniem voor \"{$normSource[0]}\". Pas het bestaande item aan via de bibliotheek.{$note}"],
            };
        }

        // phrase
        $result = $this->repo->addPhraseBridgeEntry($normSource, $normTarget, $linkId);
        return [
            'status' => $result,
            'type' => 'phrase',
            'message' => $result === 'added'
                ? 'Toegevoegd als frase: "' . implode(' ', $normSource) . '" ↔ "' . implode(' ', $normTarget) . '".'
                : 'Deze frase staat al in de bibliotheek.',
        ];
    }

    /**
     * bridge/multi entries are keyed by the SOURCE word's normalize()
     * output, not its raw spelling -- required, since that's exactly what
     * bridgeSynonyms()/bridgeMultiSynonyms() look up against (they only ever
     * see already-normalized tokens). When an existing DEFAULT_LEXICON/
     * library-lexicon entry already rewrites this word before it would ever
     * reach the synonym stage (e.g. "der" -> "van"), the stored key differs
     * from what the user actually clicked -- storing it under the raw word
     * instead would make the new rule permanently unreachable. This adds a
     * short explanatory note to the toast message so that doesn't look like
     * a mistake.
     */
    private function rewriteNote(string $rawSourceText, string $normalizedSource): string
    {
        $raw = $this->alignment->rawForm($rawSourceText);
        if ($raw === $normalizedSource) {
            return '';
        }

        return " (\"{$raw}\" wordt al via het lexicon naar \"{$normalizedSource}\" vertaald, dus de regel geldt voor \"{$normalizedSource}\".)";
    }
}
