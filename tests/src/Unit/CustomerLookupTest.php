<?php

declare(strict_types=1);

namespace Drupal\Tests\commerce_unleashed\Unit;

use Drupal\commerce_order\Entity\OrderInterface;
use Drupal\commerce_unleashed\UnleashedManager;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Group;
use Symfony\Component\EventDispatcher\EventDispatcherInterface;

/**
 * Tests finding the Unleashed customer an order belongs to.
 *
 * The customerCode filter does not match exactly, and a failed lookup must
 * never be read as "no such customer": that creates a duplicate.
 */
#[CoversClass(UnleashedManager::class)]
#[Group('commerce_unleashed')]
class CustomerLookupTest extends ManagerTestBase {

  /**
   * The exact code wins over a longer one the API listed first.
   */
  public function testExactCodeBeatsPartialMatch(): void {
    $manager = $this->manager();
    $this->client->method('getCustomers')->willReturn(self::page([
      ['CustomerCode' => 'jo@example.com.au', 'Guid' => 'wrong'],
      ['CustomerCode' => 'jo@example.com', 'Guid' => 'right'],
    ]));

    $this->assertSame('right', $manager->getCustomerByCode('jo@example.com')['Guid']);
  }

  /**
   * Only partial matches means there is no such customer.
   */
  public function testOnlyPartialMatchesIsNoCustomer(): void {
    $manager = $this->manager();
    $this->client->method('getCustomers')->willReturn(self::page([
      ['CustomerCode' => 'jo@example.com.au'],
    ]));

    $this->assertSame([], $manager->getCustomerByCode('jo@example.com'));
  }

  /**
   * A code differing only in case is the same customer to Unleashed.
   */
  public function testCaseOnlyDifferenceMatches(): void {
    $manager = $this->manager();
    $this->client->method('getCustomers')->willReturn(self::page([
      ['CustomerCode' => 'Jo@Example.com', 'Guid' => 'case'],
    ]));

    $this->assertSame('case', $manager->getCustomerByCode('jo@example.com')['Guid']);
  }

  /**
   * An exact match on a later page is found, and preferred to a case match.
   */
  public function testLaterPagesAreRead(): void {
    $manager = $this->manager();
    $this->client->expects($this->exactly(2))->method('getCustomers')
      ->willReturnCallback(static fn (string $query, ?int $page): array => match ($page) {
        1 => self::page([['CustomerCode' => 'JO@EXAMPLE.COM', 'Guid' => 'case']], 2, 1),
        2 => self::page([['CustomerCode' => 'jo@example.com', 'Guid' => 'exact']], 2, 2),
        default => self::fail('Only two pages exist.'),
      });

    $this->assertSame('exact', $manager->getCustomerByCode('jo@example.com')['Guid']);
  }

  /**
   * A failed lookup reports the error rather than an empty result.
   */
  public function testApiErrorIsReported(): void {
    $manager = $this->manager();
    $this->client->method('getCustomers')->willReturn(['error' => 'Forbidden']);

    $this->assertSame(['error' => 'Forbidden'], $manager->getCustomerByCode('jo@example.com'));
  }

  /**
   * A failed lookup never creates a customer.
   */
  public function testFailedLookupCreatesNoCustomer(): void {
    $dispatcher = $this->createMock(EventDispatcherInterface::class);
    $dispatcher->method('dispatch')->willReturnArgument(0);
    $manager = $this->manager([], NULL, $dispatcher);

    $order = $this->createMock(OrderInterface::class);
    $order->method('getEmail')->willReturn('jo@example.com');

    $this->client->method('getCustomers')->willReturn(['error' => 'Forbidden']);
    $this->client->expects($this->never())->method('createCustomer');

    $this->assertSame(['error' => 'Forbidden'], $manager->getCustomerFromOrder($order));
  }

}
