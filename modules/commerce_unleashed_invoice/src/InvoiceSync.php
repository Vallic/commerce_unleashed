<?php

declare(strict_types=1);

namespace Drupal\commerce_unleashed_invoice;

use Drupal\commerce_order\Entity\OrderInterface;
use Drupal\commerce_order\Adjustment;
use Drupal\commerce_price\Price;
use Drupal\commerce_unleashed\UnleashedManagerInterface;
use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Logger\LoggerChannelInterface;

/**
 * Mirrors one order's Unleashed invoice onto a Commerce invoice.
 *
 * Read, never write. The Unleashed Invoices endpoint exposes no create or
 * update operation, so there is nothing this site could push even if it
 * wanted to - which is what makes Unleashed the source of truth rather than a
 * peer. Everything here follows from that:
 *
 *  - The invoice is fetched BY ORDER NUMBER, one request per order. The
 *    tenant holds 881,000 invoices; mirroring it wholesale would be 4,400
 *    requests to build a copy of something nobody asked to browse. An order
 *    is the only unit anyone actually looks at.
 *  - The state comes from the payload, not from anything happening here. A
 *    local transition would be undone by the next read.
 *  - An invoice that has gone from Unleashed is marked deleted rather than
 *    removed, because a record a customer has already seen should not
 *    silently disappear.
 */
final class InvoiceSync {

  /**
   * The invoice type this module ships and owns.
   */
  public const INVOICE_TYPE = 'unleashed';

  /**
   * The invoice item bundle.
   *
   * Commerce_invoice declares invoice item bundles from the PURCHASABLE
   * entity types, not from invoice types, so there is no 'unleashed' bundle
   * to create items in.
   *
   * @see commerce_invoice_entity_bundle_info()
   */
  public const ITEM_TYPE = 'commerce_product_variation';

  public function __construct(
    private readonly UnleashedManagerInterface $unleashedManager,
    private readonly EntityTypeManagerInterface $entityTypeManager,
    private readonly ConfigFactoryInterface $configFactory,
    private readonly LoggerChannelInterface $logger,
  ) {}

  /**
   * Reads an order's invoice from Unleashed and mirrors it.
   *
   * @param \Drupal\commerce_order\Entity\OrderInterface $order
   *   The order to read the invoice for.
   *
   * @return string
   *   What happened: 'created', 'updated', 'unchanged', 'deleted', 'none' when
   *   Unleashed has no invoice for the order yet, or 'failed'.
   */
  public function syncOrder(OrderInterface $order): string {
    $number = (string) ($order->getOrderNumber() ?? $order->id());
    if ($number === '') {
      return 'none';
    }

    $response = $this->read($number);
    if ($response === NULL) {
      return 'failed';
    }

    $items = $response['Items'] ?? [];
    if ($items === []) {
      // Nothing came back. That is either an order Unleashed has not invoiced
      // yet, or one whose invoice has been deleted - and those are not the
      // same thing, so an existing mirror is retired while an order that
      // never had one is simply left alone.
      return $this->retire($order) ? 'deleted' : 'none';
    }

    return $this->apply($order, new UnleashedInvoicePayload(reset($items)));
  }

  /**
   * Reads the invoices for one order number.
   *
   * @return array<string, mixed>|null
   *   The decoded response, or NULL when the read failed.
   */
  private function read(string $order_number): ?array {
    try {
      $response = $this->unleashedManager->getClient()
        ->getInvoices('orderNumber=' . rawurlencode($order_number));
    }
    catch (\Throwable $e) {
      $this->logger->error('Invoice read failed for order @order: @message', [
        '@order' => $order_number,
        '@message' => $e->getMessage(),
      ]);

      return NULL;
    }

    if (isset($response['error'])) {
      $this->logger->error('Invoice read refused for order @order: @message', [
        '@order' => $order_number,
        '@message' => (string) $response['error'],
      ]);

      return NULL;
    }

    return $response;
  }

