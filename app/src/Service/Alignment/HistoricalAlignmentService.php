<?php

namespace App\Service\Alignment;

use App\Repository\AlignmentLibraryRepository;

/**
 * PHP port of `align.py` (historical-dutch-bible-alignment): word-for-word
 * alignment between two Dutch Bible-translation editions across spelling
 * eras (SV1657 <-> SV / SVGBS / HSV), driven by a spelling-normalisation
 * layer plus a two-stage anchor/window matching pipeline.
 *
 * Pipeline order (mirrors align.py's `align_pair` exactly):
 *   normalize -> findNegationParticles -> [forced manual anchors] ->
 *   findAnchors -> detectCompounds -> bridgePhrases -> matchWindow (per
 *   anchor-bounded segment) -> rescueMovedBlocks -> mergeClitics (1:n) ->
 *   bridgeSynonyms -> bridgeMultiSynonyms -> dropKnownPrefixes.
 *
 * The one architectural addition over align.py: `alignPair()` accepts
 * `$forcedAnchors` -- existing human-confirmed (`manual`) links, passed in
 * as [srcIndex, tgtIndex] position pairs. These are seeded into the result
 * as score-1.0 anchors before the pipeline runs and their positions are
 * excluded from `findAnchors()`'s own candidate search, so the rest of the
 * pipeline aligns around them without ever overwriting them -- the same way
 * it already aligns around its own algorithmic anchors.
 *
 * All rule tables (CHAR_RULES, LEXICON, RULE_EXEMPT, GLUE_WORDS,
 * CLITIC_WORDS, PHRASE_BRIDGE, SYNONYM_BRIDGE, MULTI_SYNONYM_BRIDGE,
 * PREFIX_DROP_WORDS) are transcribed verbatim from align.py. align.py stays
 * the place new language rules are discovered and tested; changes are
 * ported here by hand.
 */
class HistoricalAlignmentService
{
    /** @var array<string, string> */
    private readonly array $lexicon;
    /** @var array<string, string[]> */
    private readonly array $synonymBridge;
    /** @var array<string, string[]> */
    private readonly array $multiSynonymBridge;
    /** @var list<array{0: string[], 1: string[]}> */
    private readonly array $phraseBridge;

    /**
     * $libraryRepo is optional (and omitted by every unit test that
     * constructs this service directly): without it, matching behaves
     * exactly as before this table -> DB extension was added, using only
     * the hardcoded DEFAULT_* constants below. When provided (real app
     * usage, autowired), rows added via the alignment-library UI
     * (App\Service\Alignment\AlignmentLibraryService) are merged on top.
     */
    public function __construct(
        private readonly HungarianAlgorithm $hungarian = new HungarianAlgorithm(),
        ?AlignmentLibraryRepository $libraryRepo = null,
    ) {
        $this->lexicon = $libraryRepo
            ? array_merge(self::DEFAULT_LEXICON, $libraryRepo->loadLexicon())
            : self::DEFAULT_LEXICON;

        $this->synonymBridge = $libraryRepo
            ? array_merge_recursive(self::DEFAULT_SYNONYM_BRIDGE, $libraryRepo->loadSynonymBridge())
            : self::DEFAULT_SYNONYM_BRIDGE;

        $this->multiSynonymBridge = $libraryRepo
            ? array_merge(self::DEFAULT_MULTI_SYNONYM_BRIDGE, $libraryRepo->loadMultiSynonymBridge())
            : self::DEFAULT_MULTI_SYNONYM_BRIDGE;

        $this->phraseBridge = $libraryRepo
            ? [...self::DEFAULT_PHRASE_BRIDGE, ...$libraryRepo->loadPhraseBridge()]
            : self::DEFAULT_PHRASE_BRIDGE;
    }

    // ── 1. Normalisation ──────────────────────────────────────────────────

    /** Applied in order. ^ = word start, $ = word end. */
    private const CHAR_RULES = [
        ['/^gh/u', 'g'],
        ['/heyt$/u', 'heid'],
        ['/eyt$/u', 'eid'],
        ['/gh(?!eid)/u', 'g'],
        ['/^s(?=[aeiouy])/u', 'z'],
        ['/dts$/u', 'ds'],
        ['/lick/u', 'lijk'],
        ['/ck/u', 'k'],
        ['/uy/u', 'ui'],
        ['/ey/u', 'ei'],
        ['/ae/u', 'aa'],
        ['/sch(?=s?$)/u', 's'],
        ['/dt$/u', 'd'],
        ['/([lnr])t$/u', '$1d'],
        ['/^([bcdfghjklmnpqrstvwz]*)([aeiou])\2(?=[bcdfghjklmnpqrstvwz][aeiou])/u', '$1$2'],
        ['/y/u', 'ij'],
    ];

