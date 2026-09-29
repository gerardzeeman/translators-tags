<?php

namespace App\Tests\Unit\Service;

use App\Service\SpellingContext;
use App\Service\SpellingRules;
use PHPUnit\Framework\TestCase;

/**
 * Unit tests for SpellingRules (modern spelling of the Los translation).
 */
class SpellingRulesTest extends TestCase
{
    private function rules(array ...$rules): SpellingRules
    {
        return new SpellingRules(array_map(fn($r) => [
            'kind' => $r[0], 'source' => $r[1], 'target' => $r[2], 'exceptions' => $r[3] ?? '',
        ], $rules));
    }

    public function testWordRuleReplacesWholeWordsOnly(): void
    {
        $r = $this->rules(['word', 'beteekent', 'betekent']);
        $this->assertSame('Dit betekent: onbeteekent blijft.', $r->modernize('Dit beteekent: onbeteekent blijft.'));
    }

    public function testCaseOfTheReplacedWordIsKept(): void
    {
        $r = $this->rules(['word', 'beteekent', 'betekent'], ['word', 'gelijk', 'zoals']);
        $this->assertSame('Betekent zoals. Zoals ZOALS', $r->modernize('Beteekent gelijk. Gelijk GELIJK'));
    }

    public function testLongestPhraseWinsAndNeedsOnlyWhiteSpaceBetween(): void
    {
        $r = $this->rules(['word', 'gelijk', 'net als'], ['word', 'gelijk als', 'zoals']);
        $this->assertSame('zoals wij', $r->modernize('gelijk als wij'));
        $this->assertSame('net als, als wij', $r->modernize('gelijk, als wij'));
    }

    public function testPatternAtTheEnd(): void
    {
        $r = $this->rules(['pattern', '*sch', '*s']);
        $this->assertSame('de mens en het vlees', $r->modernize('de mensch en het vleesch'));
        // the * stands for at least one letter: "sch" alone is not changed
        $this->assertSame('sch', $r->modernize('sch'));
    }

    public function testPatternAtTheStartAndInside(): void
    {
        $r = $this->rules(['pattern', 'sch*', 's*'], ['pattern', '*ee*', '*e*']);
        $this->assertSame('sip betekent', $r->modernize('schip beteekent'));
        // not at the very start or end: "eet" and "zee" stay
        $this->assertSame('eet zee', $r->modernize('eet zee'));
    }

    public function testPatternsChainInOrderAndWordRulesWin(): void
    {
        $r = $this->rules(['pattern', '*schen', '*sen'], ['pattern', '*ee*', '*e*'], ['word', 'eene', 'een']);
        $this->assertSame('mensen', $r->modernize('menschen'));
        $this->assertSame('een', $r->modernize('eene'));
    }

    public function testExceptionsAreSkipped(): void
    {
        $r = $this->rules(['pattern', '*sch', '*s', "Asch\nwasch"]);
        $this->assertSame('Asch wasch mens', $r->modernize('Asch wasch mensch'));
    }

    public function testPartsTellWhatChangedAndWhy(): void
    {
        $r = $this->rules(['word', 'beteekent', 'betekent']);
        $parts = $r->apply('Dit beteekent veel.');
        $this->assertSame([
            ['type' => 'text', 'content' => 'Dit '],
            ['type' => 'modern', 'content' => 'betekent', 'original' => 'beteekent', 'rule' => 'beteekent → betekent',
             'key' => 'beteekent', 'occurrence' => 1, 'options' => [['id' => null, 'target' => 'betekent']], 'chosen' => null],
            ['type' => 'text', 'content' => ' veel.'],
        ], $parts);
    }

    public function testInactiveAndInvalidRulesAreIgnored(): void
    {
        $r = new SpellingRules([
            ['kind' => 'word', 'source' => 'zoo', 'target' => 'zo', 'active' => false],
            ['kind' => 'pattern', 'source' => 'sch', 'target' => 's'],   // no *
        ]);
        $this->assertTrue($r->isEmpty());
        $this->assertSame('zoo mensch', $r->modernize('zoo mensch'));
    }

