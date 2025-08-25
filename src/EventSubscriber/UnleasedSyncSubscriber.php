<?php

namespace Drupal\commerce_unleashed\EventSubscriber;

use Drupal\advancedqueue\Job;
use Drupal\commerce_product\Event\ProductVariationEvent;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\state_machine\Event\WorkflowTransitionEvent;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;

class UnleasedSyncSubscriber implements EventSubscriberInterface {

  public function __construct(protected EntityTypeManagerInterface $entityTypeManager) {}

  /**
   * {@inheritdoc}
   */
  public static function getSubscribedEvents(): array {
    return [
      'commerce_order.place.post_transition' => ['onOrderPlace'],
      'commerce_product.commerce_product_variation.presave' => ['onVariationSave'],
      'commerce_product.commerce_product_variation.create' => ['onVariationCreate'],
    ];
  }

  /**
   * Sync on order placement.
   */
  public function onOrderPlace(WorkflowTransitionEvent $event): void {
    $order = $event->getEntity();
    $order_type = $this->entityTypeManager->getStorage('commerce_order_type')->load($order->bundle());
    if ($order_type->getThirdPartySetting('commerce_unleashed', 'sync', 0)) {
      $this->queueSyncJob($order->id(), $order->getEntityTypeId());
    }
  }

  /**
   * Sync on variation create.
   */
  public function onVariationCreate(ProductVariationEvent $event): void {
    $product_variation = $event->getProductVariation();
    $product_variation_type = $this->entityTypeManager->getStorage('commerce_product_variation_type')->load($product_variation->bundle());

    // Add null check as a safety measure
    if ($product_variation_type && $product_variation_type->getThirdPartySetting('commerce_unleashed', 'sync', 0)) {
        $this->queueSyncJob($product_variation->id(), $product_variation->getEntityTypeId());
    }
  }

  /**
   * Sync on variation resave.
   */
  public function onVariationSave(ProductVariationEvent $event): void {
    $product_variation = $event->getProductVariation();
    $product_variation_type = $this->entityTypeManager->getStorage('commerce_product_variation_type')->load($product_variation->bundle());
    if ($product_variation_type->getThirdPartySetting('commerce_unleashed', 'sync', 0)) {
      $this->queueSyncJob($product_variation->id(), $product_variation->getEntityTypeId());
    }
  }

  /**
   * Queue jobs.
   */
  protected function queueSyncJob($entity_id, $entity_type): void {
    $queue_storage = $this->entityTypeManager->getStorage('advancedqueue_queue');
    /** @var \Drupal\advancedqueue\Entity\QueueInterface $queue */
    $queue = $queue_storage->load('commerce_unleashed');
    // Create a job and queue each one up.
    $sync = Job::create('commerce_unleashed_sync', [
      'entity_id' => $entity_id,
      'entity_type' => $entity_type,
    ]);
    $queue->enqueueJob($sync);
  }

}
