<?php

namespace Drupal\commerce_unleashed\Commands;

use Drupal\commerce_unleashed\ProductSyncBatch;
use Drupal\commerce_unleashed\UnleashedManagerInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\KeyValueStore\KeyValueFactory;
use Drush\Commands\DrushCommands;

class UnleashedCommands extends DrushCommands {

  /**
   * The entity type manager.
   */
  protected EntityTypeManagerInterface $entityTypeManager;

  /**
   * The Unleashed manager.
   */
  protected UnleashedManagerInterface $unleashedManager;

  protected KeyValueFactory $keyValueFactory;

  /**
   * Constructs a new UnleashedCommands object.
   */
  public function __construct(EntityTypeManagerInterface $entity_type_manager, UnleashedManagerInterface $unleashed_manager, KeyValueFactory $keyValueFactory) {
    parent::__construct();

    $this->entityTypeManager = $entity_type_manager;
    $this->unleashedManager = $unleashed_manager;
    $this->keyValueFactory = $keyValueFactory;
  }

  /**
   * Sync products.
   *
   * @param string $sku
   *   The specific product sku.
   * @param int $full_sync
   *   The full or partial sync.
   * @param array $options
   *   The options passed to this drush function.
   *
   * @command commerce_unleashed:sync:products
   */
  public function syncProducts(string $sku = 'all', int $full_sync = 0, array $options = ['query' => NULL]): void {
    if ($sku === 'all') {
      $query = $options['query']
        ? str_replace('//', '&', $options['query'])
        : $this->unleashedManager->getProductsBaseQuery();

      if (empty($full_sync)) {
        $last_product_sync = $this->keyValueFactory->get('commerce_unleashed')->get('products') ?? 0;

        if ($last_product_sync > 0) {
          $last_modified_date = date('Y-m-d', $last_product_sync);
          $query .= '&modifiedSince=' . $last_modified_date;
        }
      }
      $this->unleashedManager->syncProducts($query);

      // The marker means "the whole catalog has been read up to here", and
      // cron takes deltas from it. A run narrowed by --query read part of the
      // catalog, so recording it would have cron skip everything the filter
      // excluded, forever.
      if (!$options['query']) {
        $this->keyValueFactory->get('commerce_unleashed')->set('products', time());
      }
    }
    else {
      $this->unleashedManager->syncProduct($sku);
    }
  }

  /**
   * Reads the whole catalog in a batch, one API request per operation.
   *
   * The counterpart to cron, which only ever reads a delta. A full read is
   * a hundred-odd requests and tens of thousands of products; run as a batch
   * each page gets its own PHP request, so memory is returned between pages
   * and a failure costs one page rather than the whole run.
   *
   * @param array $options
   *   The options passed to this drush function.
   *
   * @command commerce_unleashed:sync:products:full
   * @aliases cu-sync-full
   * @option query Extra Unleashed filters, with // in place of &.
   * @usage drush commerce_unleashed:sync:products:full
   *   Read the whole catalog.
   * @usage drush commerce_unleashed:sync:products:full --query=productGroup=Tobacco
   *   Read everything in one product group.
   */
  public function syncProductsFull(array $options = ['query' => NULL]): int {
    // Filters are ADDED to the base query, not swapped for it: the base
    // carries whether attributes are read, and a caller narrowing the catalog
    // by product group is not asking for a record without them.
    $query = $this->unleashedManager->getProductsBaseQuery();
    $complete = empty($options['query']);
    if (!$complete) {
      $query .= '&' . str_replace('//', '&', $options['query']);
    }

    // One request up front, only to learn how many pages there are. Its items
    // are synced by the batch like any other page, so nothing is read twice
    // for the sake of counting.
    try {
      $first = $this->unleashedManager->syncProductPage($query, 1);
    }
    catch (\Throwable $e) {
      $this->logger()->error(dt('Could not read the catalog: @message', ['@message' => $e->getMessage()]));

      return self::EXIT_FAILURE;
    }

    if ($first['items'] === 0) {
      $this->logger()->warning(dt('Unleashed returned no products for that query.'));

      return self::EXIT_FAILURE;
    }

    $this->logger()->notice(dt('@total product(s) over @pages page(s).', [
      '@total' => $first['total'],
      '@pages' => $first['pages'],
    ]));

    if ($first['pages'] > 1) {
      // Page 1 is done; the batch picks up from 2.
      $definition = ProductSyncBatch::definition($query, $first['pages'], $complete);
      $definition['operations'] = array_slice($definition['operations'], 1);
      batch_set($definition);
      drush_backend_batch_process();

      return self::EXIT_SUCCESS;
    }

    // A single page needs no batch, but still marks the read as complete.
    ProductSyncBatch::finished(TRUE, [
      'pages' => 1,
      'queued' => $first['items'],
      'failed' => [],
      'complete' => $complete,
    ], []);

    return self::EXIT_SUCCESS;
  }

  /**
   * Sync stock.
   *
   * @command commerce_unleashed:sync:stock
   */
  public function syncStock(): void {
    $this->unleashedManager->syncStockOnHand();
  }

}
