<?php

namespace App\Service;

/**
 * A set of spelling rules that turn old Dutch (Los 1900) into modern
 * spelling -- built from spelling_rule rows, applied to plain text.
 *
 * Two kinds of rule:
 *  - word:    the whole word, or several words in a row separated by white
 *             space only ("beteekent" -> "betekent", "gelijk als" ->
 *             "zoals"). The longest phrase that matches wins.
 *  - pattern: part of any word, a * standing for the rest of it (at least
 *             one letter): "*sch" -> "*s" (end: mensch -> mens), "sch*" ->
 *             "s*" (start), "*ee*" -> "*e*" (inside: every occurrence that
 *             is neither first nor last). Not for the words in exceptions.
 *
 * A word rule wins over the patterns; the patterns apply one after the
 * other, in their order (so "menschelijke" can go through more than one).
 * Each word is changed once: a replacement is not matched again.
 *
 * An old form can have several word rules ("gelijk" -> "zoals" / "net als"):
 * the default (is_default) applies everywhere, the others are alternatives,
 * chosen -- or Los's own form -- at a single spot (SpellingContext).
 *
 * Capitals: a rule written in lower case matches the word in any case, and
 * the result gets the case of the word it replaces ("Beteekent" ->
 * "Betekent", "MENSCH" -> "MENS"). A word rule written with a capital
 * ("Gods" -> "van God") has the capital as part of the word: it matches only
 * the word with that capital (not "gods", heathen gods), and the result is
 * written as the rule has it -- with a capital only at the start of a
 * sentence ("Gods genade" at the start -> "Van God genade"). For the same
 * word, such a rule goes before one in lower case.
 */
final class SpellingRules
{
    /** A word: letters, with the apostrophe of "’t" / "zoo’n". */
    public const WORD_RE = '/[\p{L}’\']+/u';

    /**
     * first word => phrases, longest (and cased) first; a phrase's options
     * are its rules, the default first (the others: alternatives, chosen at
     * one spot -- SpellingContext).
     *
     * @var array<string, list<array{words: list<string>, key: string, cased: bool,
     *                               options: list<array{id: ?int, target: string, label: string}>}>>
     */
    private array $phrases = [];

    /** @var list<array{where: string, from: string, to: string, exceptions: array<string, true>, label: string}> */
    private array $patterns = [];

    /**
     * @param iterable<array{kind: string, source: string, target: string, exceptions?: ?string, active?: bool|int|string|null}> $rules
     *        in their order (patterns apply in this order)
     */
    public function __construct(iterable $rules)
    {
        $groups = [];   // key => phrase
        foreach ($rules as $rule) {
            if (array_key_exists('active', $rule) && !self::truthy($rule['active'])) {
                continue;
            }
            if (self::validate($rule['kind'], $rule['source'], $rule['target']) !== null) {
                continue;
            }
            $label = trim($rule['source']) . ' → ' . trim($rule['target']);
            if ($rule['kind'] === 'word') {
                $key = self::key($rule['source']);
                $groups[$key] ??= [
                    'words'   => preg_split('/\s+/u', mb_strtolower(trim($rule['source']))),
                    'key'     => $key,
                    // written with a capital: the capital belongs to the word
                    'cased'   => self::startsUpper(trim($rule['source'])),
                    'options' => [],
                    'default' => [],
                ];
                $option = ['id' => isset($rule['id']) ? (int) $rule['id'] : null, 'target' => trim($rule['target']), 'label' => $label];
                // the default first; without one marked, the first rule is it
                $isDefault = !array_key_exists('is_default', $rule) || self::truthy($rule['is_default']);
                $groups[$key][$isDefault ? 'default' : 'options'][] = $option;
            } else {
                [$where, $from, $to] = self::parsePattern($rule['source'], $rule['target']);
                $this->patterns[] = [
                    'where'      => $where,
                    'from'       => mb_strtolower($from),
                    'to'         => $to,
                    'exceptions' => array_fill_keys(self::exceptionWords($rule['exceptions'] ?? ''), true),
                    'label'      => $label,
                ];
            }
        }
        foreach ($groups as $phrase) {
            $phrase['options'] = [...$phrase['default'], ...$phrase['options']];
            unset($phrase['default']);
            $this->phrases[$phrase['words'][0]][] = $phrase;
        }
        foreach ($this->phrases as &$list) {
            usort($list, fn($a, $b) => count($b['words']) <=> count($a['words']) ?: $b['cased'] <=> $a['cased']);
        }
    }

