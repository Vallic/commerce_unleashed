<?php

declare(strict_types=1);

namespace Drupal\commerce_unleashed_invoice\Hook;

use Drupal\advancedqueue\Entity\QueueInterface;
use Drupal\advancedqueue\Job;
use Drupal\commerce_order\Entity\OrderInterface;
use Drupal\commerce_unleashed_invoice\InvoiceSync;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Hook\Attribute\Hook;

/**
 * Reads an order's invoice when the order reaches a configured state.
 *
 * Keyed on the STATE an order arrives in rather than on a named transition:
 * a site can rename a transition, add a second route into the same state, or
 * place an order straight into it, and all three should mean the same thing
 * here. Which states count is configuration, because which state means "this
 * is now invoiced in Unleashed" differs per workflow.
 */
final class OrderHooks {

  public function __construct(
    private readonly InvoiceSync $invoiceSync,
    private readonly EntityTypeManagerInterface $entityTypeManager,
  ) {}

  /**
   * Implements hook_ENTITY_TYPE_insert() for commerce_order.
   */
  #[Hook('commerce_order_insert')]
  public function orderInsert(OrderInterface $order): void {
    $this->maybeSync($order, NULL);
  }

  /**
   * Implements hook_ENTITY_TYPE_update() for commerce_order.
   */
  #[Hook('commerce_order_update')]
  public function orderUpdate(OrderInterface $order): void {
    $original = $order->original ?? NULL;
    $this->maybeSync($order, $original instanceof OrderInterface ? $original->getState()->getId() : NULL);
  }

  /**
   * Queues a read when the order has just entered a configured state.
   *
   * @param \Drupal\commerce_order\Entity\OrderInterface $order
   *   The saved order.
   * @param string|null $previous_state
   *   The state the order was in before, or NULL on insert.
   */
  private function maybeSync(OrderInterface $order, ?string $previous_state): void {
    if (!$this->invoiceSync->enabled()) {
      return;
    }

    $state = $order->getState()->getId();
    if ($state === $previous_state) {
      // Saved for some other reason. Re-reading on every save would turn one
      // API request per order into one per edit.
      return;
    }

    if (!in_array($state, $this->invoiceSync->triggerStates(), TRUE)) {
      return;
    }

    if (!$this->invoiceSync->queueOnTransition()) {
      $this->invoiceSync->syncOrder($order);

      return;
    }

    // Queued by default: the read is an HTTP call to a third party, and an
    // order should not fail to save, or hang, because Unleashed is slow.
    $queue = $this->entityTypeManager->getStorage('advancedqueue_queue')->load('commerce_unleashed');
    if ($queue instanceof QueueInterface) {
      $queue->enqueueJob(Job::create('commerce_unleashed_invoice', ['order_id' => $order->id()]));
    }
  }

}
