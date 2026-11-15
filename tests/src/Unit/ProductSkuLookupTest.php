<?php

declare(strict_types=1);

namespace Drupal\Tests\commerce_unleashed\Unit;

use Drupal\commerce_unleashed\UnleashedManager;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Group;

/**
 * Tests reading one product by SKU.
 *
 * It must arrive as complete as a product from a list sync, and only the SKU
 * asked for: the productCode filter matches on the start of the code.
 */
#[CoversClass(UnleashedManager::class)]
#[Group('commerce_unleashed')]
class ProductSkuLookupTest extends ManagerTestBase {

  /**
   * The read carries the list sync's base query and a page size.
   */
  public function testReadUsesTheBaseQuery(): void {
    $manager = $this->manager(['products.include_attributes' => TRUE]);
    $this->client->expects($this->once())->method('getProducts')
      ->with($this->callback(static fn (string $query): bool => str_contains($query, 'includeAttributes=true')
        && str_contains($query, 'productCode=ABC1')
        && str_contains($query, 'pageSize=')), 1)
      ->willReturn(self::page([['ProductCode' => 'ABC1']]));

    $this->assertCount(1, $manager->getProductsBySku('ABC1'));
  }

  /**
   * Longer codes sharing the prefix are left out, across pages.
   */
  public function testOnlyTheExactSkuIsReturned(): void {
    $manager = $this->manager();
    $this->client->method('getProducts')
      ->willReturnCallback(static fn (string $query, ?int $page): array => match ($page) {
        1 => self::page([['ProductCode' => 'ABC10'], ['ProductCode' => 'ABC100']], 2, 1),
        2 => self::page([['ProductCode' => 'abc1', 'Guid' => 'match']], 2, 2),
        default => self::fail('Only two pages exist.'),
      });

    $products = $manager->getProductsBySku('ABC1');
    $this->assertCount(1, $products);
    $this->assertSame('match', $products[0]['Guid']);
  }

  /**
   * A failed read is logged and syncs nothing.
   */
  public function testFailedReadIsLogged(): void {
    $manager = $this->manager();
    $this->client->method('getProducts')->willReturn(['error' => 'Forbidden']);
    $this->logger->expects($this->once())->method('error');

    $this->assertSame([], $manager->getProductsBySku('ABC1'));
  }

}
