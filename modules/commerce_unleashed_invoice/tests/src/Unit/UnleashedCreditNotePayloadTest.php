<?php

declare(strict_types=1);

namespace Drupal\Tests\commerce_unleashed_invoice\Unit;

use Drupal\commerce_unleashed_invoice\UnleashedCreditNotePayload;
use Drupal\Tests\UnitTestCase;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Group;

/**
 * Tests reading an Unleashed credit note.
 *
 * @group commerce_unleashed
 */
#[CoversClass(UnleashedCreditNotePayload::class)]
#[Group('commerce_unleashed')]
class UnleashedCreditNotePayloadTest extends UnitTestCase {

  /**
   * The number comes from CreditNoteNumber, not the documented CreditNumber.
   */
  public function testNumberUsesTheFieldTheApiActuallyReturns(): void {
    $payload = new UnleashedCreditNotePayload([
      'CreditNoteNumber' => 'CN-00006272',
      'CreditNumber' => 'ignored',
    ]);

    $this->assertSame('CN-00006272', $payload->creditNumber());
  }

  /**
   * The status comes from Status, not the documented CreditStatus.
   */
  public function testStatusUsesTheFieldTheApiActuallyReturns(): void {
    $payload = new UnleashedCreditNotePayload(['Status' => 'Completed']);

    $this->assertSame('Completed', $payload->status());
    $this->assertSame('completed', $payload->state());
  }

  /**
   * A parked credit note is parked.
   */
  public function testParkedIsCarriedThrough(): void {
    $this->assertSame('parked', (new UnleashedCreditNotePayload(['Status' => 'Parked']))->state());
  }

  /**
   * A free credit has no sales order behind it.
   */
  public function testFreeCreditIsRecognized(): void {
    $free = new UnleashedCreditNotePayload(['CreditType' => 'FreeCredit']);
    $normal = new UnleashedCreditNotePayload(['CreditType' => 'Credit']);

    $this->assertTrue($free->isFreeCredit());
    $this->assertFalse($normal->isFreeCredit());
  }

  /**
   * Credit type matching does not depend on casing.
   */
  public function testFreeCreditMatchingIgnoresCase(): void {
    $lowercased = strtolower(UnleashedCreditNotePayload::FREE_CREDIT);

    $this->assertTrue((new UnleashedCreditNotePayload(['CreditType' => $lowercased]))->isFreeCredit());
  }

  /**
   * The sales order and invoice links are read.
   */
  public function testLinksAreRead(): void {
    $payload = new UnleashedCreditNotePayload([
      'SalesOrder' => ['OrderNumber' => 'SO-00192674'],
      'InvoiceNumber' => 'SI-00192674',
    ]);

    $this->assertSame('SO-00192674', $payload->orderNumber());
    $this->assertSame('SI-00192674', $payload->invoiceNumber());
  }

  /**
   * A credit with no sales order reports none rather than failing.
   */
  public function testMissingSalesOrderIsEmpty(): void {
    $this->assertSame('', (new UnleashedCreditNotePayload([]))->orderNumber());
  }

  /**
   * A line reports what was actually credited per unit.
   */
  public function testLineUnitPriceIsDerivedFromTheLineTotal(): void {
    $payload = new UnleashedCreditNotePayload([
      'CreditLines' => [
        [
          'Product' => ['ProductCode' => 'SKU1', 'ProductDescription' => 'A bottle'],
          'CreditQuantity' => 2,
          'CreditPrice' => 100,
          'LineTotal' => 150,
          'LineTax' => 30,
          'Reason' => 'Damaged',
          'ReturnToStock' => TRUE,
        ],
      ],
    ]);

    $line = $payload->lines()[0];
    $this->assertSame('75.0000', $line['unit_price']);
    $this->assertSame('150.0000', $line['total']);
    $this->assertSame('30.0000', $line['tax']);
    $this->assertSame('Damaged', $line['reason']);
    $this->assertTrue($line['returned']);
  }

  /**
   * Lines crediting nothing are dropped.
   */
  public function testZeroQuantityLinesAreDropped(): void {
    $payload = new UnleashedCreditNotePayload([
      'CreditLines' => [
        ['Product' => ['ProductCode' => 'SKU1'], 'CreditQuantity' => 0, 'LineTotal' => 0],
        'not a line',
        ['Product' => ['ProductCode' => 'SKU2'], 'CreditQuantity' => 1, 'LineTotal' => 9],
      ],
    ]);

    $lines = $payload->lines();
    $this->assertCount(1, $lines);
    $this->assertSame('SKU2', $lines[0]['sku']);
  }

  /**
   * Credit dates are read.
   */
  public function testCreditDateIsParsed(): void {
    $payload = new UnleashedCreditNotePayload(['CreditDate' => '/Date(1789948800000)/']);

    $this->assertSame(1789948800, $payload->creditDate());
  }

}
