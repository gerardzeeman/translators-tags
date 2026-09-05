<?php

namespace App\Tests\Unit\Service\Alignment;

use App\Repository\AlignmentLibraryRepository;
use App\Service\Alignment\AlignmentLibraryService;
use App\Service\Alignment\HistoricalAlignmentService;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

class AlignmentLibraryServiceTest extends TestCase
{
    #[DataProvider('typeProvider')]
    public function testDetermineSynonymType(int $sourceCount, int $targetCount, string $expected): void
    {
        $this->assertSame($expected, AlignmentLibraryService::determineSynonymType($sourceCount, $targetCount));
    }

    public static function typeProvider(): array
    {
        return [
            '1:1 is a plain bridge'                 => [1, 1, 'bridge'],
            '1:2 is a multi (one word explodes)'    => [1, 2, 'multi'],
            '1:5 is still a multi'                  => [1, 5, 'multi'],
            '2:1 is a phrase (multi-word source)'   => [2, 1, 'phrase'],
            '3:1 is a phrase'                       => [3, 1, 'phrase'],
            '2:2 is a phrase'                       => [2, 2, 'phrase'],
            '3:5 is a phrase'                       => [3, 5, 'phrase'],
        ];
    }

    public function testLexiconRejectsAMultiWordGroup(): void
    {
        $repo = $this->createMock(AlignmentLibraryRepository::class);
        $repo->expects($this->once())->method('findPairGroup')->willReturn([
            'source_link_id' => 1,
            'source_words'   => [['id' => 1, 'word_text' => 'doodsloegh']],
            'target_words'   => [['id' => 2, 'word_text' => 'sloeg'], ['id' => 3, 'word_text' => 'dood']],
        ]);
        $repo->expects($this->never())->method('addLexiconEntry');

        $service = new AlignmentLibraryService($repo, new HistoricalAlignmentService());
        $result = $service->addToLibrary(1, 2, 'lexicon');

        $this->assertSame('error', $result['status']);
    }

    public function testLexiconNoopsWhenAlreadyEqualAfterNormalize(): void
    {
        $repo = $this->createMock(AlignmentLibraryRepository::class);
        $repo->method('findPairGroup')->willReturn([
            'source_link_id' => 1,
            'source_words'   => [['id' => 1, 'word_text' => 'PAULUS']],
            'target_words'   => [['id' => 2, 'word_text' => 'Paulus']],
        ]);
        $repo->expects($this->never())->method('addLexiconEntry');

        $service = new AlignmentLibraryService($repo, new HistoricalAlignmentService());
        $result = $service->addToLibrary(1, 2, 'lexicon');

        $this->assertSame('noop', $result['status']);
    }

    public function testLexiconAddsRawSourceFormToNormalizedTargetForm(): void
    {
        // Neither word matches any DEFAULT_LEXICON entry or CHAR_RULES
        // pattern, so normalize() is effectively the identity here -- this
        // isolates the "genuinely new mapping" path from the "already
        // equal after normalisation" noop path.
        $repo = $this->createMock(AlignmentLibraryRepository::class);
        $repo->method('findPairGroup')->willReturn([
            'source_link_id' => 42,
            'source_words'   => [['id' => 1, 'word_text' => 'Fóóbarhist!']],
            'target_words'   => [['id' => 2, 'word_text' => 'quxmoderna']],
        ]);
        $repo->expects($this->once())
            ->method('addLexiconEntry')
            ->with('foobarhist', 'quxmoderna', 42)
            ->willReturn('added');

        $service = new AlignmentLibraryService($repo, new HistoricalAlignmentService());
        $result = $service->addToLibrary(1, 2, 'lexicon');

        $this->assertSame('added', $result['status']);
        $this->assertSame('lexicon', $result['type']);
    }

    public function testLexiconConflictWhenSourceFormAlreadyMapsElsewhere(): void
    {
        $repo = $this->createMock(AlignmentLibraryRepository::class);
        $repo->method('findPairGroup')->willReturn([
            'source_link_id' => null,
            'source_words'   => [['id' => 1, 'word_text' => 'foo']],
            'target_words'   => [['id' => 2, 'word_text' => 'bar']],
        ]);
        $repo->method('addLexiconEntry')->willReturn('conflict');

        $service = new AlignmentLibraryService($repo, new HistoricalAlignmentService());
        $result = $service->addToLibrary(1, 2, 'lexicon');

        $this->assertSame('conflict', $result['status']);
    }

