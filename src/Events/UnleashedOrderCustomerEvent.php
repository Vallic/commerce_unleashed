<?php

namespace Drupal\commerce_unleashed\Events;

use Drupal\commerce_order\Entity\OrderInterface;
use Symfony\Contracts\EventDispatcher\Event;

/**
 * Resolves which Unleashed customer an order belongs to.
 *
 * The module's own answer is the order email, which is right for a shop where
 * the person paying is the customer. It is wrong wherever the two differ - a
 * trade representative ordering for a venue, a marketplace seller, a parent
 * account billed for a child's order - and wrong in a way that does not merely
 * mis-attribute: an unmatched code is CREATED, so the tenant accumulates one
 * customer per email that was never a customer.
 *
 * Subscribe to replace the code with whatever the site actually keys customers
 * on. Both the order payload and the "does this customer exist yet" lookup ask
 * this, so one subscriber settles both.
 */
class UnleashedOrderCustomerEvent extends Event {

  public function __construct(
    protected OrderInterface $order,
    protected string $customerCode,
  ) {}

  /**
   * The order whose customer is being resolved.
   */
  public function getOrder(): OrderInterface {
    return $this->order;
  }

  /**
   * The customer code as it stands.
   */
  public function getCustomerCode(): string {
    return $this->customerCode;
  }

  /**
   * Sets the customer code the order should be raised against.
   */
  public function setCustomerCode(string $customer_code): self {
    $this->customerCode = $customer_code;

    return $this;
  }

}
