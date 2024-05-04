<?php

namespace App\Repository;

use App\Entity\TelegramUser;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;
use Symfony\Component\HttpKernel\Exception\BadRequestHttpException;

/**
 * @extends ServiceEntityRepository<TelegramUser>
 *
 * @method TelegramUser|null find($id, $lockMode = null, $lockVersion = null)
 * @method TelegramUser|null findOneBy(array $criteria, array $orderBy = null)
 * @method TelegramUser[]    findAll()
 * @method TelegramUser[]    findBy(array $criteria, array $orderBy = null, $limit = null, $offset = null)
 */
class TelegramUserRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, TelegramUser::class);
    }

    public function getByTelegramId(string $telegramId): ?TelegramUser
    {
        return $this->createQueryBuilder('tu')
            ->where('tu.telegram_id = :telegram_id')
            ->setParameter('telegram_id', $telegramId)
            ->getQuery()->getOneOrNullResult();
    }

    public function save(TelegramUser $telegramUser)
    {
        $this->getEntityManager()->persist($telegramUser);
        $this->getEntityManager()->flush();
    }
}
