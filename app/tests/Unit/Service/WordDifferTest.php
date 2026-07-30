<?php

namespace App\Tests\Unit\Service;

use App\Service\WordDiffer;
use PHPUnit\Framework\TestCase;

/**
 * Unit tests for WordDiffer.
 */
class WordDifferTest extends TestCase
{
    private WordDiffer $differ;

    protected function setUp(): void
    {
        $this->differ = new WordDiffer();
    }

    public function testIdenticalTextIsAllEqual(): void
    {
        $result = $this->differ->diff('De kat zit op de mat', 'De kat zit op de mat');

        $this->assertSame(
            [['op' => 'equal', 'text' => 'De kat zit op de mat']],
            $result
        );
    }

    public function testCompletelyDifferentTextIsOneDeleteAndOneInsert(): void
    {
        $result = $this->differ->diff('appel peer banaan', 'auto fiets trein');

        $this->assertSame(
            [
                ['op' => 'delete', 'text' => 'appel peer banaan'],
                ['op' => 'insert', 'text' => 'auto fiets trein'],
            ],
            $result
        );
    }

    public function testEmptyOldTextIsAllInsert(): void
    {
        $result = $this->differ->diff('', 'nieuwe tekst hier');

        $this->assertSame(
            [['op' => 'insert', 'text' => 'nieuwe tekst hier']],
            $result
        );
    }

    public function testEmptyNewTextIsAllDelete(): void
    {
        $result = $this->differ->diff('oude tekst hier', '');

        $this->assertSame(
            [['op' => 'delete', 'text' => 'oude tekst hier']],
            $result
        );
    }

    public function testBothEmptyIsEmptyDiff(): void
    {
        $this->assertSame([], $this->differ->diff('', ''));
    }

    public function testSingleWordSubstitutionInLongerSentence(): void
    {
        $result = $this->differ->diff(
            'De kennis van God en de kennis van onszelf zijn met elkaar verbonden.',
            'De kennis van God en de kennis van onszelf zijn nauw met elkaar verbonden.'
        );

        $this->assertSame(
            [
                ['op' => 'equal', 'text' => 'De kennis van God en de kennis van onszelf zijn'],
                ['op' => 'insert', 'text' => 'nauw'],
                ['op' => 'equal', 'text' => 'met elkaar verbonden.'],
            ],
            $result
        );
    }

    public function testLeadingAndTrailingWhitespaceIsIgnored(): void
    {
        $result = $this->differ->diff('  De kat zit  ', 'De kat zit');

        $this->assertSame(
            [['op' => 'equal', 'text' => 'De kat zit']],
            $result
        );
    }

    public function testWordRemovedFromMiddle(): void
    {
        $result = $this->differ->diff(
            'Dit is een erg lange zin met veel woorden.',
            'Dit is een lange zin met veel woorden.'
        );

        $this->assertSame(
            [
                ['op' => 'equal', 'text' => 'Dit is een'],
                ['op' => 'delete', 'text' => 'erg'],
                ['op' => 'equal', 'text' => 'lange zin met veel woorden.'],
            ],
            $result
        );
    }
}
