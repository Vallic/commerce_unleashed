<?php

declare(strict_types=1);

namespace Drupal\Tests\commerce_unleashed\Kernel;

use Drupal\commerce_unleashed\Events\UnleashedStockChangedEvent;
use Drupal\commerce_unleashed\Stock\StockSync;
use Drupal\commerce_unleashed\Stock\WarehouseStockLedger;
use Drupal\commerce_unleashed\UnleashedClient;
use Drupal\commerce_unleashed\UnleashedManagerInterface;
use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Config\ImmutableConfig;
use Drupal\Core\Database\Connection;
use Drupal\Core\Lock\DatabaseLockBackend;
use Drupal\Core\Logger\LoggerChannelInterface;
use Drupal\KernelTests\KernelTestBase;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Group;
use Symfony\Component\EventDispatcher\EventDispatcherInterface;

/**
 * Tests the per-warehouse stock sync end to end against a real database.
 *
 * Unleashed is a mocked client; everything after it is real: the totals table
 * a storefront reads, the per-warehouse table a delta totals from, the
 * "filled" flag in State, the transaction and the lock. The unit tests pin the
 * overlay arithmetic; this pins that the sync wires it up, so a delta that
 * sees one warehouse move does not write that warehouse's figure over the
 * total.
 *
 * The module is not enabled, as its services need Commerce: the two tables
 * are created from its own schema definitions.
 */
#[CoversClass(StockSync::class)]
#[Group('commerce_unleashed')]
final class StockSyncTest extends KernelTestBase {

  /**
   * The counted warehouses, in the order they are pulled.
   */
  private const WAREHOUSES = ['MAIN', 'CITY', 'NORTH'];

  /**
   * The totals table.
   */
  private const TABLE = UnleashedManagerInterface::UNLEASHED_STOCK_TABLE;

  /**
   * The database.
   */
  private Connection $database;

  /**
   * The per-warehouse store.
   */
  private WarehouseStockLedger $ledger;

  /**
   * Every StockOnHand query the mocked client was asked, in order.
   *
   * @var string[]
   */
  private array $queries = [];

  /**
   * Every stock-changed event dispatched.
   *
   * @var \Drupal\commerce_unleashed\Events\UnleashedStockChangedEvent[]
   */
  private array $events = [];

  /**
   * Messages logged at warning level or above.
   *
   * @var string[]
   */
  private array $warnings = [];

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();

    $this->database = $this->container->get('database');
    require_once dirname(__DIR__, 3) . '/commerce_unleashed.install';
    $schema = $this->database->schema();
    foreach (commerce_unleashed_schema() as $table => $definition) {
      $schema->createTable($table, $definition);
    }

