<?php

namespace Drupal\commerce_unleashed\EventSubscriber;

use Drupal\advancedqueue\Job;
use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\state_machine\Event\WorkflowTransitionEvent;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;

class UnleashedSyncSubscriber implements EventSubscriberInterface {

  public function __construct(protected EntityTypeManagerInterface $entityTypeManager, protected ConfigFactoryInterface $configFactory) {}

  /**
   * {@inheritdoc}
   */
  public static function getSubscribedEvents(): array {
    return [
      'commerce_order.place.post_transition' => ['onOrderPlace'],
    ];
  }

  /**
   * Sync on order placement.
   */
  public function onOrderPlace(WorkflowTransitionEvent $event): void {
    $order = $event->getEntity();
    $settings = $this->configFactory->get('commerce_unleashed.settings');
    $order_types = $settings->get('purchase_orders.types');
    if ($settings->get('purchase_orders.sync') && !empty($order_types[$order->bundle()])) {
      $queue_storage = $this->entityTypeManager->getStorage('advancedqueue_queue');
      /** @var \Drupal\advancedqueue\Entity\QueueInterface $queue */
      $queue = $queue_storage->load('commerce_unleashed');
      // Create a job and queue each one up.
      $sync = Job::create('commerce_unleashed_purchase_order', [
        'order_id' => $order->id(),
      ]);
      $queue->enqueueJob($sync);
    }
  }

}
