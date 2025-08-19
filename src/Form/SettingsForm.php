<?php

namespace Drupal\commerce_unleashed\Form;

use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Config\TypedConfigManagerInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Form\ConfigFormBase;
use Drupal\Core\Form\FormStateInterface;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Configure Commerce Unleashed settings for this site.
 */
class SettingsForm extends ConfigFormBase {

  /**
   * Construct UnleashedSettingsForm class.
   */
  public function __construct(protected ConfigFactoryInterface $config_factory, protected TypedConfigManagerInterface $typedConfigManager, protected EntityTypeManagerInterface $entityTypeManager) {
    parent::__construct($config_factory, $typedConfigManager);
  }

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container) {
    return new static(
      $container->get('config.factory'),
      $container->get('config.typed'),
      $container->get('entity_type.manager')
    );
  }

  /**
   * {@inheritdoc}
   */
  public function getFormId(): string {
    return 'commerce_unleashed_settings';
  }

  /**
   * {@inheritdoc}
   */
  protected function getEditableConfigNames(): array {
    return ['commerce_unleashed.settings'];
  }

  /**
   * {@inheritdoc}
   */
  public function buildForm(array $form, FormStateInterface $form_state): array {
    $config = $this->config('commerce_unleashed.settings');

    $form['api_id'] = [
      '#type' => 'textfield',
      '#title' => $this->t('API ID'),
      '#default_value' => $config->get('api_id'),
    ];

    $form['api_key'] = [
      '#type' => 'textfield',
      '#title' => $this->t('API Key'),
      '#default_value' => $config->get('api_key'),
    ];

    $form['logging'] = [
      '#type' => 'checkbox',
      '#title' => $this->t('Log API calls'),
      '#default_value' => $config->get('logging') ?? FALSE,
    ];

    return parent::buildForm($form, $form_state);
  }

  /**
   * {@inheritdoc}
   */
  public function submitForm(array &$form, FormStateInterface $form_state) {
    $this->config('commerce_unleashed.settings')
      ->set('api_id', $form_state->getValue('api_id'))
      ->set('api_key', $form_state->getValue('api_key'))
      ->set('logging', $form_state->getValue('logging'))
      ->save();
    parent::submitForm($form, $form_state);
  }

}
