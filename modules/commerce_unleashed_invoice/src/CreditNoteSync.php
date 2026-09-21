<?php

declare(strict_types=1);

namespace Drupal\commerce_unleashed_invoice;

use Drupal\commerce_order\Adjustment;
use Drupal\commerce_order\Entity\OrderInterface;
use Drupal\commerce_price\Price;
use Drupal\commerce_unleashed\UnleashedManagerInterface;
use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\KeyValueStore\KeyValueFactoryInterface;
use Drupal\Core\Logger\LoggerChannelInterface;

/**
 * Mirrors Unleashed credit notes onto Commerce invoices.
 *
 * Paged rather than fetched per order, which is the opposite of how invoices
 * are handled here, and not by preference: the CreditNotes endpoint accepts
 * `orderNumber` and `invoiceNumber` and then IGNORES them, returning the whole
 * set either way. There is no way to ask for one order's credits, so the only
 * honest approach is to walk the list and match locally.
 *
 * That is affordable because credit notes are rare next to invoices - a few
 * thousand against nearly a million - and `modifiedSince` narrows a repeat run
 * to what has changed.
 *
 * Only credits that can be attached to an order here are mirrored. Most of a
 * tenant's credits are raised against orders this site has never seen, and a
 * `FreeCredit` has no order at all; mirroring those would fill the invoice
 * list with records belonging to nobody. The count is reported so a run that
 * skips most of what it read says so rather than looking like it did nothing.
 */
final class CreditNoteSync {

  /**
   * The invoice type credit notes are mirrored into.
   */
  public const CREDIT_TYPE = 'unleashed_credit';

  /**
   * Where the last completed run is remembered, for the next delta.
   */
  private const STATE_KEY = 'credit_notes';

  /**
   * How many credit notes to ask for per request.
   */
  private const PAGE_SIZE = 200;

  public function __construct(
    private readonly UnleashedManagerInterface $unleashedManager,
    private readonly EntityTypeManagerInterface $entityTypeManager,
    private readonly ConfigFactoryInterface $configFactory,
    private readonly KeyValueFactoryInterface $keyValueFactory,
    private readonly LoggerChannelInterface $logger,
  ) {}

  /**
   * Reads credit notes and mirrors them.
   *
   * @param bool $full
   *   TRUE reads every credit note; FALSE reads only what changed since the
   *   last completed run.
   * @param string|null $since
   *   An explicit YYYY-MM-DD to read from, overriding both.
   *
   * @return array{read: int, created: int, updated: int, unchanged: int, skipped: int, requests: int, failed: bool}
   *   What the run did.
   */
  public function sync(bool $full = FALSE, ?string $since = NULL): array {
    $result = [
      'read' => 0,
      'created' => 0,
      'updated' => 0,
      'unchanged' => 0,
      'skipped' => 0,
      'requests' => 0,
      'failed' => FALSE,
    ];

    $query = 'pageSize=' . self::PAGE_SIZE;
    $from = $since ?? ($full ? NULL : $this->lastRun());
    if ($from !== NULL) {
      $query .= '&modifiedSince=' . $from;
    }

    $started = time();
    $page = 1;

    do {
      $result['requests']++;
      $response = $this->read($query, $page);
      if ($response === NULL) {
        $result['failed'] = TRUE;

        return $result;
      }

      foreach ($response['Items'] ?? [] as $item) {
        if (!is_array($item)) {
          continue;
        }
        $result['read']++;
        $outcome = $this->apply(new UnleashedCreditNotePayload($item));
        $result[$outcome]++;
      }

      $pages = (int) ($response['Pagination']['NumberOfPages'] ?? 1);
      $page++;
    } while ($page <= $pages);

    // Only a run that finished sets the marker. Recording a partial one would
    // mean the pages it never reached are never looked at again, because a
    // delta from now on will not mention a credit note that did not change.
    $this->keyValueFactory->get('commerce_unleashed')->set(self::STATE_KEY, $started);

    $this->logger->info('Credit notes (@mode): @read read, @created created, @updated updated, @unchanged unchanged, @skipped skipped, @requests request(s).', [
      '@mode' => $from === NULL ? 'full' : 'since ' . $from,
      '@read' => $result['read'],
      '@created' => $result['created'],
      '@updated' => $result['updated'],
      '@unchanged' => $result['unchanged'],
      '@skipped' => $result['skipped'],
      '@requests' => $result['requests'],
    ]);

    return $result;
  }

  /**
   * The date the last completed run started, as YYYY-MM-DD.
   */
  private function lastRun(): ?string {
    $timestamp = $this->keyValueFactory->get('commerce_unleashed')->get(self::STATE_KEY);

    // A day is taken off on purpose: modifiedSince is date-granular, so
    // reading from the exact day of the last run can miss a note modified
    // later that same day.
    return $timestamp ? date('Y-m-d', (int) $timestamp - 86400) : NULL;
  }

  /**
   * Reads one page of credit notes.
   *
   * @return array<string, mixed>|null
   *   The decoded page, or NULL when it could not be read.
   */
  private function read(string $query, int $page): ?array {
    try {
      $response = $this->unleashedManager->getClient()->getCreditNotes($query, $page);
    }
    catch (\Throwable $e) {
      $this->logger->error('Credit note read failed on page @page: @message', [
        '@page' => $page,
        '@message' => $e->getMessage(),
      ]);

      return NULL;
    }

    if (isset($response['error'])) {
      $this->logger->error('Credit note read refused on page @page: @message', [
        '@page' => $page,
        '@message' => (string) $response['error'],
      ]);

      return NULL;
    }

    return $response;
  }

