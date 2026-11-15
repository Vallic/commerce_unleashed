<?php

declare(strict_types=1);

namespace Drupal\commerce_unleashed\Stock;

use Drupal\Component\Datetime\TimeInterface;
use Drupal\Core\Database\Connection;
use Drupal\Core\State\StateInterface;

/**
 * The last stock figures Unleashed gave for each SKU at each warehouse.
 *
 * The stock-on-hand table holds one total per SKU: all a storefront needs,
 * and not enough for a delta pull to work out a new total. A delta returns a
 * SKU only from the warehouses where it moved; the figures for the others
 * have to come from somewhere, and this is where.
 *
 * Only the counted warehouses are kept, and the figures are Unleashed's own,
 * unclamped: WarehouseStock clamps the total.
 *
 * Filled means a full pull wrote it. Until one has, a missing row could be
 * "none held there" or "never recorded", and a delta overlaying an empty store
 * would write partial totals. StockSync runs a delta as a full pull until
 * isSeeded() is TRUE.
 */
class WarehouseStockLedger {

  /**
   * The table the per-warehouse figures live in.
   */
  public const string TABLE = 'commerce_unleashed_warehouse_stock';

  /**
   * State key set once a full pull has filled the table.
   */
  public const string SEEDED_STATE_KEY = 'commerce_unleashed.warehouse_stock_seeded';

  /**
   * How many SKUs are read or written in one query.
   */
  protected const CHUNK = 500;

  public function __construct(
    protected readonly Connection $database,
    protected readonly StateInterface $state,
    protected readonly TimeInterface $time,
  ) {}

  /**
   * Whether a full pull has filled the table.
   */
  public function isSeeded(): bool {
    return (bool) $this->state->get(self::SEEDED_STATE_KEY, FALSE);
  }

  /**
   * Records that a full pull has filled the table.
   */
  public function markSeeded(): void {
    $this->state->set(self::SEEDED_STATE_KEY, $this->time->getRequestTime());
  }

  /**
   * Forgets that the table was filled, so the next sync is a full pull.
   */
  public function resetSeeded(): void {
    $this->state->delete(self::SEEDED_STATE_KEY);
  }

  /**
   * The stored rows for some SKUs.
   *
   * @param array<int|string> $skus
   *   The SKUs a pull saw.
   *
   * @return array<string, array<string, array{available: float, allocated: float, on_hand: float, guid: string}>>
   *   Rows keyed by SKU, then warehouse code. A SKU with no rows is absent.
   */
  public function load(array $skus): array {
    $skus = array_values(array_unique(array_map('strval', $skus)));
    $out = [];

    foreach (array_chunk($skus, static::CHUNK) as $chunk) {
      $result = $this->database->select(self::TABLE, 'w')
        ->fields('w', ['ProductCode', 'WarehouseCode', 'AvailableQty', 'AllocatedQty', 'QtyOnHand', 'ProductGuid'])
        ->condition('ProductCode', $chunk, 'IN')
        ->execute();

      foreach ($result as $row) {
        $out[(string) $row->ProductCode][(string) $row->WarehouseCode] = [
          'available' => (float) $row->AvailableQty,
          'allocated' => (float) $row->AllocatedQty,
          'on_hand' => (float) $row->QtyOnHand,
          'guid' => (string) $row->ProductGuid,
        ];
      }
    }

    return $out;
  }

  /**
   * Stores the rows a pull returned, replacing what was there for them.
   *
   * In batches: per warehouse, CHUNK SKUs at a time, one DELETE of the rows
   * being replaced and one multi-row INSERT. A merge() per row is two
   * round-trips each, and a full pull returns tens of thousands of rows.
   *
   * Only the (SKU, warehouse) pairs given are replaced. A delta reports a SKU
   * from just the warehouses where it moved, and the stored figures for the
   * others are exactly what the next delta overlays, so they must survive.
   *
   * Not an upsert: Drupal's upsert() takes one key field, and SQLite and
   * PostgreSQL need the conflict target to match a unique index exactly, which
   * a composite (ProductCode, WarehouseCode) key does not.
   *
   * @param array<int|string, array<string, array{available: float, allocated: float, on_hand: float, guid: string}>> $warehouse_rows
   *   Rows keyed by SKU, then warehouse code, as
   *   WarehouseStock::warehouseRows() returns them.
   *
   * @return int
   *   How many rows were written.
   */
  public function save(array $warehouse_rows): int {
    $now = $this->time->getRequestTime();

    // Regrouped by warehouse, so each batch deletes by one warehouse code and
    // a list of SKUs: a plain indexed condition, not a list of pairs.
    $by_warehouse = [];
    foreach ($warehouse_rows as $sku => $warehouses) {
      foreach ($warehouses as $warehouse => $row) {
        $by_warehouse[(string) $warehouse][(string) $sku] = $row;
      }
    }

    $written = 0;
    foreach ($by_warehouse as $warehouse => $rows) {
      foreach (array_chunk($rows, static::CHUNK, TRUE) as $chunk) {
        $this->database->delete(self::TABLE)
          ->condition('WarehouseCode', $warehouse)
          ->condition('ProductCode', array_map('strval', array_keys($chunk)), 'IN')
          ->execute();

        $insert = $this->database->insert(self::TABLE)
          ->fields([
            'ProductCode',
            'WarehouseCode',
            'AvailableQty',
            'AllocatedQty',
            'QtyOnHand',
            'ProductGuid',
            'timestamp',
          ]);
        foreach ($chunk as $sku => $row) {
          $insert->values([
            (string) $sku,
            (string) $warehouse,
            (float) $row['available'],
            (float) $row['allocated'],
            (float) $row['on_hand'],
            (string) $row['guid'],
            $now,
          ]);
        }
        $insert->execute();
        $written += count($chunk);
      }
    }

    return $written;
  }

  /**
   * Deletes a warehouse's rows for SKUs a FULL pull did not return there.
   *
   * Only ever after a full pull: on a delta, absence means nothing moved. On
   * a full pull it means the warehouse holds none, and a stale row would be
   * overlaid into the next delta's total.
   *
   * @param string $warehouse
   *   The warehouse code.
   * @param array<int|string> $seen_skus
   *   Every SKU the full pull returned for that warehouse.
   *
   * @return int
   *   How many rows were deleted.
   */
  public function pruneUnseen(string $warehouse, array $seen_skus): int {
    $seen = array_fill_keys(array_map('strval', $seen_skus), TRUE);

    $stored = $this->database->select(self::TABLE, 'w')
      ->fields('w', ['ProductCode'])
      ->condition('WarehouseCode', $warehouse)
      ->execute()
      ->fetchCol();

    $gone = [];
    foreach ($stored as $sku) {
      if (!isset($seen[(string) $sku])) {
        $gone[] = (string) $sku;
      }
    }

    $deleted = 0;
    foreach (array_chunk($gone, static::CHUNK) as $chunk) {
      $deleted += (int) $this->database->delete(self::TABLE)
        ->condition('WarehouseCode', $warehouse)
        ->condition('ProductCode', $chunk, 'IN')
        ->execute();
    }

    return $deleted;
  }

  /**
   * Deletes the rows of warehouses that no longer count.
   *
   * @param string[] $warehouses
   *   The warehouses that count now.
   *
   * @return int
   *   How many rows were deleted.
   */
  public function pruneWarehouses(array $warehouses): int {
    $query = $this->database->delete(self::TABLE);
    if ($warehouses !== []) {
      $query->condition('WarehouseCode', $warehouses, 'NOT IN');
    }

    return (int) $query->execute();
  }

}
