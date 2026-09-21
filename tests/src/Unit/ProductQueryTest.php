<?php

declare(strict_types=1);

namespace Drupal\Tests\commerce_unleashed\Unit;

use Drupal\commerce_unleashed\UnleashedManager;
use Drupal\Tests\UnitTestCase;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Group;

/**
 * Tests the query a product read is made with.
 *
 * The two options are not independent of each other at the API: `brief=true`
 * suppresses `includeAttributes`, so they can never both be sent.
 */
#[CoversClass(UnleashedManager::class)]
#[Group('commerce_unleashed')]
class ProductQueryTest extends UnitTestCase {

  /**
   * A manager answering the two settings as given.
   */
  private function manager(bool $attributes, bool $obsolete): UnleashedManager {
    $manager = $this->getMockBuilder(UnleashedManager::class)
      ->disableOriginalConstructor()
      ->onlyMethods(['includeAttributes', 'includeObsolete'])
      ->getMock();
    $manager->method('includeAttributes')->willReturn($attributes);
    $manager->method('includeObsolete')->willReturn($obsolete);

    return $manager;
  }

  /**
   * Attributes are asked for by default.
   */
  public function testAttributesAreRequested(): void {
    $this->assertSame('includeAttributes=true', $this->manager(TRUE, FALSE)->getProductsBaseQuery());
  }

  /**
   * Turning attributes off falls back to a brief read.
   */
  public function testWithoutAttributesTheReadIsBrief(): void {
    $this->assertSame('brief=true', $this->manager(FALSE, FALSE)->getProductsBaseQuery());
  }

  /**
   * Obsolete products are added to whichever read is being made.
   */
  public function testObsoleteIsIndependentOfAttributes(): void {
    $this->assertSame(
      'includeAttributes=true&includeObsolete=true',
      $this->manager(TRUE, TRUE)->getProductsBaseQuery()
    );
    $this->assertSame(
      'brief=true&includeObsolete=true',
      $this->manager(FALSE, TRUE)->getProductsBaseQuery()
    );
  }

  /**
   * The two record-shape options are never sent together.
   *
   * A brief read returns no attribute set even when one is asked for, so a
   * query carrying both would quietly return less than it appears to.
   */
  public function testBriefAndAttributesAreMutuallyExclusive(): void {
    foreach ([[TRUE, TRUE], [TRUE, FALSE], [FALSE, TRUE], [FALSE, FALSE]] as [$attributes, $obsolete]) {
      $query = $this->manager($attributes, $obsolete)->getProductsBaseQuery();
      $this->assertFalse(
        str_contains($query, 'brief=true') && str_contains($query, 'includeAttributes=true'),
        'Query must not ask for both a brief record and attributes: ' . $query
      );
    }
  }

}
