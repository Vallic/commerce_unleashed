<?php

namespace Drupal\commerce_unleashed;

use Drupal\advancedqueue\Job;
use Drupal\commerce_order\Entity\OrderInterface;
use Drupal\commerce_price\Price;
use Drupal\commerce_product\Entity\Product;
use Drupal\commerce_product\Entity\ProductVariationInterface;
use Drupal\commerce_unleashed\Events\UnleashedEvents;
use Drupal\commerce_unleashed\Events\UnleashedOrderEvent;
use Drupal\commerce_unleashed\Events\UnleashedProductEvent;
use Drupal\commerce_unleashed\Events\UnleashedProductVariationEvent;
use Drupal\commerce_unleashed\Events\UnleashedSyncEvent;
use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Config\ImmutableConfig;
use Drupal\Core\Database\Connection;
use Drupal\Core\Datetime\DateFormatterInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Symfony\Component\EventDispatcher\EventDispatcherInterface;

/**
 * Commerce Core payloads and methods for interaction with Unleashed.
 */
class UnleashedManager implements UnleashedManagerInterface {

  protected UnleashedClient $unleashedClient;

  protected ImmutableConfig $unleashedSettings;

  public function __construct(protected EntityTypeManagerInterface $entityTypeManager, protected ConfigFactoryInterface $configFactory, protected EventDispatcherInterface $eventDispatcher, protected DateFormatterInterface $dateFormatter, protected Connection $connection) {
    $this->unleashedClient = $this->getClient();
  }

  /**
   * Setup http client.
   */
  public function getClient(): UnleashedClient {
    return new UnleashedClient($this->getApiId(), $this->getApiKey(), $this->getLogging());
  }

  /**
   * {@inheritdoc}
   */
  public function syncProducts(string $query = '', ?int $page_number = NULL): void {
    $products = $this->unleashedClient->getProducts($query, $page_number);
    $pages = $products['Pagination']['NumberOfPages'];
    $page_number = $products['Pagination']['PageNumber'];
    if (!empty($products['Items'])) {
      foreach ($products['Items'] as $product) {

        $unleashed_sync_event = new UnleashedSyncEvent($product);
        $this->eventDispatcher->dispatch($unleashed_sync_event, UnleashedEvents::UNLEASHED_SYNC_EVENT);
        if ($unleashed_sync_event->skipSync()) {
          continue;
        }
        $this->queueSyncJob($product);
      }

      if ($pages > 1 && $page_number < $pages) {
        $this->syncProducts($query, $page_number + 1);
      }
    }
  }

  /**
   * {@inheritdoc}
   */
  public function syncProduct(string $sku): void {
    $products = $this->unleashedClient->getProductBySku($sku);

    if (!empty($products['Items'])) {
      foreach ($products['Items'] as $product) {
        $unleashed_sync_event = new UnleashedSyncEvent($product);
        $this->eventDispatcher->dispatch($unleashed_sync_event, UnleashedEvents::UNLEASHED_SYNC_EVENT);
        if ($unleashed_sync_event->skipSync()) {
          continue;
        }
        $this->queueSyncJob($product);
      }
    }
  }

  /**
   * {@inheritdoc}
   */
  public function processProductSync(array $payload): void {
    /** @var \Drupal\commerce_product\ProductVariationStorageInterface $product_variation_storage */
    $product_variation_storage = $this->entityTypeManager->getStorage('commerce_product_variation');

    $product_variation = $product_variation_storage->loadBySku($payload['ProductCode']);
    $price = new Price((string) $payload['DefaultSellPrice'], $this->getCurrencyCode());

    $save_product_variation = TRUE;
    if (!$product_variation) {
      $product_variation = $product_variation_storage->create([
        'type' => $this->getVariationType(),
        'sku' => $payload['ProductCode'],
        'title' => $payload['ProductDescription'],
        'price' => $price,
      ]);
    }
    // TBD: what we do update by default on regular sync.
    else {
      $compare = $product_variation->getPrice()?->compareTo($price);
      if (!empty($compare)) {
        $product_variation->setPrice($price);
      }
      else {
        $save_product_variation = FALSE;
      }
    }

    if ($this->syncFullProduct()) {
      $payload = $this->unleashedClient->getProduct($payload['Guid']) ?? $payload;
    }

    $unleashed_product__variation_event = new UnleashedProductVariationEvent($product_variation, $payload, $save_product_variation);
    $this->eventDispatcher->dispatch($unleashed_product__variation_event, UnleashedEvents::UNLEASHED_PRODUCT_VARIATION);
    $product_variation = $unleashed_product__variation_event->getProductVariation();

    if ($unleashed_product__variation_event->saveProductVariation()) {
      $product_variation->save();
    }

    $product = $product_variation->getProduct();
    $save_product = FALSE;
    if (!$product) {
      $product = Product::create([
        'type' => $this->getVariationType(),
        'title' => $payload['ProductDescription'],
        'stores' => [$this->getStoreId()],
        'variations' => [$product_variation->id()],
        // Keep them unpublished.
        'status' => 0,
      ]);
      $save_product = TRUE;
    }

    $unleashed_product_event = new UnleashedProductEvent($product, $payload, $save_product);
    $this->eventDispatcher->dispatch($unleashed_product_event, UnleashedEvents::UNLEASHED_PRODUCT);
    $product = $unleashed_product_event->getProduct();

    if ($unleashed_product_event->saveProduct()) {
      $product->save();
    }

  }

