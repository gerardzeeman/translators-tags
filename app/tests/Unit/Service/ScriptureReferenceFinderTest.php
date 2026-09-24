<?php

namespace App\Tests\Unit\Service;

use App\Service\ScriptureReferenceFinder;
use PHPUnit\Framework\TestCase;

/**
 * Unit tests for ScriptureReferenceFinder, on reference forms taken from
 * the Canones Dordraceni and NGB texts.
 */
class ScriptureReferenceFinderTest extends TestCase
{
    private ScriptureReferenceFinder $finder;

    protected function setUp(): void
    {
        $this->finder = new ScriptureReferenceFinder();
    }

    /** Each span as [matched text, encoded refs]. */
    private function found(string $text, bool $latin): array
    {
        $spans = $latin ? $this->finder->findLatin($text) : $this->finder->findDutch($text);
        return array_map(
            fn($s) => [mb_substr($text, $s['start'], $s['end'] - $s['start']), $this->finder->encode($s['refs'])],
            $spans
        );
    }

    public function testDutchVerseListMergesConsecutiveVerses(): void
    {
        $this->assertSame(
            [['Rom. 3:19, 23', 'ROM.3.19-19;ROM.3.23-23'], ['1 Petr. 1:18, 19', '1PE.1.18-19']],
            $this->found('der heerlijkheid Gods (Rom. 3:19, 23). Zie 1 Petr. 1:18, 19.', false)
        );
    }

    public function testDutchChainsWithinOneBookAndSplitsPerBook(): void
    {
        $this->assertSame(
            [
                ['Hand. 20:27', 'ACT.20.27-27'],
                ['Rom. 12:3 en 11:33, 34', 'ROM.12.3-3;ROM.11.33-34'],
                ['Hebr. 6:17, 18', 'HEB.6.17-18'],
            ],
            $this->found('(Hand. 20:27; Rom. 12:3 en 11:33, 34; Hebr. 6:17, 18)', false)
        );
        $this->assertSame([['Gen. 6:5; 8:21', 'GEN.6.5-5;GEN.8.21-21']], $this->found('(Gen. 6:5; 8:21.)', false));
    }

    public function testDutchRangeAndUnknownBook(): void
    {
        $this->assertSame([['Rom. 11:33-36', 'ROM.11.33-36']], $this->found('(Rom. 11:33-36)', false));
        $this->assertSame([], $this->found('Art. 5:3 van de kerkorde', false));
    }

    public function testLatinRomanChaptersAndVerContinuation(): void
    {
        $this->assertSame(
            [['Rom. iii. 19', 'ROM.3.19-19'], ['Ver. 23', 'ROM.3.23-23'], ['Rom. vi. 23', 'ROM.6.23-23']],
            $this->found('condemnationi Dei. Rom. iii. 19. Omnes peccaverunt. Ver. 23. Et, Stipendium. Rom. vi. 23.', true)
        );
    }

    public function testLatinNumberedBooksRangesAndEt(): void
    {
        $this->assertSame([['1 Johan. iv. 9', '1JN.4.9-9']], $this->found('1 Johan. iv. 9;', true));
        $this->assertSame([['Rom. xi. 33–36', 'ROM.11.33-36']], $this->found('Rom. xi. 33–36.', true));
        $this->assertSame([['Act xiii. 48', 'ACT.13.48-48']], $this->found('ad vitam æternam. Act xiii. 48.', true));
        $this->assertSame([['Gen. vi. 5 et viii. 21', 'GEN.6.5-5;GEN.8.21-21']], $this->found('Gen. vi. 5 et viii. 21:', true));
        $this->assertSame([['Psa. cxlvii. 19, 20', 'PSA.147.19-20']], $this->found('Psa. cxlvii. 19, 20:', true));
    }

    public function testLatinNgbArabicAndWholeChapters(): void
    {
        $this->assertSame([['Rom. 1, 20', 'ROM.1.20-20']], $this->found('ut Apostolus Paulus loquitur Rom. 1, 20. Quae', true));
        $this->assertSame(
            [['1 Tim. 3', '1TI.3'], ['Tit. 1', 'TIT.1']],
            $this->found('quod habetur 1 Tim. 3. et Tit. 1.', true)
        );
    }

    public function testLatinIgnoresNonBookWords(): void
    {
        $this->assertSame([['1 Cor. i. 8', '1CO.1.8-8']], $this->found('Domini nostri Jesu Christi. 1 Cor. i. 8.', true));
    }

    public function testDecodeIsStrictAndRoundTrips(): void
    {
        $refs = $this->finder->decode('ROM.3.19-19;1TI.3;bad;ROM.x.1-2');
        $this->assertSame('ROM.3.19-19;1TI.3', $this->finder->encode($refs));
    }
}
