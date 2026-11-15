<?php

declare(strict_types=1);

namespace Drupal\Tests\commerce_unleashed\Kernel;

use Drupal\KernelTests\KernelTestBase;
use Drupal\commerce_unleashed\Stock\WarehouseStockLedger;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Group;

/**
 * Tests the per-warehouse stock table against a real database.
 *
 * The unit tests pin the overlay arithmetic; this proves the figures it
 * overlays round-trip through the table, and that a full pull's prune only
 * takes the rows of the warehouse it is told about.
 *
 * The table is created from the module's own schema definition rather than by
 * enabling the module, whose services need Commerce; the ledger needs only the
 * database, state and the clock.
 */
#[CoversClass(WarehouseStockLedger::class)]
#[Group('commerce_unleashed')]
final class WarehouseStockLedgerTest extends KernelTestBase {

  /**
   * The ledger under test.
   */
  private WarehouseStockLedger $ledger;

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();

    require_once dirname(__DIR__, 3) . '/commerce_unleashed.install';
    $database = $this->container->get('database');
    $database->schema()->createTable(WarehouseStockLedger::TABLE, _commerce_unleashed_warehouse_stock_schema());

    $this->ledger = new WarehouseStockLedger(
      $database,
      $this->container->get('state'),
      $this->container->get('datetime.time'),
    );
  }

  /**
   * One stored row.
   */
  private static function row(float $available, float $allocated = 0, float $onHand = 0, string $guid = ''): array {
    return ['available' => $available, 'allocated' => $allocated, 'on_hand' => $onHand, 'guid' => $guid];
  }

  /**
   * Saved rows load back per SKU and warehouse, and a save replaces a row.
   */
  public function testSaveAndLoadRoundTrip(): void {
    $this->assertSame(2, $this->ledger->save([
      'SKU9' => [
        'MAIN' => self::row(53, 0, 53, 'guid-aw'),
        'CITY' => self::row(2, 1, 3, 'guid-aw'),
      ],
    ]));
    $this->ledger->save([
      'SKU9' => ['CITY' => self::row(-1, 0, 0, 'guid-aw')],
      'SB9261' => ['NORTH' => self::row(24)],
    ]);

    $this->assertSame([
      'SKU9' => [
        'CITY' => self::row(-1, 0, 0, 'guid-aw'),
        'MAIN' => self::row(53, 0, 53, 'guid-aw'),
      ],
    ], $this->sorted($this->ledger->load(['SKU9', 'NOPE'])));
    $this->assertSame(['SB9261' => ['NORTH' => self::row(24)]], $this->ledger->load(['SB9261']));
    $this->assertSame([], $this->ledger->load([]));
  }

  /**
   * More SKUs than one query reads still all load.
   */
  public function testLoadIsChunked(): void {
    $rows = [];
    for ($i = 0; $i < 1203; $i++) {
      $rows['SKU' . $i] = ['MAIN' => self::row($i)];
    }
    $this->ledger->save($rows);

    $loaded = $this->ledger->load(array_keys($rows));

    $this->assertCount(1203, $loaded);
    $this->assertSame(1202.0, $loaded['SKU1202']['MAIN']['available']);
  }

  /**
   * A full pull's prune drops only that warehouse's unreturned rows.
   */
  public function testPruneUnseenIsPerWarehouse(): void {
    $this->ledger->save([
      'SKU1' => ['MAIN' => self::row(5), 'NORTH' => self::row(1)],
      'SKU2' => ['MAIN' => self::row(7), 'NORTH' => self::row(2)],
    ]);

    $this->assertSame(1, $this->ledger->pruneUnseen('NORTH', ['SKU2']));

    $this->assertSame([
      'SKU1' => ['MAIN' => self::row(5)],
      'SKU2' => ['MAIN' => self::row(7), 'NORTH' => self::row(2)],
    ], $this->sorted($this->ledger->load(['SKU1', 'SKU2'])));
  }

  /**
   * The table counts as filled only once marked so.
   */
  public function testSeededFlag(): void {
    $this->assertFalse($this->ledger->isSeeded());
    $this->ledger->markSeeded();
    $this->assertTrue($this->ledger->isSeeded());
  }

  /**
   * Loaded rows with warehouses in a stable order, for comparison.
   */
  private function sorted(array $loaded): array {
    ksort($loaded);
    foreach ($loaded as &$warehouses) {
      ksort($warehouses);
    }

    return $loaded;
  }

}