    $this->ledger = new WarehouseStockLedger(
      $this->database,
      $this->container->get('state'),
      $this->container->get('datetime.time'),
    );
  }

  /**
   * A full pull fills the store; a delta from one warehouse keeps the others.
   *
   * 53 at MAIN, 2 at CITY. One sells at CITY, so the delta returns only CITY,
   * now 1. The total is 54, not 1.
   */
  public function testDeltaFromOneWarehouseKeepsTheOthers(): void {
    $this->sync(['MAIN' => [$this->row('SKU9', 'MAIN', 53)], 'CITY' => [$this->row('SKU9', 'CITY', 2)]], TRUE);
    $this->assertSame(55, $this->total('SKU9'));

    $this->queries = [];
    $result = $this->sync(['CITY' => [$this->row('SKU9', 'CITY', 1)]]);

    $this->assertFalse($result['full'], 'Ran as a delta.');
    $this->assertStringContainsString('modifiedSince=', $this->firstQuery());
    $this->assertSame(54, $this->total('SKU9'), 'MAIN 53 + CITY 1, not CITY alone.');
    $this->assertSame(['CITY' => 1.0, 'MAIN' => 53.0], $this->stored('SKU9'));
  }

  /**
   * The first run is a full pull, and its changes are not news.
   *
   * The store is empty, so a delta cannot total anything and runs as a full
   * pull. That pull corrects figures rather than reporting deliveries. Once
   * done, the next run is an ordinary delta whose changes are news.
   */
  public function testFirstRunIsFullAndQuiet(): void {
    $this->seedTotal('SKU5', 0);
    $this->assertFalse($this->ledger->isSeeded());

    $result = $this->sync([
      'MAIN' => [$this->row('SKU5', 'MAIN', 3)],
      'CITY' => [$this->row('SKU5', 'CITY', 2)],
    ]);

    $this->assertTrue($result['full'], 'A delta on an empty store runs as a full pull.');
    $this->assertCount(3, $this->queries);
    foreach ($this->queries as $query) {
      $this->assertStringNotContainsString('modifiedSince=', $query);
    }
    $this->assertSame(5, $this->total('SKU5'));
    $this->assertCount(1, $this->events);
    $this->assertFalse($this->events[0]->shouldNotify(), 'Filling the store is a correction, not news.');
    $this->assertSame(['SKU5' => [0.0, 5.0]], $this->events[0]->getChanges());
    $this->assertTrue($this->ledger->isSeeded());

    // The next run is a delta again, and a delivery is news.
    $this->seedTotal('NEW1', 0);
    $this->queries = [];
    $this->events = [];
    $result = $this->sync(['NORTH' => [$this->row('NEW1', 'NORTH', 12)]]);

    $this->assertFalse($result['full']);
    $this->assertStringContainsString('modifiedSince=', $this->firstQuery());
    $this->assertCount(1, $this->events);
    $this->assertTrue($this->events[0]->shouldNotify());
    $this->assertFalse($this->events[0]->isFull());
    $this->assertSame(['NEW1' => [0.0, 12.0]], $this->events[0]->getChanges());
  }

  /**
   * A failed warehouse writes nothing, and the full pull is tried again.
   */
  public function testFailedWarehouseWritesNothingAndRetriesTheFullPull(): void {
    $this->seedTotal('SKU1', 7);

    // MAIN answers, CITY refuses on every attempt. MAIN's figure must not be
    // written on its own.
    $result = $this->sync([
      'MAIN' => [$this->row('SKU1', 'MAIN', 2)],
      'CITY' => NULL,
    ]);

    $this->assertTrue($result['failed']);
    $this->assertSame(0, $result['skus']);
    $this->assertSame(7, $this->total('SKU1'), 'The total is untouched.');
    $this->assertSame([], $this->stored('SKU1'), 'Nothing is stored per warehouse.');
    $this->assertFalse($this->ledger->isSeeded());
    $this->assertSame([], $this->events);
    $this->assertNotEmpty(array_filter($this->warnings, static fn (string $m): bool => str_contains($m, 'Stock pull failed for warehouse CITY')));
    $this->assertCount(StockSync::MAX_ATTEMPTS, array_filter($this->queries, static fn (string $q): bool => str_contains($q, 'warehouseCode=CITY')), 'The failing read was retried.');

    // An empty full pull from every warehouse writes nothing either.
    $result = $this->sync([]);
    $this->assertTrue($result['failed'], 'An empty full pull is a failure.');
    $this->assertSame(7, $this->total('SKU1'));
    $this->assertFalse($this->ledger->isSeeded());

    // The next run is the full pull again, and this time it works.
    $this->queries = [];
    $result = $this->sync(['MAIN' => [$this->row('SKU1', 'MAIN', 2)], 'CITY' => [$this->row('SKU1', 'CITY', 1)]]);

    $this->assertTrue($result['full']);
    $this->assertFalse($result['failed']);
    $this->assertStringNotContainsString('modifiedSince=', $this->firstQuery());
    $this->assertSame(3, $this->total('SKU1'));
    $this->assertTrue($this->ledger->isSeeded());
  }

  /**
   * An empty delta is a quiet period: nothing written, nothing failed.
   */
  public function testEmptyDeltaIsQuiet(): void {
    $this->sync(['MAIN' => [$this->row('SKU1', 'MAIN', 4)]], TRUE);
    $this->events = [];

    $result = $this->sync([]);

    $this->assertFalse($result['full']);
    $this->assertFalse($result['failed']);
    $this->assertSame(4, $this->total('SKU1'));
    $this->assertSame([], $this->events);
  }

  /**
   * A full pull zeroes and prunes what the warehouses no longer return.
   */
  public function testFullPullPrunesRowsNoLongerReturned(): void {
    $this->sync([
      'MAIN' => [$this->row('SKU1', 'MAIN', 4), $this->row('GONE', 'MAIN', 6)],
      'NORTH' => [$this->row('SKU1', 'NORTH', 2)],
    ], TRUE);
    $this->assertSame(6, $this->total('SKU1'));
    $this->assertSame(6, $this->total('GONE'));

    // NORTH has sold out of SKU1 and dropped its row; GONE is obsolete and
    // Unleashed returns it from nowhere.
    $this->events = [];
    $this->sync(['MAIN' => [$this->row('SKU1', 'MAIN', 4)]], TRUE);

    $this->assertSame(['MAIN' => 4.0], $this->stored('SKU1'), 'The NORTH row is gone.');
    $this->assertSame(4, $this->total('SKU1'));
    $this->assertSame([], $this->stored('GONE'));
    $this->assertSame(0, $this->total('GONE'), 'Zeroed in the totals table.');
    $this->assertSame(['SKU1' => [6.0, 4.0], 'GONE' => [6.0, 0.0]], $this->firstEvent()->getChanges());

    // And the pruned NORTH row is not overlaid back into the next delta.
    $this->sync(['MAIN' => [$this->row('SKU1', 'MAIN', 3)]]);
    $this->assertSame(3, $this->total('SKU1'));
  }

  /**
   * Stock moving between warehouses updates the store though the sum holds.
   *
   * MAIN 10 + CITY 2. One is transferred to CITY: MAIN 9, CITY 3, still 12.
   * The total is not rewritten, but the per-warehouse rows must be, or the
   * next delta overlays the old MAIN 10.
   */
  public function testTransferBetweenWarehousesUpdatesTheStore(): void {
    $this->sync(['MAIN' => [$this->row('SKU1', 'MAIN', 10)], 'CITY' => [$this->row('SKU1', 'CITY', 2)]], TRUE);
    $this->events = [];

    $result = $this->sync(['MAIN' => [$this->row('SKU1', 'MAIN', 9)], 'CITY' => [$this->row('SKU1', 'CITY', 3)]]);

    $this->assertSame(0, $result['updated'], 'The total did not change.');
    $this->assertSame([], $this->events, 'No change, no event.');
    $this->assertSame(12, $this->total('SKU1'));
    $this->assertSame(['CITY' => 3.0, 'MAIN' => 9.0], $this->stored('SKU1'));

    // Proof it matters: CITY sells one. 9 + 2, not the stale 10 + 2.
    $this->sync(['CITY' => [$this->row('SKU1', 'CITY', 2)]]);
    $this->assertSame(11, $this->total('SKU1'));
  }

  /**
   * A warehouse taken off the counted list leaves the store on a full pull.
   */
  public function testUncountedWarehouseRowsArePruned(): void {
    $this->database->insert(WarehouseStockLedger::TABLE)
      ->fields(['ProductCode' => 'SKU1', 'WarehouseCode' => 'OLD', 'AvailableQty' => 5, 'AllocatedQty' => 0, 'QtyOnHand' => 5, 'ProductGuid' => '', 'timestamp' => 1])
      ->execute();

    $this->sync(['MAIN' => [$this->row('SKU1', 'MAIN', 1)]], TRUE);

    $this->assertSame(['MAIN' => 1.0], $this->stored('SKU1'));
  }

  /**
   * A sync started while another holds the lock does nothing.
   */
  public function testOverlappingSyncIsSkipped(): void {
    $this->sync(['MAIN' => [$this->row('SKU1', 'MAIN', 5)]], TRUE);

    // Another process holds the lock.
    $other = new DatabaseLockBackend($this->database);
    $this->assertTrue($other->acquire(StockSync::LOCK, 60));

    $this->queries = [];
    $result = $this->sync(['MAIN' => [$this->row('SKU1', 'MAIN', 1)]]);

    $this->assertTrue($result['skipped']);
    $this->assertSame([], $this->queries, 'Unleashed was not asked.');
    $this->assertSame(5, $this->total('SKU1'));

    $other->release(StockSync::LOCK);
    $result = $this->sync(['MAIN' => [$this->row('SKU1', 'MAIN', 1)]]);

    $this->assertFalse($result['skipped']);
    $this->assertSame(1, $this->total('SKU1'));
  }

  /**
   * Fractional quantities are kept, and negative totals clamped.
   */
  public function testQuantitiesAreStoredAsUnleashedReportsThem(): void {
    $this->sync([
      'MAIN' => [$this->row('KG1', 'MAIN', 2.5), $this->row('NEG', 'MAIN', -3)],
      'CITY' => [$this->row('KG1', 'CITY', 0.25), $this->row('NEG', 'CITY', 1)],
    ], TRUE);

    $this->assertSame('2.75', $this->rawTotal('KG1'));
    $this->assertSame('0', $this->rawTotal('NEG'));
  }

  /**
   * Runs one sync against canned Unleashed answers.
   *
   * @param array<string, array<int, array<string, mixed>>|null> $answers
   *   StockOnHand rows by warehouse code. NULL makes that warehouse fail on
   *   every attempt; a warehouse not listed answers with no rows.
   * @param bool $full
   *   Whether to ask for a full pull.
   *
   * @return array<string, mixed>
   *   What sync() returned.
   */
  private function sync(array $answers, bool $full = FALSE): array {
    $client = $this->createMock(UnleashedClient::class);
    $client->method('getStockOnHand')->willReturnCallback(function (string $query, ?int $page = NULL) use ($answers): array {
      $this->queries[] = $query;
      parse_str($query, $params);
      $warehouse = (string) ($params['warehouseCode'] ?? '');
      if (array_key_exists($warehouse, $answers) && $answers[$warehouse] === NULL) {
        return ['error' => 'An error occurred while executing the command definition.'];
      }

      return ['Items' => $answers[$warehouse] ?? [], 'Pagination' => ['NumberOfPages' => 1]];
    });
    $manager = $this->createMock(UnleashedManagerInterface::class);
    $manager->method('getClient')->willReturn($client);
    $manager->method('getStockWarehouses')->willReturn(self::WAREHOUSES);
    $manager->method('getStockPageSize')->willReturn(500);

    $config = $this->createMock(ImmutableConfig::class);
    $config->method('get')->willReturnCallback(static fn (string $key): mixed => ['stock.modified_since_days' => 3][$key] ?? NULL);
    $config_factory = $this->createMock(ConfigFactoryInterface::class);
    $config_factory->method('get')->with('commerce_unleashed.settings')->willReturn($config);

    $logger = $this->createMock(LoggerChannelInterface::class);
    $record = function (string|\Stringable $message, array $context = []): void {
      $this->warnings[] = strtr((string) $message, array_map('strval', $context));
    };
    foreach (['warning', 'error'] as $level) {
      $logger->method($level)->willReturnCallback($record);
    }

    $dispatcher = $this->createMock(EventDispatcherInterface::class);
    $dispatcher->method('dispatch')->willReturnCallback(function (object $event): object {
      if ($event instanceof UnleashedStockChangedEvent) {
        $this->events[] = $event;
      }
      return $event;
    });

    $sync = new class(
      $manager,
      $this->ledger,
      $this->database,
      $config_factory,
      $dispatcher,
      // A lock backend of its own, as a separate process would have, so the
      // overlap test's lock is held by someone else.
      new DatabaseLockBackend($this->database),
      $this->container->get('datetime.time'),
      $logger,
    ) extends StockSync {

      /**
       * {@inheritdoc}
       */
      protected function pause(int $seconds): void {
        // The retry path is real; only the back-off is skipped.
      }

    };

    return $sync->sync($full);
  }

  /**
   * The first StockOnHand query since the list was last cleared.
   */
  private function firstQuery(): string {
    $this->assertNotEmpty($this->queries, 'Unleashed was asked at least once.');

    return $this->queries[0];
  }

  /**
   * The first stock-changed event since the list was last cleared.
   *
   * Read through a method: the list is filled by the mocked dispatcher's
   * callback, which static analysis cannot see after `$this->events = []`.
   */
  private function firstEvent(): UnleashedStockChangedEvent {
    $this->assertNotEmpty($this->events, 'A stock-changed event was dispatched.');

    return $this->events[0];
  }

  /**
   * One StockOnHand row.
   *
   * @return array<string, mixed>
   *   The row as Unleashed returns it.
   */
  private function row(string $sku, string $warehouse, float $available): array {
    return [
      'ProductCode' => $sku,
      'WarehouseCode' => $warehouse,
      'AvailableQty' => $available,
      'AllocatedQty' => 0,
      'QtyOnHand' => $available,
      'ProductGuid' => 'guid-' . $sku,
    ];
  }

  /**
   * Writes a total directly, as the table held it before a run.
   */
  private function seedTotal(string $sku, int $available): void {
    $this->database->merge(self::TABLE)
      ->key('ProductCode', $sku)
      ->fields([
        'AvailableQty' => (string) $available,
        'AllocatedQty' => '0',
        'QtyOnHand' => (string) $available,
        'ProductGuid' => 'guid-' . $sku,
        'timestamp' => 1,
      ])
      ->execute();
  }

  /**
   * The total a storefront reads for a SKU, as stored.
   */
  private function rawTotal(string $sku): ?string {
    $value = $this->database->select(self::TABLE, 's')
      ->fields('s', ['AvailableQty'])
      ->condition('ProductCode', $sku)
      ->execute()
      ->fetchField();

    return $value === FALSE ? NULL : (string) $value;
  }

  /**
   * The total a storefront reads for a SKU, as an integer, or NULL.
   */
  private function total(string $sku): ?int {
    $value = $this->rawTotal($sku);

    return $value === NULL ? NULL : (int) $value;
  }

  /**
   * The stored available figure per warehouse for a SKU, sorted by code.
   *
   * @return array<string, float>
   *   Available quantity by warehouse code.
   */
  private function stored(string $sku): array {
    $rows = $this->ledger->load([$sku])[$sku] ?? [];
    ksort($rows);

    return array_map(static fn (array $row): float => $row['available'], $rows);
  }

}
