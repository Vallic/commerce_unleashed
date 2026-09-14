<?php

namespace Drupal\commerce_unleashed;

use Drupal\commerce_order\Entity\OrderInterface;
use Drupal\commerce_product\Entity\ProductVariationInterface;

interface UnleashedManagerInterface {

  public const UNLEASHED_STOCK_TABLE = 'commerce_unleashed_stock_on_hand';

  public const UNLEASHED_NON_MANAGED = -1;

  public const UNLEASHED_SALES_ORDERS = 'sales';

  public const UNLEASHED_PURCHASE_ORDERS = 'purchase';

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
  public function syncPurchaseOrder(OrderInterface $order): array;

  /**
   * Sync order to Unleashed.
   */
  public function syncSalesOrder(OrderInterface $order): array;

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
   * The product type new products are created as.
   *
   * Falls back to the variation type when unset, which is what the module did
   * before this setting existed.
   */
  public function getProductType(): string;

  /**
   * The page size to read products with.
   *
   * A read carrying modifiedSince is a delta and can afford a large page,
   * because the response only contains what moved. A read of the whole
   * catalogue materialises that many complete records per request, which is
   * expensive for Unleashed to serve, so it uses a smaller one.
   *
   * @param string $query
   *   The query the read will be made with.
   */
  public function getProductsPageSize(string $query = ''): int;

  /**
   * The page size to read stock on hand with.
   */
  public function getStockPageSize(): int;

  /**
   * Whether an existing variation's price is updated from Unleashed.
   *
   * Sites whose Drupal price is authoritative — or whose Unleashed price is a
   * different price to the one the storefront quotes — turn this off and map
   * the price themselves from the product variation event.
   */
  public function syncPrice(): bool;

  /**
   * The Unleashed payload field the price is read from.
   *
   * DefaultSellPrice by default; may name a sell price tier instead.
   */
  public function getPriceField(): string;

  /**
   * Reads the configured price out of an Unleashed product payload.
   *
   * @return string|null
   *   The price as a numeric string, or NULL when the payload has none.
   */
  public function getPayloadPrice(array $payload): ?string;

  /**
   * Get the default currency code.
   */
  public function getCurrencyCode(): string;

  /**
   * Is the order sync enabled.
   */
  public function syncPurchaseOrders(): bool;

  /**
   * Is the order type eligible for purchase order sync.
   */
  public function syncPurchaseOrderType(string $bundle): bool;

  /**
   * Is the order sync enabled for sales orders.
   */
  public function syncSalesOrders(): bool;

  /**
   * Is the order type eligible for sync with Unleashed.
   */
  public function syncSalesOrderType(string $bundle): bool;

  /**
   * Is order eligible for sync with Unleashed.
   */
  public function isOrderEligible(OrderInterface $order): ?string;

  /**
   * Get default supplier code.
   */
  public function getSupplierCode(): string;

  /**
   * Do order upon fulfillment need to be send to Unleashed?
   */
  public function completeOrders(OrderInterface $order): ?string;

  /**
   * Get the default shipping SKU.
   */
  public function getShippingSku(OrderInterface $order): ?string;

  /**
   * Is the stock sync enabled?
   */
  public function syncStock(): bool;

  /**
   * Determine do we enforce stock.
   */
  public function enforceStockAvailability(): bool;

  /**
   * Determine do we update local stock table post order placement.
   */
  public function updateLocalStock(OrderInterface $order): bool;

}
