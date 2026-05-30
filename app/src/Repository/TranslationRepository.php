<?php
namespace App\Repository;

use App\Entity\Translation;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

class TranslationRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $r)
    {
        parent::__construct($r, Translation::class);
    }

    public function findByCode(string $code): ?Translation
    {
        return $this->findOneBy(['code' => $code]);
    }

    /** @return Translation[] */
    public function findAllOrderedById(): array
    {
        return $this->findBy([], ['id' => 'ASC']);
    }
}