    /** Vangnet-laag voor lexicale/morfologische verschillen die geen char-regel vangt. */
    private const DEFAULT_LEXICON = [
        'ende' => 'en',
        'vleeschs' => 'vlees',
        'vleses' => 'vlees',
        'vleses,' => 'vlees',
        'levens' => 'leven',
        'oogen' => 'oog',
        'ogen' => 'oog',
        'des' => 'van',
        'der' => 'van',
        'den' => 'de',
        'sijnen' => 'zijn',
        'noch' => 'nog',
        'geliefde' => 'geliefden',
        'alsoo' => 'zo',
        'alzo' => 'zo',
        'soo' => 'zo',
        'oock' => 'ook',
        'malkanderen' => 'elkander',
        'genaemt' => 'genaamd',
        'wesen' => 'wezen',
        'daerom' => 'daarom',
        'daarom' => 'daarom',
        'sone' => 'zoon',
        'zone' => 'zoon',
        'godts' => 'god',
        'gods' => 'god',
        'rechtveerdigheyt' => 'rechtvaardigheid',
        'yegelick' => 'iegelijk',
        'cain' => 'kain',
        'boosen' => 'boze',
        'dootsloegh' => 'doodsloeg',
        'oorsake' => 'oorzaak',
        'doot' => 'dood',
        'gelooven' => 'geloven',
        'name' => 'naam',
        'iesu' => 'jezus',
        'christi' => 'christus',
        'waarom' => 'waarom',
        'jesu' => 'jezus',
        'wille' => 'wil',
        'gemeynte' => 'gemeente',
        'corinthen' => 'korinthe',
        'christo' => 'christus',
        'plaetse' => 'plaats',
        'onses' => 'onze',
        'bidde' => 'bid',
        'selve' => 'zelfde',
        'selven' => 'zelfden',
        'eenen' => 'een',
        'geene' => 'geen',
        'gevoeght' => 'gevoegd',
        'predikinge' => 'prediking',
        'behaeght' => 'behaagd',
        'aaneengesmeed' => 'aaneengesmeed',
    ];

    /** Functiewoorden die de ([lnr])t$ -> \1d-regel verkeerd zou treffen. */
    private const RULE_EXEMPT = [
        'want' => true, 'niet' => true, 'met' => true, 'dat' => true, 'wat' => true,
        'het' => true, 'tot' => true, 'uit' => true, 'is' => true, 'en' => true,
        'samen' => true, 'samengevoegd' => true, 'bent' => true,
    ];

    /** Functiewoorden die bij een 1:n koppeling opgeslokt mogen worden. */
    private const CLITIC_WORDS = [
        'van' => true, 'het' => true, 'de' => true, 'der' => true, 'des' => true, 'den' => true,
    ];

    /** Tussenwoordjes die in een samentrekking zelf verdwijnen. */
    private const GLUE_WORDS = ['te' => true, 'ge' => true];

    private const DEFAULT_SYNONYM_BRIDGE = [
        'want' => ['omdat'],
        'opdat' => ['dat'],
        'gelijk' => ['zoals'],
        'boos' => ['slecht'],
        'hares' => ['hun'],
        'te' => ['in'],
        'alle' => ['elke'],
    ];

    private const DEFAULT_MULTI_SYNONYM_BRIDGE = [
        'doodsloeg' => ['sloeg', 'dood'],
        'openbaar' => ['te', 'herkennen'],
    ];

    private const DEFAULT_PHRASE_BRIDGE = [
        [['de', 'beginne'], ['het', 'begin']],
        [['om', 'wat', 'oorzaak'], ['waarom']],
        [['hier', 'in'], ['hieraan']],
        [['een', 'iegelijk'], ['ieder']],
        [['te', 'samen', 'gevoegd'], ['aaneengesmeed']],
        [['in', 'een', 'zelfden', 'zin'], ['een', 'van', 'denken']],
    ];

    /** Bekende gevallen van geïsoleerd wegvallende voorvoegsels. */
    private const PREFIX_DROP_WORDS = ['op' => true];

    /**
     * Lowercase + diacritic-strip + non-word-strip only -- the part of
     * normalize() that runs before the lexicon/char-rule lookups. This is
     * the key form new alignment_lexicon entries are stored under (see
     * AlignmentLibraryService), so a manually-added entry takes effect
     * immediately for this exact historical spelling regardless of what the
     * char-rules would otherwise have done with it.
     */
    public function rawForm(string $token): string
    {
        $w = mb_strtolower($token, 'UTF-8');
        $w = \Normalizer::normalize($w, \Normalizer::FORM_KD) ?: $w;
        $w = preg_replace('/\p{Mn}/u', '', $w);
        return preg_replace('/(*UCP)[^\w]/u', '', $w);
    }

    public function normalize(string $token): string
    {
        $w = $this->rawForm($token);
        if ($w === '') {
            return '';
        }
        if (isset($this->lexicon[$w])) {
            return $this->lexicon[$w];
        }
        if (isset(self::RULE_EXEMPT[$w])) {
            return $w;
        }
        foreach (self::CHAR_RULES as [$pattern, $replacement]) {
            $w = preg_replace($pattern, $replacement, $w);
        }

        return $this->lexicon[$w] ?? $w;
    }

