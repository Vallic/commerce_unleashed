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
use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Database\Connection;
use Drupal\Core\Datetime\DateFormatterInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Symfony\Component\EventDispatcher\EventDispatcherInterface;

/**
 * Commerce Core payloads and methods for interaction with Unleashed.
 */
class UnleashedManager implements UnleashedManagerInterface {

  protected UnleashedClient $unleashedClient;

  public function __construct(protected EntityTypeManagerInterface $entityTypeManager, protected ConfigFactoryInterface $configFactory, protected EventDispatcherInterface $eventDispatcher, protected DateFormatterInterface $dateFormatter, protected Connection $connection) {
    $this->unleashedClient = $this->getClient();
  }

  /**
   * Setup http client.
   */
  protected function getClient(): UnleashedClient {
    $settings = $this->configFactory->get('commerce_unleashed.settings');
    return new UnleashedClient($settings->get('api_id') ?? 'empty', $settings->get('api_key') ?? 'empty', (bool) $settings->get('logging'));
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
        $this->queueSyncJob($product);
      }
    }
  }

  /**
   * {@inheritdoc}
   */
  public function processProductSync(array $payload): void {
    $settings = $this->configFactory->get('commerce_unleashed.settings');
    /** @var \Drupal\commerce_product\ProductVariationStorageInterface $product_variation_storage */
    $product_variation_storage = $this->entityTypeManager->getStorage('commerce_product_variation');

    $product_variation = $product_variation_storage->loadBySku($payload['ProductCode']);
    $price = new Price((string) $payload['DefaultSellPrice'], $settings->get('products.currency_code'));
    if (!$product_variation) {
      $product_variation = $product_variation_storage->create([
        'type' => $settings->get('products.type'),
        'sku' => $payload['ProductCode'],
        'title' => $payload['ProductDescription'],
        'price' => $price,
      ]);
    }
    // TBD: what we do update by default on regular sync.
    else {
      $product_variation->setPrice($price);
    }

    if ($settings->get('products.full')) {
      $payload = $this->unleashedClient->getProduct($payload['Guid']);
    }

    $unleashed_product_event = new UnleashedProductVariationEvent($product_variation, $payload);
    $this->eventDispatcher->dispatch($unleashed_product_event, UnleashedEvents::UNLEASHED_PRODUCT_VARIATION);
    $product_variation = $unleashed_product_event->getProductVariation();
    $product_variation->save();

    if (!$product_variation->getProduct()) {
      $product = Product::create([
        'type' => $settings->get('products.type'),
        'title' => $payload['ProductDescription'],
        'stores' => [$settings->get('products.store')],
        'variations' => [$product_variation->id()],
      ]);
      $product->save();
    }
  }

  /**
   * {@inheritdoc}
   */
  public function syncOrder(OrderInterface $order): array {
    $settings = $this->configFactory->get('commerce_unleashed.settings');
    $currency = $order->getTotalPrice()->getCurrencyCode();
    $payload = [
      'Guid' => $order->uuid(),
      'OrderNumber' => $order->getOrderNumber(),
      'OrderStatus' => 'Placed',
      'SubTotal' => $order->getSubtotalPrice()->getNumber(),
      'Supplier' => [
        'SupplierCode' => $settings->get('purchase_orders.supplier_code'),
      ],
      'Total' => $order->getTotalPrice()->getNumber(),
      'OrderDate' => $this->dateFormatter->format($order->getCreatedTime(), 'custom', 'Y-m-d\\TH:i:s', 'UTC'),
    ];

    if ($placed = $order->getPlacedTime()) {
      $payload['ReceivedDate'] = $this->dateFormatter->format($placed, 'custom', 'Y-m-d\\TH:i:s', 'UTC');
    }

    $tax_total = new Price('0', $currency);
    $promotion_total = new Price('0', $currency);
    foreach ($order->getAdjustments() as $adjustment) {
      if ($adjustment->getType() === 'tax') {
        $tax_total = $tax_total->add($adjustment->getAmount());
      }
      if ($adjustment->getType() === 'promotion') {
        $promotion_total = $promotion_total->add($adjustment->getAmount());
      }
    }

    $payload['TaxTotal'] = $tax_total->getNumber();

    $payload['DiscountRate'] = $promotion_total->isZero() ? '0.00' : abs($promotion_total->divide($order->getTotalPrice()->getNumber()));

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
        'UnitPrice' => $item->getUnitPrice()->getNumber(),
        'LineTotal' => $item->getTotalPrice()->getNumber(),
        'ReceiptQuantity' => $item->getQuantity(),
      ];

      $tax_total = new Price('0', $currency);
      $promotion_total = new Price('0', $currency);
      foreach ($order->getAdjustments(['tax', 'promotion']) as $adjustment) {
        if ($adjustment->getType() === 'tax') {
          $tax_total = $tax_total->add($adjustment->getAmount());
        }

        if ($adjustment->getType() === 'promotion') {
          $promotion_total = $promotion_total->add($adjustment->getAmount());
        }
      }

      $item_payload['LineTax'] = $tax_total->getNumber();
      $item_payload['DiscountRate'] = $promotion_total->isZero() ? '0.00' : abs($promotion_total->divide($item->getTotalPrice()->getNumber()));

      $payload['PurchaseOrderLines'][] = $item_payload;
    }

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
  public function completePurchaseOrder(OrderInterface $order): void {
    $this->unleashedClient->completePurchaseOrder($order->uuid());
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
  public function getStockOnHand(ProductVariationInterface $product_variation): ?float {
    return $this->connection->select(self::UNLEASHED_STOCK_TABLE, 's')->fields('s', ['QtyOnHand'])->condition('ProductCode', $product_variation->getSku())->execute()->fetchField();
  }

  /**
   * {@inheritdoc}
   */
  public function enforceStockAvailability(): bool {
    return $this->configFactory->get('commerce_unleashed.settings')->get('stock.availability');
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
