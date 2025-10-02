<?php

namespace Drupal\commerce_unleashed\Commands;

use Drupal\commerce_unleashed\UnleashedManagerInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
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

  /**
   * Constructs a new UnleashedCommands object.
   */
  public function __construct(EntityTypeManagerInterface $entity_type_manager, UnleashedManagerInterface $unleashed_manager) {
    parent::__construct();

    $this->entityTypeManager = $entity_type_manager;
    $this->unleashedManager = $unleashed_manager;
  }

  /**
   * Sync products.
   *
   * @param string $sku
   *   The specific product sku.
   * @param array $options
   *   The options passed to this drush function.
   *
   * @command commerce_unleashed:sync:products
   */
  public function syncProducts(string $sku = 'all', array $options = ['query' => NULL]): void {
    if ($sku === 'all') {
      $query = $options['query'] ?? 'brief=true';
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