    /**
     * Regel-afbrekingskoppeltekens (be-ginne) zijn typografie, geen echte
     * woordgrens: 'woord-woord' zonder omringende spaties wordt hier al één
     * token.
     *
     * @return string[]
     */
    public function tokenize(string $text): array
    {
        preg_match_all('/(*UCP)[\w]+|[^\w\s]/u', $text, $m);
        $raw = $m[0];
        $merged = [];
        $i = 0;
        $n = count($raw);
        while ($i < $n) {
            if (
                $i + 2 < $n
                && $this->isAlpha($raw[$i])
                && $raw[$i + 1] === '-'
                && $this->isAlpha($raw[$i + 2])
            ) {
                $merged[] = $raw[$i] . $raw[$i + 2];
                $i += 3;
            } else {
                $merged[] = $raw[$i];
                $i++;
            }
        }

        return $merged;
    }

    private function isAlpha(string $s): bool
    {
        return preg_match('/(*UCP)^\p{L}+$/u', $s) === 1;
    }

    // ── 2. Similariteit ───────────────────────────────────────────────────

    public function levenshtein(string $a, string $b): int
    {
        if ($a === $b) {
            return 0;
        }
        if ($a === '') {
            return strlen($b);
        }
        if ($b === '') {
            return strlen($a);
        }

        return levenshtein($a, $b);
    }

    public function commonPrefix(string $a, string $b): int
    {
        $n = 0;
        $len = min(strlen($a), strlen($b));
        for ($i = 0; $i < $len; $i++) {
            if ($a[$i] !== $b[$i]) {
                break;
            }
            $n++;
        }

        return $n;
    }

    public function similarity(string $a, string $b): float
    {
        if ($a === '' || $b === '') {
            return 0.0;
        }
        if ($a === $b) {
            return 1.0;
        }
        $maxLen = max(strlen($a), strlen($b));
        $lev = 1.0 - $this->levenshtein($a, $b) / $maxLen;
        $stem = $this->commonPrefix($a, $b) / $maxLen;

        return 0.7 * $lev + 0.3 * $stem;
    }

    // ── 3. Ankerfase ──────────────────────────────────────────────────────

    /**
     * Houdt alleen de monotoon oplopende ankers over (verwijdert kruisingen).
     * Ties gaan naar de eerst-gevonden keten, exact zoals Python's max().
     *
     * @param array<int, array{0:int,1:int}> $pairs
     * @return array<int, array{0:int,1:int}>
     */
    public function longestIncreasingSubsequence(array $pairs): array
    {
        if (!$pairs) {
            return [];
        }
        $best = [];
        foreach ($pairs as $pair) {
            $chosen = [];
            foreach ($best as $chain) {
                $last = $chain[count($chain) - 1];
                if ($last[1] < $pair[1] && count($chain) > count($chosen)) {
                    $chosen = $chain;
                }
            }
            $chosen[] = $pair;
            $best[] = $chosen;
        }
        $result = [];
        foreach ($best as $chain) {
            if (count($chain) > count($result)) {
                $result = $chain;
            }
        }

        return $result;
    }

    /**
     * 1:1 koppelingen die in beide getuigen uniek en (bijna) identiek zijn.
     * $forcedSrcUsed / $forcedTgtUsed (index => true) markeren posities die
     * al door een handmatige (forced) anker bezet zijn en dus overgeslagen
     * worden in de kandidaatzoektocht.
     *
     * @param string[] $src
     * @param string[] $tgt
     * @return array<int, array{0:int,1:int}>
     */
    public function findAnchors(
        array $src,
        array $tgt,
        float $threshold = 0.95,
        array $forcedSrcUsed = [],
        array $forcedTgtUsed = [],
    ): array {
        $srcCounts = [];
        foreach ($src as $w) {
            $srcCounts[$w] = ($srcCounts[$w] ?? 0) + 1;
        }
        $tgtCounts = [];
        foreach ($tgt as $w) {
            $tgtCounts[$w] = ($tgtCounts[$w] ?? 0) + 1;
        }

        $candidates = [];
        foreach ($src as $i => $sw) {
            if ($sw === '' || ($srcCounts[$sw] ?? 0) !== 1) {
                continue;
            }
            if (isset($forcedSrcUsed[$i])) {
                continue;
            }
            foreach ($tgt as $j => $tw) {
                if (($tgtCounts[$tw] ?? 0) !== 1) {
                    continue;
                }
                if (isset($forcedTgtUsed[$j])) {
                    continue;
                }
                if ($this->similarity($sw, $tw) >= $threshold) {
                    $candidates[] = [$i, $j];
                    break;
                }
            }
        }

        return $this->longestIncreasingSubsequence($candidates);
    }

