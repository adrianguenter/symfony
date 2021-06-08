<?php

/*
 * This file is part of the Symfony package.
 *
 * (c) Fabien Potencier <fabien@symfony.com>
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Symfony\Component\Security\Http\Tests\EventListener;

use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\PasswordHasher\Hasher\PasswordHasherFactoryInterface;
use Symfony\Component\PasswordHasher\PasswordHasherInterface;
use Symfony\Component\Security\Core\Authentication\Token\TokenInterface;
use Symfony\Component\Security\Core\User\InMemoryUser;
use Symfony\Component\Security\Core\User\PasswordAuthenticatedUserInterface;
use Symfony\Component\Security\Core\User\PasswordUpgraderInterface;
use Symfony\Component\Security\Core\User\UserInterface;
use Symfony\Component\Security\Core\User\UserProviderInterface;
use Symfony\Component\Security\Http\Authenticator\AuthenticatorInterface;
use Symfony\Component\Security\Http\Authenticator\Passport\Badge\PasswordUpgradeBadge;
use Symfony\Component\Security\Http\Authenticator\Passport\Badge\UserBadge;
use Symfony\Component\Security\Http\Authenticator\Passport\PassportInterface;
use Symfony\Component\Security\Http\Authenticator\Passport\SelfValidatingPassport;
use Symfony\Component\Security\Http\Authenticator\Passport\UserPassportInterface;
use Symfony\Component\Security\Http\Event\LoginSuccessEvent;
use Symfony\Component\Security\Http\EventListener\PasswordMigratingListener;

class PasswordMigratingListenerTest extends TestCase
{
    private $hasherFactory;
    private $listener;
    private $user;

    protected function setUp(): void
    {
        $this->user = $this->createMock(TestPasswordAuthenticatedUser::class);
        $this->user->expects($this->any())->method('getPassword')->willReturn('old-hash');
        $encoder = $this->createMock(PasswordHasherInterface::class);
        $encoder->expects($this->any())->method('needsRehash')->willReturn(true);
        $encoder->expects($this->any())->method('hash')->with('pa$$word', null)->willReturn('new-hash');
        $this->hasherFactory = $this->createMock(PasswordHasherFactoryInterface::class);
        $this->hasherFactory->expects($this->any())->method('getPasswordHasher')->with($this->user)->willReturn($encoder);
        $this->listener = new PasswordMigratingListener($this->hasherFactory);
    }

    /**
     * @dataProvider provideUnsupportedEvents
     */
    public function testUnsupportedEvents($event)
    {
        $this->hasherFactory->expects($this->never())->method('getPasswordHasher');

        $this->listener->onLoginSuccess($event);
    }

    public function provideUnsupportedEvents()
    {
        // no password upgrade badge
        yield [$this->createEvent(new SelfValidatingPassport(new UserBadge('test', function () { return $this->createMock(UserInterface::class); })))];

        // blank password
        yield [$this->createEvent(new SelfValidatingPassport(new UserBadge('test', function () { return $this->createMock(TestPasswordAuthenticatedUser::class); }), [new PasswordUpgradeBadge('', $this->createPasswordUpgrader())]))];

        // no user
        yield [$this->createEvent($this->createMock(PassportInterface::class))];
    }

    public function testUnsupportedPassport()
    {
        // A custom Passport, without an UserBadge
        $passport = $this->createMock(UserPassportInterface::class);
        $passport->method('getUser')->willReturn($this->user);
        $passport->method('hasBadge')->withConsecutive([PasswordUpgradeBadge::class], [UserBadge::class])->willReturnOnConsecutiveCalls(true, false);
        $passport->expects($this->once())->method('getBadge')->with(PasswordUpgradeBadge::class)->willReturn(new PasswordUpgradeBadge('pa$$word'));
        // We should never "getBadge" for "UserBadge::class"

        $event = $this->createEvent($passport);

        $this->listener->onLoginSuccess($event);
    }

    public function testUpgradeWithUpgrader()
    {
        $passwordUpgrader = $this->createPasswordUpgrader();
        $passwordUpgrader->expects($this->once())
            ->method('upgradePassword')
            ->with($this->user, 'new-hash')
        ;

        $event = $this->createEvent(new SelfValidatingPassport(new UserBadge('test', function () { return $this->user; }), [new PasswordUpgradeBadge('pa$$word', $passwordUpgrader)]));
        $this->listener->onLoginSuccess($event);
    }

    public function testUpgradeWithoutUpgrader()
    {
        $userLoader = $this->getMockForAbstractClass(TestMigratingUserProvider::class);
        $userLoader->expects($this->any())->method('loadUserByIdentifier')->willReturn($this->user);

        $userLoader->expects($this->once())
            ->method('upgradePassword')
            ->with($this->user, 'new-hash')
        ;

        $event = $this->createEvent(new SelfValidatingPassport(new UserBadge('test', [$userLoader, 'loadUserByIdentifier']), [new PasswordUpgradeBadge('pa$$word')]));
        $this->listener->onLoginSuccess($event);
    }

    public function testUserWithoutPassword()
    {
        $this->user = new InMemoryUser('test', null);

        $this->hasherFactory->expects($this->never())->method('getPasswordHasher');

        $event = $this->createEvent(new SelfValidatingPassport(new UserBadge('test', function () { return $this->user; }), [new PasswordUpgradeBadge('pa$$word')]));
        $this->listener->onLoginSuccess($event);
    }

    private function createPasswordUpgrader()
    {
        return $this->getMockForAbstractClass(TestMigratingUserProvider::class);
    }

    private function createEvent(PassportInterface $passport)
    {
        return new LoginSuccessEvent($this->createMock(AuthenticatorInterface::class), $passport, $this->createMock(TokenInterface::class), new Request(), null, 'main');
    }
}

abstract class TestMigratingUserProvider implements UserProviderInterface, PasswordUpgraderInterface
{
    abstract public function upgradePassword(PasswordAuthenticatedUserInterface $user, string $newHashedPassword): void;
    abstract public function loadUserByIdentifier(string $identifier): UserInterface;
}

abstract class TestPasswordAuthenticatedUser implements UserInterface, PasswordAuthenticatedUserInterface
{
    abstract public function getPassword(): ?string;
}