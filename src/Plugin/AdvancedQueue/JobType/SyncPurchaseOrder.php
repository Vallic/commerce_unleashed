<?php

namespace Drupal\commerce_unleashed\Plugin\AdvancedQueue\JobType;

use Drupal\advancedqueue\Job;
use Drupal\commerce_order\Entity\OrderInterface;
use Drupal\advancedqueue\JobResult;

/**
 * Provides the job type for syncing orders with Unleashed.
 *
 * @AdvancedQueueJobType(
 *   id = "commerce_unleashed_purchase_order",
 *   label = @Translation("Unleashed sync purchase orders"),
 * )
 *
 * @phpstan-consistent-constructor
 */
class SyncPurchaseOrder extends AbstractOrderSync {

  /**
   * {@inheritdoc}
   */
  public function process(Job $job) {
    $payload = $job->getPayload();
    $entity_id = $payload['order_id'];
    $entity_storage = $this->entityTypeManager->getStorage('commerce_order');
    $entity = $entity_storage->load($entity_id);
    if (!$entity instanceof OrderInterface) {
      return JobResult::failure(sprintf('Order with id "%s" not found.', $entity_id));
    }

    $response = $this->unleashedManager->syncPurchaseOrder($entity);
    if (isset($response['error'])) {
      return JobResult::failure($response['error']);
    }
    return JobResult::success(sprintf('Successfully synced. Unleashed GUID %s', $entity->uuid()));
  }

}