  /**
   * Creates or updates the invoice for an order.
   */
  private function apply(OrderInterface $order, UnleashedInvoicePayload $payload): string {
    $invoice = $this->loadInvoice($order, $payload->invoiceNumber());
    $storage = $this->entityTypeManager->getStorage('commerce_invoice');
    $new = $invoice === NULL;

    if ($new) {
      $invoice = $storage->create([
        'type' => self::INVOICE_TYPE,
        'store_id' => $order->getStoreId(),
        'uid' => $order->getCustomerId(),
        'mail' => $order->getEmail(),
        'orders' => [$order->id()],
      ]);
    }

    $state = $payload->state();

    // Only the fields Unleashed owns are touched; a billing profile or a file
    // attached locally is left as it is.
    $before = $new ? NULL : [
      $invoice->getState()->getId(),
      (string) $invoice->getTotalPrice()?->getNumber(),
      (string) $invoice->get('invoice_date')->value,
      (string) $invoice->getInvoiceNumber(),
    ];

    $invoice->setInvoiceNumber($payload->invoiceNumber());
    $invoice->set('state', $state);
    $invoice->set('invoice_date', $payload->invoiceDate());
    $invoice->set('due_date', $payload->dueDate());
    $invoice->set('data', [
      'unleashed' => [
        'guid' => $payload->guid(),
        'status' => $payload->status(),
        'customer_code' => $payload->customerCode(),
        'payment_received' => $payload->paymentReceived(),
        'last_modified' => $payload->lastModified(),
      ],
    ]);

    $this->setItems($invoice, $payload);
    $this->setTax($invoice, $payload);
    $invoice->save();
    $this->checkTotal($invoice, $payload);

    if ($new) {
      return 'created';
    }

    $after = [
      $invoice->getState()->getId(),
      (string) $invoice->getTotalPrice()?->getNumber(),
      (string) $invoice->get('invoice_date')->value,
      (string) $invoice->getInvoiceNumber(),
    ];

    return $before === $after ? 'unchanged' : 'updated';
  }

  /**
   * Replaces the invoice items with what the payload holds.
   *
   * Rebuilt rather than reconciled line by line: Unleashed has no stable
   * identity for a line that this site could match on, and an invoice is
   * small. The old items are deleted so they do not outlive the invoice they
   * belonged to.
   */
  private function setItems(object $invoice, UnleashedInvoicePayload $payload): void {
    $item_storage = $this->entityTypeManager->getStorage('commerce_invoice_item');
    $currency = $payload->currencyCode();

    $existing = $invoice->get('invoice_items')->referencedEntities();
    $items = [];

    foreach ($payload->lines() as $line) {
      // The only bundle commerce_invoice declares for an invoice item is the
      // purchasable entity type; there is no per-invoice-type bundle, and no
      // purchasable entity reference to fill in, so the SKU is kept in data.
      $item = $item_storage->create([
        'type' => self::ITEM_TYPE,
        'title' => $line['title'],
        'quantity' => $line['quantity'],
        'unit_price' => new Price($line['unit_price'], $currency),
        'data' => [
          'unleashed' => [
            'sku' => $line['sku'],
            'list_price' => $line['list_price'],
            'discount_rate' => $line['discount_rate'],
            'tax' => $line['tax'],
          ],
        ],
      ]);
      // No total is set: commerce_invoice recalculates it as unit price x
      // quantity on every save, which is why the unit price carried here is
      // already the discounted one.
      $item->save();
      $items[] = $item;
    }

    $invoice->set('invoice_items', $items);

    if ($existing) {
      $item_storage->delete($existing);
    }
  }