    /**
     * The key of a word rule's old form -- the same for its alternatives, and
     * what a choice at one spot (spelling_choice.source) refers to: in lower
     * case, unless written with a capital ("Gods" is not "gods").
     */
    public static function key(string $source): string
    {
        $source = preg_replace('/\s+/u', ' ', trim($source));
        return self::startsUpper($source) ? $source : mb_strtolower($source);
    }

    public function isEmpty(): bool
    {
        return !$this->phrases && !$this->patterns;
    }

    /**
     * Why a rule can't be used, or null when it can.
     */
    public static function validate(string $kind, string $source, string $target): ?string
    {
        $source = trim($source);
        $target = trim($target);
        if ($source === '' || $target === '') {
            return 'Vul zowel de oude als de nieuwe vorm in.';
        }
        if ($kind === 'word') {
            if (str_contains($source, '*')) {
                return 'Een woordregel heeft geen *; gebruik een patroonregel voor een deel van een woord.';
            }
            if (!preg_match('/^[\p{L}’\']+(?:\s+[\p{L}’\']+)*$/u', $source)) {
                return 'De oude vorm mag alleen letters (en ’) bevatten, woorden gescheiden door spaties.';
            }
            return null;
        }
        if ($kind !== 'pattern') {
            return 'Onbekende soort regel.';
        }
        if (!preg_match('/^\*?[\p{L}’\']+\*?$/u', $source) || !str_contains($source, '*')) {
            return 'Een patroon is letters met een * aan het begin en/of eind: *sch, sch*, *ee*.';
        }
        if (!preg_match('/^\*?[\p{L}’\']*\*?$/u', $target)
            || str_starts_with($source, '*') !== str_starts_with($target, '*')
            || str_ends_with($source, '*') !== str_ends_with($target, '*')) {
            return 'De nieuwe vorm moet de * op dezelfde plaats(en) hebben als de oude: *sch → *s.';
        }
        return null;
    }

