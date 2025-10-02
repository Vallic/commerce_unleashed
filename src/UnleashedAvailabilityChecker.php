<?php

namespace Drupal\commerce_unleashed;

use Drupal\commerce\Context;
use Drupal\commerce_order\AvailabilityCheckerInterface;
use Drupal\commerce_order\AvailabilityResult;
use Drupal\commerce_order\Entity\OrderItemInterface;
use Drupal\commerce_product\Entity\ProductVariationInterface;

/**
 * The availability checker.
 */
class UnleashedAvailabilityChecker implements AvailabilityCheckerInterface {

  public function __construct(protected UnleashedManagerInterface $unleashedManager) {}

  /**
   * {@inheritdoc}
   */
  public function applies(OrderItemInterface $order_item): bool {
    $purchased_entity = $order_item->getPurchasedEntity();
    return $purchased_entity instanceof ProductVariationInterface;
  }

  /**
   * {@inheritdoc}
   */
  public function check(OrderItemInterface $order_item, Context $context): AvailabilityResult {
    if ($this->unleashedManager->enforceStockAvailability()) {
      $purchased_entity = $order_item->getPurchasedEntity();
      $on_hand = $this->unleashedManager->getStockOnHand($purchased_entity);
      if ($on_hand === 0) {
        return AvailabilityResult::unavailable('Out of stock');
      }
    }

    return AvailabilityResult::neutral();
  }

}
