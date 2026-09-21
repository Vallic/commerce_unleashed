<?php

declare(strict_types=1);

namespace Drupal\commerce_unleashed_invoice\Plugin\AdvancedQueue\JobType;

use Drupal\advancedqueue\Job;
use Drupal\advancedqueue\JobResult;
use Drupal\advancedqueue\Plugin\AdvancedQueue\JobType\JobTypeBase;
use Drupal\commerce_order\Entity\OrderInterface;
use Drupal\commerce_unleashed_invoice\InvoiceSync;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Plugin\ContainerFactoryPluginInterface;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Reads one order's invoice from Unleashed.
 *
 * @AdvancedQueueJobType(
 *   id = "commerce_unleashed_invoice",
 *   label = @Translation("Unleashed read invoice"),
 *   max_retries = 5,
 *   retry_delay = 3600,
 * )
 *
 * @phpstan-consistent-constructor
 */
class SyncInvoice extends JobTypeBase implements ContainerFactoryPluginInterface {

  public function __construct(
    array $configuration,
    $plugin_id,
    $plugin_definition,
    protected InvoiceSync $invoiceSync,
    protected EntityTypeManagerInterface $entityTypeManager,
  ) {
    parent::__construct($configuration, $plugin_id, $plugin_definition);
  }

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container, array $configuration, $plugin_id, $plugin_definition) {
    return new static(
      $configuration,
      $plugin_id,
      $plugin_definition,
      $container->get('commerce_unleashed_invoice.sync'),
      $container->get('entity_type.manager'),
    );
  }

  /**
   * {@inheritdoc}
   */
  public function process(Job $job) {
    $payload = $job->getPayload();
    $order = $this->entityTypeManager->getStorage('commerce_order')->load($payload['order_id'] ?? 0);

    if (!$order instanceof OrderInterface) {
      // The order has gone since the job was queued. Nothing to read, and
      // retrying will not bring it back.
      return JobResult::success('Order no longer exists.');
    }

    $result = $this->invoiceSync->syncOrder($order);

    if ($result === 'failed') {
      return JobResult::failure('Unleashed could not be read.');
    }

    if ($result === 'none') {
      // Unleashed has not raised the invoice yet. This is the ordinary case
      // for an order that has only just completed, so it is a retry rather
      // than a failure - the retry delay is an hour for exactly this.
      return JobResult::failure('No invoice in Unleashed yet.');
    }

    return JobResult::success(sprintf('Invoice %s.', $result));
  }

}
