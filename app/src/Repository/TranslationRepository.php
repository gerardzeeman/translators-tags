<?php
namespace App\Repository;

use App\Entity\Translation;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

class TranslationRepository extends ServiceEntityRepository
{
    /**
     * Display order for translation switchers/columns/dropdowns app-wide:
     * SV Jongbloed, SV(GBS), then HSV. Translations not listed here (future
     * additions) sort after these, in id order.
     */
    private const DISPLAY_ORDER = ['SV' => 1, 'SVGBS' => 2, 'HSV' => 3];

    public function __construct(ManagerRegistry $r)
    {
        parent::__construct($r, Translation::class);
    }

    public function findByCode(string $code): ?Translation
    {
        return $this->findOneBy(['code' => $code]);
    }

    /** @return Translation[] */
    public function findAllForDisplay(): array
    {
        $translations = $this->findBy([], ['id' => 'ASC']);
        usort($translations, fn(Translation $a, Translation $b) =>
            (self::DISPLAY_ORDER[$a->getCode()] ?? 99) <=> (self::DISPLAY_ORDER[$b->getCode()] ?? 99)
        );
        return $translations;
    }
}
