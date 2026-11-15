<?php

namespace Drupal\commerce_unleashed;

use Drupal\advancedqueue\Job;
use Drupal\commerce_order\Entity\OrderInterface;
use Drupal\commerce_shipping\Entity\ShipmentInterface;
use Drupal\commerce_price\Price;
use Drupal\commerce_product\Entity\Product;
use Drupal\commerce_product\Entity\ProductVariationInterface;
use Drupal\commerce_unleashed\Events\UnleashedEvents;
use Drupal\commerce_unleashed\Events\UnleashedOrderCustomerEvent;
use Drupal\commerce_unleashed\Events\UnleashedOrderEvent;
use Drupal\commerce_unleashed\Events\UnleashedProductEvent;
use Drupal\commerce_unleashed\Events\UnleashedProductVariationEvent;
use Drupal\commerce_unleashed\Events\UnleashedSyncEvent;
use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Config\ImmutableConfig;
use Drupal\Core\Database\Connection;
use Drupal\Core\Datetime\DateFormatterInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Logger\LoggerChannelTrait;
use Symfony\Component\EventDispatcher\EventDispatcherInterface;

/**
 * Commerce Core payloads and methods for interaction with Unleashed.
 */
class UnleashedManager implements UnleashedManagerInterface {

  use LoggerChannelTrait;

  /**
   * The shipping method field holding that method's Unleashed carriage code.
   */
  public const string CARRIAGE_FIELD = 'field_unleashed_carriage_sku';

  /**
   * Page size for a delta read.
   *
   * Page size is a cap, not a promise: a modifiedSince read returns only what
   * moved, so a large page costs Unleashed little and saves us requests.
   */
  public const DEFAULT_PAGE_SIZE = 500;

  /**
   * Page size for a read of the whole catalog.
   *
   * A full read genuinely builds this many complete records per request,
   * which is expensive for Unleashed to serve - they have asked integrators
   * not to use the 1000 maximum for exactly that reason. Smaller pages cost
   * more requests and less strain, and that trade is deliberate here.
   */
  public const FULL_SYNC_PAGE_SIZE = 200;

  /**
   * Bounds the Unleashed API accepts for pageSize.
   */
  public const MIN_PAGE_SIZE = 1;

  /**
   * Largest page size the API accepts.
   */
  public const MAX_PAGE_SIZE = 1000;

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
    $page = $page_number ?? 1;

