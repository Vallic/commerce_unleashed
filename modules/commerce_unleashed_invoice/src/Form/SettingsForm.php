<?php

declare(strict_types=1);

namespace Drupal\commerce_unleashed_invoice\Form;

use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Form\ConfigFormBase;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\Config\TypedConfigManagerInterface;
use Drupal\state_machine\WorkflowManagerInterface;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Settings for mirroring Unleashed invoices.
 */
final class SettingsForm extends ConfigFormBase {

  public function __construct(
    ConfigFactoryInterface $config_factory,
    TypedConfigManagerInterface $typed_config_manager,
    protected readonly WorkflowManagerInterface $workflowManager,
  ) {
    parent::__construct($config_factory, $typed_config_manager);
  }

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container): static {
    return new static(
      $container->get('config.factory'),
      $container->get('config.typed'),
      $container->get('plugin.manager.workflow'),
    );
  }

  /**
   * {@inheritdoc}
   */
  public function getFormId(): string {
    return 'commerce_unleashed_invoice_settings';
  }

  /**
   * {@inheritdoc}
   */
  protected function getEditableConfigNames(): array {
    return ['commerce_unleashed_invoice.settings'];
  }

  /**
   * {@inheritdoc}
   */
  public function buildForm(array $form, FormStateInterface $form_state): array {
    $config = $this->config('commerce_unleashed_invoice.settings');

    $form['sync'] = [
      '#type' => 'checkbox',
      '#title' => $this->t('Mirror invoices from Unleashed'),
      '#description' => $this->t('Unleashed is the source of truth: the Invoices endpoint is read-only, so invoices are copied here and never sent back. A mirrored invoice is not editable in any way that would survive the next read.'),
      '#default_value' => $config->get('sync'),
    ];

    $form['credit_notes'] = [
      '#type' => 'checkbox',
      '#title' => $this->t('Mirror credit notes from Unleashed'),
      '#description' => $this->t('Credit notes are read in pages rather than per order, because the Unleashed endpoint accepts an order filter and then ignores it. Nothing runs automatically: use <code>drush commerce-unleashed:credit-notes</code>, which reads only what has changed since the last completed run.'),
      '#default_value' => $config->get('credit_notes'),
    ];

    $form['order_states'] = [
      '#type' => 'checkboxes',
      '#title' => $this->t('Read the invoice when an order reaches'),
      '#description' => $this->t('One API request per order, made when the order ENTERS one of these states. Pick the state that means the order has been invoiced in Unleashed — usually the one at the end of the workflow. Orders already in a state before it is ticked here are left alone; use the backfill Drush command for those.'),
      '#options' => $this->orderStateOptions(),
      '#default_value' => $config->get('order_states') ?: [],
    ];

    $form['queue_on_transition'] = [
      '#type' => 'checkbox',
      '#title' => $this->t('Read in the background'),
      '#description' => $this->t('Queues the read instead of making it while the order is being saved. Leave this on: it is a call to a third party, and an order should not fail to save, or hang, because Unleashed is slow. It also means an order Unleashed has not invoiced yet is retried rather than lost.'),
      '#default_value' => $config->get('queue_on_transition') ?? TRUE,
    ];

    return parent::buildForm($form, $form_state);
  }

  /**
   * {@inheritdoc}
   */
  public function submitForm(array &$form, FormStateInterface $form_state): void {
    $this->config('commerce_unleashed_invoice.settings')
      ->set('sync', (bool) $form_state->getValue('sync'))
      ->set('order_states', array_values(array_filter($form_state->getValue('order_states'))))
      ->set('queue_on_transition', (bool) $form_state->getValue('queue_on_transition'))
      ->set('credit_notes', (bool) $form_state->getValue('credit_notes'))
      ->save();

    parent::submitForm($form, $form_state);
  }

  /**
   * Every state any order workflow can reach.
   *
   * @return array<string, string>
   *   State labels keyed by state ID.
   */
  private function orderStateOptions(): array {
    $options = [];

    foreach ($this->workflowManager->getGroupedLabels('commerce_order') as $workflows) {
      foreach (array_keys($workflows) as $workflow_id) {
        $workflow = $this->workflowManager->createInstance($workflow_id);
        foreach ($workflow->getStates() as $state) {
          // Keyed by state ID across every workflow: two workflows can share
          // a state ID, and an order in either should trigger the same read.
          $options[$state->getId()] = $state->getLabel();
        }
      }
    }
    ksort($options);

    return $options;
  }

}