    // ── 4. Vensterfase ────────────────────────────────────────────────────

    /**
     * Volledige bipartite matching binnen een venster (Hongaars algoritme).
     * Volgorde is hier vrij; 0.01x positie-tiebreaker breekt exacte ties.
     *
     * @param int[] $srcIdx
     * @param int[] $tgtIdx
     * @param string[] $src
     * @param string[] $tgt
     * @return array<int, array{0:int,1:int,2:float}>
     */
    public function matchWindow(array $srcIdx, array $tgtIdx, array $src, array $tgt, float $threshold = 0.45): array
    {
        if (!$srcIdx || !$tgtIdx) {
            return [];
        }
        $lenSrc = count($src);
        $lenTgt = count($tgt);

        $pureCost = [];
        foreach (array_values($srcIdx) as $r => $i) {
            foreach (array_values($tgtIdx) as $c => $j) {
                $pureCost[$r][$c] = 1.0 - $this->similarity($src[$i], $tgt[$j]);
            }
        }
        $cost = [];
        foreach (array_values($srcIdx) as $r => $i) {
            foreach (array_values($tgtIdx) as $c => $j) {
                $cost[$r][$c] = $pureCost[$r][$c] + 0.01 * abs($i / $lenSrc - $j / $lenTgt);
            }
        }

        $srcIdx = array_values($srcIdx);
        $tgtIdx = array_values($tgtIdx);
        $out = [];
        foreach ($this->hungarian->solve($cost) as [$r, $c]) {
            $score = 1.0 - $pureCost[$r][$c];
            if ($score >= $threshold) {
                $out[] = [$srcIdx[$r], $tgtIdx[$c], $score];
            }
        }

        return $out;
    }

    /**
     * Globale, positie-onafhankelijke matching over wat na anker+venster nog
     * overblijft. Vangt het geval waarbij een frase een anker passeert.
     * Muteert $links in place en sorteert op src, zoals align.py.
     *
     * @param AlignmentLink[] $links
     * @param string[] $src
     * @param string[] $tgt
     */
    public function rescueMovedBlocks(array &$links, array $src, array $tgt, float $threshold = 0.55): void
    {
        [$usedSrc, $usedTgt] = $this->usedSets($links);

        $leftSrc = [];
        foreach ($src as $i => $w) {
            if ($w !== '' && !isset($usedSrc[$i])) {
                $leftSrc[] = $i;
            }
        }
        $leftTgt = [];
        foreach ($tgt as $j => $w) {
            if ($w !== '' && !isset($usedTgt[$j])) {
                $leftTgt[] = $j;
            }
        }
        if (!$leftSrc || !$leftTgt) {
            return;
        }

        $lenSrc = count($src);
        $lenTgt = count($tgt);
        $pureCost = [];
        foreach ($leftSrc as $r => $i) {
            foreach ($leftTgt as $c => $j) {
                $pureCost[$r][$c] = 1.0 - $this->similarity($src[$i], $tgt[$j]);
            }
        }
        $cost = [];
        foreach ($leftSrc as $r => $i) {
            foreach ($leftTgt as $c => $j) {
                $cost[$r][$c] = $pureCost[$r][$c] + 0.01 * abs($i / $lenSrc - $j / $lenTgt);
            }
        }

        foreach ($this->hungarian->solve($cost) as [$r, $c]) {
            $score = 1.0 - $pureCost[$r][$c];
            if ($score < $threshold) {
                continue;
            }
            $i = $leftSrc[$r];
            $j = $leftTgt[$c];

            $neighbours = array_values(array_filter($links, static fn(AlignmentLink $l) => $l->tgt !== []));
            $expectedOk = true;
            foreach ($neighbours as $l) {
                $lt = min($l->tgt);
                if (($l->src < $i) !== ($lt < $j)) {
                    $expectedOk = false;
                    break;
                }
            }
            $kind = $expectedOk ? 'window' : 'moved';
            $links[] = new AlignmentLink($i, [$j], $score, $kind);
        }
        usort($links, static fn(AlignmentLink $a, AlignmentLink $b) => $a->src <=> $b->src);
    }

    // ── 5. Samentrekking / bruggen ───────────────────────────────────────

