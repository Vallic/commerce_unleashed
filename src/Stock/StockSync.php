<?php

declare(strict_types=1);

namespace Drupal\commerce_unleashed\Stock;

use Drupal\commerce_unleashed\Events\UnleashedEvents;
use Drupal\commerce_unleashed\Events\UnleashedStockChangedEvent;
use Drupal\commerce_unleashed\UnleashedManagerInterface;
use Drupal\Component\Datetime\TimeInterface;
use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Database\Connection;
use Drupal\Core\Lock\LockBackendInterface;
use Drupal\Core\Logger\LoggerChannelInterface;
use Symfony\Component\EventDispatcher\EventDispatcherInterface;

/**
 * Pulls stock on hand from the chosen Unleashed warehouses.
 *
 * Used when stock.warehouses is set. Each warehouse is read on its own and
 * the figures added up per SKU into the stock-on-hand table, which is what
 * the availability checker and the stock overview read. The figures per
 * warehouse are kept as well, in WarehouseStockLedger.
 *
 * Rules:
 *
 *  - A failed or empty pull changes nothing. "Everything is zero" and "we
 *    could not ask" look the same in the data and mean opposite things.
 *  - A delta only writes what it saw. Unleashed's modifiedSince filter
 *    returns movements, so a SKU absent from a delta has not changed. Only a
 *    full pull zeroes a SKU it did not see.
 *  - A delta total includes the warehouses that did not move. A delta
 *    returns a SKU only from the warehouses where it moved; the stored figure
 *    is used for the rest. Until a full pull has filled that store, a delta
 *    runs as a full pull.
 *  - One sync at a time. A delta reads stored figures before it writes; a
 *    full pull committing in between would have stale figures written back
 *    over it. A second run is skipped, not queued.
 */
class StockSync {

  /**
   * The lock every stock sync takes.
   */
  public const string LOCK = 'commerce_unleashed.stock_sync';

  /**
   * How many times one read is attempted before it is given up on.
   */
  public const int MAX_ATTEMPTS = 4;

  /**
   * Days of movement a delta asks for when none is configured.
   */
  public const int DEFAULT_MODIFIED_SINCE_DAYS = 1;

  /**
   * How long the lock is held at most, in seconds.
   *
   * Released as soon as a run ends, and at shutdown if it dies; this is only
   * how long a process killed outright blocks the next one.
   */
  protected const float LOCK_TIMEOUT = 1800.0;

  /**
   * How many API requests the current run has made, retries included.
   */
  protected int $requests = 0;

  public function __construct(
    protected readonly UnleashedManagerInterface $unleashedManager,
    protected readonly WarehouseStockLedger $ledger,
    protected readonly Connection $database,
    protected readonly ConfigFactoryInterface $configFactory,
    protected readonly EventDispatcherInterface $eventDispatcher,
    protected readonly LockBackendInterface $lock,
    protected readonly TimeInterface $time,
    protected readonly LoggerChannelInterface $logger,
  ) {}

  /**
   * Runs a stock sync.
   *
   * @param bool $full
   *   TRUE for a full pull. Run one regularly: a SKU whose stock has not
   *   moved never appears in a delta, and a stale figure would otherwise hold
   *   forever.
   * @param bool $notify
   *   FALSE to mark this run's stock-changed event as not news.
   *
   * @return array{warehouses: int, skus: int, updated: int, zeroed: int, full: bool, failed: bool, skipped: bool}
   *   What the run did. `full` is whether it ran as a full pull, which a delta
   *   does until the per-warehouse store is filled. `failed` is TRUE when
   *   nothing was written because a warehouse could not be read or a full
   *   pull came back empty; an empty delta is a quiet period, not a failure.
   *   `skipped` is TRUE when another sync held the lock, or no warehouse is
   *   configured.
   */
  public function sync(bool $full = FALSE, bool $notify = TRUE): array {
    $result = ['warehouses' => 0, 'skus' => 0, 'updated' => 0, 'zeroed' => 0, 'full' => $full, 'failed' => FALSE, 'skipped' => TRUE];

    if ($this->unleashedManager->getStockWarehouses() === []) {
      $this->logger->error('No stock warehouses are configured, so the per-warehouse stock sync did nothing.');
      return $result;
    }

    if (!$this->lock->acquire(self::LOCK, self::LOCK_TIMEOUT)) {
      $this->logger->warning('Another stock sync is running, so this @mode was skipped; nothing was pulled or written.', [
        '@mode' => $full ? 'full pull' : 'delta',
      ]);
      return $result;
    }

    try {
      return $this->run($full, $notify) + ['skipped' => FALSE];
    }
    finally {
      $this->lock->release(self::LOCK);
    }
  }

