<?php

namespace App\Entity;

use App\Repository\HebrewWordRepository;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity(repositoryClass: HebrewWordRepository::class)]
#[ORM\Table(name: 'hebrew_words')]
#[ORM\UniqueConstraint(name: 'hw_ref_unique', columns: ['book_id', 'chapter', 'verse', 'word_position'])]
class HebrewWord
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\ManyToOne(targetEntity: Book::class, inversedBy: 'hebrewWords')]
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
    private ?string $transliteration = null;

    #[ORM\Column(type: 'text', nullable: true)]
    private ?string $lemma = null;

    #[ORM\Column(type: 'string', length: 10, nullable: true)]
    private ?string $strongs = null;

    #[ORM\Column(type: 'text', nullable: true)]
    private ?string $morphCode = null;

    #[ORM\Column]
    private bool $isKetiv = false;

    #[ORM\Column]
    private bool $hasQere = false;

    #[ORM\OneToMany(mappedBy: 'hebrewWord', targetEntity: WordLink::class)]
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
    public function getTransliteration(): ?string { return $this->transliteration; }
    public function getLemma(): ?string { return $this->lemma; }
    public function getStrongs(): ?string { return $this->strongs; }
    public function getMorphCode(): ?string { return $this->morphCode; }
    public function isKetiv(): bool { return $this->isKetiv; }
    public function hasQere(): bool { return $this->hasQere; }
    public function getWordLinks(): Collection { return $this->wordLinks; }

    /** Returns the best confidence score across all links, or null if unlinked. */
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