    public function testValidation(): void
    {
        $this->assertNull(SpellingRules::validate('word', 'gelijk als', 'zoals'));
        $this->assertNull(SpellingRules::validate('pattern', '*sch', '*s'));
        $this->assertNotNull(SpellingRules::validate('word', 'men*', 'mens'));
        $this->assertNotNull(SpellingRules::validate('pattern', '*sch', 's'));
        $this->assertNotNull(SpellingRules::validate('pattern', 'sch', 's'));
        $this->assertNotNull(SpellingRules::validate('word', '', 'x'));
    }

    public function testRuleWrittenWithACapitalKeepsItsOwnCase(): void
    {
        $r = $this->rules(['word', 'Gods', 'van God']);
        // the capital belongs to the word: the result as the rule has it
        $this->assertSame('het woord van God is', $r->modernize('het woord Gods is'));
        // "gods" (heathen gods) is another word
        $this->assertSame('de gods van Egypte', $r->modernize('de gods van Egypte'));
        // a capital only to start a sentence
        $this->assertSame('Van God genade. Van God woord, en van God wil', $r->modernize('Gods genade. Gods woord, en Gods wil'));
        // text that doesn't start a sentence (after a Hebrew word, say)
        $this->assertSame('van God', $r->modernize('Gods', false));
        $this->assertSame('„Van God genade', $r->modernize('„Gods genade'));
        $this->assertSame('vs. 3 van God', $r->modernize('vs. 3 Gods'));
    }

    public function testCapitalisedRuleGoesBeforeLowerCaseOne(): void
    {
        $r = $this->rules(['word', 'gods', 'goden'], ['word', 'Gods', 'van God']);
        $this->assertSame('de goden en het woord van God', $r->modernize('de gods en het woord Gods'));
        $this->assertSame('goden', $r->modernize('gods'));
    }

    public function testDefaultRuleAppliesAndAlternativesCanBeChosenPerSpot(): void
    {
        $r = new SpellingRules([
            ['id' => 1, 'kind' => 'word', 'source' => 'gelijk', 'target' => 'net als', 'is_default' => false],
            ['id' => 2, 'kind' => 'word', 'source' => 'gelijk', 'target' => 'zoals', 'is_default' => true],
        ]);
        $this->assertSame('zoals a, zoals b, zoals c', $r->modernize('gelijk a, gelijk b, gelijk c'));

        // the 2nd "gelijk": the alternative; the 3rd: Los's own form
        $context = new SpellingContext(['gelijk' => [2 => 1, 3 => 0]]);
        $this->assertSame('zoals a, net als b, gelijk c', $r->modernize('gelijk a, gelijk b, gelijk c', true, $context));

        // spots count on through the parts of one text
        $context = new SpellingContext(['gelijk' => [2 => 1]]);
        $first = $r->apply('Gelijk a.', true, $context);
        $second = $r->apply(' gelijk b', false, $context);
        $this->assertSame('Zoals', $first[0]['content']);
        $this->assertSame(1, $first[0]['occurrence']);
        $this->assertSame('net als', $second[1]['content']);
        $this->assertSame(2, $second[1]['occurrence']);
        $this->assertSame(1, $second[1]['chosen']);
        $this->assertSame([['id' => 2, 'target' => 'zoals'], ['id' => 1, 'target' => 'net als']], $second[1]['options']);
    }

    public function testChoiceOfARuleThatIsGoneFallsBackToTheDefault(): void
    {
        $r = new SpellingRules([['id' => 2, 'kind' => 'word', 'source' => 'gelijk', 'target' => 'zoals']]);
        $parts = $r->apply('gelijk', true, new SpellingContext(['gelijk' => [1 => 99]]));
        $this->assertSame('zoals', $parts[0]['content']);
        $this->assertNull($parts[0]['chosen']);
    }

    public function testApostropheWords(): void
    {
        $r = $this->rules(['word', '’t', 'het']);
        $this->assertSame('het is het beste', $r->modernize('’t is ’t beste'));
        $this->assertSame('Het is het beste', $r->modernize('’T is ’t beste'));
    }
}
