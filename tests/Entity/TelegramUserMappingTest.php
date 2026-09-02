<?php

namespace App\Tests\Entity;

use App\Entity\TelegramUser;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

/**
 * The Doctrine mapping of TelegramUser, checked the only way that catches what broke it.
 *
 * On 02.09.2026 a new property was inserted between the #[ORM\ManyToOne] attributes and
 * the $account property they described. PHP attributes bind to whatever declaration
 * follows them, so the association silently moved onto the new field and $account became
 * unmapped — every DQL query joining b.account then threw "has no association named
 * account", which is a 500 on /admin/users and nowhere else. The syntax was valid, the
 * container linted, and no unit test touches a query builder.
 */
class TelegramUserMappingTest extends KernelTestCase
{
    public function testTheAssociationsAndColumnsAreWhereTheyAreExpected(): void
    {
        self::bootKernel();

        /** @var EntityManagerInterface $em */
        $em = self::getContainer()->get(EntityManagerInterface::class);
        $meta = $em->getClassMetadata(TelegramUser::class);

        $this->assertTrue(
            $meta->hasAssociation('account'),
            'TelegramUser::$account lost its ManyToOne — check nothing was inserted between the attribute and the property',
        );

        $this->assertSame('account_id', $meta->getAssociationMapping('account')['joinColumns'][0]['name']);

        foreach (['role', 'phone_number', 'telegram_id', 'additional_phones'] as $field) {
            $this->assertTrue($meta->hasField($field), sprintf('TelegramUser::$%s is not mapped', $field));
        }

        $this->assertFalse(
            $meta->hasAssociation('role'),
            'role must be a plain column, not the association that belongs to $account',
        );
    }
}
