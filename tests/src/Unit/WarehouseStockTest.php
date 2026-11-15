<?php

declare(strict_types=1);

namespace Drupal\Tests\commerce_unleashed\Unit;

use Drupal\commerce_unleashed\Stock\WarehouseStock;
use Drupal\Tests\UnitTestCase;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Group;

/**
 * Tests the warehouse rule that decides what is in stock.
 *
 * Getting this wrong is not visible in the data afterwards: the number simply
 * looks plausible and is wrong.
 *
 *  - Counting an excluded warehouse offers stock the store cannot sell.
 *  - Treating a SKU held only at an uncounted warehouse as "seen with zero"
 *    rather than "not seen" is the difference between correctly reporting no
 *    available stock and wrongly zeroing it on a delta run.
 *  - Summing QtyOnHand instead of AvailableQty promises allocated stock.
 */
#[CoversClass(WarehouseStock::class)]
#[Group('commerce_unleashed')]
final class WarehouseStockTest extends UnitTestCase {

  /**
   * The counted warehouses.
   */
  private const COUNTED = ['MAIN', 'CITY', 'NORTH'];

  /**
   * Builds one StockOnHand row.
   */
  private function row(string $sku, string $warehouse, float $available): array {
    return [
      'ProductCode' => $sku,
      'WarehouseCode' => $warehouse,
      'AvailableQty' => $available,
      // Deliberately different, so a test fails if the wrong field is summed.
      'QtyOnHand' => $available + 100,
    ];
  }

  /**
   * Stock from the counted warehouses is summed.
   */
  public function testCountedWarehousesAreSummed(): void {
    $stock = new WarehouseStock(self::COUNTED);
    $stock->addAll([
      $this->row('SKU1', 'MAIN', 5),
      $this->row('SKU1', 'CITY', 3),
      $this->row('SKU1', 'NORTH', 2),
    ]);

    $this->assertSame(['SKU1' => 10.0], $stock->quantities());
  }

  /**
   * An uncounted warehouse is not stock anybody can be sold.
   */
  public function testUncountedWarehouseIsIgnored(): void {
    $stock = new WarehouseStock(self::COUNTED);
    $stock->addAll([
      $this->row('SKU1', 'MAIN', 4),
      $this->row('SKU1', 'SHOP', 96),
    ]);

    $this->assertSame(['SKU1' => 4.0], $stock->quantities());
  }

  /**
   * A SKU held only at an uncounted warehouse is not seen at all.
   *
   * Not "seen with zero": on a delta pull, only SKUs that were seen are
   * written, and recording this one as zero would wrongly empty a bottle whose
   * countable stock simply did not move.
   */
  public function testSkuOnlyAtAnUncountedWarehouseIsNotSeen(): void {
    $stock = new WarehouseStock(self::COUNTED);
    $stock->addAll([$this->row('SKU1', 'SHOP', 12)]);

    $this->assertSame([], $stock->quantities());
    $this->assertFalse($stock->sawSku('SKU1'));
    $this->assertSame(0, $stock->count());
  }

  /**
   * A counted warehouse reporting zero IS seen.
   */
  public function testZeroFromCountedWarehouseIsSeen(): void {
    $stock = new WarehouseStock(self::COUNTED);
    $stock->addAll([$this->row('SKU1', 'MAIN', 0)]);

    $this->assertSame(['SKU1' => 0.0], $stock->quantities());
    $this->assertTrue($stock->sawSku('SKU1'));
  }

  /**
   * Available quantity is summed, not quantity on hand.
   */
  public function testAvailableQuantityIsWhatCounts(): void {
    $stock = new WarehouseStock(self::COUNTED);
    $stock->addAll([$this->row('SKU1', 'MAIN', 6)]);

    $this->assertSame(['SKU1' => 6.0], $stock->quantities(), 'QtyOnHand is 106 on this row.');
  }

  /**
   * A negative total is clamped to zero.
   *
   * Unleashed can report a negative on hand as a bookkeeping artefact; it is
   * not a quantity anybody can be sold, and a negative would read as in stock
   * to anything testing "not zero".
   */
  public function testNegativeTotalsAreClamped(): void {
    $stock = new WarehouseStock(self::COUNTED);
    $stock->addAll([
      $this->row('SKU1', 'MAIN', -5),
      $this->row('SKU1', 'CITY', 2),
    ]);

    $this->assertSame(['SKU1' => 0.0], $stock->quantities());
  }

