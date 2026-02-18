<?php

namespace Drupal\commerce_unleashed\Commands;

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
      $query = $options['query'] ? str_replace('//', '&', $options['query']) : 'brief=true';

      if (empty($full_sync)) {
        $last_product_sync = $this->keyValueFactory->get('commerce_unleashed')->get('products') ?? 0;

        if ($last_product_sync > 0) {
          $last_modified_date = date('Y-m-d', $last_product_sync);
          $query .= '&modifiedSince=' . $last_modified_date;
        }
      }
      $this->keyValueFactory->get('commerce_unleashed')->set('products', time());
      $this->unleashedManager->syncProducts($query);
    }
    else {
      $this->unleashedManager->syncProduct($sku);
    }
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
