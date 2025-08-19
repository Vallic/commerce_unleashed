<?php

namespace Drupal\commerce_unleashed\Plugin\AdvancedQueue\JobType;

use Drupal\advancedqueue\Job;
use Drupal\advancedqueue\JobResult;
use Drupal\advancedqueue\Plugin\AdvancedQueue\JobType\JobTypeBase;
use Drupal\commerce_order\Entity\OrderInterface;
use Drupal\commerce_product\Entity\ProductVariationInterface;
use Drupal\commerce_unleashed\UnleashedManagerInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Plugin\ContainerFactoryPluginInterface;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Provides the job type for syncing items with Unleashed.
 *
 * @AdvancedQueueJobType(
 *   id = "commerce_unleashed_sync",
 *   label = @Translation("Unleashed sync"),
 * )
 *
 * @phpstan-consistent-constructor
 */
class UnleashedSync extends JobTypeBase implements ContainerFactoryPluginInterface {

  protected EntityTypeManagerInterface $entityTypeManager;

  protected UnleashedManagerInterface $unleashedManager;

  public function __construct(array $configuration, $plugin_id, $plugin_definition, EntityTypeManagerInterface $entity_type_manager, UnleashedManagerInterface $unleashed_manager) {
    parent::__construct($configuration, $plugin_id, $plugin_definition);

    $this->entityTypeManager = $entity_type_manager;
    $this->unleashedManager = $unleashed_manager;
  }

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container, array $configuration, $plugin_id, $plugin_definition) {
    return new static(
      $configuration,
      $plugin_id,
      $plugin_definition,
      $container->get('entity_type.manager'),
      $container->get('commerce_unleashed.manager')
    );
  }

  /**
   * {@inheritdoc}
   */
  public function process(Job $job) {
    $payload = $job->getPayload();
    $entity_id = $payload['entity_id'];
    $entity_type = $payload['entity_type'];
    $entity_storage = $this->entityTypeManager->getStorage($entity_type);
    $entity = $entity_storage->load($entity_id);
    if (!$entity) {
      return JobResult::failure(sprintf('Entity with id "%s" of type %s not found.', $entity_id, $entity_type));
    }

    if ($entity instanceof ProductVariationInterface) {
      $response = $this->unleashedManager->syncProductVariation($entity);
      if (isset($response['error'])) {
        return JobResult::failure($response['error']);
      }
      return JobResult::success('Successfully synced ');
    }

    if ($entity instanceof OrderInterface) {
      $response = $this->unleashedManager->syncOrder($entity);
      if (isset($response['error'])) {
        return JobResult::failure($response['error']);
      }
      return JobResult::success('Successfully synced ');
    }
    return JobResult::failure('Wrong entity queued.');
  }

}