  /**
   * The counted list is configuration, not a constant.
   */
  public function testTheCountedListIsConfigurable(): void {
    $stock = new WarehouseStock(['SHOP']);
    $stock->addAll([
      $this->row('SKU1', 'MAIN', 4),
      $this->row('SKU1', 'SHOP', 7),
    ]);

    $this->assertSame(['SKU1' => 7.0], $stock->quantities());
  }

  /**
   * Rows with no SKU, or that are not rows, are ignored.
   */
  public function testMalformedRowsAreIgnored(): void {
    $stock = new WarehouseStock(self::COUNTED);
    $stock->addAll([
      ['WarehouseCode' => 'MAIN', 'AvailableQty' => 5],
      ['ProductCode' => '  ', 'WarehouseCode' => 'MAIN', 'AvailableQty' => 5],
      'not a row',
      $this->row('SKU1', 'MAIN', 1),
    ]);

    $this->assertSame(['SKU1' => 1.0], $stock->quantities());
  }

  /**
   * Several SKUs across several warehouses total independently.
   */
  public function testSeveralSkusTotalIndependently(): void {
    $stock = new WarehouseStock(self::COUNTED);
    $stock->addAll([
      $this->row('SKU1', 'MAIN', 2),
      $this->row('SKU2', 'CITY', 9),
      $this->row('SKU1', 'NORTH', 3),
      $this->row('SKU2', 'SHOP', 100),
    ]);

    $this->assertSame(['SKU1' => 5.0, 'SKU2' => 9.0], $stock->quantities());
    $this->assertSame(2, $stock->count());
  }

  /**
   * The split by warehouse covers every counted warehouse, and only those.
   *
   * It is how staff see stock in Unleashed, so a stock report can show it;
   * an uncounted warehouse never appears.
   */
  public function testByWarehouseListsCountedWarehousesOnly(): void {
    $stock = new WarehouseStock(self::COUNTED);
    $stock->addAll([
      $this->row('SKU1', 'MAIN', 20),
      $this->row('SKU1', 'CITY', 4),
      $this->row('SKU1', 'SHOP', 7),
    ]);

    $this->assertSame(['MAIN' => 20.0, 'CITY' => 4.0, 'NORTH' => 0.0], $stock->byWarehouse('SKU1'));
    $this->assertSame(['SKU1' => 24.0], $stock->quantities(), 'The total is unchanged by the split.');
  }

  /**
   * A SKU never seen reads as none at every counted warehouse.
   */
  public function testByWarehouseForAnUnseenSku(): void {
    $stock = new WarehouseStock(self::COUNTED);
    $stock->addAll([$this->row('SKU1', 'SHOP', 12)]);

    $this->assertSame(['MAIN' => 0.0, 'CITY' => 0.0, 'NORTH' => 0.0], $stock->byWarehouse('SKU1'));
  }

  /**
   * Each warehouse's figure is as Unleashed reported it; only the total clamps.
   */
  public function testByWarehouseIsNotClamped(): void {
    $stock = new WarehouseStock(self::COUNTED);
    $stock->addAll([
      $this->row('SKU1', 'MAIN', -5),
      $this->row('SKU1', 'CITY', 2),
    ]);

    $this->assertSame(['MAIN' => -5.0, 'CITY' => 2.0, 'NORTH' => 0.0], $stock->byWarehouse('SKU1'));
    $this->assertSame(['SKU1' => 0.0], $stock->quantities());
  }

  /**
   * A stored per-warehouse row, as WarehouseStockLedger::load() returns it.
   */
  private function stored(float $available, float $allocated = 0, float $onHand = 0, string $guid = ''): array {
    return ['available' => $available, 'allocated' => $allocated, 'on_hand' => $onHand, 'guid' => $guid];
  }

