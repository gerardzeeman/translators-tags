<?php

namespace App\Service;

use App\Repository\SpellingRuleRepository;

/**
 * The modern-spelling version of a historical Dutch translation layer: its
 * rules (SpellingRules, loaded once per request) and, for the rules page,
 * what they do to the translator's text -- hits per rule, a preview of a
 * rule being written, how much of the old spelling is covered, and the most
 * frequent old-fashioned words no rule covers yet.
 */
class SpellingModernizer
{
    /** @var array<string, SpellingRules> */
    private array $rules = [];

    /** @var array<string, array<string, int>> layer => lower-case word => count */
    private array $frequencies = [];

    public function __construct(private readonly SpellingRuleRepository $repository) {}

    public function rules(string $layer = SpellingRuleRepository::DEFAULT_LAYER): SpellingRules
    {
        return $this->rules[$layer] ??= new SpellingRules($this->repository->all($layer));
    }

    /**
     * Dutch paragraphs of parts (CommentaryController::dutchParagraphs) in
     * modern spelling: plain text through the rules; a misprint's intended
     * word too (its popup stays); Hebrew/Greek words as they are.
     *
     * @param list<list<array<string, mixed>>> $paragraphs
     * @return list<list<array<string, mixed>>>
     */
    public function paragraphs(array $paragraphs, string $layer = SpellingRuleRepository::DEFAULT_LAYER,
                               ?SpellingContext $context = null): array
    {
        $rules = $this->rules($layer);
        // one context for the whole text: spots count on across paragraphs
        $context ??= new SpellingContext();
        return array_map(function (array $parts) use ($rules, $context): array {
            $out = [];
            $sentenceStart = true;      // a paragraph starts a sentence
            foreach ($parts as $part) {
                if ($part['type'] === 'text') {
                    array_push($out, ...$rules->apply($part['content'], $sentenceStart, $context));
                } elseif ($part['type'] === 'correction') {
                    $out[] = ['content' => $rules->modernize($part['content'], $sentenceStart, $context)] + $part;
                } else {
                    $out[] = $part;
                }
                // the next part starts one only after a full stop etc.
                $sentenceStart = (bool) preg_match('/[.!?]["”’)]*\s*$/u', (string) $part['content']);
            }
            return $out;
        }, $paragraphs);
    }

    /**
     * What one rule, as it is being written, would do: how often it applies,
     * the words it changes, and examples in context.
     *
     * @return array{error: ?string, hits: int, words: list<array{old: string, new: string, count: int}>,
     *               examples: list<array{before: string, old: string, new: string, after: string}>}
     */
    public function preview(string $kind, string $source, string $target, string $exceptions,
                            string $layer = SpellingRuleRepository::DEFAULT_LAYER): array
    {
        $error = SpellingRules::validate($kind, $source, $target);
        if ($error !== null) {
            return ['error' => $error, 'hits' => 0, 'words' => [], 'examples' => []];
        }
        $rule = new SpellingRules([['kind' => $kind, 'source' => $source, 'target' => $target, 'exceptions' => $exceptions]]);
        $hits = 0;
        $words = [];
        $examples = [];
        // (a phrase, or a word written with a capital -- which then counts --
        // is found in the text itself, not in the lower-case word list)
        $multiWord = $kind === 'word' && (preg_match('/\s/u', trim($source)) || self::startsUpper(trim($source)));
        if (!$multiWord) {
            foreach ($this->frequencies($layer) as $word => $count) {
                $new = $kind === 'word'
                    ? (mb_strtolower(trim($source)) === $word ? mb_strtolower(trim($target)) : $word)
                    : $rule->patternsOn($word)[0];
                if ($new !== $word) {
                    $hits += $count;
                    $words[] = ['old' => $word, 'new' => $new, 'count' => $count];
                }
            }
            usort($words, fn($a, $b) => $b['count'] <=> $a['count']);
        }
        foreach ($this->repository->corpus($layer) as $text) {
            $offset = 0;
            foreach ($rule->apply($text) as $part) {
                if ($part['type'] === 'modern') {
                    if ($multiWord) {
                        $hits++;
                        $words[$part['original']] ??= ['old' => mb_strtolower($part['original']), 'new' => $part['content'], 'count' => 0];
                        $words[$part['original']]['count']++;
                    }
                    if (count($examples) < 6) {
                        $examples[] = [
                            'before' => self::tail(substr($text, 0, $offset), 60),
                            'old'    => $part['original'],
                            'new'    => $part['content'],
                            'after'  => self::head(substr($text, $offset + strlen($part['original'])), 60),
                        ];
                    }
                }
                $offset += strlen($part['type'] === 'modern' ? $part['original'] : $part['content']);
            }
            if (!$multiWord && count($examples) >= 6) {
                break;
            }
        }
        return ['error' => null, 'hits' => $hits, 'words' => array_slice(array_values($words), 0, 40), 'examples' => $examples];
    }

