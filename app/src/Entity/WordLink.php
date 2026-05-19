<?php

namespace App\Entity;

use App\Repository\WordLinkRepository;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity(repositoryClass: WordLinkRepository::class)]
#[ORM\Table(name: 'word_links')]
class WordLink
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\Column(type: 'string', length: 2)]
    private string $sourceLanguage;

    #[ORM\ManyToOne(targetEntity: HebrewWord::class, inversedBy: 'wordLinks')]
    #[ORM\JoinColumn(name: 'hebrew_word_id', referencedColumnName: 'id', nullable: true, onDelete: 'CASCADE')]
    private ?HebrewWord $hebrewWord = null;

    #[ORM\ManyToOne(targetEntity: GreekWord::class, inversedBy: 'wordLinks')]
    #[ORM\JoinColumn(name: 'greek_word_id', referencedColumnName: 'id', nullable: true, onDelete: 'CASCADE')]
    private ?GreekWord $greekWord = null;

    #[ORM\ManyToOne(targetEntity: TranslationWord::class, inversedBy: 'wordLinks')]
    #[ORM\JoinColumn(name: 'translation_word_id', referencedColumnName: 'id', nullable: false, onDelete: 'CASCADE')]
    private TranslationWord $translationWord;

    #[ORM\OneToMany(mappedBy: 'link', targetEntity: LinkConfidence::class, cascade: ['persist', 'remove'])]
    private Collection $confidences;

    public function __construct()
    {
        $this->confidences = new ArrayCollection();
    }

    public function getId(): ?int { return $this->id; }
    public function getSourceLanguage(): string { return $this->sourceLanguage; }
    public function getHebrewWord(): ?HebrewWord { return $this->hebrewWord; }
    public function getGreekWord(): ?GreekWord { return $this->greekWord; }
    public function getTranslationWord(): TranslationWord { return $this->translationWord; }
    public function getConfidences(): Collection { return $this->confidences; }

    public function setSourceLanguage(string $lang): self { $this->sourceLanguage = $lang; return $this; }
    public function setHebrewWord(?HebrewWord $word): self { $this->hebrewWord = $word; return $this; }
    public function setGreekWord(?GreekWord $word): self { $this->greekWord = $word; return $this; }
    public function setTranslationWord(TranslationWord $word): self { $this->translationWord = $word; return $this; }

    public function getSourceWord(): HebrewWord|GreekWord|null
    {
        return $this->hebrewWord ?? $this->greekWord;
    }

    public function getBestScore(): ?float
    {
        $best = null;
        foreach ($this->confidences as $conf) {
            $s = $conf->getScore();
            if ($best === null || $s > $best) {
                $best = $s;
            }
        }
        return $best;
    }

    public function isManual(): bool
    {
        foreach ($this->confidences as $conf) {
            if ($conf->getMethod() === 'manual') {
                return true;
            }
        }
        return false;
    }
}