  /**
   * Puts the invoice tax on as a single adjustment.
   *
   * Invoice lines are net, and an invoice total is derived from its items
   * plus adjustments - it cannot simply be assigned. One adjustment carrying
   * Unleashed's TaxTotal is therefore what makes the mirrored total come out
   * at the figure Unleashed reports, and it is one adjustment rather than one
   * per line because the invoice states a single tax total and that is the
   * number that has to be reproduced.
   */
  private function setTax(object $invoice, UnleashedInvoicePayload $payload): void {
    $tax = $payload->taxTotal();
    $invoice->set('adjustments', []);

    if ((float) $tax === 0.0) {
      return;
    }

    $invoice->addAdjustment(new Adjustment([
      'type' => 'tax',
      'label' => 'Tax',
      'amount' => new Price($tax, $payload->currencyCode()),
      'source_id' => 'unleashed|' . $payload->invoiceNumber(),
      'included' => FALSE,
    ]));
  }

  /**
   * Warns when the mirrored total does not match what Unleashed reported.
   *
   * The total is built up from lines and tax rather than copied, so it can in
   * principle disagree - a line Unleashed rounded differently, or a charge
   * that lives on the invoice but not on any line. Worth knowing about rather
   * than silently presenting a figure the supplier's own system disputes.
   */
  private function checkTotal(object $invoice, UnleashedInvoicePayload $payload): void {
    $mirrored = (float) ($invoice->getTotalPrice()?->getNumber() ?? 0);
    $reported = (float) $payload->total();

    if (abs($mirrored - $reported) < 0.005) {
      return;
    }

    $this->logger->warning('Invoice @number totals @mirrored here but @reported in Unleashed.', [
      '@number' => $payload->invoiceNumber(),
      '@mirrored' => number_format($mirrored, 2, '.', ''),
      '@reported' => number_format($reported, 2, '.', ''),
    ]);
  }

  /**
   * Marks an order's invoice deleted, if it still holds one.
   *
   * @return bool
   *   TRUE when an invoice was retired.
   */
  private function retire(OrderInterface $order): bool {
    $invoice = $this->loadInvoice($order, NULL);
    if ($invoice === NULL || $invoice->getState()->getId() === 'deleted') {
      return FALSE;
    }

    $invoice->set('state', 'deleted');
    $invoice->save();

    $this->logger->notice('Invoice @number is no longer in Unleashed; marked deleted.', [
      '@number' => $invoice->getInvoiceNumber(),
    ]);

    return TRUE;
  }

  /**
   * Loads this module's invoice for an order.
   */
  private function loadInvoice(OrderInterface $order, ?string $invoice_number): ?object {
    $storage = $this->entityTypeManager->getStorage('commerce_invoice');
    $query = $storage->getQuery()
      ->accessCheck(FALSE)
      ->condition('type', self::INVOICE_TYPE)
      ->condition('orders', $order->id())
      ->range(0, 1);

    if ($invoice_number !== NULL && $invoice_number !== '') {
      // Matching on the number as well would miss an invoice Unleashed has
      // renumbered, so it is only used to disambiguate when one is given.
      $query->condition('invoice_number', $invoice_number);
    }

    $ids = $query->execute();
    if (!$ids) {
      return $invoice_number === NULL ? NULL : $this->loadInvoice($order, NULL);
    }

    return $storage->load(reset($ids));
  }

  /**
   * Whether mirroring is switched on.
   */
  public function enabled(): bool {
    return (bool) $this->settings()->get('sync');
  }

  /**
   * The order states a read is triggered by.
   *
   * @return string[]
   *   Order state IDs.
   */
  public function triggerStates(): array {
    $states = $this->settings()->get('order_states') ?? [];

    return array_values(array_filter(array_map('strval', $states)));
  }

  /**
   * Whether the read is queued rather than made during the request.
   */
  public function queueOnTransition(): bool {
    $value = $this->settings()->get('queue_on_transition');

    return $value === NULL ? TRUE : (bool) $value;
  }

  /**
   * The module settings.
   */
  private function settings(): object {
    return $this->configFactory->get('commerce_unleashed_invoice.settings');
  }

}