  /**
   * {@inheritdoc}
   */
  public function syncPurchaseOrder(OrderInterface $order): array {
    $payload = $this->getOrderPayload($order, self::UNLEASHED_PURCHASE_ORDERS);
    $unleashed_order_event = new UnleashedOrderEvent($order, $payload, self::UNLEASHED_PURCHASE_ORDERS);
    $this->eventDispatcher->dispatch($unleashed_order_event, UnleashedEvents::UNLEASHED_ORDER);
    return $this->unleashedClient->createPurchaseOrder($unleashed_order_event->getPayload(), $order->uuid());
  }

  /**
   * {@inheritdoc}
   */
  public function syncSalesOrder(OrderInterface $order): array {
    $payload = $this->getOrderPayload($order);
    $unleashed_order_event = new UnleashedOrderEvent($order, $payload);
    $this->eventDispatcher->dispatch($unleashed_order_event, UnleashedEvents::UNLEASHED_ORDER);
    return $this->unleashedClient->createSalesOrder($unleashed_order_event->getPayload(), $order->uuid());
  }

  /**
   * Generic payload build for both sales and purchase orders.
   */
  public function getOrderPayload(OrderInterface $order, $type = self::UNLEASHED_SALES_ORDERS): array {
    $currency = $order->getTotalPrice()->getCurrencyCode();
    $payload = [
      'Guid' => $order->uuid(),
      'OrderNumber' => $order->getOrderNumber() ?? $order->id(),
      'OrderStatus' => 'Placed',
      'Total' => $order->getTotalPrice()->getNumber(),
      'OrderDate' => $this->dateFormatter->format($order->getCreatedTime(), 'custom', 'Y-m-d\\TH:i:s', 'UTC'),
      // TBD: Add commerce_exchanger rate support.
      'ExchangeRate' => 1,
    ];

    if ($type === self::UNLEASHED_PURCHASE_ORDERS) {
      $payload['Supplier']['SupplierCode'] = $this->getSupplierCode();
    }
    else {
      $payload['Customer']['CustomerCode'] = $order->getEmail();
      $payload['Warehouse']['WarehouseCode'] = $this->getWarehouseCode();
    }

    $subtotal = $order->getSubtotalPrice();

    if ($placed = $order->getPlacedTime()) {
      $payload['ReceivedDate'] = $this->dateFormatter->format($placed, 'custom', 'Y-m-d\\TH:i:s', 'UTC');
    }

    $tax_total = new Price('0', $currency);
    $promotion_total = new Price('0', $currency);

    $line_items_key = $type === 'sales' ? 'SalesOrderLines' : 'PurchaseOrderLines';

    $payload[$line_items_key] = [];
    foreach ($order->getItems() as $item) {
      $item_payload = [
        'Guid' => $item->uuid(),
        'LineNumber' => $item->id(),
        'Product' => [
          'ProductCode' => $item->getPurchasedEntity()->getSku(),
        ],
        'Currency' => [
          'CurrencyCode' => $currency,
        ],
        'OrderQuantity' => $item->getQuantity(),
        'ReceiptQuantity' => $item->getQuantity(),
      ];

      $unit_price = $item->getUnitPrice();
      $line_total = $item->getTotalPrice();

      $tax_item_total = new Price('0', $currency);
      $promotion_item_total = new Price('0', $currency);
      foreach ($item->getAdjustments(['tax', 'promotion']) as $adjustment) {
        if ($adjustment->getType() === 'tax') {
          $tax_item_total = $tax_item_total->add($adjustment->getAmount());
          $tax_total = $tax_total->add($adjustment->getAmount());
          $item_payload['TaxRate'] = $adjustment->getPercentage();
          if ($adjustment->isIncluded()) {
            $unit_price = $unit_price->subtract($adjustment->getAmount()->divide($item->getQuantity()));
            $line_total = $line_total->subtract($adjustment->getAmount());
            $subtotal = $subtotal->subtract($adjustment->getAmount());
          }
        }
        else {
          $promotion_item_total = $promotion_item_total->add($adjustment->getAmount());
          $promotion_total = $promotion_total->add($adjustment->getAmount());
          if ($adjustment->isIncluded()) {
            $unit_price = $unit_price->add($adjustment->getAmount()->divide($item->getQuantity()));
            $line_total = $line_total->add($adjustment->getAmount());
            $subtotal = $subtotal->add($adjustment->getAmount());
          }
        }
      }

      $item_payload['UnitPrice'] = $unit_price->getNumber();
      $item_payload['LineTotal'] = $line_total->add($promotion_item_total)->getNumber();

      $item_payload['LineTax'] = $tax_item_total->getNumber();
      $item_payload['DiscountRate'] = $promotion_item_total->isZero() ? '0.00' : (string) abs((float) $promotion_item_total->divide($line_total->getNumber())->getNumber());

      if (!$promotion_item_total->isZero()) {
        $item_payload['DiscountedUnitPrice'] = round($unit_price->multiply(1 - $item_payload['DiscountRate'])->getNumber(), 4, PHP_ROUND_HALF_UP);
      }

      $payload[$line_items_key][] = $item_payload;
    }

    /** @var \Drupal\commerce_shipping\Entity\ShipmentInterface[] $shipments */
    $shipments = $order->get('shipments')->referencedEntities();

    foreach ($shipments as $shipment) {
      $item_payload = [
        'LineNumber' => time(),
        'Product' => [
          'ProductCode' => $this->getShippingSku($order),
        ],
        'Currency' => [
          'CurrencyCode' => $currency,
        ],
        'OrderQuantity' => 1,
        'ReceiptQuantity' => 1,
      ];

      $unit_price = $shipment->getAmount();
      $line_total = $shipment->getAmount();
      foreach ($shipment->getAdjustments() as $adjustment) {
        if ($adjustment->getType() === 'tax') {
          $tax_total = $tax_total->add($adjustment->getAmount());
          $item_payload['LineTax'] = $adjustment->getAmount()->getNumber();
          $item_payload['TaxRate'] = $adjustment->getPercentage();
          // Shipping tax is always included.
          if ($adjustment->isIncluded()) {
            $unit_price = $unit_price->subtract($adjustment->getAmount());
            $line_total = $line_total->subtract($adjustment->getAmount());
          }
        }

        if ($adjustment->getType() === 'shipping_promotion') {
          $unit_price = $unit_price->add($adjustment->getAmount());
          $line_total = $line_total->add($adjustment->getAmount());
        }
      }

      $item_payload['UnitPrice'] = $unit_price->getNumber();
      $item_payload['LineTotal'] = $line_total->getNumber();
      // Unleashed handle shipping as a line item.
      $subtotal = $subtotal->add($unit_price);

      $payload[$line_items_key][] = $item_payload;
    }

    $payload['TaxTotal'] = $tax_total->getNumber();
    $tax_rate = abs((float) $tax_total->divide($order->getTotalPrice()->subtract($tax_total)->getNumber())->getNumber());
    $payload['TaxRate'] = $tax_total->isZero() ? '0.00' : (string) round($tax_rate, 2, PHP_ROUND_HALF_UP);

    if ($type === self::UNLEASHED_SALES_ORDERS) {
      $payload['Tax'] = [
        'TaxRate' => $payload['TaxRate'],
      ];
    }

    $payload['Subtotal'] = $subtotal->add($promotion_total)->getNumber();
    $profiles = $order->collectProfiles();
    if (isset($profiles['shipping'])) {
      $shipping = $profiles['shipping'];
      /** @var \Drupal\address\Plugin\Field\FieldType\AddressItem $address */
      $address = $shipping->get('address')->first();

      $payload['DeliveryCity'] = $address->getLocality();
      $payload['DeliveryCountry'] = $address->getCountryCode();
      $payload['DeliveryPostCode'] = $address->getPostalCode();
      $payload['DeliveryStreetAddress'] = $address->getAddressLine1();
      $payload['DeliveryStreetAddress2'] = $address->getAddressLine2();
      $payload['DeliverRegion'] = $address->getAdministrativeArea();
      $payload['DeliveryName'] = $address->getGivenName() . ' ' . $address->getFamilyName();
    }

    return $payload;
  }

