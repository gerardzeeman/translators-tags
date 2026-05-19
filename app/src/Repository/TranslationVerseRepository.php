<?php
namespace App\Repository;
use App\Entity\TranslationVerse;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;
class TranslationVerseRepository extends ServiceEntityRepository {
    public function __construct(ManagerRegistry $r) { parent::__construct($r, TranslationVerse::class); }
}
