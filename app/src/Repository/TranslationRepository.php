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
    private const DISPLAY_ORDER = ['SV1657' => 1, 'SV' => 2, 'SVGBS' => 3, 'HSV' => 4];

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

    /**
     * The translation code whose word_links (Hebrew/Greek) are authoritative
     * and propagate to the rest of its family via inter_translation_links
     * (currently 'SV'/Jongbloed). Not mapped on the Translation entity
     * itself (source_lang_authority is a DBAL-only concern, see
     * LinkingRepository), so this reads it via the raw connection.
     */
    public function findSourceLangAuthorityCode(): ?string
    {
        $code = $this->getEntityManager()->getConnection()->fetchOne(
            'SELECT code FROM translations WHERE source_lang_authority = TRUE LIMIT 1'
        );

        return $code !== false ? (string) $code : null;
    }
}
