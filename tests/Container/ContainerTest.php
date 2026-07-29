<?php

declare(strict_types=1);

namespace App\Tests\Container;

use App\Container\Container;
use App\Container\Exception\ServiceNotFoundException;
use PHPUnit\Framework\TestCase;
use PHPUnit\Framework\Attributes\Test;

final class ContainerTest extends TestCase
{
    #[Test]
    public function testGetResolvesRegisteredService(): void
    {
        $container = new Container();
        $container->set('greeting', fn () => 'hello');

        self::assertSame('hello', $container->get('greeting'));
    }

    #[Test]

    public function testGetReturnsSameInstanceOnSecondCall(): void
    {
        $container = new Container();
        $container->set('object', fn () => new \stdClass());

        $first = $container->get('object');
        $second = $container->get('object');

        self::assertSame($first, $second);
    }

    #[Test]

    public function testFactoryReceivesContainerToChainDependencies(): void
    {
        $container = new Container();
        $container->set('a', fn () => 'valeur-a');
        $container->set('b', fn (Container $c) => $c->get('a') . '-b');

        self::assertSame('valeur-a-b', $container->get('b'));
    }

    #[Test]

    public function testGetThrowsWhenServiceIsUnknown(): void
    {
        $container = new Container();

        $this->expectException(ServiceNotFoundException::class);

        $container->get('inconnu');
    }
}