  /**
   * Reads stock on hand from the counted warehouses; writes nothing.
   *
   * Public so a report can compare against exactly what a sync would write.
   * Takes no lock: it writes nothing, so it cannot race a sync.
   *
   * @param bool $full
   *   TRUE for every SKU; FALSE for the configured modifiedSince delta.
   *
   * @return \Drupal\commerce_unleashed\Stock\WarehouseStock|null
   *   What Unleashed reported, not yet overlaid with stored figures; or NULL
   *   when no warehouse is configured or any warehouse's pull failed. A
   *   partial read would understate every SKU that warehouse holds, so it is
   *   never returned. An empty read is returned as empty.
   */
  public function read(bool $full = TRUE): ?WarehouseStock {
    $warehouses = $this->unleashedManager->getStockWarehouses();
    $this->requests = 0;

    if ($warehouses === []) {
      return NULL;
    }

    $stock = new WarehouseStock($warehouses);
    $days = $full ? 0 : $this->modifiedSinceDays();
    $page_size = $this->unleashedManager->getStockPageSize();

    foreach ($warehouses as $warehouse) {
      if (!$this->pullWarehouse($stock, $warehouse, $page_size, $days)) {
        $this->logger->error('Stock pull failed for warehouse @warehouse; nothing was written.', ['@warehouse' => $warehouse]);
        return NULL;
      }
    }

    return $stock;
  }

  /**
   * How many API requests the last run made, retries included.
   */
  public function requestCount(): int {
    return $this->requests;
  }

  /**
   * The sync itself, under the lock.
   *
   * @return array{warehouses: int, skus: int, updated: int, zeroed: int, full: bool, failed: bool}
   *   What the run did.
   */
  protected function run(bool $full, bool $notify): array {
    $warehouses = $this->unleashedManager->getStockWarehouses();
    $result = ['warehouses' => count($warehouses), 'skus' => 0, 'updated' => 0, 'zeroed' => 0, 'full' => $full, 'failed' => FALSE];

    if (!$this->ledger->isSeeded()) {
      // An empty store would make a delta write the totals of only the
      // warehouses that moved. Until a full pull has filled it, a delta is
      // one, and since that pull corrects figures rather than reporting
      // deliveries, its changes are not news.
      $this->logger->warning('Per-warehouse stock has never been filled by a full pull, so this @mode runs as a full pull.', [
        '@mode' => $full ? 'full pull' : 'delta',
      ]);
      $full = TRUE;
      $notify = FALSE;
      $result['full'] = TRUE;
    }

    $stock = $this->read($full);
    if ($stock === NULL) {
      $result['failed'] = TRUE;
      return $result;
    }

    if ($stock->count() === 0) {
      // An empty full pull is far more often a wrong warehouse code or a
      // misbehaving API than an empty store; an empty delta is a quiet
      // period. Neither is a reason to write zeros.
      $this->logger->warning('Unleashed returned no stock rows for @warehouses; nothing was written.', [
        '@warehouses' => implode(', ', $warehouses),
      ]);
      $result['failed'] = $full;
      return $result;
    }

    if (!$full) {
      // Every warehouse was pulled, so whatever a seen SKU did not report is
      // a warehouse where it did not move: its stored figure still holds.
      $stock->overlay($this->ledger->load(array_keys($stock->quantities())));
    }

    $changes = [];
    $transaction = $this->database->startTransaction();
    try {
      [$result['updated'], $result['zeroed']] = $this->write($stock, $full, $changes);
    }
    catch (\Throwable $e) {
      $transaction->rollBack();
      throw $e;
    }
    // Commits.
    unset($transaction);

    $result['skus'] = $stock->count();

    if ($full) {
      // Only now: a full pull that failed or came back empty returned above,
      // and the next run tries the full pull again.
      $this->ledger->markSeeded();
    }

    if ($changes !== []) {
      $this->eventDispatcher->dispatch(
        new UnleashedStockChangedEvent($changes, $full, $notify),
        UnleashedEvents::STOCK_CHANGED,
      );
    }

    // Logged on every run: the API is metered, and a schedule quietly
    // costing more requests than anyone counted is how a limit gets hit.
    $this->logger->info('Stock sync (@mode) over @warehouses: @skus SKUs seen, @updated updated, @zeroed zeroed, in @requests API request(s).', [
      '@mode' => $full ? 'full' : 'delta',
      '@warehouses' => implode(', ', $warehouses),
      '@skus' => $result['skus'],
      '@updated' => $result['updated'],
      '@zeroed' => $result['zeroed'],
      '@requests' => $this->requests,
    ]);

    return $result;
  }