    /**
     * Meerdere aaneengesloten, nog ongekoppelde brontokens die (op
     * tussenwoordjes na) samen letterlijk een nog ongekoppeld doeltoken
     * vormen. Retourneert nieuwe links om aan de bestaande lijst toe te
     * voegen (muteert $links zelf niet, zoals align.py's return-waarde).
     *
     * @param AlignmentLink[] $links
     * @param string[] $src
     * @param string[] $tgt
     * @return AlignmentLink[]
     */
    public function detectCompounds(array $links, array $src, array $tgt, int $maxLen = 3): array
    {
        [$usedSrc, $usedTgt] = $this->usedSets($links);

        $unmatchedSrc = [];
        foreach ($src as $i => $w) {
            if ($w !== '' && !isset($usedSrc[$i])) {
                $unmatchedSrc[] = $i;
            }
        }
        $unmatchedTgt = [];
        foreach ($tgt as $j => $w) {
            if ($w !== '' && !isset($usedTgt[$j])) {
                $unmatchedTgt[] = $j;
            }
        }

        $runs = [];
        foreach ($unmatchedSrc as $i) {
            $append = false;
            if ($runs !== []) {
                $lastRun = $runs[count($runs) - 1];
                $lastIdx = $lastRun[count($lastRun) - 1];
                if ($i === $lastIdx + 1) {
                    $append = true;
                } else {
                    $append = true;
                    for ($k = $lastIdx + 1; $k < $i; $k++) {
                        if ($src[$k] !== '') {
                            $append = false;
                            break;
                        }
                    }
                }
            }
            if ($append) {
                $runs[count($runs) - 1][] = $i;
            } else {
                $runs[] = [$i];
            }
        }

        $findWindows = static function (array $run) use ($maxLen): array {
            $n = count($run);
            $out = [];
            for ($length = min($maxLen, $n); $length > 1; $length--) {
                for ($start = 0; $start <= $n - $length; $start++) {
                    $out[] = array_slice($run, $start, $length);
                }
            }

            return $out;
        };
        $allWindows = [];
        foreach ($runs as $run) {
            foreach ($findWindows($run) as $w) {
                $allWindows[] = $w;
            }
        }

        $newLinks = [];
        $claimedTgt = [];
        foreach ([true, false] as $exactOnly) {
            $usedWindows = [];
            foreach ($allWindows as $window) {
                $key = implode(',', $window);
                if (isset($usedWindows[$key])) {
                    continue;
                }
                $newLinkSrcs = [];
                foreach ($newLinks as $l) {
                    $newLinkSrcs[$l->src] = true;
                }
                $collision = false;
                foreach ($window as $i) {
                    if (isset($newLinkSrcs[$i])) {
                        $collision = true;
                        break;
                    }
                }
                if ($collision) {
                    continue;
                }

                $contentWords = [];
                foreach ($window as $i) {
                    if (!isset(self::GLUE_WORDS[$src[$i]])) {
                        $contentWords[] = $src[$i];
                    }
                }
                if (count($contentWords) < 2) {
                    continue;
                }
                $concat = implode('', $contentWords);
                if (mb_strlen($concat) <= 3) {
                    continue;
                }

                foreach ($unmatchedTgt as $j) {
                    if (isset($claimedTgt[$j])) {
                        continue;
                    }
                    $tw = $tgt[$j];
                    $isExact = $concat === $tw;
                    if ($isExact || (!$exactOnly && $this->levenshtein($concat, $tw) <= 1)) {
                        foreach ($window as $i) {
                            $newLinks[] = new AlignmentLink($i, [$j], 1.0, 'compound');
                        }
                        $claimedTgt[$j] = true;
                        $usedWindows[$key] = true;
                        break;
                    }
                }
            }
        }

        return $newLinks;
    }

