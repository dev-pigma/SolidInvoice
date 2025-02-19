<?php

declare(strict_types=1);

/*
 * This file is part of SolidInvoice project.
 *
 * (c) Pierre du Plessis <open-source@solidworx.co>
 *
 * This source file is subject to the MIT license that is bundled
 * with this source code in the file LICENSE.
 */

namespace SolidInvoice\UserBundle\Tests\Repository;

use DateTimeInterface;
use Doctrine\ORM\QueryBuilder;
use Faker\Generator;
use Liip\TestFixturesBundle\Services\DatabaseToolCollection;
use Liip\TestFixturesBundle\Services\DatabaseTools\AbstractDatabaseTool;
use SolidInvoice\CoreBundle\Test\Traits\FakerTestTrait;
use SolidInvoice\InstallBundle\Test\EnsureApplicationInstalled;
use SolidInvoice\UserBundle\DataFixtures\ORM\LoadData;
use SolidInvoice\UserBundle\Entity\User;
use SolidInvoice\UserBundle\Repository\UserRepository;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Security\Core\Exception\UnsupportedUserException;
use Symfony\Component\Security\Core\Exception\UserNotFoundException;
use Symfony\Component\Security\Core\User\UserInterface;

/**
 * @group functional
 */
class UserRepositoryTest extends KernelTestCase
{
    use EnsureApplicationInstalled;
    use FakerTestTrait;

    /**
     * @var Generator
     */
    private $faker;

    /**
     * @var UserRepository
     */
    private $repository;

    protected AbstractDatabaseTool $databaseTool;

    protected function setUp(): void
    {
        parent::setUp();

        $kernel = self::bootKernel();
        $this->repository = $kernel->getContainer()->get('doctrine')->getRepository(User::class);
        $this->faker = $this->getFaker();

        self::bootKernel();

        $this->databaseTool = static::getContainer()->get(DatabaseToolCollection::class)->get();
    }

    public function testSave(): void
    {
        $user = new User();
        $user->setUsername($this->faker->userName)
            ->setEmail($this->faker->email)
            ->setPassword($this->faker->password);

        $this->repository->save($user);

        self::assertNotNull($user->getId());
        self::assertCount(1, $this->repository->findAll());
    }

    public function testRefreshUser(): void
    {
        $executor = $this->databaseTool->loadFixtures([LoadData::class], true);
        $user = $executor->getReferenceRepository()->getReference('user2');
        $newUser = $this->repository->refreshUser($user);
        self::assertSame($user->getId(), $newUser->getId());
        self::assertSame($user->getUsername(), $newUser->getUsername());
        self::assertSame($user->getEmail(), $newUser->getEmail());
    }

    public function testRefreshUserWithInvalidUser(): void
    {
        $user = new class() implements UserInterface {
            public function getRoles(): array
            {
                return [];
            }

            public function getPassword(): ?string
            {
                return null;
            }

            public function getSalt(): ?string
            {
                return null;
            }

            public function getUsername(): string
            {
                return '';
            }

            public function eraseCredentials(): void
            {
            }
        };

        $this->expectException(UnsupportedUserException::class);
        $this->expectExceptionMessage(sprintf('Instances of "%s" are not supported.', get_class($user)));
        $this->repository->refreshUser($user);
    }

    public function testLoadUserByUsername(): void
    {
        $this->databaseTool->loadFixtures([LoadData::class], true);
        self::assertInstanceOf(User::class, $this->repository->loadUserByUsername('test2'));
        self::assertInstanceOf(User::class, $this->repository->loadUserByUsername('test2@test.com'));
    }

    public function testLoadUserByUsernameWithDisabledUser(): void
    {
        $this->databaseTool->loadFixtures([LoadData::class], true);
        $this->expectException(UserNotFoundException::class);
        $this->expectExceptionMessage('User "test1" does not exist.');
        $this->repository->loadUserByUsername('test1');
    }

    public function testLoadUserByUsernameWithInvalidUser(): void
    {
        $username = $this->faker->userName;
        $this->databaseTool->loadFixtures([LoadData::class], true);
        $this->expectException(UserNotFoundException::class);
        $this->expectExceptionMessage('User "' . $username . '" does not exist.');
        $this->repository->loadUserByUsername($username);
    }

    public function testGetUserCount(): void
    {
        self::assertSame(0, $this->repository->getUserCount());

        $this->databaseTool->loadFixtures([LoadData::class], true);

        self::assertSame(2, $this->repository->getUserCount());
    }

    public function testSupportsClass(): void
    {
        self::assertFalse($this->repository->supportsClass(self::class));
        self::assertTrue($this->repository->supportsClass(User::class));
    }

    public function testDeleteUsers(): void
    {
        $executor = $this->databaseTool->loadFixtures([LoadData::class], true);

        $userIds = [$executor->getReferenceRepository()->getReference('user1')->getId(), $executor->getReferenceRepository()->getReference('user2')->getId()];

        self::assertSame(2, $this->repository->deleteUsers($userIds));
        self::assertCount(0, $this->repository->findAll());
    }

    public function testClearUserConfirmationToken(): void
    {
        $executor = $this->databaseTool->loadFixtures([LoadData::class], true);
        /** @var User $user1 */
        $user1 = $executor->getReferenceRepository()->getReference('user1');

        self::assertNotNull($user1->getConfirmationToken());
        self::assertNotNull($user1->getPasswordRequestedAt());
        self::assertInstanceOf(DateTimeInterface::class, $user1->getPasswordRequestedAt());

        $this->repository->clearUserConfirmationToken($user1);

        self::assertNull($user1->getConfirmationToken());
        self::assertNull($user1->getPasswordRequestedAt());
    }

    public function testGetGridQuery(): void
    {
        $queryBuilder = $this->repository->getGridQuery();
        self::assertInstanceOf(QueryBuilder::class, $queryBuilder);
        $alias = $queryBuilder->getRootAliases()[0];
        $fields = implode(', ', ["{$alias}.id", "{$alias}.username", "{$alias}.email", "{$alias}.enabled", "{$alias}.created"]);
        self::assertCount(1, $queryBuilder->getDQLPart('select'));
        self::assertSame($fields, (string) $queryBuilder->getDQLPart('select')[0]);
    }
}
