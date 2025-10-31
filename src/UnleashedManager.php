<?php

namespace Drupal\commerce_unleashed;

use Drupal\advancedqueue\Job;
use Drupal\commerce_order\Entity\OrderInterface;
use Drupal\commerce_price\Price;
use Drupal\commerce_product\Entity\Product;
use Drupal\commerce_product\Entity\ProductVariationInterface;
use Drupal\commerce_unleashed\Events\UnleashedEvents;
use Drupal\commerce_unleashed\Events\UnleashedOrderEvent;
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

    $save = TRUE;
    $product_variation = $product_variation_storage->loadBySku($payload['ProductCode']);
    $price = new Price((string) $payload['DefaultSellPrice'], $this->getCurrencyCode());
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
        $save = FALSE;
      }
    }

    if ($this->syncFullProduct()) {
      $payload = $this->unleashedClient->getProduct($payload['Guid']);
    }

    $unleashed_product_event = new UnleashedProductVariationEvent($product_variation, $payload);
    $this->eventDispatcher->dispatch($unleashed_product_event, UnleashedEvents::UNLEASHED_PRODUCT_VARIATION);
    $product_variation = $unleashed_product_event->getProductVariation();
    if ($save) {
      $product_variation->save();
    }

    if (!$product_variation->getProduct()) {
      $product = Product::create([
        'type' => $this->getVariationType(),
        'title' => $payload['ProductDescription'],
        'stores' => [$this->getStoreId()],
        'variations' => [$product_variation->id()],
        // Keep them unpublished.
        'status' => 0,
      ]);
      $product->save();
    }
  }

  /**
   * {@inheritdoc}
   */
  public function syncOrder(OrderInterface $order): array {
    $currency = $order->getTotalPrice()->getCurrencyCode();
    $payload = [
      'Guid' => $order->uuid(),
      'OrderNumber' => $order->getOrderNumber() ?? $order->id(),
      'OrderStatus' => 'Placed',
      'Supplier' => [
        'SupplierCode' => $this->getSupplierCode(),
      ],
      'Total' => $order->getTotalPrice()->getNumber(),
      'OrderDate' => $this->dateFormatter->format($order->getCreatedTime(), 'custom', 'Y-m-d\\TH:i:s', 'UTC'),
    ];

    $subtotal = $order->getSubtotalPrice();

    if ($placed = $order->getPlacedTime()) {
      $payload['ReceivedDate'] = $this->dateFormatter->format($placed, 'custom', 'Y-m-d\\TH:i:s', 'UTC');
    }

    $tax_total = new Price('0', $currency);
    $promotion_total = new Price('0', $currency);

    $payload['PurchaseOrderLines'] = [];
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
            $unit_price = $unit_price->subtract($adjustment->getAmount());
            $line_total = $line_total->subtract($adjustment->getAmount());
            $subtotal = $subtotal->subtract($adjustment->getAmount());
          }
        }

        if ($adjustment->getType() === 'promotion') {
          $promotion_item_total = $promotion_item_total->add($adjustment->getAmount());
          $promotion_total = $promotion_total->add($adjustment->getAmount());
        }
      }

      $item_payload['UnitPrice'] = $unit_price->getNumber();
      $item_payload['LineTotal'] = $line_total->getNumber();

      $item_payload['LineTax'] = $tax_item_total->getNumber();
      $item_payload['DiscountRate'] = $promotion_item_total->isZero() ? '0.00' : abs((float) $promotion_item_total->divide($item->getTotalPrice()->getNumber())->getNumber());

      $payload['PurchaseOrderLines'][] = $item_payload;
    }

    /** @var \Drupal\commerce_shipping\Entity\ShipmentInterface[] $shipments */
    $shipments = $order->get('shipments')->referencedEntities();

    foreach ($shipments as $shipment) {
      $item_payload = [
        'LineNumber' => time(),
        'Product' => [
          'ProductCode' => $this->getShippingSku(),
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

        if ($adjustment->getType() === 'promotion') {
          $promotion_total = $promotion_total->add($adjustment->getAmount());
        }
      }

      $item_payload['UnitPrice'] = $unit_price->getNumber();
      $item_payload['LineTotal'] = $line_total->getNumber();
      // Unleashed handle shipping as a line item.
      $subtotal = $subtotal->add($unit_price);

      $payload['PurchaseOrderLines'][] = $item_payload;
    }

    $payload['DiscountRate'] = $promotion_total->isZero() ? '0.00' : abs((float) $promotion_total->divide($order->getTotalPrice()->getNumber())->getNumber());

    $payload['TaxTotal'] = $tax_total->getNumber();
    $payload['TaxRate'] = $tax_total->isZero() ? '0.00' : round(abs((float) $tax_total->divide($order->getTotalPrice()->subtract($tax_total)->getNumber())->getNumber()), 2, PHP_ROUND_HALF_UP);
    $payload['Subtotal'] = $subtotal->getNumber();
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

    $unleashed_order_event = new UnleashedOrderEvent($order, $payload);
    $this->eventDispatcher->dispatch($order, UnleashedEvents::UNLEASHED_PURCHASE_ORDER);
    $payload = $unleashed_order_event->getPayload();
    return $this->unleashedClient->createPurchaseOrder($payload, $order->uuid());
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
  public function syncOrders(): bool {
    return (bool) $this->unleashedSettings()->get('purchase_orders.sync');
  }

  /**
   * {@inheritdoc}
   */
  public function syncOrderType(string $bundle): bool {
    $types = $this->unleashedSettings()->get('purchase_orders.types');
    return !empty($types[$bundle]);
  }

  /**
   * {@inheritdoc}
   */
  public function isOrderEligible(OrderInterface $order): bool {
    return $this->syncOrders() && $this->syncOrderType($order->bundle());
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
  public function getShippingSku(): string {
    return $this->unleashedSettings()->get('purchase_orders.shipping_sku') ?? '';
  }

  /**
   * {@inheritdoc}
   */
  public function completeOrders(): bool {
    return (bool) $this->unleashedSettings()->get('purchase_orders.complete');
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
  public function updateLocalStock(): bool {
    return (bool) $this->unleashedSettings()->get('stock.local');
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