    /**
     * Handmatige lijst van brontekst-frasen die letterlijk een andere
     * doeltekst-frase van dezelfde lengte-orde opleveren. Muteert $links.
     *
     * @param AlignmentLink[] $links
     * @param string[] $src
     * @param string[] $tgt
     */
    public function bridgePhrases(array &$links, array $src, array $tgt): void
    {
        [$usedSrc, $usedTgt] = $this->usedSets($links);

        $unmatchedSrc = [];
        foreach ($src as $i => $w) {
            if ($w !== '' && !isset($usedSrc[$i])) {
                $unmatchedSrc[] = $i;
            }
        }
        $unmatchedTgt = [];
        foreach ($tgt as $j => $w) {
            if ($w !== '' && !isset($usedTgt[$j])) {
                $unmatchedTgt[] = $j;
            }
        }

        foreach ($this->phraseBridge as [$srcPhrase, $tgtPhrase]) {
            $n = count($srcPhrase);
            $m = count($tgtPhrase);

            // Keep finding occurrences of this phrase pair until none are
            // left -- a phrase like "hetgeen" (2 source words -> 1 target
            // word) can genuinely occur more than once in the same verse,
            // and only ever linking the first occurrence silently drops the
            // rest.
            while (true) {
                $srcPos = null;
                for ($k = 0; $k <= count($unmatchedSrc) - $n; $k++) {
                    $match = true;
                    for ($o = 0; $o < $n; $o++) {
                        if ($src[$unmatchedSrc[$k + $o]] !== $srcPhrase[$o]) {
                            $match = false;
                            break;
                        }
                    }
                    if ($match) {
                        for ($o = 0; $o < $n - 1; $o++) {
                            $curIdx = $unmatchedSrc[$k + $o];
                            $nextIdx = $unmatchedSrc[$k + $o + 1];
                            if ($nextIdx === $curIdx + 1) {
                                continue;
                            }
                            $allEmpty = true;
                            for ($x = $curIdx + 1; $x < $nextIdx; $x++) {
                                if ($src[$x] !== '') {
                                    $allEmpty = false;
                                    break;
                                }
                            }
                            if (!$allEmpty) {
                                $match = false;
                                break;
                            }
                        }
                    }
                    if ($match) {
                        $srcPos = $k;
                        break;
                    }
                }

                $tgtPos = null;
                for ($k = 0; $k <= count($unmatchedTgt) - $m; $k++) {
                    $match = true;
                    for ($o = 0; $o < $m; $o++) {
                        if ($tgt[$unmatchedTgt[$k + $o]] !== $tgtPhrase[$o]) {
                            $match = false;
                            break;
                        }
                    }
                    if ($match) {
                        for ($o = 0; $o < $m - 1; $o++) {
                            if ($unmatchedTgt[$k + $o + 1] !== $unmatchedTgt[$k + $o] + 1) {
                                $match = false;
                                break;
                            }
                        }
                    }
                    if ($match) {
                        $tgtPos = $k;
                        break;
                    }
                }

                if ($srcPos === null || $tgtPos === null) {
                    break;
                }

                $srcIdx = [];
                for ($o = 0; $o < $n; $o++) {
                    $srcIdx[] = $unmatchedSrc[$srcPos + $o];
                }
                $tgtIdx = [];
                for ($o = 0; $o < $m; $o++) {
                    $tgtIdx[] = $unmatchedTgt[$tgtPos + $o];
                }

                foreach ($srcIdx as $i) {
                    $links[] = new AlignmentLink($i, $tgtIdx, 0.95, 'phrase');
                }

                $unmatchedSrc = array_values(array_diff($unmatchedSrc, $srcIdx));
                $unmatchedTgt = array_values(array_diff($unmatchedTgt, $tgtIdx));
            }
        }
    }

    /**
     * Laatste redmiddel: kleine lijst functiewoord-paren met dezelfde
     * grammaticale functie maar totaal verschillende vorm. Muteert $links.
     *
     * @param AlignmentLink[] $links
     * @param string[] $src
     * @param string[] $tgt
     */
    public function bridgeSynonyms(array &$links, array $src, array $tgt): void
    {
        [$usedSrc, $usedTgt] = $this->usedSets($links);

        foreach ($src as $i => $sw) {
            if ($sw === '' || isset($usedSrc[$i]) || !isset($this->synonymBridge[$sw])) {
                continue;
            }
            foreach ($tgt as $j => $tw) {
                if ($tw === '' || isset($usedTgt[$j])) {
                    continue;
                }
                if (in_array($tw, $this->synonymBridge[$sw], true)) {
                    $links[] = new AlignmentLink($i, [$j], 0.99, 'synonym');
                    $usedTgt[$j] = true;
                    break;
                }
            }
        }
    }

    /**
     * Zoals bridgeSynonyms, maar voor het geval één bronwoord uiteenvalt in
     * meerdere losse doelwoorden (niet per se aaneengesloten). Muteert $links.
     *
     * @param AlignmentLink[] $links
     * @param string[] $src
     * @param string[] $tgt
     */
    public function bridgeMultiSynonyms(array &$links, array $src, array $tgt): void
    {
        [$usedSrc, $usedTgt] = $this->usedSets($links);

        foreach ($src as $i => $sw) {
            if ($sw === '' || isset($usedSrc[$i]) || !isset($this->multiSynonymBridge[$sw])) {
                continue;
            }
            $found = [];
            $claimed = $usedTgt;
            foreach ($this->multiSynonymBridge[$sw] as $needed) {
                // Closest unclaimed occurrence to $i, not the first one in
                // the sentence -- a common word like "de" easily occurs
                // several times, and picking an arbitrary far-away one
                // (rather than the one actually next to this source word)
                // produces a wrong pairing even though the target *word* is
                // technically correct.
                $j = null;
                $bestDist = null;
                foreach ($tgt as $jj => $tw) {
                    if ($tw === $needed && !isset($claimed[$jj])) {
                        $dist = abs($jj - $i);
                        if ($bestDist === null || $dist < $bestDist) {
                            $bestDist = $dist;
                            $j = $jj;
                        }
                    }
                }
                if ($j === null) {
                    $found = [];
                    break;
                }
                $found[] = $j;
                $claimed[$j] = true;
            }
            if ($found) {
                $links[] = new AlignmentLink($i, $found, 0.9, 'synonym');
                foreach ($found as $j) {
                    $usedTgt[$j] = true;
                }
            }
        }
    }