  /**
   * Writes the totals and the per-warehouse figures, inside a transaction.
   *
   * @param \Drupal\commerce_unleashed\Stock\WarehouseStock $stock
   *   What the pull returned, overlaid on a delta.
   * @param bool $full
   *   Whether this was a full pull.
   * @param array<string, array{0: float|null, 1: float}> $changes
   *   Gains every SKU whose available total changed.
   *
   * @return array{0: int, 1: int}
   *   How many SKUs were updated, and how many of those were zeroed.
   */
  protected function write(WarehouseStock $stock, bool $full, array &$changes): array {
    $table = UnleashedManagerInterface::UNLEASHED_STOCK_TABLE;
    $updated = 0;
    $zeroed = 0;
    $now = $this->time->getRequestTime();

    foreach (array_chunk($stock->rows(), 500, TRUE) as $chunk) {
      // Read the current figures first so an unchanged row is not rewritten:
      // a full pull touches every SKU.
      $current = $this->database->select($table, 's')
        ->fields('s', ['ProductCode', 'AvailableQty'])
        ->condition('ProductCode', array_map('strval', array_keys($chunk)), 'IN')
        ->execute()
        ->fetchAllKeyed();

      foreach ($chunk as $sku => $row) {
        $sku = (string) $sku;
        $before = isset($current[$sku]) ? (float) $current[$sku] : NULL;
        if ($before !== NULL && self::same($before, $row['available'])) {
          continue;
        }

        $this->database->merge($table)
          ->key('ProductCode', $sku)
          ->fields([
            'AvailableQty' => self::formatQuantity($row['available']),
            'AllocatedQty' => self::formatQuantity($row['allocated']),
            'QtyOnHand' => self::formatQuantity($row['on_hand']),
            'ProductGuid' => $row['guid'],
            'timestamp' => $now,
          ])
          ->execute();

        $changes[$sku] = [$before, $row['available']];
        $updated++;
        if ($row['available'] <= 0) {
          $zeroed++;
        }
      }
    }

    if ($full) {
      $zeroed += $this->zeroUnseen($stock, $changes);
    }

    // Always, even where the total did not change: stock can move between
    // warehouses and leave the sum where it was.
    $warehouse_rows = $stock->warehouseRows();
    $this->ledger->save($warehouse_rows);

    if ($full) {
      foreach ($stock->warehouses() as $warehouse) {
        $held = [];
        foreach ($warehouse_rows as $sku => $by_warehouse) {
          if (isset($by_warehouse[$warehouse])) {
            $held[] = (string) $sku;
          }
        }
        $this->ledger->pruneUnseen($warehouse, $held);
      }
      $this->ledger->pruneWarehouses($stock->warehouses());
    }

    return [$updated, $zeroed];
  }

  /**
   * Zeroes SKUs a FULL pull did not return.
   *
   * Only ever on a full pull. Unleashed drops obsolete products out of
   * StockOnHand entirely, so on a full run an absent SKU genuinely has none.
   * On a delta, absence means nothing moved.
   *
   * @param \Drupal\commerce_unleashed\Stock\WarehouseStock $stock
   *   What the pull returned.
   * @param array<string, array{0: float|null, 1: float}> $changes
   *   Gains the SKUs zeroed.
   *
   * @return int
   *   How many SKUs were zeroed.
   */
  protected function zeroUnseen(WarehouseStock $stock, array &$changes): int {
    $table = UnleashedManagerInterface::UNLEASHED_STOCK_TABLE;
    $zeroed = 0;
    $now = $this->time->getRequestTime();

    $held = $this->database->select($table, 's')
      ->fields('s', ['ProductCode', 'AvailableQty'])
      ->execute()
      ->fetchAllKeyed();

    foreach ($held as $sku => $quantity) {
      $sku = (string) $sku;
      if ($stock->sawSku($sku) || (float) $quantity <= 0) {
        continue;
      }
      $this->database->update($table)
        ->fields(['AvailableQty' => '0', 'AllocatedQty' => '0', 'QtyOnHand' => '0', 'timestamp' => $now])
        ->condition('ProductCode', $sku)
        ->execute();
      $changes[$sku] = [(float) $quantity, 0.0];
      $zeroed++;
    }

    return $zeroed;
  }