  /**
   * Creates or updates the invoice for one credit note.
   *
   * @return string
   *   'created', 'updated', 'unchanged', or 'skipped' when the credit has no
   *   order in this site to belong to.
   */
  private function apply(UnleashedCreditNotePayload $payload): string {
    $number = $payload->creditNumber();
    if ($number === '') {
      return 'skipped';
    }

    $order = $this->resolveOrder($payload);
    $storage = $this->entityTypeManager->getStorage('commerce_invoice');
    $invoice = $this->loadCredit($number);
    $new = $invoice === NULL;

    if ($order === NULL) {
      // A credit this site cannot attach to an order is not mirrored. Most
      // credits in a tenant are raised against orders Drupal has never seen -
      // POS sales, and orders older or newer than whatever was migrated - and
      // a free credit has no order at all. Mirroring those would fill the
      // invoice list with records belonging to nobody, reachable from nothing.
      //
      // A credit already mirrored is LEFT AS IT IS rather than deleted: the
      // order may simply be absent from this run's view, and removing a
      // record a customer has seen is worse than keeping a stale one.
      return 'skipped';
    }

    if ($new) {
      $invoice = $storage->create([
        'type' => self::CREDIT_TYPE,
        'store_id' => $order->getStoreId(),
        'uid' => $order->getCustomerId(),
        'mail' => $order->getEmail(),
      ]);
    }

    $before = $new ? NULL : [
      $invoice->getState()->getId(),
      (string) $invoice->getTotalPrice()?->getNumber(),
    ];

    $invoice->setInvoiceNumber($number);
    $invoice->set('state', $payload->state());
    $invoice->set('invoice_date', $payload->creditDate());
    $invoice->set('orders', [$order->id()]);
    $invoice->set('data', [
      'unleashed' => [
        'guid' => $payload->guid(),
        'status' => $payload->status(),
        'credit_type' => $payload->isFreeCredit() ? UnleashedCreditNotePayload::FREE_CREDIT : 'Credit',
        'customer_code' => $payload->customerCode(),
        'invoice_number' => $payload->invoiceNumber(),
        'order_number' => $payload->orderNumber(),
        'last_modified' => $payload->lastModified(),
      ],
    ]);

    $this->setItems($invoice, $payload);
    $this->setTax($invoice, $payload);
    $invoice->save();

    if ($new) {
      return 'created';
    }

    return $before === [
      $invoice->getState()->getId(),
      (string) $invoice->getTotalPrice()?->getNumber(),
    ] ? 'unchanged' : 'updated';
  }

  /**
   * The order a credit note belongs to, when there is one.
   */
  private function resolveOrder(UnleashedCreditNotePayload $payload): ?OrderInterface {
    $number = $payload->orderNumber();
    if ($number === '' || $payload->isFreeCredit()) {
      return NULL;
    }

    $ids = $this->entityTypeManager->getStorage('commerce_order')->getQuery()
      ->accessCheck(FALSE)
      ->condition('order_number', $number)
      ->range(0, 1)
      ->execute();

    if (!$ids) {
      return NULL;
    }

    $order = $this->entityTypeManager->getStorage('commerce_order')->load(reset($ids));

    return $order instanceof OrderInterface ? $order : NULL;
  }

  /**
   * Loads a mirrored credit note by its number.
   */
  private function loadCredit(string $number): ?object {
    $storage = $this->entityTypeManager->getStorage('commerce_invoice');
    $ids = $storage->getQuery()
      ->accessCheck(FALSE)
      ->condition('type', self::CREDIT_TYPE)
      ->condition('invoice_number', $number)
      ->range(0, 1)
      ->execute();

    return $ids ? $storage->load(reset($ids)) : NULL;
  }

  /**
   * Replaces the credit lines.
   */
  private function setItems(object $invoice, UnleashedCreditNotePayload $payload): void {
    $item_storage = $this->entityTypeManager->getStorage('commerce_invoice_item');
    $currency = $payload->currencyCode();
    $existing = $invoice->get('invoice_items')->referencedEntities();
    $items = [];

    foreach ($payload->lines() as $line) {
      $item = $item_storage->create([
        'type' => InvoiceSync::ITEM_TYPE,
        'title' => $line['title'],
        'quantity' => $line['quantity'],
        'unit_price' => new Price($line['unit_price'], $currency),
        'data' => [
          'unleashed' => [
            'sku' => $line['sku'],
            'tax' => $line['tax'],
            'reason' => $line['reason'],
            'returned_to_stock' => $line['returned'],
          ],
        ],
      ]);
      $item->save();
      $items[] = $item;
    }

    $invoice->set('invoice_items', $items);

    if ($existing) {
      $item_storage->delete($existing);
    }
  }

  /**
   * Puts the credited tax on as a single adjustment.
   */
  private function setTax(object $invoice, UnleashedCreditNotePayload $payload): void {
    $tax = $payload->taxTotal();
    $invoice->set('adjustments', []);

    if ((float) $tax === 0.0) {
      return;
    }

    $invoice->addAdjustment(new Adjustment([
      'type' => 'tax',
      'label' => 'Tax',
      'amount' => new Price($tax, $payload->currencyCode()),
      'source_id' => 'unleashed|' . $payload->creditNumber(),
      'included' => FALSE,
    ]));
  }

  /**
   * Whether credit note mirroring is switched on.
   */
  public function enabled(): bool {
    return (bool) $this->configFactory->get('commerce_unleashed_invoice.settings')->get('credit_notes');
  }

}
