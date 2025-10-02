<?php

namespace Drupal\commerce_unleashed\Plugin\AdvancedQueue\JobType;

use Drupal\advancedqueue\Job;
use Drupal\advancedqueue\JobResult;
use Drupal\advancedqueue\Plugin\AdvancedQueue\JobType\JobTypeBase;
use Drupal\commerce_unleashed\UnleashedManagerInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Plugin\ContainerFactoryPluginInterface;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Provides the job type for syncing product from Unleashed.
 *
 * @AdvancedQueueJobType(
 *   id = "commerce_unleashed_product",
 *   label = @Translation("Unleashed sync products"),
 * )
 *
 * @phpstan-consistent-constructor
 */
class SyncProduct extends JobTypeBase implements ContainerFactoryPluginInterface {

  protected EntityTypeManagerInterface $entityTypeManager;

  protected UnleashedManagerInterface $unleashedManager;

  public function __construct(array $configuration, $plugin_id, $plugin_definition, UnleashedManagerInterface $unleashed_manager) {
    parent::__construct($configuration, $plugin_id, $plugin_definition);
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
      $container->get('commerce_unleashed.manager')
    );
  }

  /**
   * {@inheritdoc}
   */
  public function process(Job $job) {
    $payload = $job->getPayload();

    try {
      $this->unleashedManager->processProductSync($payload);
    }
    catch (\Exception $e) {
      return JobResult::failure($e->getMessage());
    }
    return JobResult::success('Successfully synced ');
  }

}
