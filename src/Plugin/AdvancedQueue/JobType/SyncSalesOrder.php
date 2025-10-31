<?php

namespace Drupal\commerce_unleashed\Plugin\AdvancedQueue\JobType;

use Drupal\advancedqueue\Job;
use Drupal\advancedqueue\JobResult;

/**
 * Provides the job type for syncing orders with Unleashed.
 *
 * @AdvancedQueueJobType(
 *   id = "commerce_unleashed_sales_order",
 *   label = @Translation("Unleashed sync sales orders"),
 * )
 *
 * @phpstan-consistent-constructor
 */
class SyncSalesOrder extends AbstractOrderSync {

  /**
   * {@inheritdoc}
   */
  public function process(Job $job) {
    $payload = $job->getPayload();
    $entity_id = $payload['order_id'];
    $entity_storage = $this->entityTypeManager->getStorage('commerce_order');
    $entity = $entity_storage->load($entity_id);
    if (!$entity) {
      return JobResult::failure(sprintf('Order with id "%s" not found.', $entity_id));
    }

    $customer = $this->unleashedManager->getCustomerFromOrder($entity);
    if (isset($customer['error'])) {
      return JobResult::failure($customer['error']);
    }

    $response = $this->unleashedManager->syncSalesOrder($entity);
    if (isset($response['error'])) {
      return JobResult::failure($response['error']);
    }
    return JobResult::success(sprintf('Successfully synced. Unleashed GUID %s', $entity->uuid()));
  }

}
