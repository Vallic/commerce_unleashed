<?php

namespace Drupal\commerce_unleashed\Events;

use Drupal\commerce\EventBase;
use Drupal\commerce_product\Entity\ProductVariationInterface;

/**
 * Defines the product variation request event.
 *
 * @see \Drupal\commerce_unleashed\Events\UnleashedEvents
 */
class UnleashedProductVariationEvent extends EventBase {

  public function __construct(protected ProductVariationInterface $productVariation, protected array $payload) {}

  /**
   * Gets the product variation.
   */
  public function getProductVariation(): ProductVariationInterface {
    return $this->productVariation;
  }

  /**
   * Gets the product variation.
   */
  public function setProductVariation(ProductVariationInterface $product_variation): UnleashedProductVariationEvent {
    $this->productVariation = $product_variation;
    return $this;
  }

  /**
   * Gets the Unleashed payload.
   */
  public function getPayload(): array {
    return $this->payload;
  }

}
