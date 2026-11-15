<?php

declare(strict_types=1);

namespace Drupal\commerce_unleashed\Stock;

/**
 * Totals Unleashed stock-on-hand rows into one quantity per SKU.
 *
 * Only the counted warehouses are added in. Which warehouses count is a
 * business decision, not an arithmetic one: a retail shop, a bonded store or
 * a returns bay can hold stock the store must not sell. A row from any other
 * warehouse is dropped, not recorded as zero, so a SKU held only there comes
 * out of this as "not seen" rather than as "seen, with none".
 *
 * A delta pull only sees the warehouses that moved. StockOnHand is asked one
 * warehouse at a time, so a delta returns a SKU only from the warehouses where
 * its stock changed. Summing just those rows gives a wrong total: one bottle
 * selling at a shop would replace a total of 54 with the shop's 1. So the rows
 * are kept per warehouse as well. warehouseRows() is what the sync stores, and
 * overlay() fills in, from that store, every counted warehouse a SKU did not
 * come back from this time.
 *
 * No services and no entities: the arithmetic that decides whether something
 * is sellable is worth pinning on its own.
 */
final class WarehouseStock {

  /**
   * Available quantity per SKU, in the order rows were added.
   *
   * @var array<string, float>
   */
  private array $quantities = [];

  /**
   * Allocated quantity per SKU, summed over the counted warehouses.
   *
   * @var array<string, float>
   */
  private array $allocated = [];

  /**
   * On-hand quantity per SKU, summed over the counted warehouses.
   *
   * @var array<string, float>
   */
  private array $onHand = [];

  /**
   * Unleashed product guid per SKU.
   *
   * @var array<string, string>
   */
  private array $guids = [];

  /**
   * The rows this pull returned, per SKU and counted warehouse, unclamped.
   *
   * Only what Unleashed reported: figures overlay() brings in from the store
   * are added to the totals but never recorded here, so they are not saved
   * back as if they were fresh.
   *
   * @var array<string, array<string, array{available: float, allocated: float, on_hand: float, guid: string}>>
   */
  private array $reported = [];

  /**
   * Constructs the totaliser.
   *
   * @param string[] $warehouses
   *   Warehouse codes whose stock counts.
   */
  public function __construct(private readonly array $warehouses) {}

  /**
   * The counted warehouses, in configured order.
   *
   * @return string[]
   *   Warehouse codes.
   */
  public function warehouses(): array {
    return $this->warehouses;
  }

  /**
   * Adds one Unleashed StockOnHand row.
   *
   * @param array<string, mixed> $row
   *   The row as Unleashed returns it.
   */
  public function add(array $row): void {
    $sku = trim((string) ($row['ProductCode'] ?? ''));
    $warehouse = trim((string) ($row['WarehouseCode'] ?? ''));

    if ($sku === '' || !in_array($warehouse, $this->warehouses, TRUE)) {
      return;
    }

    // AvailableQty is what the store can sell: Unleashed nets off what is
    // already allocated to orders. The other two are carried because the
    // stock table has columns for them, and a half-filled row reads as
    // "allocated: none".
    $available = (float) ($row['AvailableQty'] ?? 0);
    $allocated = (float) ($row['AllocatedQty'] ?? 0);
    $on_hand = (float) ($row['QtyOnHand'] ?? 0);
    $guid = trim((string) ($row['ProductGuid'] ?? $row['Guid'] ?? ''));

    $this->tally($sku, $available, $allocated, $on_hand, $guid);

    $previous = $this->reported[$sku][$warehouse] ?? NULL;
    $this->reported[$sku][$warehouse] = [
      'available' => ($previous['available'] ?? 0.0) + $available,
      'allocated' => ($previous['allocated'] ?? 0.0) + $allocated,
      'on_hand' => ($previous['on_hand'] ?? 0.0) + $on_hand,
      'guid' => $guid !== '' ? $guid : ($previous['guid'] ?? ''),
    ];
  }

  /**
   * Adds every row in a StockOnHand response page.
   *
   * @param array<int, mixed> $rows
   *   Rows from the Items key. Typed loosely on purpose: this reads an API
   *   response, and a row that is not an array is dropped rather than fatal.
   */
  public function addAll(array $rows): void {
    foreach ($rows as $row) {
      if (is_array($row)) {
        $this->add($row);
      }
    }
  }

