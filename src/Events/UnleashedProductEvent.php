<?php

namespace Drupal\commerce_unleashed\Events;

use Drupal\commerce\EventBase;
use Drupal\commerce_product\Entity\ProductInterface;

/**
 * Defines the product request event.
 *
 * @see \Drupal\commerce_unleashed\Events\UnleashedEvents
 */
class UnleashedProductEvent extends EventBase {

  public function __construct(protected ProductInterface $product, protected array $payload, protected bool $saveProduct = FALSE) {}

  /**
   * Gets the product.
   */
  public function getProduct(): ProductInterface {
    return $this->product;
  }

  /**
   * Gets the product.
   */
  public function setProduct(ProductInterface $product): UnleashedProductEvent {
    $this->product = $product;
    $this->saveProduct = TRUE;
    return $this;
  }

  /**
   * Gets the Unleashed payload.
   */
  public function getPayload(): array {
    return $this->payload;
  }

  /**
   * Marks if the product should be saved.
   *
   * Based of setProduct method.
   */
  public function saveProduct(): bool {
    return $this->saveProduct;
  }

}
