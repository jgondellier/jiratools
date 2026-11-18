<?php

namespace App\Repository;

use App\Entity\LocalApp;
use App\Entity\LocalUpdateRun;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<LocalUpdateRun>
 */
class LocalUpdateRunRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, LocalUpdateRun::class);
    }

    /** @return LocalUpdateRun[] */
    public function findLatest(?LocalApp $app = null, int $limit = 20): array
    {
        $criteria = $app ? ['app' => $app] : [];

        return $this->findBy($criteria, ['startedAt' => 'DESC', 'id' => 'DESC'], $limit);
    }
}
