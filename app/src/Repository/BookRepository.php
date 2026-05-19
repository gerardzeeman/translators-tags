<?php

namespace App\Repository;

use App\Entity\Book;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

class BookRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, Book::class);
    }

    /** @return Book[] */
    public function findAllOldTestament(): array
    {
        return $this->createQueryBuilder('b')
            ->where('b.testament = :t')
            ->setParameter('t', 'OT')
            ->orderBy('b.id', 'ASC')
            ->getQuery()
            ->getResult();
    }

    /** @return Book[] */
    public function findAllNewTestament(): array
    {
        return $this->createQueryBuilder('b')
            ->where('b.testament = :t')
            ->setParameter('t', 'NT')
            ->orderBy('b.id', 'ASC')
            ->getQuery()
            ->getResult();
    }

    public function findByUsfmCode(string $code): ?Book
    {
        return $this->findOneBy(['usfmCode' => strtoupper($code)]);
    }
}
