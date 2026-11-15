<?php

declare(strict_types=1);

namespace Drupal\Tests\commerce_unleashed\Unit;

use Drupal\commerce_unleashed\UnleashedManager;
use Drupal\Core\Database\Connection;
use Drupal\Core\Database\Query\Merge;
use Drupal\Core\Database\Query\Update;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Group;

/**
 * Tests the stock read limited to chosen warehouses.
 */
#[CoversClass(UnleashedManager::class)]
#[Group('commerce_unleashed')]
class WarehouseStockTest extends ManagerTestBase {

  /**
   * Rows written, keyed by product code.
   *
   * @var array<string, array<string, mixed>>
   */
  private array $written = [];

  /**
   * How many times products missing from the read were zeroed.
   */
  private int $zeroed = 0;

  /**
   * A connection recording what a stock sync writes.
   */
  private function connection(): Connection {
    $connection = $this->createMock(Connection::class);

    $connection->method('merge')->willReturnCallback(function (): Merge {
      $merge = $this->createMock(Merge::class);
      $key = NULL;
      $merge->method('key')->willReturnCallback(function (string $field, string $value) use ($merge, &$key): Merge {
        $key = $value;
        return $merge;
      });
      $merge->method('fields')->willReturnCallback(function (array $fields) use ($merge, &$key): Merge {
        $this->written[$key] = $fields;
        return $merge;
      });
      return $merge;
    });

    $connection->method('update')->willReturnCallback(function (): Update {
      $update = $this->createMock(Update::class);
      $update->method('fields')->willReturnSelf();
      $update->method('condition')->willReturnSelf();
      $update->method('execute')->willReturnCallback(function (): int {
        $this->zeroed++;
        return 0;
      });
      return $update;
    });

    return $connection;
  }

  /**
   * Each warehouse is read on its own and its quantities added up.
   */
  public function testQuantitiesAreSummedAcrossChosenWarehouses(): void {
    $manager = $this->manager(['stock.warehouses' => ['WH', ' RMW ', '']], $this->connection());
    $queries = [];
    $this->client->method('getStockOnHand')
      ->willReturnCallback(function (string $query, ?int $page) use (&$queries): array {
        $queries[] = $query;
        return str_contains($query, 'warehouseCode=WH&')
          ? self::page([['ProductCode' => 'A', 'ProductGuid' => 'g-a', 'WarehouseCode' => 'WH', 'AvailableQty' => 2, 'AllocatedQty' => 1, 'QtyOnHand' => 3]])
          : self::page([
            ['ProductCode' => 'A', 'ProductGuid' => 'g-a', 'WarehouseCode' => 'RMW', 'AvailableQty' => 1.5, 'AllocatedQty' => 0, 'QtyOnHand' => 1.5],
            // Never counted, even if the API returns it.
            ['ProductCode' => 'A', 'ProductGuid' => 'g-a', 'WarehouseCode' => 'PIT', 'AvailableQty' => 50, 'AllocatedQty' => 0, 'QtyOnHand' => 50],
          ]);
      });

    $manager->syncStockOnHand();

    $this->assertCount(2, $queries);
    $this->assertSame('3.5', $this->written['A']['AvailableQty']);
    $this->assertSame('1', $this->written['A']['AllocatedQty']);
    $this->assertSame('4.5', $this->written['A']['QtyOnHand']);
    $this->assertSame('g-a', $this->written['A']['ProductGuid']);
    $this->assertSame(1, $this->zeroed, 'Products none of the warehouses holds are zeroed.');
  }

  /**
   * One failed warehouse means nothing is written at all.
   */
  public function testFailedWarehouseWritesNothing(): void {
    $manager = $this->manager(['stock.warehouses' => ['WH', 'RMW']], $this->connection());
    $this->client->method('getStockOnHand')
      ->willReturnCallback(static fn (string $query): array => str_contains($query, 'warehouseCode=WH&')
        ? self::page([['ProductCode' => 'A', 'AvailableQty' => 2]])
        : ['error' => 'Timeout']);
    $this->logger->expects($this->once())->method('error');

    $manager->syncStockOnHand();

    $this->assertSame([], $this->written);
    $this->assertSame(0, $this->zeroed);
  }

  /**
   * An empty read is taken for a wrong warehouse code, not an empty store.
   */
  public function testEmptyReadWritesNothing(): void {
    $manager = $this->manager(['stock.warehouses' => ['TYPO']], $this->connection());
    $this->client->method('getStockOnHand')->willReturn(self::page([]));
    $this->logger->expects($this->once())->method('warning');

    $manager->syncStockOnHand();

    $this->assertSame([], $this->written);
    $this->assertSame(0, $this->zeroed);
  }

  /**
   * Without chosen warehouses, the all-warehouse read is unchanged.
   */
  public function testNoWarehousesReadsTheUnleashedTotal(): void {
    $manager = $this->manager([], $this->connection());
    $this->client->expects($this->once())->method('getStockOnHand')
      ->with($this->logicalNot($this->stringContains('warehouseCode')), 1)
      ->willReturn(self::page([]));

    $manager->syncStockOnHand();

    $this->assertSame(0, $this->zeroed);
  }

}
