<?php

declare(strict_types=1);

namespace Drupal\commerce_unleashed_invoice;

/**
 * One Unleashed credit note, read.
 *
 * Shaped like an invoice but not identical, and the differences matter:
 *
 *  - The number is `CreditNoteNumber` and the status is `Status`, not the
 *    `CreditNumber`/`CreditStatus` the API documentation names.
 *  - A line carries `CreditQuantity` and `CreditPrice`, not an invoice
 *    quantity and unit price.
 *  - `CreditType` is `Credit` or `FreeCredit`. A free credit has no sales
 *    order behind it, so it cannot be attached to an order here.
 */
final class UnleashedCreditNotePayload {

  /**
   * The credit type that stands alone, with no sales order.
   */
  public const FREE_CREDIT = 'FreeCredit';

  public function __construct(
    private readonly array $payload,
  ) {}

  /**
   * The credit note number, which is the number this site shows.
   */
  public function creditNumber(): string {
    return $this->string('CreditNoteNumber');
  }

  /**
   * The Unleashed status, verbatim.
   */
  public function status(): string {
    return $this->string('Status');
  }

  /**
   * The state this credit note belongs in.
   *
   * Shares the invoice mapping: credit notes move through the same statuses,
   * and a separate vocabulary for the same words would only invite the two to
   * drift apart. Credit notes carry no payment flag, so payment never applies.
   */
  public function state(): string {
    return UnleashedInvoicePayload::stateFrom($this->status());
  }

  /**
   * Whether this credit stands alone, with no sales order behind it.
   */
  public function isFreeCredit(): bool {
    return strcasecmp($this->string('CreditType'), self::FREE_CREDIT) === 0;
  }

  /**
   * The sales order this credit belongs to, if any.
   */
  public function orderNumber(): string {
    return trim((string) ($this->payload['SalesOrder']['OrderNumber'] ?? ''));
  }

  /**
   * The invoice being credited, if any.
   */
  public function invoiceNumber(): string {
    return $this->string('InvoiceNumber');
  }

  /**
   * Unleashed's own identifier.
   */
  public function guid(): string {
    return $this->string('Guid');
  }

  /**
   * The customer code.
   */
  public function customerCode(): string {
    return trim((string) ($this->payload['Customer']['CustomerCode'] ?? ''));
  }

  /**
   * The credit total.
   */
  public function total(): string {
    return $this->money('Total');
  }

  /**
   * The tax credited.
   */
  public function taxTotal(): string {
    return $this->money('TaxTotal');
  }

  /**
   * The currency the credit is in.
   */
  public function currencyCode(): string {
    $code = trim((string) ($this->payload['Currency']['CurrencyCode'] ?? ''));

    return $code !== '' ? $code : 'GBP';
  }

  /**
   * When the credit was raised.
   */
  public function creditDate(): ?int {
    return $this->timestamp('CreditDate');
  }

  /**
   * When Unleashed last touched the credit note.
   */
  public function lastModified(): ?int {
    return $this->timestamp('LastModifiedOn');
  }

  /**
   * The credit lines.
   *
   * @return array<int, array{sku: string, title: string, quantity: string, unit_price: string, total: string, tax: string, reason: string, returned: bool}>
   *   One entry per line that credits something.
   */
  public function lines(): array {
    $lines = [];

    foreach ($this->payload['CreditLines'] ?? [] as $line) {
      if (!is_array($line)) {
        continue;
      }
      $quantity = (float) ($line['CreditQuantity'] ?? 0);
      if ($quantity <= 0) {
        continue;
      }
      $sku = trim((string) ($line['Product']['ProductCode'] ?? ''));
      $title = trim((string) ($line['Product']['ProductDescription'] ?? ''));
      $total = (float) ($line['LineTotal'] ?? 0);
      $lines[] = [
        'sku' => $sku,
        'title' => $title !== '' ? $title : $sku,
        'quantity' => (string) $quantity,
        // Effective unit price, for the same reason as an invoice line: the
        // item total is recalculated as unit price x quantity on save.
        'unit_price' => self::number($total / $quantity),
        'total' => self::number($total),
        'tax' => self::number($line['LineTax'] ?? 0),
        'reason' => trim((string) ($line['Reason'] ?? '')),
        'returned' => !empty($line['ReturnToStock']),
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
   */
  private function timestamp(string $key): ?int {
    $raw = (string) ($this->payload[$key] ?? '');
    if ($raw === '' || !preg_match('#/Date\((-?\d+)#', $raw, $matches)) {
      return NULL;
    }

    return (int) round(((int) $matches[1]) / 1000);
  }

}