    /**
     * The text in parts: unchanged text, and changed words with what they
     * were and by which rule(s). $sentenceStart: whether the text begins a
     * sentence (a paragraph does; the text after a Hebrew word doesn't).
     * $context: the choices at single spots of the text this is part of (a
     * segment's Dutch), and the count of each old form so far in it -- the
     * n-th "gelijk" of the segment is spot n, however the text is split up.
     *
     * A word changed by a word rule tells its spot (key, occurrence), its
     * options (rule id + new form, the default first) and the choice made
     * there (null: the default; 0: Los's own form, kept).
     *
     * @return list<array<string, mixed>>
     */
    public function apply(string $text, bool $sentenceStart = true, ?SpellingContext $context = null): array
    {
        if ($this->isEmpty() || $text === '') {
            return [['type' => 'text', 'content' => $text]];
        }
        $context ??= new SpellingContext();
        preg_match_all(self::WORD_RE, $text, $m, PREG_OFFSET_CAPTURE);
        $words = $m[0];
        $parts = [];
        $cursor = 0;
        $n = count($words);
        for ($i = 0; $i < $n; $i++) {
            [$word, $at] = $words[$i];
            $spot = null;
            $phrase = $this->phraseAt($text, $words, $i);
            if ($phrase !== null) {
                $len = count($phrase['words']);
                $end = $words[$i + $len - 1][1] + strlen($words[$i + $len - 1][0]);
                $original = substr($text, $at, $end - $at);
                $occurrence = $context->next($phrase['key']);
                $chosen = $context->choice($phrase['key'], $occurrence);
                $option = $phrase['options'][0];
                foreach ($phrase['options'] as $o) {
                    if ($chosen !== null && $chosen !== 0 && $o['id'] === $chosen) {
                        $option = $o;
                    }
                }
                $spot = [
                    'key'        => $phrase['key'],
                    'occurrence' => $occurrence,
                    'options'    => array_map(fn($o) => ['id' => $o['id'], 'target' => $o['target']], $phrase['options']),
                    'chosen'     => $chosen === null || $chosen === 0 || $option['id'] === $chosen ? $chosen : null,
                ];
                $label = $option['label'];
                if ($chosen === 0) {
                    $new = $original;           // Los's own form, at this spot
                    $label = 'Los ongewijzigd (hier gekozen)';
                } elseif (!$phrase['cased']) {
                    $new = self::matchCase($option['target'], $word);
                } elseif (self::isAllCaps($word)) {
                    $new = mb_strtoupper($option['target']);
                } else {
                    // as the rule has it; a capital only to start a sentence
                    $new = self::beginsSentence($text, $at, $sentenceStart) ? self::capitalise($option['target']) : $option['target'];
                }
                $i += $len - 1;
            } else {
                [$new, $label] = $this->patternsOn($word);
                if ($new === $word) {
                    continue;
                }
                $original = $word;
                $end = $at + strlen($word);
            }
            if ($at > $cursor) {
                $parts[] = ['type' => 'text', 'content' => substr($text, $cursor, $at - $cursor)];
            }
            $parts[] = ['type' => 'modern', 'content' => $new, 'original' => $original, 'rule' => $label] + ($spot ?? []);
            $cursor = $end;
        }
        if ($cursor < strlen($text)) {
            $parts[] = ['type' => 'text', 'content' => substr($text, $cursor)];
        }
        return $parts ?: [['type' => 'text', 'content' => $text]];
    }

    /** The whole text in modern spelling, as a string. */
    public function modernize(string $text, bool $sentenceStart = true, ?SpellingContext $context = null): string
    {
        return implode('', array_map(fn($p) => $p['content'], $this->apply($text, $sentenceStart, $context)));
    }

    /**
     * One word through the patterns (not the word rules): the result and
     * the rules that changed it.
     *
     * @return array{0: string, 1: string}
     */
    public function patternsOn(string $word): array
    {
        $lower = mb_strtolower($word);
        $current = $lower;
        $labels = [];
        foreach ($this->patterns as $p) {
            if (isset($p['exceptions'][$lower]) || isset($p['exceptions'][$current])) {
                continue;
            }
            $next = self::applyPattern($current, $p['where'], $p['from'], mb_strtolower($p['to']));
            if ($next !== $current) {
                $current = $next;
                $labels[] = $p['label'];
            }
        }
        if ($current === $lower) {
            return [$word, ''];
        }
        return [self::matchCase($current, $word), implode('; ', $labels)];
    }

    /**
     * The phrase (word rule, with its alternatives) that matches at word $i:
     * the longest, then the one written with a capital.
     *
     * @param list<array{0: string, 1: int}> $words
     * @return array{words: list<string>, key: string, cased: bool, options: list<array{id: ?int, target: string, label: string}>}|null
     */
    private function phraseAt(string $text, array $words, int $i): ?array
    {
        $first = mb_strtolower($words[$i][0]);
        foreach ($this->phrases[$first] ?? [] as $phrase) {
            $len = count($phrase['words']);
            if ($i + $len > count($words)) {
                continue;
            }
            if ($phrase['cased'] && !self::startsUpper($words[$i][0])) {
                continue;           // "Gods" is not "gods"
            }
            for ($k = 1; $k < $len; $k++) {
                [$prev, $prevAt] = $words[$i + $k - 1];
                [$cur, $curAt] = $words[$i + $k];
                $gap = substr($text, $prevAt + strlen($prev), $curAt - $prevAt - strlen($prev));
                if (mb_strtolower($cur) !== $phrase['words'][$k] || !preg_match('/^\s+$/u', $gap)) {
                    continue 2;
                }
            }
            return $phrase;
        }
        return null;
    }