  /**
   * Adds one warehouse's figures into a SKU's totals.
   */
  private function tally(string $sku, float $available, float $allocated, float $on_hand, string $guid): void {
    $this->quantities[$sku] = ($this->quantities[$sku] ?? 0.0) + $available;
    $this->allocated[$sku] = ($this->allocated[$sku] ?? 0.0) + $allocated;
    $this->onHand[$sku] = ($this->onHand[$sku] ?? 0.0) + $on_hand;

    // One product, many warehouse rows, the same guid on each.
    if ($guid !== '') {
      $this->guids[$sku] = $guid;
    }
  }

  /**
   * Adds stored figures for the counted warehouses a SKU did not report.
   *
   * For a delta pull. Asked one warehouse at a time, Unleashed returns a SKU
   * only from the warehouses where it moved, so a counted warehouse missing
   * for a SKU this pull DID see means "did not move there", and the stored
   * figure for it is still right.
   *
   * SKUs this pull did not see are left alone: on a delta that means
   * unchanged. Stored rows for warehouses outside the counted list are
   * ignored, as reported ones are.
   *
   * @param array<string, array<string, array{available: float, allocated: float, on_hand: float, guid?: string}>> $stored
   *   Stored rows by SKU and warehouse code, as WarehouseStockLedger::load()
   *   returns them.
   */
  public function overlay(array $stored): void {
    foreach (array_keys($this->quantities) as $sku) {
      $sku = (string) $sku;
      foreach ($this->warehouses as $warehouse) {
        if (isset($this->reported[$sku][$warehouse]) || !isset($stored[$sku][$warehouse])) {
          continue;
        }
        $row = $stored[$sku][$warehouse];
        $this->tally(
          $sku,
          (float) $row['available'],
          (float) $row['allocated'],
          (float) $row['on_hand'],
          // A guid Unleashed just sent wins over a stored one.
          isset($this->guids[$sku]) ? '' : trim((string) ($row['guid'] ?? '')),
        );
      }
    }
  }

  /**
   * The rows this pull returned, per SKU and counted warehouse.
   *
   * What the sync stores so the next delta can overlay() them. Unclamped: a
   * negative at one warehouse still offsets stock at another, as it does in
   * the total.
   *
   * @return array<string, array<string, array{available: float, allocated: float, on_hand: float, guid: string}>>
   *   Rows keyed by SKU, then warehouse code.
   */
  public function warehouseRows(): array {
    return $this->reported;
  }

  /**
   * Every SKU seen, with the three quantities the stock table records.
   *
   * @return array<string, array{available: float, allocated: float, on_hand: float, guid: string}>
   *   Rows keyed by SKU.
   */
  public function rows(): array {
    $out = [];
    foreach ($this->quantities() as $sku => $available) {
      $out[$sku] = [
        'available' => $available,
        'allocated' => max(0.0, $this->allocated[$sku] ?? 0.0),
        'on_hand' => max(0.0, $this->onHand[$sku] ?? 0.0),
        'guid' => $this->guids[$sku] ?? '',
      ];
    }

    return $out;
  }

  /**
   * The available quantity for every SKU seen.
   *
   * Negative totals are clamped to zero: a negative on-hand is an Unleashed
   * bookkeeping artefact, not a quantity anybody can be sold.
   *
   * @return array<string, float>
   *   Quantities keyed by SKU.
   */
  public function quantities(): array {
    return array_map(static fn (float $quantity): float => max(0.0, $quantity), $this->quantities);
  }

  /**
   * The available quantity a SKU has in each counted warehouse.
   *
   * Every counted warehouse is present, in configured order, with 0 where the
   * SKU was not reported there. Only what this pull reported, so it is the
   * full picture only after a full read. Unclamped: only the total is.
   *
   * @return array<string, float>
   *   Quantities keyed by warehouse code.
   */
  public function byWarehouse(string $sku): array {
    $sku = trim($sku);
    $out = [];
    foreach ($this->warehouses as $warehouse) {
      $out[$warehouse] = $this->reported[$sku][$warehouse]['available'] ?? 0.0;
    }

    return $out;
  }

  /**
   * Whether a SKU appeared in any counted warehouse.
   */
  public function sawSku(string $sku): bool {
    return isset($this->quantities[$sku]);
  }

  /**
   * How many SKUs were seen.
   */
  public function count(): int {
    return count($this->quantities);
  }

}
