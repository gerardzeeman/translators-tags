<?php

namespace App\Entity;

use App\Repository\TranslationVerseRepository;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity(repositoryClass: TranslationVerseRepository::class)]
#[ORM\Table(name: 'translation_verses')]
#[ORM\UniqueConstraint(name: 'tv_ref_unique', columns: ['translation_id', 'book_id', 'chapter', 'verse'])]
class TranslationVerse
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\ManyToOne(targetEntity: Translation::class)]
    #[ORM\JoinColumn(name: 'translation_id', referencedColumnName: 'id', nullable: false)]
    private Translation $translation;

    #[ORM\ManyToOne(targetEntity: Book::class, inversedBy: 'translationVerses')]
    #[ORM\JoinColumn(name: 'book_id', referencedColumnName: 'id', nullable: false)]
    private Book $book;

    #[ORM\Column(type: 'smallint')]
    private int $chapter;

    #[ORM\Column(type: 'smallint')]
    private int $verse;

    #[ORM\Column(type: 'text')]
    private string $verseText;

    #[ORM\OneToMany(mappedBy: 'verse', targetEntity: TranslationWord::class, cascade: ['persist', 'remove'])]
    #[ORM\OrderBy(['wordPosition' => 'ASC'])]
    private Collection $words;

    public function __construct()
    {
        $this->words = new ArrayCollection();
    }

    public function getId(): ?int { return $this->id; }
    public function getTranslation(): Translation { return $this->translation; }
    public function getBook(): Book { return $this->book; }
    public function getChapter(): int { return $this->chapter; }
    public function getVerse(): int { return $this->verse; }
    public function getVerseText(): string { return $this->verseText; }
    public function getWords(): Collection { return $this->words; }

    public function getReference(): string
    {
        return sprintf('%s %d:%d', $this->book->getNameNl(), $this->chapter, $this->verse);
    }
}