    /**
     * Klein, handmatig lijstje van brontokens die soms geïsoleerd wegvallen
     * wanneer het onmiddellijk volgende bronwoord al op zichzelf gekoppeld
     * is. Muteert $links niet, retourneert alleen de gevonden indices.
     *
     * @param AlignmentLink[] $links
     * @param string[] $src
     * @return int[]
     */
    public function dropKnownPrefixes(array $links, array $src): array
    {
        $usedSrc = [];
        foreach ($links as $l) {
            $usedSrc[$l->src] = true;
        }
        $out = [];
        foreach ($src as $i => $w) {
            if (isset(self::PREFIX_DROP_WORDS[$w]) && !isset($usedSrc[$i]) && isset($usedSrc[$i + 1])) {
                $out[] = $i;
            }
        }

        return $out;
    }

    // ── 6. Middelnederlandse dubbele ontkenning ─────────────────────────

    /**
     * Een 'en' binnen een paar woorden van een 'niet' (niet over een
     * leesteken heen) is het ontkenningspartikel, niet het voegwoord
     * 'en/ende'. Heuristiek op nabijheid, niet op syntaxis.
     *
     * @param string[] $src
     * @param string[] $srcRaw
     * @return int[]
     */
    public function findNegationParticles(array $src, array $srcRaw, int $span = 3): array
    {
        $negatorSpan = ['niet' => $span, 'geen' => 1];
        $boundary = ['.', ',', ';', ':', '?', '!'];
        $out = [];
        $n = count($src);

        foreach ($src as $i => $w) {
            if ($w !== 'en') {
                continue;
            }
            $found = false;

            $k = $i - 1;
            $steps = 0;
            while ($k >= 0 && $steps <= $span) {
                if (in_array($srcRaw[$k], $boundary, true) || ($src[$k] === 'en' && $k !== $i)) {
                    break;
                }
                if (isset($negatorSpan[$src[$k]]) && $steps <= $negatorSpan[$src[$k]]) {
                    $found = true;
                    break;
                }
                if ($src[$k] !== '') {
                    $steps++;
                }
                $k--;
            }
            if (!$found) {
                $k = $i + 1;
                $steps = 0;
                while ($k < $n && $steps <= $span) {
                    if (in_array($srcRaw[$k], $boundary, true) || ($src[$k] === 'en' && $k !== $i)) {
                        break;
                    }
                    // $steps > 0 excludes 'en'/'geen'/'niet' immediately
                    // adjacent with zero gap (e.g. "en geen ergernis is" =
                    // ordinary "and no offense", not archaic en...V doubled
                    // negation): genuine "en ... V ... niet/geen" doubling
                    // always has at least the finite verb between 'en' and
                    // the negator, so steps is >=1 there; a bare "en
                    // niet/geen" is coordinating "en" immediately followed by
                    // an unrelated negative noun/adverb phrase, which the
                    // backward-scanning branch above already handles
                    // correctly for the real "geen ... en is" shape.
                    if (isset($negatorSpan[$src[$k]]) && $steps > 0 && $steps <= $negatorSpan[$src[$k]]) {
                        $found = true;
                        break;
                    }
                    if ($src[$k] !== '') {
                        $steps++;
                    }
                    $k++;
                }
            }
            if ($found) {
                $out[] = $i;
            }
        }

        return $out;
    }

    // ── 7. Clitiek-samenvoeging (1:n) ────────────────────────────────────

    /**
     * Losse functiewoorden (CLITIC_WORDS) aan een naburige link plakken.
     * Muteert de link-objecten in $links in place (geen by-ref array nodig:
     * we voegen geen elementen aan de $links-array zelf toe).
     *
     * @param AlignmentLink[] $links
     * @param string[] $tgt
     * @param string[] $tgtRaw
     */
    public function mergeClitics(array $links, array $tgt, array $tgtRaw): void
    {
        $usedTgt = [];
        foreach ($links as $l) {
            foreach ($l->tgt as $j) {
                $usedTgt[$j] = true;
            }
        }
        $strongBoundary = ['.', ';', ':'];

        foreach ($tgt as $j => $tw) {
            if (isset($usedTgt[$j]) || !isset(self::CLITIC_WORDS[$tw])) {
                continue;
            }

            $host = null;
            $hostMinTgt = null;
            foreach ($links as $l) {
                if ($l->tgt === []) {
                    continue;
                }
                $m = min($l->tgt);
                if ($m > $j && ($hostMinTgt === null || $m < $hostMinTgt)) {
                    $host = $l;
                    $hostMinTgt = $m;
                }
            }

            if ($host !== null && $hostMinTgt - $j <= 3) {
                $blocked = false;
                for ($k = $j + 1; $k < $hostMinTgt; $k++) {
                    if (in_array($tgtRaw[$k], $strongBoundary, true)) {
                        $blocked = true;
                        break;
                    }
                }
                if (!$blocked) {
                    $host->tgt[] = $j;
                    sort($host->tgt);
                    $host->kind = 'one_to_many';
                    $usedTgt[$j] = true;
                }
            }
        }
    }

    // ── 8. Orchestratie ──────────────────────────────────────────────────

