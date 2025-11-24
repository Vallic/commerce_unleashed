<?php

namespace Drupal\commerce_unleashed\Events;

use Drupal\commerce\EventBase;
use Drupal\commerce_order\Entity\OrderInterface;
use Drupal\commerce_unleashed\UnleashedManagerInterface;

/**
 * Defines the order request event.
 *
 * @see \Drupal\commerce_unleashed\Events\UnleashedEvents
 */
class UnleashedOrderEvent extends EventBase {

  public function __construct(protected OrderInterface $order, protected array $payload, protected string $orderType = UnleashedManagerInterface::UNLEASHED_SALES_ORDERS) {}

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

  /**
   * Returns the Unleashed order type.
   */
  public function getOrderType(): string {
    return $this->orderType;
  }

}