  /**
   * Pages one warehouse's stock into the totals.
   *
   * @return bool
   *   FALSE when the pull failed and the run must be abandoned.
   */
  protected function pullWarehouse(WarehouseStock $stock, string $warehouse, int $page_size, int $days): bool {
    // Not URL-encoded: the request is signed over the query as given.
    $query = 'warehouseCode=' . $warehouse . '&pageSize=' . $page_size;
    if ($days > 0) {
      $query .= '&modifiedSince=' . gmdate('Y-m-d', $this->time->getRequestTime() - ($days * 86400));
    }

    $client = $this->unleashedManager->getClient();
    $page = 1;
    do {
      $response = $this->attempt(
        static fn (): array => $client->getStockOnHand($query, $page),
        sprintf('stock page %d (%s)', $page, $query),
      );
      if ($response === NULL) {
        return FALSE;
      }

      $stock->addAll($response['Items'] ?? []);
      $pages = (int) ($response['Pagination']['NumberOfPages'] ?? 1);
      $page++;
    } while ($page <= $pages);

    return TRUE;
  }

  /**
   * Performs a read, retrying while it keeps failing.
   *
   * Unleashed fails intermittently under sustained reads, in two shapes: an
   * HTTP failure the client throws (a 502, say), and a 400 carrying "An error
   * occurred while executing the command definition", an internal error
   * dressed as a client error that the client hands back as an `error` key.
   * Both are retried. A refusal that is not transient costs a few wasted
   * requests; not retrying costs a whole warehouse discarded over a blip.
   *
   * @param callable():array $read
   *   Makes the request and returns the decoded response.
   * @param string $what
   *   What is being read, for the log.
   *
   * @return array|null
   *   The decoded response, or NULL when every attempt failed.
   */
  protected function attempt(callable $read, string $what): ?array {
    $attempt = 0;

    while (TRUE) {
      $attempt++;
      $this->requests++;

      try {
        $response = $read();
        if (!isset($response['error'])) {
          return $response;
        }
        $failure = (string) $response['error'];
      }
      catch (\Throwable $e) {
        $failure = $e->getMessage();
      }

      if ($attempt >= self::MAX_ATTEMPTS) {
        $this->logger->error('Unleashed read of @what failed after @attempts attempts: @message', [
          '@what' => $what,
          '@attempts' => $attempt,
          '@message' => $failure,
        ]);
        return NULL;
      }

      $this->logger->warning('Unleashed read of @what failed (attempt @attempt), retrying: @message', [
        '@what' => $what,
        '@attempt' => $attempt,
        '@message' => $failure,
      ]);

      // 1s, 2s, 4s: long enough for a blip to pass, backing off rather than
      // hammering an API that is already struggling.
      $this->pause(2 ** ($attempt - 1));
    }
  }

  /**
   * Waits between retries.
   */
  protected function pause(int $seconds): void {
    sleep($seconds);
  }

  /**
   * Days of movement a delta asks for.
   */
  protected function modifiedSinceDays(): int {
    $days = (int) $this->configFactory->get('commerce_unleashed.settings')->get('stock.modified_since_days');

    return $days > 0 ? $days : self::DEFAULT_MODIFIED_SINCE_DAYS;
  }

  /**
   * Whether two quantities are the same as the stock table stores them.
   */
  public static function same(float $a, float $b): bool {
    return self::formatQuantity($a) === self::formatQuantity($b);
  }

  /**
   * Formats a quantity for the varchar quantity columns.
   */
  public static function formatQuantity(float $quantity): string {
    $formatted = rtrim(rtrim(number_format($quantity, 4, '.', ''), '0'), '.');

    return $formatted === '-0' || $formatted === '' ? '0' : $formatted;
  }

}