  /**
   * Resolve Unleashed customer by Drupal order.
   */
  public function getCustomerFromOrder(OrderInterface $order): array {
    $customer = $this->getCustomerByMail($order->getEmail());

    if (empty($customer)) {
      $profiles = $order->collectProfiles();
      $payload = [
        'CustomerCode' => $order->getEmail(),
        'CustomerName' => $order->getEmail(),
        'Email' => $order->getEmail(),
        'Currency' => [
          'CurrencyCode' => $order->getTotalPrice()->getCurrencyCode(),
        ],
      ];

      foreach ($profiles as $id => $profile) {
        /** @var \Drupal\address\Plugin\Field\FieldType\AddressItem $address */
        $address = $profile->get('address')->first();

        $payload['Addresses'][] = [
          'AddressType' => $id === 'shipping' ? 'Shipping' : 'Postal',
          'AddressName' => $address->getGivenName() . ' ' . $address->getFamilyName(),
          'StreetAddress' => $address->getAddressLine1(),
          'StreetAddress2' => $address->getAddressLine2(),
          'Region' => $address->getAdministrativeArea(),
          'City' => $address->getLocality(),
          'Country' => $address->getCountryCode(),
          'PostalCode' => $address->getPostalCode(),
        ];
        $payload['CustomerName'] = $address->getGivenName() . ' ' . $address->getFamilyName();
        $payload['ContactFirstName'] = $address->getGivenName();
        $payload['ContactLastName'] = $address->getFamilyName();
      }
      $customer = $this->unleashedClient->createCustomer($payload);
    }

    return $customer;
  }