    /**
     * How often each rule applies (on its own) in the translator's text.
     *
     * @param list<array<string, mixed>> $rules
     * @return array<int, int> rule id => hits
     */
    public function hitsPerRule(array $rules, string $layer = SpellingRuleRepository::DEFAULT_LAYER): array
    {
        $freq = $this->frequencies($layer);
        $corpus = null;
        $hits = [];
        foreach ($rules as $r) {
            if (SpellingRules::validate($r['kind'], $r['source'], $r['target']) !== null) {
                $hits[$r['id']] = 0;
                continue;
            }
            $cased = self::startsUpper(trim($r['source']));
            if ($r['kind'] === 'word' && !$cased && !preg_match('/\s/u', trim($r['source']))) {
                $hits[$r['id']] = $freq[mb_strtolower(trim($r['source']))] ?? 0;
            } elseif ($r['kind'] === 'word') {
                // a phrase, or a word with its capital -- then the first
                // letter's case counts: "Gods", not "gods"
                $corpus ??= implode("\n", $this->repository->corpus($layer));
                $words = array_map(fn($w) => preg_quote($w, '/'), preg_split('/\s+/u', trim($r['source'])));
                if ($cased) {
                    $words[0] = '(?-i:' . mb_substr($words[0], 0, 1) . ')' . mb_substr($words[0], 1);
                }
                $hits[$r['id']] = preg_match_all('/(?<![\p{L}’\'])' . implode('\s+', $words) . '(?![\p{L}’\'])/iu', $corpus);
            } else {
                $one = new SpellingRules([$r + ['active' => true]]);
                $n = 0;
                foreach ($freq as $word => $count) {
                    if ($one->patternsOn($word)[0] !== $word) {
                        $n += $count;
                    }
                }
                $hits[$r['id']] = $n;
            }
        }
        return $hits;
    }

    /**
     * The translator's old-fashioned words -- not in modern Dutch (the HSV's
     * words) -- and how many of them the rules change, by occurrence; and
     * the most frequent ones still unchanged, as suggestions.
     *
     * @return array{old_tokens: int, covered_tokens: int, old_words: int, covered_words: int,
     *               suggestions: list<array{word: string, count: int}>}
     */
    public function coverage(int $suggestions = 80, string $layer = SpellingRuleRepository::DEFAULT_LAYER): array
    {
        $modern = $this->repository->modernVocabulary();
        $rules = $this->rules($layer);
        $old = $covered = $oldWords = $coveredWords = 0;
        $open = [];
        foreach ($this->frequencies($layer) as $word => $count) {
            if (isset($modern[$word]) || preg_match('/\d/', $word) || mb_strlen($word) < 2) {
                continue;
            }
            $old += $count;
            $oldWords++;
            if ($rules->modernize($word) !== $word) {
                $covered += $count;
                $coveredWords++;
            } else {
                $open[] = ['word' => $word, 'count' => $count];
            }
        }
        usort($open, fn($a, $b) => $b['count'] <=> $a['count'] ?: strcmp($a['word'], $b['word']));
        return [
            'old_tokens'     => $old,
            'covered_tokens' => $covered,
            'old_words'      => $oldWords,
            'covered_words'  => $coveredWords,
            'suggestions'    => array_slice($open, 0, $suggestions),
        ];
    }

    /** @return array<string, int> lower-case word => occurrences in the translator's text */
    private function frequencies(string $layer): array
    {
        if (!isset($this->frequencies[$layer])) {
            $freq = [];
            foreach ($this->repository->corpus($layer) as $text) {
                preg_match_all(SpellingRules::WORD_RE, mb_strtolower($text), $m);
                foreach ($m[0] as $w) {
                    $freq[$w] = ($freq[$w] ?? 0) + 1;
                }
            }
            $this->frequencies[$layer] = $freq;
        }
        return $this->frequencies[$layer];
    }

    private static function startsUpper(string $s): bool
    {
        return preg_match('/\p{L}/u', $s, $m) === 1 && mb_strtoupper($m[0]) === $m[0] && mb_strtolower($m[0]) !== $m[0];
    }

    private static function tail(string $s, int $n): string
    {
        $s = preg_replace('/\s+/u', ' ', $s);
        return mb_strlen($s) > $n ? '…' . mb_substr($s, -$n) : $s;
    }

    private static function head(string $s, int $n): string
    {
        $s = preg_replace('/\s+/u', ' ', $s);
        return mb_strlen($s) > $n ? mb_substr($s, 0, $n) . '…' : $s;
    }
}
