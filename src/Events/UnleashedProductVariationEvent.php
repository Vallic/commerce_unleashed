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
   * Gets the API request data.
   */
  public function getPayload(): array {
    return $this->payload;
  }

  /**
   * Sets the API request data.
   */
  public function setPayload(array $payload): UnleashedProductVariationEvent {
    $this->payload = $payload;
    return $this;
  }

}
