<?php

namespace Base\Newsletter\Repository;

use Base\Newsletter\Entity\Subscriber;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\ORM\QueryBuilder;
use Doctrine\Persistence\ManagerRegistry;

/** @extends ServiceEntityRepository<Subscriber> */
class SubscriberRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, Subscriber::class);
    }

    public function findOneByEmail(string $email): ?Subscriber
    {
        return $this->findOneBy(['email' => Subscriber::normalize($email)]);
    }

    public function findOneByToken(string $token): ?Subscriber
    {
        return $this->findOneBy(['token' => $token]);
    }

    /**
     * What the letters go to: confirmed and still there - of one language
     * when one is given.
     *
     * @return list<Subscriber>
     */
    public function findConfirmed(?string $locale = null): array
    {
        return $this->confirmed($locale)->orderBy('s.id', 'ASC')->getQuery()->getResult();
    }

    public function countConfirmed(?string $locale = null): int
    {
        return (int) $this->confirmed($locale)->select('COUNT(s.id)')->getQuery()->getSingleScalarResult();
    }

    /** Signed up, not confirmed yet, not gone. */
    public function countPending(): int
    {
        return (int) $this->createQueryBuilder('s')->select('COUNT(s.id)')
            ->andWhere('s.confirmedAt IS NULL')
            ->andWhere('s.unsubscribedAt IS NULL')
            ->getQuery()->getSingleScalarResult();
    }

    /** @return list<Subscriber> the last ones signed up, whatever became of them */
    public function findLatest(int $limit = 5): array
    {
        return $this->findBy([], ['createdAt' => 'DESC', 'id' => 'DESC'], $limit);
    }

    /** The last sign-up from this address, to hold the flood interval. */
    public function findLastFromIp(string $ip): ?Subscriber
    {
        return $this->findOneBy(['ip' => $ip], ['createdAt' => 'DESC']);
    }

    private function confirmed(?string $locale): QueryBuilder
    {
        $query = $this->createQueryBuilder('s')
            ->andWhere('s.confirmedAt IS NOT NULL')
            ->andWhere('s.unsubscribedAt IS NULL');
        if ($locale) {
            $query->andWhere('s.locale = :locale')->setParameter('locale', mb_strtolower($locale));
        }

        return $query;
    }
}
