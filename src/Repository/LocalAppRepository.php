<?php

namespace App\Repository;

use App\Entity\LocalApp;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<LocalApp>
 */
class LocalAppRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, LocalApp::class);
    }

    /**
     * Paramètres de mise à jour de cette installation (créés avec les valeurs par défaut au premier accès).
     */
    public function getSettings(): LocalApp
    {
        $settings = $this->findOneBy([], ['id' => 'ASC']);
        if (null === $settings) {
            $settings = new LocalApp();
            $this->getEntityManager()->persist($settings);
            $this->getEntityManager()->flush();
        }

        return $settings;
    }
}
