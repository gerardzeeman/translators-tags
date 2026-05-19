<?php

namespace App\Entity;

use App\Repository\TranslationRepository;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity(repositoryClass: TranslationRepository::class)]
#[ORM\Table(name: 'translations')]
class Translation
{
    #[ORM\Id]
    #[ORM\Column(type: 'smallint')]
    private int $id;

    #[ORM\Column(type: 'string', length: 20, unique: true)]
    private string $code;

    #[ORM\Column(type: 'text')]
    private string $name;

    #[ORM\Column(type: 'string', length: 3)]
    private string $language;

    #[ORM\Column(type: 'string', length: 3)]
    private string $direction;

    public function getId(): int { return $this->id; }
    public function getCode(): string { return $this->code; }
    public function getName(): string { return $this->name; }
    public function getLanguage(): string { return $this->language; }
    public function getDirection(): string { return $this->direction; }
}