  /**
   * A sale at one warehouse does not replace the others' stock.
   *
   * 53 at MAIN, 2 at CITY, and one sells at CITY. The delta returns only the
   * CITY row, now 1; the total is 54, not 1.
   */
  public function testDeltaFromOneShopAddsStoredWarehouses(): void {
    $stock = new WarehouseStock(self::COUNTED);
    $stock->addAll([$this->row('SKU9', 'CITY', 1)]);

    $this->assertSame(['SKU9' => 1.0], $stock->quantities(), 'Before the overlay, only the shop.');

    $stock->overlay([
      'SKU9' => [
        'MAIN' => $this->stored(53, 0, 53),
        'CITY' => $this->stored(2, 0, 2),
      ],
    ]);

    $this->assertSame(['SKU9' => 54.0], $stock->quantities());
    $this->assertSame(54.0, $stock->rows()['SKU9']['available']);
    $this->assertSame(154.0, $stock->rows()['SKU9']['on_hand'], 'CITY reported 101 on hand, MAIN stored 53.');
  }

  /**
   * What a warehouse reported this time beats what was stored for it.
   */
  public function testReportedWarehouseBeatsStoredRow(): void {
    $stock = new WarehouseStock(self::COUNTED);
    $stock->addAll([$this->row('SKU1', 'MAIN', 10)]);
    $stock->overlay([
      'SKU1' => [
        'MAIN' => $this->stored(40),
        'NORTH' => $this->stored(3),
      ],
    ]);

    $this->assertSame(['SKU1' => 13.0], $stock->quantities());
  }

  /**
   * A stored row from an uncounted warehouse is never added.
   */
  public function testStoredUncountedWarehouseIsIgnored(): void {
    $stock = new WarehouseStock(self::COUNTED);
    $stock->addAll([$this->row('SKU1', 'MAIN', 2)]);
    $stock->overlay([
      'SKU1' => [
        'SHOP' => $this->stored(96),
      ],
    ]);

    $this->assertSame(['SKU1' => 2.0], $stock->quantities());
  }

  /**
   * A SKU the delta did not see is not brought in by its stored rows.
   *
   * On a delta, absence still means "unchanged", and the SKU is not rewritten.
   */
  public function testStoredRowForUnseenSkuAddsNothing(): void {
    $stock = new WarehouseStock(self::COUNTED);
    $stock->addAll([$this->row('SKU1', 'MAIN', 2)]);
    $stock->overlay([
      'SKU2' => [
        'MAIN' => $this->stored(7),
      ],
    ]);

    $this->assertSame(['SKU1' => 2.0], $stock->quantities());
    $this->assertFalse($stock->sawSku('SKU2'));
    $this->assertSame(1, $stock->count());
  }

  /**
   * Clamping applies to the total, not to each warehouse.
   */
  public function testNegativeStoredFigureIsClampedOnTheTotal(): void {
    $stock = new WarehouseStock(self::COUNTED);
    $stock->addAll([$this->row('SKU1', 'CITY', 3)]);
    $stock->overlay(['SKU1' => ['MAIN' => $this->stored(-1)]]);
    $this->assertSame(['SKU1' => 2.0], $stock->quantities());

    $stock = new WarehouseStock(self::COUNTED);
    $stock->addAll([$this->row('SKU1', 'CITY', 3)]);
    $stock->overlay(['SKU1' => ['MAIN' => $this->stored(-5)]]);
    $this->assertSame(['SKU1' => 0.0], $stock->quantities());
  }

  /**
   * Only what Unleashed reported is a row to store, unclamped.
   */
  public function testWarehouseRowsAreOnlyWhatWasReported(): void {
    $stock = new WarehouseStock(self::COUNTED);
    $stock->addAll([
      $this->row('SKU1', 'CITY', -2) + ['AllocatedQty' => 1, 'ProductGuid' => 'g-1'],
      $this->row('SKU1', 'SHOP', 9),
    ]);
    $stock->overlay(['SKU1' => ['MAIN' => $this->stored(5)]]);

    $this->assertSame([
      'SKU1' => [
        'CITY' => ['available' => -2.0, 'allocated' => 1.0, 'on_hand' => 98.0, 'guid' => 'g-1'],
      ],
    ], $stock->warehouseRows());
  }

}