    /**
     * @param string[] $srcRaw
     * @param string[] $tgtRaw
     * @param array<int, array{0:int,1:int}> $forcedAnchors bestaande
     *     handmatige koppelingen als [srcIndex, tgtIndex]-paren; worden als
     *     score-1.0 ankers meegegeven en nooit overschreven.
     */
    public function alignPair(array $srcRaw, array $tgtRaw, array $forcedAnchors = []): AlignmentResult
    {
        $src = array_map($this->normalize(...), $srcRaw);
        $tgt = array_map($this->normalize(...), $tgtRaw);

        $particles = $this->findNegationParticles($src, $srcRaw);
        foreach ($particles as $i) {
            $src[$i] = '';
        }

        $links = [];
        $forcedSrcUsed = [];
        $forcedTgtUsed = [];
        foreach ($forcedAnchors as [$si, $ti]) {
            $links[] = new AlignmentLink($si, [$ti], 1.0, 'manual');
            $forcedSrcUsed[$si] = true;
            $forcedTgtUsed[$ti] = true;
        }

        $anchors = $this->findAnchors($src, $tgt, 0.95, $forcedSrcUsed, $forcedTgtUsed);
        foreach ($anchors as [$i, $j]) {
            $links[] = new AlignmentLink($i, [$j], 1.0, 'anchor');
        }

        foreach ($this->detectCompounds($links, $src, $tgt) as $l) {
            $links[] = $l;
        }
        usort($links, static fn(AlignmentLink $a, AlignmentLink $b) => $a->src <=> $b->src);
        $this->bridgePhrases($links, $src, $tgt);
        usort($links, static fn(AlignmentLink $a, AlignmentLink $b) => $a->src <=> $b->src);
        // NOTE: align.py computes ONE merged set here -- `{l.src ...} | {j ...}` --
        // and reuses it to filter BOTH window_src and window_tgt. That means a
        // src index and a tgt index with the same integer value can "block" each
        // other across the two axes. Looks like a quirk, not a deliberate rule,
        // but it is faithfully reproduced here: anything it wrongly excludes from
        // the window phase is still recovered by rescueMovedBlocks() right after,
        // which uses correctly-separated used_src/used_tgt sets of its own.
        [$usedSrcForBounds, $usedTgtForBounds] = $this->usedSets($links);
        $used = $usedSrcForBounds + $usedTgtForBounds;

        // Vensters tussen opeenvolgende ankers (handmatig + algoritmisch) --
        // compound/phrase-tokens overslaan.
        $allAnchors = array_merge($forcedAnchors, $anchors);
        usort($allAnchors, static fn($a, $b) => $a[0] <=> $b[0]);
        $bounds = array_merge([[-1, -1]], $allAnchors, [[count($src), count($tgt)]]);

        for ($b = 0; $b < count($bounds) - 1; $b++) {
            [$si, $ti] = $bounds[$b];
            [$sj, $tj] = $bounds[$b + 1];
            $windowSrc = [];
            for ($k = $si + 1; $k < $sj; $k++) {
                if ($src[$k] !== '' && !isset($used[$k])) {
                    $windowSrc[] = $k;
                }
            }
            $windowTgt = [];
            for ($k = $ti + 1; $k < $tj; $k++) {
                if ($tgt[$k] !== '' && !isset($used[$k])) {
                    $windowTgt[] = $k;
                }
            }
            foreach ($this->matchWindow($windowSrc, $windowTgt, $src, $tgt) as [$a, $bIdx, $score]) {
                $links[] = new AlignmentLink($a, [$bIdx], $score, 'window');
            }
        }

        usort($links, static fn(AlignmentLink $a, AlignmentLink $b) => $a->src <=> $b->src);
        $this->rescueMovedBlocks($links, $src, $tgt);

        $this->mergeClitics($links, $tgt, $tgtRaw);

        $this->bridgeSynonyms($links, $src, $tgt);
        $this->bridgeMultiSynonyms($links, $src, $tgt);
        usort($links, static fn(AlignmentLink $a, AlignmentLink $b) => $a->src <=> $b->src);
        $droppedPrefixes = $this->dropKnownPrefixes($links, $src);

        [, $usedTgtFinal] = $this->usedSets($links);
        $unmatchedTgt = [];
        foreach ($tgt as $j => $w) {
            if ($w !== '' && !isset($usedTgtFinal[$j])) {
                $unmatchedTgt[] = $j;
            }
        }

        return new AlignmentResult($links, $unmatchedTgt, $particles, $droppedPrefixes);
    }

    /**
     * @param AlignmentLink[] $links
     * @return array{0: array<int,true>, 1: array<int,true>}
     */
    private function usedSets(array $links): array
    {
        $usedSrc = [];
        $usedTgt = [];
        foreach ($links as $l) {
            $usedSrc[$l->src] = true;
            foreach ($l->tgt as $j) {
                $usedTgt[$j] = true;
            }
        }

        return [$usedSrc, $usedTgt];
    }
}
