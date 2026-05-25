<?php

namespace App\Entity;

use App\Repository\LinkConfidenceRepository;
use DateTimeImmutable;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity(repositoryClass: LinkConfidenceRepository::class)]
#[ORM\Table(name: 'link_confidence')]
class LinkConfidence
{
    #[ORM\Id]
    #[ORM\ManyToOne(targetEntity: WordLink::class, inversedBy: 'confidences')]
    #[ORM\JoinColumn(name: 'link_id', referencedColumnName: 'id', nullable: false, onDelete: 'CASCADE')]
    private WordLink $link;

    #[ORM\Id]
    #[ORM\Column(type: 'string', length: 20)]
    private string $method;

    #[ORM\Column(type: 'decimal', precision: 4, scale: 3, nullable: true)]
    private ?float $score = null;

    #[ORM\Column(type: 'datetimetz_immutable')]
    private DateTimeImmutable $createdAt;

    #[ORM\Column(type: 'text', nullable: true)]
    private ?string $createdBy = null;

    #[ORM\Column(type: 'text', nullable: true)]
    private ?string $notes = null;

    public function __construct(WordLink $link, string $method)
    {
        $this->link      = $link;
        $this->method    = $method;
        $this->createdAt = new DateTimeImmutable();
    }

    public function getLink(): WordLink { return $this->link; }
    public function getMethod(): string { return $this->method; }
    public function getScore(): ?float { return $this->score !== null ? (float) $this->score : null; }
    public function getCreatedAt(): DateTimeImmutable { return $this->createdAt; }
    public function getCreatedBy(): ?string { return $this->createdBy; }
    public function getNotes(): ?string { return $this->notes; }

    public function setScore(?float $score): self { $this->score = $score; return $this; }
    public function setCreatedBy(?string $by): self { $this->createdBy = $by; return $this; }
    public function setNotes(?string $notes): self { $this->notes = $notes; return $this; }

    /** Human-readable method label for display. */
    public function getMethodLabel(): string
    {
        return match ($this->method) {
            'manual'      => 'Handmatig',
            'manual_hint' => 'Hint (handmatig)',
            'proper_noun' => 'Eigennaam',
            'positional'  => 'Positioneel',
            default       => ucfirst($this->method),
        };
    }
}