    do {
      $result = $this->syncProductPage($query, $page);
      $page++;
    } while ($result['items'] > 0 && $page <= $result['pages']);
  }

  /**
   * {@inheritdoc}
   */
  public function syncProductPage(string $query = '', int $page_number = 1): array {
    $query = $this->withPageSize($query, $this->getProductsPageSize($query));
    $products = $this->unleashedClient->getProducts($query, $page_number);

    $items = $products['Items'] ?? [];
    foreach ($items as $product) {
      $unleashed_sync_event = new UnleashedSyncEvent($product);
      $this->eventDispatcher->dispatch($unleashed_sync_event, UnleashedEvents::UNLEASHED_SYNC_EVENT);
      if ($unleashed_sync_event->skipSync()) {
        continue;
      }
      $this->queueSyncJob($product);
    }

    return [
      'pages' => (int) ($products['Pagination']['NumberOfPages'] ?? 1),
      'page' => (int) ($products['Pagination']['PageNumber'] ?? $page_number),
      'items' => count($items),
      'total' => (int) ($products['Pagination']['NumberOfItems'] ?? 0),
    ];
  }

  /**
   * {@inheritdoc}
   */
  public function syncProduct(string $sku): void {
    foreach ($this->getProductsBySku($sku) as $product) {
      $unleashed_sync_event = new UnleashedSyncEvent($product);
      $this->eventDispatcher->dispatch($unleashed_sync_event, UnleashedEvents::UNLEASHED_SYNC_EVENT);
      if ($unleashed_sync_event->skipSync()) {
        continue;
      }
      $this->queueSyncJob($product);
    }
  }

  /**
   * {@inheritdoc}
   */
  public function getProductsBySku(string $sku): array {
    $sku = trim($sku);
    if ($sku === '') {
      return [];
    }

    // The same base query as a list sync, so a product synced on its own
    // arrives as complete as one synced in a batch: without it there is no
    // attribute set, and a subscriber keying on one reads the product as not
    // having it. The productCode filter matches on the start of the code, so
    // "ABC1" also returns "ABC10" and "ABC100", across pages.
    $query = $this->getProductsBaseQuery();
    $query = ($query === '' ? '' : $query . '&') . 'productCode=' . $sku;
    $query = $this->withPageSize($query, $this->getProductsPageSize($query));

    $matches = [];
    $page = 1;
    do {
      $data = $this->unleashedClient->getProducts($query, $page);
      if (!isset($data['Items']) || !is_array($data['Items'])) {
        $this->getLogger('commerce_unleashed')->error('Product @sku could not be read from Unleashed: @error', [
          '@sku' => $sku,
          '@error' => $data['error'] ?? 'no items in the response',
        ]);
        return [];
      }

      foreach ($data['Items'] as $product) {
        if (strcasecmp(trim((string) ($product['ProductCode'] ?? '')), $sku) === 0) {
          $matches[] = $product;
        }
      }

      $pages = (int) ($data['Pagination']['NumberOfPages'] ?? 1);
      $page++;
    } while ($page <= $pages);

    return $matches;
  }

  /**
   * {@inheritdoc}
   */
  public function processProductSync(array $payload): void {
    /** @var \Drupal\commerce_product\ProductVariationStorageInterface $product_variation_storage */
    $product_variation_storage = $this->entityTypeManager->getStorage('commerce_product_variation');

    $product_variation = $product_variation_storage->loadBySku($payload['ProductCode']);
    $price_number = $this->getPayloadPrice($payload);
    $price = $price_number !== NULL
      ? new Price($price_number, $this->getCurrencyCode())
      : NULL;

    $save_product_variation = TRUE;
    if (!$product_variation) {
      $values = [
        'type' => $this->getVariationType(),
        'sku' => $payload['ProductCode'],
        'title' => $payload['ProductDescription'],
      ];
      // A variation cannot be created without a price, so one is still set on
      // insert even when price sync is off — subscribers can replace it.
      if ($price) {
        $values['price'] = $price;
      }
      $product_variation = $product_variation_storage->create($values);
    }
    // TBD: what we do update by default on regular sync.
    elseif ($price && $this->syncPrice()) {
      $compare = $product_variation->getPrice()?->compareTo($price);
      if (!empty($compare)) {
        $product_variation->setPrice($price);
      }
      else {
        $save_product_variation = FALSE;
      }
    }
    else {
      // Nothing to update on an existing variation. A subscriber may still
      // change it and set saveProductVariation() on the event.
      $save_product_variation = FALSE;
    }

    if ($this->syncFullProduct()) {
      // getProduct() always returns an array - an empty one, or one carrying
      // an error - so the fallback is on it being unusable, not on it being
      // NULL.
      $full = $this->unleashedClient->getProduct($payload['Guid']);
      $payload = (!$full || isset($full['error'])) ? $payload : $full;
    }

    assert($product_variation instanceof ProductVariationInterface);
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
        'type' => $this->getProductType(),
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
      // Configurable: a warehouse that picks from parked orders needs
      // 'Parked', or the order skips the step it works from.
      'OrderStatus' => $this->getOrderStatus($type),
      'Total' => $order->getTotalPrice()->getNumber(),
      'OrderDate' => $this->dateFormatter->format($order->getCreatedTime(), 'custom', 'Y-m-d\\TH:i:s', 'UTC'),
      // TBD: Add commerce_exchanger rate support.
      'ExchangeRate' => 1,
    ];

    if ($type === self::UNLEASHED_SALES_ORDERS) {
      // Sent explicitly rather than left to Unleashed's default: relying on
      // an unstated default is how an order reaches the warehouse with
      // nothing reserved against it.
      $payload['AllocateProduct'] = TRUE;
    }

    if ($type === self::UNLEASHED_PURCHASE_ORDERS) {
      $payload['Supplier']['SupplierCode'] = $this->getSupplierCode();
    }
    else {
      $payload['Customer']['CustomerCode'] = $this->getOrderCustomerCode($order);
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
      $purchased_entity = $item->getPurchasedEntity();
      $item_payload = [
        'Guid' => $item->uuid(),
        'LineNumber' => $item->id(),
        'Product' => [
          // Unleashed identifies a line by product code, which only a product
          // variation carries.
          'ProductCode' => $purchased_entity instanceof ProductVariationInterface ? $purchased_entity->getSku() : '',
        ],
        'Currency' => [
          'CurrencyCode' => $currency,
        ],
        'OrderQuantity' => $item->getQuantity(),
        'ReceiptQuantity' => $item->getQuantity(),
      ];

      // Both are nullable, and a line with no resolved price took the whole
      // push down with "Call to a member function add() on null" a few lines
      // below. Skipped and logged instead: one unpriced line should not stop
      // the rest of the order reaching Unleashed, and a line Unleashed cannot
      // price is one somebody has to look at anyway.
      $unit_price = $item->getUnitPrice();
      $line_total = $item->getTotalPrice();
      if ($unit_price === NULL || $line_total === NULL) {
        $this->getLogger('commerce_unleashed')->warning('Order @order line @line (@sku) has no price, so it was left out of the Unleashed payload.', [
          '@order' => $order->id(),
          '@line' => $item->id(),
          '@sku' => $purchased_entity instanceof ProductVariationInterface ? $purchased_entity->getSku() : '(no variation)',
        ]);
        continue;
      }

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
          // An INCLUDED promotion is already inside the unit price, so the
          // undiscounted price is the unit price LESS the (negative) amount —
          // the same direction as an included tax above. Adding it took the
          // discount off a second time below, where the line and the subtotal
          // apply the promotion, and Unleashed refused the order because its
          // subtotal and tax no longer reached the total.
          if ($adjustment->isIncluded()) {
            $unit_price = $unit_price->subtract($adjustment->getAmount()->divide($item->getQuantity()));
            $line_total = $line_total->subtract($adjustment->getAmount());
            $subtotal = $subtotal->subtract($adjustment->getAmount());
          }
        }
      }

      $item_payload['UnitPrice'] = $unit_price->getNumber();
      $item_payload['LineTotal'] = $line_total->add($promotion_item_total)->getNumber();

      $item_payload['LineTax'] = $tax_item_total->getNumber();
      // A line with no tax adjustment is zero-rated (a VAT-exempt product, or
      // an order outside the VAT area), and says so: left unset, Unleashed
      // falls back to the order's rate for it.
      $item_payload['TaxRate'] ??= '0';
      $item_payload['DiscountRate'] = $promotion_item_total->isZero() ? '0.00' : (string) abs((float) $promotion_item_total->divide($line_total->getNumber())->getNumber());

      if (!$promotion_item_total->isZero()) {
        $discounted = $unit_price->multiply((string) (1 - (float) $item_payload['DiscountRate']));
        $item_payload['DiscountedUnitPrice'] = round((float) $discounted->getNumber(), 4, PHP_ROUND_HALF_UP);
      }

      $payload[$line_items_key][] = $item_payload;
    }

    /** @var \Drupal\commerce_shipping\Entity\ShipmentInterface[] $shipments */
    $shipments = $order->get('shipments')->referencedEntities();

    foreach ($shipments as $shipment) {
      $item_payload = [
        'LineNumber' => time(),
        'Product' => [
          'ProductCode' => $this->getShippingSku($order, $shipment),
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
      // Zero-rated unless a tax adjustment said otherwise, as for products.
      $item_payload['LineTax'] ??= '0';
      $item_payload['TaxRate'] ??= '0';
      // Unleashed handle shipping as a line item.
      $subtotal = $subtotal->add($unit_price);

      $payload[$line_items_key][] = $item_payload;
    }

    $payload['TaxTotal'] = $tax_total->getNumber();
    // The order's own rate is the one its taxed lines are charged at, not the
    // tax as a share of the whole order. Unleashed resolves this rate to one of
    // its taxes and refuses the order when none matches, and a blended rate
    // matches nothing as soon as one line is zero-rated: 20% VAT on most of an
    // order and a VAT-exempt bottle came to 0.18, "The TaxCode '' and TaxRate
    // of '0.18' does not map to a valid tax". So the order carries the
    // standard rate, and each line its own.
    $line_rates = array_map(
      static fn (array $line): float => (float) $line['TaxRate'],
      $payload[$line_items_key],
    );
    $tax_rate = $line_rates === [] ? 0.0 : max($line_rates);
    $payload['TaxRate'] = $tax_total->isZero() || $tax_rate <= 0 ? '0.00' : (string) round($tax_rate, 2, PHP_ROUND_HALF_UP);

    if ($type === self::UNLEASHED_SALES_ORDERS) {
      $payload['Tax'] = [
        'TaxRate' => $payload['TaxRate'],
      ];
    }

    $payload['Subtotal'] = $subtotal->add($promotion_total)->getNumber();

    // Unleashed validates Subtotal + TaxTotal == Total and rejects the order
    // with "invoice sub total ... does not equal invoice total" when they
    // disagree. They can, because Total comes straight off the order while
    // these two are built up from adjustments — so anything in the order total
    // that is not a line or a tax adjustment opens a gap: a shipment whose SKU
    // Unleashed will not accept, a fee nothing turns into a line, or a migrated
    // order carrying totals without the adjustments behind them.
    //
    // Logged with the actual difference, because Unleashed's own message names
    // the three numbers but not what is missing from them.
    if ($type === self::UNLEASHED_SALES_ORDERS) {
      $stated = new Price((string) $payload['Total'], $currency);
      $derived = (new Price((string) $payload['Subtotal'], $currency))->add($tax_total);
      if (!$stated->equals($derived)) {
        $this->getLogger('commerce_unleashed')->warning('Order @order will be refused by Unleashed: it totals @total but its lines and tax come to @derived, a difference of @gap. Something in the order total is not represented as a line — most often delivery, or a fee.', [
          '@order' => $order->id(),
          '@total' => $stated->getNumber(),
          '@derived' => $derived->getNumber(),
          '@gap' => $stated->subtract($derived)->getNumber(),
        ]);
      }
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

    return $payload;
  }

  /**
   * The Unleashed customer code an order belongs to.
   *
   * The order email by default, which is right wherever the person paying IS
   * the customer. Sites where the two differ - a trade representative ordering
   * for a venue, most obviously - subscribe to UNLEASHED_ORDER_CUSTOMER and
   * return whatever they key customers on.
   *
   * Asked in both places that need it, so one subscriber settles the payload
   * and the "create the customer if missing" lookup together. They must agree:
   * resolving them differently raises the order against one customer while
   * creating another.
   */
  public function getOrderCustomerCode(OrderInterface $order): string {
    $event = new UnleashedOrderCustomerEvent($order, (string) $order->getEmail());
    $this->eventDispatcher->dispatch($event, UnleashedEvents::UNLEASHED_ORDER_CUSTOMER);

    $code = trim($event->getCustomerCode());

    return $code !== '' ? $code : (string) $order->getEmail();
  }

  /**
   * Resolve Unleashed customer by Drupal order.
   */
  public function getCustomerFromOrder(OrderInterface $order): array {
    $code = $this->getOrderCustomerCode($order);
    $customer = $this->getCustomerByCode($code);

    // A failed lookup is not a missing customer. Creating one here would
    // duplicate an existing customer every time the API had a bad moment.
    if (isset($customer['error'])) {
      return $customer;
    }

    if (empty($customer)) {
      $profiles = $order->collectProfiles();
      $payload = [
        'CustomerCode' => $code,
        'CustomerName' => $code,
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
   * {@inheritdoc}
   */
  public function getCustomerByCode(string $code): array {
    $code = trim($code);
    if ($code === '') {
      return [];
    }

    // The customerCode filter does not match exactly: "jo@example.com" also
    // returns "jo@example.com.au". Taking the first item raised orders against
    // whichever customer the API listed first.
    $query = 'customerCode=' . $code;
    $case_match = [];
    $page = 1;
    do {
      $response = $this->unleashedClient->getCustomers($query, $page);
      if (isset($response['error'])) {
        return $response;
      }
      if (!isset($response['Items']) || !is_array($response['Items'])) {
        return ['error' => sprintf('Customer %s could not be read from Unleashed.', $code)];
      }

      foreach ($response['Items'] as $customer) {
        $candidate = trim((string) ($customer['CustomerCode'] ?? ''));
        if ($candidate === $code) {
          return $customer;
        }
        // Unleashed treats codes that differ only in case as the same code,
        // so creating one would be refused. Kept in case no exact match turns
        // up on a later page.
        if ($case_match === [] && strcasecmp($candidate, $code) === 0) {
          $case_match = $customer;
        }
      }

      $pages = (int) ($response['Pagination']['NumberOfPages'] ?? 1);
      $page++;
    } while ($page <= $pages);

    return $case_match;
  }

  /**
   * Get customer by code.
   *
   * @deprecated in commerce_unleashed:1.1.0 and is removed from
   *   commerce_unleashed:2.0.0. Use ::getCustomerByCode() instead, which
   *   matches the code exactly and reports a failed lookup as an error.
   * @see https://www.drupal.org/project/commerce_unleashed/issues/3624766
   */
  public function getCustomerByMail(string $mail): array {
    @trigger_error(__METHOD__ . '() is deprecated in commerce_unleashed:1.1.0 and is removed from commerce_unleashed:2.0.0. Use ::getCustomerByCode() instead.', E_USER_DEPRECATED);
    $customer = $this->getCustomerByCode($mail);

    return isset($customer['error']) ? [] : $customer;
  }

  /**
   * {@inheritdoc}
   */
  public function syncStockOnHand($page_number = NULL): void {
    $warehouses = $this->getStockWarehouses();
    if ($warehouses === []) {
      $this->syncAllWarehouseStock($page_number ?? 1);
      return;
    }

    $this->syncWarehouseStock($warehouses);
  }

  /**
   * {@inheritdoc}
   */
  public function getStockWarehouses(): array {
    $codes = (array) ($this->unleashedSettings()->get('stock.warehouses') ?? []);
    $codes = array_map(static fn ($code): string => trim((string) $code), $codes);

    return array_values(array_unique(array_filter($codes, static fn (string $code): bool => $code !== '')));
  }

  /**
   * Reads stock on hand across every warehouse, as Unleashed totals it.
   */
  protected function syncAllWarehouseStock(int $page): void {
    $query = 'pageSize=' . $this->getStockPageSize();

    do {
      $data = $this->unleashedClient->getStockOnHand($query, $page);
      if (!isset($data['Items']) || !is_array($data['Items'])) {
        $this->getLogger('commerce_unleashed')->error('Stock on hand page @page could not be read: @error', [
          '@page' => $page,
          '@error' => $data['error'] ?? 'no items in the response',
        ]);
        return;
      }

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

      $pages = (int) ($data['Pagination']['NumberOfPages'] ?? 1);
      $page++;
    } while ($page <= $pages);
  }

  /**
   * Reads stock on hand from the given warehouses only, summed per product.
   *
   * Unleashed's own total counts every warehouse, which is wrong wherever one
   * of them is not stock the store can sell from: a retail shop, a bonded
   * store, a returns bay. Each warehouse is read on its own and the figures
   * added up here.
   *
   * Nothing is written unless every warehouse was read. A partial read would
   * understate every product the missing warehouse holds, which looks like
   * stock disappearing. An empty read is not written either: it is far more
   * often a mistyped warehouse code than a store with nothing in it, and
   * writing it would zero the whole table.
   *
   * @param string[] $warehouses
   *   The warehouse codes whose stock counts.
   */
  protected function syncWarehouseStock(array $warehouses): void {
    $page_size = $this->getStockPageSize();
    $totals = [];

    foreach ($warehouses as $warehouse) {
      // Not URL-encoded: the request is signed over the query as given.
      $query = 'warehouseCode=' . $warehouse . '&pageSize=' . $page_size;
      $page = 1;

      do {
        $data = $this->unleashedClient->getStockOnHand($query, $page);
        if (!isset($data['Items']) || !is_array($data['Items'])) {
          $this->getLogger('commerce_unleashed')->error('Stock on hand for warehouse @warehouse could not be read (page @page): @error. Nothing was written.', [
            '@warehouse' => $warehouse,
            '@page' => $page,
            '@error' => $data['error'] ?? 'no items in the response',
          ]);
          return;
        }

        foreach ($data['Items'] as $item) {
          $code = trim((string) ($item['ProductCode'] ?? ''));
          if ($code === '') {
            continue;
          }
          // Filtered by the query already; checked again so a row from another
          // warehouse can never be counted.
          if (isset($item['WarehouseCode']) && strcasecmp(trim((string) $item['WarehouseCode']), $warehouse) !== 0) {
            continue;
          }

          $totals[$code] ??= ['guid' => '', 'available' => 0.0, 'allocated' => 0.0, 'on_hand' => 0.0];
          $totals[$code]['available'] += (float) ($item['AvailableQty'] ?? 0);
          $totals[$code]['allocated'] += (float) ($item['AllocatedQty'] ?? 0);
          $totals[$code]['on_hand'] += (float) ($item['QtyOnHand'] ?? 0);
          if ($totals[$code]['guid'] === '') {
            $totals[$code]['guid'] = trim((string) ($item['ProductGuid'] ?? ''));
          }
        }

        $pages = (int) ($data['Pagination']['NumberOfPages'] ?? 1);
        $page++;
      } while ($page <= $pages);
    }

    if ($totals === []) {
      $this->getLogger('commerce_unleashed')->warning('Unleashed returned no stock for warehouses @warehouses, so nothing was written. Check the warehouse codes in the Unleashed settings.', [
        '@warehouses' => implode(', ', $warehouses),
      ]);
      return;
    }

    $now = time();
    foreach ($totals as $code => $row) {
      $this->connection->merge(self::UNLEASHED_STOCK_TABLE)
        ->key('ProductCode', $code)
        ->fields([
          'ProductGuid' => $row['guid'],
          'AvailableQty' => $this->formatQuantity($row['available']),
          'AllocatedQty' => $this->formatQuantity($row['allocated']),
          'QtyOnHand' => $this->formatQuantity($row['on_hand']),
          'timestamp' => $now,
        ])
        ->execute();
    }

    // A product none of these warehouses holds is out of stock here, even if
    // another warehouse has it. Every product that was read now carries $now,
    // so anything older was not in the read.
    $this->connection->update(self::UNLEASHED_STOCK_TABLE)
      ->fields([
        'AvailableQty' => '0',
        'AllocatedQty' => '0',
        'QtyOnHand' => '0',
        'timestamp' => $now,
      ])
      ->condition('timestamp', $now, '<')
      ->execute();
  }

  /**
   * Formats a summed quantity for the varchar quantity columns.
   */
  protected function formatQuantity(float $quantity): string {
    $formatted = rtrim(rtrim(number_format($quantity, 4, '.', ''), '0'), '.');

    return $formatted === '-0' || $formatted === '' ? '0' : $formatted;
  }

  /**
   * {@inheritdoc}
   */
  public function getStockOnHand(ProductVariationInterface $product_variation): int {
    $stock = $this->connection->select(self::UNLEASHED_STOCK_TABLE, 's')->fields('s', ['AvailableQty'])->condition('ProductCode', $product_variation->getSku())->execute()->fetchField();
    // fetchField() returns FALSE for no row, never NULL, so an is_null()
    // guard never fires and an unknown SKU was cast to 0 — reported as out
    // of stock rather than as not tracked here.
    return $stock !== FALSE && $stock !== NULL
      ? (int) $stock
      : UnleashedManagerInterface::UNLEASHED_NON_MANAGED;
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
    return (string) ($this->unleashedSettings()->get('api_id') ?? '');
  }

  /**
   * {@inheritdoc}
   */
  public function getApiKey(): string {
    return (string) ($this->unleashedSettings()->get('api_key') ?? '');
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
  public function getProductsBaseQuery(): string {
    $parts = [];

    // Attributes are the reason this is not `brief=true`.
    //
    // `brief=true` returns seven fields - Guid, ProductCode,
    // ProductDescription, DefaultPurchasePrice, DefaultSellPrice,
    // SellPriceTier1, DefaultSupplierId - and nothing else: no ProductGroup,
    // no Supplier, no Obsolete, and no AttributeSet. It also SUPPRESSES
    // `includeAttributes`, so the two cannot be combined to get the best of
    // both; asking for attributes while brief is on returns none.
    //
    // Attribute sets are how Unleashed models the per-product flags an
    // integration actually keys on, so a sync that cannot see them cannot make
    // most of the decisions it exists to make. A full list read costs more per
    // page than a brief one, but it is still one request per page rather than
    // the one request PER PRODUCT that ::syncFullProduct() costs - and unlike
    // that, it does return the attribute set.
    if ($this->includeAttributes()) {
      $parts[] = 'includeAttributes=true';
    }
    else {
      $parts[] = 'brief=true';
    }

    // Obsolete products are excluded by the API unless asked for. Whether they
    // matter is a site decision: a storefront that only sells current lines
    // wants them gone, while anything reconciling against a back catalog needs
    // them, because an obsolete product in Unleashed is still a product the
    // store may hold stock of and have sold.
    if ($this->includeObsolete()) {
      $parts[] = 'includeObsolete=true';
    }

    return implode('&', $parts);
  }

  /**
   * {@inheritdoc}
   */
  public function includeAttributes(): bool {
    // Defaults to TRUE. The alternative is a sync that silently cannot see
    // attribute sets, which is the more surprising of the two behaviors.
    $value = $this->unleashedSettings()->get('products.include_attributes');

    return $value === NULL ? TRUE : (bool) $value;
  }

  /**
   * {@inheritdoc}
   */
  public function includeObsolete(): bool {
    return (bool) $this->unleashedSettings()->get('products.include_obsolete');
  }

  /**
   * {@inheritdoc}
   */
  public function getProductsPageSize(string $query = ''): int {
    // A read carrying modifiedSince is a delta; anything else walks the whole
    // catalog. Derived from the query rather than passed in, so cron, Drush
    // and any other caller get the same treatment without having to say so.
    $is_delta = stripos($query, 'modifiedSince') !== FALSE;
    $key = $is_delta ? 'products.page_size' : 'products.page_size_full';
    $default = $is_delta ? self::DEFAULT_PAGE_SIZE : self::FULL_SYNC_PAGE_SIZE;

    return $this->clampPageSize($this->unleashedSettings()->get($key), $default);
  }

  /**
   * {@inheritdoc}
   */
  public function getStockPageSize(): int {
    return $this->clampPageSize(
      $this->unleashedSettings()->get('stock.page_size'),
      self::DEFAULT_PAGE_SIZE
    );
  }

  /**
   * Applies a page size to a query, leaving an explicit one alone.
   */
  protected function withPageSize(string $query, int $page_size): string {
    if (stripos($query, 'pageSize') !== FALSE) {
      return $query;
    }

    return $query === '' ? 'pageSize=' . $page_size : $query . '&pageSize=' . $page_size;
  }

  /**
   * Keeps a configured page size inside the range the API accepts.
   */
  protected function clampPageSize(mixed $configured, int $default): int {
    $size = is_numeric($configured) ? (int) $configured : $default;

    return max(self::MIN_PAGE_SIZE, min($size, self::MAX_PAGE_SIZE));
  }

  /**
   * {@inheritdoc}
   */
  public function getProductType(): string {
    // Falls back to the variation type so that a site upgrading from before
    // this setting existed behaves exactly as it did.
    $product_type = (string) ($this->unleashedSettings()->get('products.product_type') ?? '');

    return $product_type !== '' ? $product_type : $this->getVariationType();
  }

  /**
   * {@inheritdoc}
   */
  public function syncPrice(): bool {
    // Defaults to TRUE: before this setting existed the price was always
    // written, and a site that has not opted out should keep that behavior.
    $sync = $this->unleashedSettings()->get('products.price_sync');

    return $sync === NULL ? TRUE : (bool) $sync;
  }

  /**
   * {@inheritdoc}
   */
  public function getPriceField(): string {
    $field = (string) ($this->unleashedSettings()->get('products.price_field') ?? '');

    return $field !== '' ? $field : 'DefaultSellPrice';
  }

  /**
   * {@inheritdoc}
   */
  public function getPayloadPrice(array $payload): ?string {
    $value = $payload[$this->getPriceField()] ?? NULL;

    // Sell price tiers are objects — {Value, ...} — while DefaultSellPrice is
    // a bare number. Both are accepted so the setting can name either.
    if (is_array($value)) {
      $value = $value['Value'] ?? NULL;
    }

    if ($value === NULL || $value === '') {
      return NULL;
    }

    return (string) $value;
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
   * The status an order is created with in Unleashed.
   *
   * Configurable because it is a workflow decision rather than a fact about
   * the order: a site whose warehouse picks from parked orders wants 'Parked',
   * and one that treats the push as the final word wants 'Placed'.
   */
  public function getOrderStatus(string $type = self::UNLEASHED_SALES_ORDERS): string {
    $status = trim((string) ($this->unleashedSettings()->get(sprintf('%s_orders.order_status', $type)) ?? ''));

    return $status !== '' ? $status : 'Placed';
  }

  /**
   * {@inheritdoc}
   */
  public function getShippingSku(OrderInterface $order, ?ShipmentInterface $shipment = NULL): string {
    // The shipping method's own code first. Delivery is often billed against
    // different Unleashed carriage products by service and destination, and a
    // single site-wide code would bill an international parcel as domestic.
    $method = $shipment?->getShippingMethod();
    if ($method !== NULL && $method->hasField(self::CARRIAGE_FIELD) && !$method->get(self::CARRIAGE_FIELD)->isEmpty()) {
      $code = trim((string) $method->get(self::CARRIAGE_FIELD)->value);
      if ($code !== '') {
        return $code;
      }
    }

    // Otherwise the site-wide default, which is also what an unmapped or newly
    // created method falls back to.
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