    /**
     * Regression for a real report: linking SVGBS "der" to HSV "van"+"de"
     * produced the confusing message 'Toegevoegd als multi-synoniem: "van"
     * → "van + de"' -- because "der" is already in DEFAULT_LEXICON as
     * "der" => "van", normalize() rewrites it to "van" before the
     * multi-synonym stage ever sees it, so "van" is the only key under
     * which the rule can ever actually fire (storing it under "der" would
     * make it permanently unreachable). The fix isn't a different key --
     * it's making the message explain the substitution instead of silently
     * showing "van" as if the user had typed that.
     */
    public function testSynonymMultiExplainsWhenAnExistingLexiconEntryAlreadyRewritesTheSourceWord(): void
    {
        $repo = $this->createMock(AlignmentLibraryRepository::class);
        $repo->method('findPairGroup')->willReturn([
            'source_link_id' => null,
            'source_words'   => [['id' => 1, 'word_text' => 'der']],
            'target_words'   => [['id' => 2, 'word_text' => 'van'], ['id' => 3, 'word_text' => 'de']],
        ]);
        $repo->expects($this->once())
            ->method('addMultiSynonymBridgeEntry')
            ->with('van', ['van', 'de'], null) // 'der' -> 'van' via DEFAULT_LEXICON before this stage
            ->willReturn('added');

        $service = new AlignmentLibraryService($repo, new HistoricalAlignmentService());
        $result = $service->addToLibrary(1, 2, 'synonym');

        $this->assertSame('added', $result['status']);
        $this->assertStringContainsString('"van" → "van + de"', $result['message']);
        $this->assertStringContainsString('"der" wordt al via het lexicon naar "van" vertaald', $result['message']);
    }

    public function testSynonymMultiPassesAllTargetFormsInPositionOrder(): void
    {
        // 'doodsloegh' normalizes to 'doodsloeg' via the plain gh->g
        // char-rule (not the lexicon) -- addToLibrary() must store the
        // normalize()-output form, matching how bridgeMultiSynonyms()
        // reads this table against already-normalized verse tokens.
        $repo = $this->createMock(AlignmentLibraryRepository::class);
        $repo->method('findPairGroup')->willReturn([
            'source_link_id' => 7,
            'source_words'   => [['id' => 1, 'word_text' => 'doodsloegh']],
            'target_words'   => [['id' => 2, 'word_text' => 'sloeg'], ['id' => 3, 'word_text' => 'dood']],
        ]);
        $repo->expects($this->once())
            ->method('addMultiSynonymBridgeEntry')
            ->with('doodsloeg', ['sloeg', 'dood'], 7)
            ->willReturn('added');

        $service = new AlignmentLibraryService($repo, new HistoricalAlignmentService());
        $result = $service->addToLibrary(1, 2, 'synonym');

        $this->assertSame('added', $result['status']);
        $this->assertSame('multi', $result['type']);
    }

    public function testSynonymPhraseJoinsBothSidesInPositionOrder(): void
    {
        $repo = $this->createMock(AlignmentLibraryRepository::class);
        $repo->method('findPairGroup')->willReturn([
            'source_link_id' => null,
            'source_words'   => [['id' => 1, 'word_text' => 'de'], ['id' => 2, 'word_text' => 'beginne']],
            'target_words'   => [['id' => 3, 'word_text' => 'het'], ['id' => 4, 'word_text' => 'begin']],
        ]);
        $repo->expects($this->once())
            ->method('addPhraseBridgeEntry')
            ->with(['de', 'beginne'], ['het', 'begin'], null)
            ->willReturn('added');

        $service = new AlignmentLibraryService($repo, new HistoricalAlignmentService());
        $result = $service->addToLibrary(1, 3, 'synonym');

        $this->assertSame('added', $result['status']);
        $this->assertSame('phrase', $result['type']);
    }

    public function testUnknownKindIsAnError(): void
    {
        $repo = $this->createMock(AlignmentLibraryRepository::class);
        $repo->expects($this->never())->method('findPairGroup');

        $service = new AlignmentLibraryService($repo, new HistoricalAlignmentService());
        $result = $service->addToLibrary(1, 2, 'bogus');

        $this->assertSame('error', $result['status']);
    }

    public function testUnresolvableGroupIsAnError(): void
    {
        $repo = $this->createMock(AlignmentLibraryRepository::class);
        $repo->method('findPairGroup')->willReturn(null);

        $service = new AlignmentLibraryService($repo, new HistoricalAlignmentService());
        $result = $service->addToLibrary(1, 2, 'lexicon');

        $this->assertSame('error', $result['status']);
    }
}