  /**
   * Get customer by mail.
   */
  public function getCustomerByMail(string $mail): array {
    $response = $this->unleashedClient->getCustomers('customerCode=' . $mail);

    if (empty($response['Items'])) {
      return [];
    }

    return $response['Items'][0];
  }

  /**
   * {@inheritdoc}
   */
  public function syncStockOnHand($page_number = NULL): void {
    $data = $this->unleashedClient->getStockOnHand('pageSize=100', $page_number);
    $pages = $data['Pagination']['NumberOfPages'];
    $page_number = $data['Pagination']['PageNumber'];

    foreach ($data['Items'] as $item) {
      $this->connection->merge(self::UNLEASHED_STOCK_TABLE)->fields([
        'ProductCode' => $item['ProductCode'],
        'ProductGuid' => $item['ProductGuid'],
        'AllocatedQty' => $item['AllocatedQty'],
        'AvailableQty' => $item['AvailableQty'],
        'QtyOnHand' => $item['QtyOnHand'],
        'timestamp' => time(),
      ])->condition('ProductGuid', $item['ProductGuid'])->execute();
    }

    if ($pages > 1 && $page_number < $pages) {
      $this->syncStockOnHand($page_number + 1);
    }
  }

  /**
   * {@inheritdoc}
   */
  public function getStockOnHand(ProductVariationInterface $product_variation): int {
    $stock = $this->connection->select(self::UNLEASHED_STOCK_TABLE, 's')->fields('s', ['AvailableQty'])->condition('ProductCode', $product_variation->getSku())->execute()->fetchField();
    return !is_null($stock) ? (int) $stock : UnleashedManagerInterface::UNLEASHED_NON_MANAGED;
  }

  /**
   * {@inheritdoc}
   */
  public function updateLocalStockOnHand(ProductVariationInterface $product_variation, $quantity): void {
    $stock = $this->getStockOnHand($product_variation);
    if ($stock > 0) {
      $this->connection->merge(self::UNLEASHED_STOCK_TABLE)->fields([
        'AvailableQty' => $stock - $quantity,
        'timestamp' => time(),
      ])->condition('ProductCode', $product_variation->getSku())->execute();
    }
  }

  /**
   * {@inheritdoc}
   */
  public function getApiId(): string {
    return $this->unleashedSettings()->get('api_id');
  }

  /**
   * {@inheritdoc}
   */
  public function getApiKey(): string {
    return $this->unleashedSettings()->get('api_key');
  }

  /**
   * {@inheritdoc}
   */
  public function getLogging(): bool {
    return (bool) $this->unleashedSettings()->get('logging');
  }