    private static function applyPattern(string $word, string $where, string $from, string $to): string
    {
        $wl = mb_strlen($word);
        $fl = mb_strlen($from);
        if ($wl <= $fl) {
            return $word;           // the * stands for at least one letter
        }
        switch ($where) {
            case 'end':
                return mb_substr($word, -$fl) === $from ? mb_substr($word, 0, $wl - $fl) . $to : $word;
            case 'start':
                return mb_substr($word, 0, $fl) === $from ? $to . mb_substr($word, $fl) : $word;
            default:                // inside: at least one letter before and after
                $out = mb_substr($word, 0, 1);
                $rest = mb_substr($word, 1);
                while ($rest !== '') {
                    if (mb_strlen($rest) > $fl && mb_substr($rest, 0, $fl) === $from) {
                        $out .= $to;
                        $rest = mb_substr($rest, $fl);
                    } else {
                        $out .= mb_substr($rest, 0, 1);
                        $rest = mb_substr($rest, 1);
                    }
                }
                return $out;
        }
    }

    /**
     * Where a pattern's * stands and its letters: [where, from, to].
     *
     * @return array{0: string, 1: string, 2: string}
     */
    private static function parsePattern(string $source, string $target): array
    {
        $source = trim($source);
        $target = trim($target);
        $start = str_starts_with($source, '*');
        $end = str_ends_with($source, '*');
        $where = $start && $end ? 'inside' : ($start ? 'end' : 'start');
        return [$where, trim($source, '*'), trim($target, '*')];
    }

    /** @return list<string> */
    public static function exceptionWords(?string $exceptions): array
    {
        return array_values(array_filter(array_map(
            fn($w) => mb_strtolower(trim($w)),
            preg_split('/[\n,;]+/u', (string) $exceptions)
        ), fn($w) => $w !== ''));
    }

    /**
     * Whether the word at byte offset $at begins a sentence: nothing but
     * opening quotes/brackets before it since a full stop, question or
     * exclamation mark (or the start of the text, when that starts one).
     */
    private static function beginsSentence(string $text, int $at, bool $sentenceStart): bool
    {
        $before = rtrim(preg_replace('/[\s„“"‘\'(]+$/u', '', substr($text, 0, $at)));
        if ($before === '') {
            return $sentenceStart;
        }
        return (bool) preg_match('/[.!?]["”’)]*$/u', $before) && !preg_match('/\b(?:vs|d\.i|nl|enz|bijv|o\.a|cap|hfdst)\.$/iu', $before);
    }

    private static function startsUpper(string $s): bool
    {
        return preg_match('/\p{L}/u', $s, $m) === 1 && mb_strtoupper($m[0]) === $m[0] && mb_strtolower($m[0]) !== $m[0];
    }

    private static function isAllCaps(string $word): bool
    {
        $letters = preg_replace('/[^\p{L}]/u', '', $word);
        return mb_strlen($letters) > 1 && mb_strtoupper($letters) === $letters;
    }

    private static function capitalise(string $s): string
    {
        return preg_replace_callback('/\p{L}/u', fn($x) => mb_strtoupper($x[0]), $s, 1);
    }

    /** The replacement in the case of the word it replaces. */
    private static function matchCase(string $replacement, string $model): string
    {
        $letters = preg_replace('/[^\p{L}]/u', '', $model);
        if (mb_strlen($letters) > 1 && mb_strtoupper($letters) === $letters) {
            return mb_strtoupper($replacement);
        }
        $firstLetter = preg_match('/\p{L}/u', $model, $m) ? $m[0] : '';
        if ($firstLetter !== '' && mb_strtoupper($firstLetter) === $firstLetter) {
            // the first letter of the replacement, after a leading ’ ("’t")
            return preg_replace_callback('/\p{L}/u', fn($x) => mb_strtoupper($x[0]), $replacement, 1);
        }
        return $replacement;
    }

    private static function truthy(mixed $v): bool
    {
        return $v === true || $v === 1 || $v === '1' || $v === 't' || $v === 'true';
    }
}
