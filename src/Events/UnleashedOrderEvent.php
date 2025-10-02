<?php

namespace Drupal\commerce_unleashed\Events;

use Drupal\commerce\EventBase;
use Drupal\commerce_order\Entity\OrderInterface;

/**
 * Defines the order request event.
 *
 * @see \Drupal\commerce_unleashed\Events\UnleashedEvents
 */
class UnleashedOrderEvent extends EventBase {

  public function __construct(protected OrderInterface $order, protected array $payload) {}

  /**
   * Gets the order.
   */
  public function getOrder(): OrderInterface {
    return $this->order;
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
  public function setPayload(array $payload): UnleashedOrderEvent {
    $this->payload = $payload;
    return $this;
  }

}