  /**
   * {@inheritdoc}
   */
  public function syncInventory(): bool {
    return (bool) $this->unleashedSettings()->get('products.sync');
  }

  /**
   * {@inheritdoc}
   */
  public function syncFullProduct(): bool {
    return (bool) $this->unleashedSettings()->get('products.full');
  }

  /**
   * {@inheritdoc}
   */
  public function syncOnCron(): bool {
    return (bool) $this->unleashedSettings()->get('products.cron');
  }

  /**
   * {@inheritdoc}
   */
  public function getVariationType(): string {
    return $this->unleashedSettings()->get('products.type');
  }

  /**
   * {@inheritdoc}
   */
  public function getStoreId(): string {
    return $this->unleashedSettings()->get('products.store');
  }

  /**
   * {@inheritdoc}
   */
  public function getCurrencyCode(): string {
    return $this->unleashedSettings()->get('products.currency_code');
  }

  /**
   * {@inheritdoc}
   */
  public function syncPurchaseOrders(): bool {
    return (bool) $this->unleashedSettings()->get('purchase_orders.sync');
  }

  /**
   * {@inheritdoc}
   */
  public function syncPurchaseOrderType(string $bundle): bool {
    $types = $this->unleashedSettings()->get('purchase_orders.types');
    return !empty($types[$bundle]);
  }

  /**
   * {@inheritdoc}
   */
  public function syncSalesOrders(): bool {
    return (bool) $this->unleashedSettings()->get('sales_orders.sync');
  }

  /**
   * {@inheritdoc}
   */
  public function syncSalesOrderType(string $bundle): bool {
    $types = $this->unleashedSettings()->get('sales_orders.types');
    return !empty($types[$bundle]);
  }

  /**
   * {@inheritdoc}
   */
  public function isOrderEligible(OrderInterface $order): ?string {
    if ($this->syncPurchaseOrders() && $this->syncPurchaseOrderType($order->bundle())) {
      return self::UNLEASHED_PURCHASE_ORDERS;
    }
    if ($this->syncSalesOrders() && $this->syncSalesOrderType($order->bundle())) {
      return self::UNLEASHED_SALES_ORDERS;
    }
    return NULL;
  }

  /**
   * {@inheritdoc}
   */
  public function getSupplierCode(): string {
    return $this->unleashedSettings()->get('purchase_orders.supplier_code') ?? '';
  }

  /**
   * {@inheritdoc}
   */
  public function getWarehouseCode(): string {
    return $this->unleashedSettings()->get('sales_orders.warehouse_code') ?? '';
  }

  /**
   * {@inheritdoc}
   */
  public function getShippingSku(OrderInterface $order): string {
    $type = $this->isOrderEligible($order);
    return $this->unleashedSettings()->get(sprintf('%s_orders.shipping_sku', $type)) ?? '';
  }

  /**
   * {@inheritdoc}
   */
  public function completeOrders(OrderInterface $order): ?string {
    if ($eligible = $this->isOrderEligible($order)) {
      if ($this->unleashedSettings()->get(sprintf('%s_orders.complete', $eligible))) {
        return $eligible;
      }
    }

    return NULL;
  }

  /**
   * {@inheritdoc}
   */
  public function syncStock(): bool {
    return (bool) $this->unleashedSettings()->get('stock.sync');
  }

  /**
   * {@inheritdoc}
   */
  public function enforceStockAvailability(): bool {
    return (bool) $this->unleashedSettings()->get('stock.availability');
  }

  /**
   * {@inheritdoc}
   */
  public function updateLocalStock(OrderInterface $order): bool {
    if ($this->syncSalesOrderType($order->bundle())) {
      return (bool) $this->unleashedSettings()->get('stock.local');
    }

    return FALSE;
  }

  /**
   * Retrieve configuration.
   */
  protected function unleashedSettings(): ImmutableConfig {
    if (empty($this->unleashedSettings)) {
      $this->unleashedSettings = $this->configFactory->get('commerce_unleashed.settings');
    }
    return $this->unleashedSettings;
  }

  /**
   * Queue jobs.
   */
  protected function queueSyncJob($payload): void {
    $queue_storage = $this->entityTypeManager->getStorage('advancedqueue_queue');
    /** @var \Drupal\advancedqueue\Entity\QueueInterface $queue */
    $queue = $queue_storage->load('commerce_unleashed');
    // Create a job and queue each one up.
    $sync = Job::create('commerce_unleashed_product', $payload);
    $queue->enqueueJob($sync);
  }

}
