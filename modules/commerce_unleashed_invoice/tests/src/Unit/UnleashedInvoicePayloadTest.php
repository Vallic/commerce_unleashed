<?php

declare(strict_types=1);

namespace Drupal\Tests\commerce_unleashed_invoice\Unit;

use Drupal\commerce_unleashed_invoice\UnleashedInvoicePayload;
use Drupal\Tests\UnitTestCase;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Group;

/**
 * Tests reading an Unleashed invoice.
 *
 * @group commerce_unleashed
 */
#[CoversClass(UnleashedInvoicePayload::class)]
#[Group('commerce_unleashed')]
class UnleashedInvoicePayloadTest extends UnitTestCase {

  /**
   * A completed invoice is completed.
   */
  public function testCompletedMapsToCompleted(): void {
    $this->assertSame('completed', UnleashedInvoicePayload::stateFrom('Completed'));
  }

  /**
   * Status matching does not depend on casing.
   */
  public function testStatusMatchingIgnoresCase(): void {
    $this->assertSame('completed', UnleashedInvoicePayload::stateFrom('COMPLETED'));
    $this->assertSame('deleted', UnleashedInvoicePayload::stateFrom(' deleted '));
  }

  /**
   * Payment outranks the status.
   */
  public function testPaymentReceivedMakesAnInvoicePaid(): void {
    $this->assertSame('paid', UnleashedInvoicePayload::stateFrom('Completed', TRUE));
    $this->assertSame('paid', UnleashedInvoicePayload::stateFrom('Parked', TRUE));
  }

  /**
   * A deleted invoice is deleted even when it was paid.
   *
   * Deletion is the one thing that outranks payment: presenting a deleted
   * invoice as paid would assert something Unleashed is no longer saying.
   */
  public function testDeletedOutranksPayment(): void {
    $this->assertSame('deleted', UnleashedInvoicePayload::stateFrom('Deleted', TRUE));
  }

  /**
   * An unknown status claims as little as possible.
   */
  public function testUnknownStatusFallsBackToParked(): void {
    $this->assertSame('parked', UnleashedInvoicePayload::stateFrom('Something New'));
    $this->assertSame('parked', UnleashedInvoicePayload::stateFrom(NULL));
    $this->assertSame('parked', UnleashedInvoicePayload::stateFrom(''));
  }

  /**
   * Microsoft JSON dates are read as timestamps.
   */
  public function testDatesAreParsed(): void {
    $payload = new UnleashedInvoicePayload([
      'InvoiceDate' => '/Date(1789987161000)/',
      'DueDate' => '/Date(1789990761000)/',
    ]);

    $this->assertSame(1789987161, $payload->invoiceDate());
    $this->assertSame(1789990761, $payload->dueDate());
  }

  /**
   * A missing or malformed date is NULL rather than the epoch.
   */
  public function testMissingDateIsNull(): void {
    $payload = new UnleashedInvoicePayload(['InvoiceDate' => '', 'DueDate' => 'nonsense']);

    $this->assertNull($payload->invoiceDate());
    $this->assertNull($payload->dueDate());
  }

  /**
   * A line reports the price actually charged, not the list price.
   *
   * Commerce_invoice recalculates an item total as unit price x quantity, so
   * a discounted line carrying its list price would contradict the line total
   * Unleashed reports.
   */
  public function testLineUnitPriceIsNetOfDiscount(): void {
    $payload = new UnleashedInvoicePayload([
      'InvoiceLines' => [
        [
          'Product' => ['ProductCode' => 'SKU1', 'ProductDescription' => 'A bottle'],
          'InvoiceQuantity' => 2,
          'UnitPrice' => 20,
          'DiscountRate' => 0.1,
          'LineTotal' => 36,
          'LineTax' => 7.2,
        ],
      ],
    ]);

    $line = $payload->lines()[0];
    $this->assertSame('18.0000', $line['unit_price']);
    $this->assertSame('20.0000', $line['list_price']);
    $this->assertSame('36.0000', $line['total']);
    $this->assertSame('7.2000', $line['tax']);
  }

  /**
   * A line with no quantity is not a line.
   */
  public function testZeroQuantityLinesAreDropped(): void {
    $payload = new UnleashedInvoicePayload([
      'InvoiceLines' => [
        ['Product' => ['ProductCode' => 'SKU1'], 'InvoiceQuantity' => 0, 'LineTotal' => 0],
        'not a line',
        ['Product' => ['ProductCode' => 'SKU2'], 'InvoiceQuantity' => 1, 'LineTotal' => 5],
      ],
    ]);

    $lines = $payload->lines();
    $this->assertCount(1, $lines);
    $this->assertSame('SKU2', $lines[0]['sku']);
  }

  /**
   * A line with no description falls back to its product code.
   */
  public function testLineWithoutDescriptionUsesTheProductCode(): void {
    $payload = new UnleashedInvoicePayload([
      'InvoiceLines' => [
        ['Product' => ['ProductCode' => 'SKU1'], 'InvoiceQuantity' => 1, 'LineTotal' => 5],
      ],
    ]);

    $this->assertSame('SKU1', $payload->lines()[0]['title']);
  }

  /**
   * Currency falls back to GBP when the invoice does not state one.
   */
  public function testCurrencyFallsBack(): void {
    $this->assertSame('GBP', (new UnleashedInvoicePayload([]))->currencyCode());
    $this->assertSame('EUR', (new UnleashedInvoicePayload(['Currency' => ['CurrencyCode' => 'EUR']]))->currencyCode());
  }

}
