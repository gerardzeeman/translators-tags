<?php

namespace App\Entity;

use App\Repository\GreekWordRepository;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity(repositoryClass: GreekWordRepository::class)]
#[ORM\Table(name: 'greek_words')]
#[ORM\UniqueConstraint(name: 'gw_ref_unique', columns: ['book_id', 'chapter', 'verse', 'word_position'])]
class GreekWord
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\ManyToOne(targetEntity: Book::class, inversedBy: 'greekWords')]
    #[ORM\JoinColumn(name: 'book_id', referencedColumnName: 'id', nullable: false)]
    private Book $book;

    #[ORM\Column(type: 'smallint')]
    private int $chapter;

    #[ORM\Column(type: 'smallint')]
    private int $verse;

    #[ORM\Column(type: 'smallint')]
    private int $wordPosition;

    #[ORM\Column(type: 'text')]
    private string $wordText;

    #[ORM\Column(type: 'text', nullable: true)]
    private ?string $lemma = null;

    #[ORM\Column(type: 'string', length: 8, nullable: true)]
    private ?string $strongs = null;

    #[ORM\Column(type: 'text', nullable: true)]
    private ?string $parseCode = null;

    #[ORM\Column(type: 'text')]
    private string $transliteration;

    #[ORM\OneToMany(mappedBy: 'greekWord', targetEntity: WordLink::class)]
    private Collection $wordLinks;

    public function __construct()
    {
        $this->wordLinks = new ArrayCollection();
    }

    public function getId(): ?int { return $this->id; }
    public function getBook(): Book { return $this->book; }
    public function getChapter(): int { return $this->chapter; }
    public function getVerse(): int { return $this->verse; }
    public function getWordPosition(): int { return $this->wordPosition; }
    public function getWordText(): string { return $this->wordText; }
    public function getLemma(): ?string { return $this->lemma; }
    public function getStrongs(): ?string { return $this->strongs; }
    public function getParseCode(): ?string { return $this->parseCode; }
    public function getTransliteration(): string { return $this->transliteration; }
    public function getWordLinks(): Collection { return $this->wordLinks; }

    /**
     * Parse Robinson morphology code into a human-readable description.
     * e.g. "V-PAI-3S" → "Verb, Present Active Indicative, 3rd Singular"
     */
    public function parseMorphology(): string
    {
        if (!$this->parseCode) {
            return '';
        }
        return MorphologyParser::describeGreek($this->parseCode);
    }

    public function getBestLinkScore(): ?float
    {
        $best = null;
        foreach ($this->wordLinks as $link) {
            foreach ($link->getConfidences() as $conf) {
                $score = $conf->getScore();
                if ($best === null || $score > $best) {
                    $best = $score;
                }
            }
        }
        return $best;
    }
}
