<?php
namespace App\Repository;

use App\Entity\WordLink;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

class WordLinkRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $r) { parent::__construct($r, WordLink::class); }

    /** Fetch all links for a Hebrew word with confidences eagerly loaded (avoids N+1). */
    public function findByHebrewWordWithConfidences(int $wordId): array
    {
        return $this->createQueryBuilder('wl')
            ->leftJoin('wl.confidences', 'lc')->addSelect('lc')
            ->leftJoin('wl.translationWord', 'tw')->addSelect('tw')
            ->where('wl.hebrewWord = :id')
            ->setParameter('id', $wordId)
            ->getQuery()
            ->getResult();
    }

    /** Fetch all links for a Greek word with confidences eagerly loaded (avoids N+1). */
    public function findByGreekWordWithConfidences(int $wordId): array
    {
        return $this->createQueryBuilder('wl')
            ->leftJoin('wl.confidences', 'lc')->addSelect('lc')
            ->leftJoin('wl.translationWord', 'tw')->addSelect('tw')
            ->where('wl.greekWord = :id')
            ->setParameter('id', $wordId)
            ->getQuery()
            ->getResult();
    }
}

