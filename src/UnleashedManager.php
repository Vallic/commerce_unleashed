<?php

namespace Drupal\commerce_unleashed;

use Drupal\commerce_order\Entity\OrderInterface;
use Drupal\commerce_price\Price;
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

  public function __construct(protected EntityTypeManagerInterface $entity_type_manager, protected ConfigFactoryInterface $configFactory, protected EventDispatcherInterface $eventDispatcher, protected DateFormatterInterface $dateFormatter, protected Connection $connection) {
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
  public function syncProductVariation(ProductVariationInterface $product_variation): array {
    $payload = [
      'ProductCode' => $product_variation->getSku(),
      'ProductDescription' => $product_variation->getTitle(),
      'Guid' => $product_variation->uuid(),
      'DefaultSellPrice' => $product_variation->getPrice()->getNumber(),
    ];

    $unleashed_product_event = new UnleashedProductVariationEvent($product_variation, $payload);
    $this->eventDispatcher->dispatch($unleashed_product_event, UnleashedEvents::UNLEASHED_PRODUCT_VARIATION);
    $payload = $unleashed_product_event->getPayload();

    return $this->unleashedClient->updateProduct($payload, $product_variation->uuid());
  }

  /**
   * {@inheritdoc}
   */
  public function syncOrder(OrderInterface $order): array {
    $order_type = $this->entity_type_manager->getStorage('commerce_order_type')->load($order->bundle());
    $currency = $order->getTotalPrice()->getCurrencyCode();
    $payload = [
      'Guid' => $order->uuid(),
      'OrderNumber' => $order->getOrderNumber(),
      'OrderStatus' => 'Placed',
      'SubTotal' => $order->getSubtotalPrice()->getNumber(),
      'Supplier' => [
        'SupplierCode' => $order_type->getThirdPartySetting('commerce_unleashed', 'supplier_code', ''),
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
  public function getStockOnHand(ProductVariationInterface $product_variation): float {
    return $this->connection->select(self::UNLEASHED_STOCK_TABLE, 's')->fields('s', ['QtyOnHand'])->condition('ProductGuid', $product_variation->uuid())->execute()->fetchField() ?? 0;
  }

}
