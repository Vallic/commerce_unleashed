<?php

declare(strict_types=1);

namespace Drupal\commerce_unleashed_invoice;

/**
 * One Unleashed sales invoice, read.
 *
 * Unleashed dates arrive as `/Date(1789987161000)/` - milliseconds since the
 * epoch wrapped in Microsoft's JSON date format - and money arrives as floats.
 * Both are converted here rather than at each call site, so the sync deals in
 * timestamps and numeric strings.
 */
final class UnleashedInvoicePayload {

  /**
   * Drupal states, keyed by the Unleashed status that produces them.
   *
   * Only the statuses Unleashed actually emits. Anything unrecognized is
   * treated as parked rather than guessed at: parked is the state that claims
   * least, and a mirror that invents "completed" for a status it does not know
   * would be asserting something the source never said.
   */
  private const STATES = [
    'parked' => 'parked',
    'completed' => 'completed',
    'deleted' => 'deleted',
  ];

  public function __construct(
    private readonly array $payload,
  ) {}

  /**
   * The Drupal invoice state for an Unleashed status.
   *
   * Pure, and public because it is the rule the workflow is built around.
   *
   * Payment outranks status: an invoice Unleashed reports as paid is paid
   * whatever else it says, and `deleted` outranks even that - a deleted
   * invoice is not a paid one, whatever the payment flag still holds.
   *
   * @param string|null $status
   *   The Unleashed InvoiceStatus.
   * @param bool $payment_received
   *   The Unleashed PaymentReceived flag.
   */
  public static function stateFrom(?string $status, bool $payment_received = FALSE): string {
    $state = self::STATES[strtolower(trim((string) $status))] ?? 'parked';

    if ($state === 'deleted') {
      return 'deleted';
    }

    return $payment_received ? 'paid' : $state;
  }

  /**
   * The state this invoice belongs in.
   */
  public function state(): string {
    return self::stateFrom($this->string('InvoiceStatus'), $this->paymentReceived());
  }

  /**
   * The Unleashed status, verbatim.
   */
  public function status(): string {
    return $this->string('InvoiceStatus');
  }

  /**
   * The invoice number, which is the number this site shows.
   */
  public function invoiceNumber(): string {
    return $this->string('InvoiceNumber');
  }

  /**
   * The sales order this invoice belongs to.
   */
  public function orderNumber(): string {
    return $this->string('OrderNumber');
  }

  /**
   * Unleashed's own identifier, kept so a record can be traced back.
   */
  public function guid(): string {
    return $this->string('Guid');
  }

  /**
   * The customer code, which is how a trade account is identified.
   */
  public function customerCode(): string {
    return trim((string) ($this->payload['Customer']['CustomerCode'] ?? ''));
  }

  /**
   * Whether Unleashed has recorded payment.
   */
  public function paymentReceived(): bool {
    return !empty($this->payload['PaymentReceived']);
  }

  /**
   * The invoice total, as a numeric string.
   */
  public function total(): string {
    return $this->money('Total');
  }

  /**
   * The net total, before tax.
   */
  public function subTotal(): string {
    return $this->money('SubTotal');
  }

  /**
   * The tax charged across the invoice.
   */
  public function taxTotal(): string {
    return $this->money('TaxTotal');
  }

  /**
   * The currency the invoice is in.
   */
  public function currencyCode(): string {
    $code = trim((string) ($this->payload['Currency']['CurrencyCode'] ?? ''));

    return $code !== '' ? $code : 'GBP';
  }

  /**
   * When the invoice was raised, as a timestamp.
   */
  public function invoiceDate(): ?int {
    return $this->timestamp('InvoiceDate');
  }

  /**
   * When the invoice falls due, as a timestamp.
   */
  public function dueDate(): ?int {
    return $this->timestamp('DueDate');
  }

  /**
   * When Unleashed last touched the invoice.
   */
  public function lastModified(): ?int {
    return $this->timestamp('LastModifiedOn');
  }

  /**
   * The invoice lines.
   *
   * @return array<int, array{sku: string, title: string, quantity: string, unit_price: string, list_price: string, discount_rate: string, total: string, tax: string}>
   *   One entry per line that carries a product and a quantity.
   */
  public function lines(): array {
    $lines = [];

    foreach ($this->payload['InvoiceLines'] ?? [] as $line) {
      if (!is_array($line)) {
        continue;
      }
      $quantity = (float) ($line['InvoiceQuantity'] ?? $line['OrderQuantity'] ?? 0);
      if ($quantity <= 0) {
        continue;
      }
      $sku = trim((string) ($line['Product']['ProductCode'] ?? ''));
      $title = trim((string) ($line['Product']['ProductDescription'] ?? ''));
      $total = (float) ($line['LineTotal'] ?? 0);
      $lines[] = [
        'sku' => $sku,
        // A line with no description still has to render as something; the
        // product code is the only other thing that identifies it.
        'title' => $title !== '' ? $title : $sku,
        'quantity' => (string) $quantity,
        // The EFFECTIVE unit price, after any discount. commerce_invoice
        // recalculates an item's total as unit price x quantity on every
        // save, so a list price here would quietly contradict the line total
        // Unleashed reports on any discounted line. Kept to four decimal
        // places so the multiplication lands back on the line total exactly.
        'unit_price' => self::number($total / $quantity),
        'list_price' => self::number($line['UnitPrice'] ?? 0),
        'discount_rate' => self::number($line['DiscountRate'] ?? 0),
        'total' => self::number($total),
        'tax' => self::number($line['LineTax'] ?? 0),
      ];
    }

    return $lines;
  }

  /**
   * Reads a trimmed string field.
   */
  private function string(string $key): string {
    return trim((string) ($this->payload[$key] ?? ''));
  }

  /**
   * Reads a money field as a numeric string.
   */
  private function money(string $key): string {
    return self::number($this->payload[$key] ?? 0);
  }

  /**
   * Formats a number the way commerce_price expects one.
   */
  private static function number(mixed $value): string {
    return number_format((float) $value, 4, '.', '');
  }

  /**
   * Converts an Unleashed date to a timestamp.
   *
   * `/Date(1789987161000)/`, occasionally with a trailing offset. Only the
   * milliseconds matter: they are already UTC.
   */
  private function timestamp(string $key): ?int {
    $raw = (string) ($this->payload[$key] ?? '');
    if ($raw === '' || !preg_match('#/Date\((-?\d+)#', $raw, $matches)) {
      return NULL;
    }

    return (int) round(((int) $matches[1]) / 1000);
  }

}
