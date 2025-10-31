<?php

namespace Drupal\commerce_unleashed\EventSubscriber;

use Drupal\advancedqueue\Job;
use Drupal\commerce_unleashed\UnleashedManagerInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Logger\LoggerChannelTrait;
use Drupal\state_machine\Event\WorkflowTransitionEvent;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;

class UnleashedSyncSubscriber implements EventSubscriberInterface {

  use LoggerChannelTrait;

  public function __construct(protected EntityTypeManagerInterface $entityTypeManager, protected UnleashedManagerInterface $unleashedManager) {}

  /**
   * {@inheritdoc}
   */
  public static function getSubscribedEvents(): array {
    return [
      'commerce_order.place.post_transition' => ['onOrderPlace'],
      'commerce_order.fulfill.post_transition' => ['onOrderFulfill'],
      'commerce_order.cancel.post_transition' => ['onOrderCancel'],
    ];
  }

  /**
   * Sync on order placement.
   */
  public function onOrderPlace(WorkflowTransitionEvent $event): void {
    $order = $event->getEntity();
    if ($sync_type = $this->unleashedManager->isOrderEligible($order)) {
      $queue_storage = $this->entityTypeManager->getStorage('advancedqueue_queue');
      /** @var \Drupal\advancedqueue\Entity\QueueInterface $queue */
      $queue = $queue_storage->load('commerce_unleashed');
      // Create a job and queue each one up.
      $sync = Job::create(sprintf('commerce_unleashed_%s_order', $sync_type), [
        'order_id' => $order->id(),
      ]);
      $queue->enqueueJob($sync);

      if ($this->unleashedManager->updateLocalStock($order)) {
        foreach ($order->getItems() as $item) {
          $this->unleashedManager->updateLocalStockOnHand($item->getPurchasedEntity(), (int) $item->getQuantity());
        }
      }
    }
  }

  /**
   * Sync on order completion.
   */
  public function onOrderFulfill(WorkflowTransitionEvent $event): void {
    $order = $event->getEntity();
    if ($type = $this->unleashedManager->completeOrders($order)) {
      try {
        if ($type === UnleashedManagerInterface::UNLEASHED_SALES_ORDERS) {
          $this->unleashedManager->getClient()->completeSalesOrder($order->uuid());
        }
        else {
          $this->unleashedManager->getClient()->completePurchaseOrder($order->uuid());
        }
      }
      catch (\Exception $e) {
        $this->getLogger('commerce_unleashed')->error($e->getMessage());
      }
    }
  }

  /**
   * Sync on order cancellation.
   */
  public function onOrderCancel(WorkflowTransitionEvent $event): void {
    $order = $event->getEntity();
    if ($type = $this->unleashedManager->isOrderEligible($order)) {
      try {
        if ($type === UnleashedManagerInterface::UNLEASHED_SALES_ORDERS) {
          $this->unleashedManager->getClient()->deleteSalesOrder($order->uuid());
        }
        else {
          $this->unleashedManager->getClient()->deletePurchaseOrder($order->uuid());
        }
      }
      catch (\Exception $e) {
        $this->getLogger('commerce_unleashed')->error($e->getMessage());
      }
    }
  }

}
