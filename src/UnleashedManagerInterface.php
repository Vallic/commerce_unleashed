<?php

namespace Drupal\commerce_unleashed;

use Drupal\commerce_order\Entity\OrderInterface;
use Drupal\commerce_product\Entity\ProductVariationInterface;

interface UnleashedManagerInterface {

  public const string UNLEASHED_STOCK_TABLE = 'commerce_unleashed_stock_on_hand';

  /**
   * Sync all products.
   */
  public function syncProducts(string $query = '', ?int $page_number = NULL);

  /**
   * Sync product.
   */
  public function syncProduct(string $sku): void;

  /**
   * Sync product variation to Unleashed.
   */
  public function processProductSync(array $payload);

  /**
   * Sync order to Unleashed.
   */
  public function syncOrder(OrderInterface $order): array;

  /**
   * Finalize order.
   */
  public function completePurchaseOrder(OrderInterface $order): void;

  /**
   * Sync stock on hand.
   */
  public function syncStockOnHand($page_number = NULL): void;

  /**
   * Get stock on hand for product variation.
   */
  public function getStockOnHand(ProductVariationInterface $product_variation): ?float;

  /**
   * Determine do we enforce stock.
   */
  public function enforceStockAvailability(): bool;

}
