<?php

namespace Drupal\commerce_unleashed;

use Drupal\commerce_order\Entity\OrderInterface;
use Drupal\commerce_product\Entity\ProductVariationInterface;

interface UnleashedManagerInterface {

  public const UNLEASHED_STOCK_TABLE = 'commerce_unleashed_stock_on_hand';

  public const UNLEASHED_NON_MANAGED = -1;

  /**
   * Handle direct calls via client.
   */
  public function getClient(): UnleashedClient;

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
   * Sync stock on hand.
   */
  public function syncStockOnHand($page_number = NULL): void;

  /**
   * Get stock on hand for product variation.
   */
  public function getStockOnHand(ProductVariationInterface $product_variation): int;

  /**
   * Update local stock on hand.
   */
  public function updateLocalStockOnHand(ProductVariationInterface $product_variation, $quantity): void;

  /**
   * The API ID.
   */
  public function getApiId(): string;

  /**
   * The api key.
   */
  public function getApiKey(): string;

  /**
   * Is the logging enabled.
   */
  public function getLogging(): bool;

  /**
   * Is the product sync enabled.
   */
  public function syncInventory(): bool;

  /**
   * Do we run full, and not brief import.
   */
  public function syncFullProduct(): bool;

  /**
   * Are we syncing on the cron.
   */
  public function syncOnCron(): bool;

  /**
   * Get default variation type.
   */
  public function getVariationType(): string;

  /**
   * Get the default store.
   */
  public function getStoreId(): string;

  /**
   * Get the default currency code.
   */
  public function getCurrencyCode(): string;

  /**
   * Is the order sync enabled.
   */
  public function syncOrders(): bool;

  /**
   * Is the order type eligible for sync.
   */
  public function syncOrderType(string $bundle): bool;

  /**
   * Is order eligible for sync.
   */
  public function isOrderEligible(OrderInterface $order): bool;

  /**
   * Get default supplier code.
   */
  public function getSupplierCode(): string;

  /**
   * Do order upon fulfillment needs to sent to Unleashed.
   */
  public function completeOrders(): bool;

  /**
   * Is the stock sync enabled.
   */
  public function syncStock(): bool;

  /**
   * Determine do we enforce stock.
   */
  public function enforceStockAvailability(): bool;

  /**
   * Determine do we update local stock table post order placement.
   */
  public function updateLocalStock(): bool;

}
