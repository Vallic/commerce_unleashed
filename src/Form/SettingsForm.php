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
  public function __construct(ConfigFactoryInterface $config_factory, TypedConfigManagerInterface $typedConfigManager, protected EntityTypeManagerInterface $entityTypeManager) {
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

    $form['help'] = [
      '#type' => 'markup',
      '#markup' => '<div class="messages messages--info">' .
      $this->t('To obtain your API credentials, please visit the <a href="@url" target="_blank">Unleashed API Integration page</a> and follow the instructions to generate your API ID and API Key.', [
        '@url' => 'https://au.unleashedsoftware.com/v2/Integration/Api',
      ]) . '</div>',
      '#weight' => -10,
    ];

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

    $form['products'] = [
      '#type' => 'details',
      '#title' => 'Products',
      '#tree' => TRUE,
      '#open' => TRUE,
    ];

    $form['products']['sync'] = [
      '#type' => 'checkbox',
      '#title' => $this->t('Enable Unleashed product synchronization'),
      '#description' => $this->t('Synchronize Unleashed products via productCode as sku on product variations.'),
      '#default_value' => $config->get('products.sync') ?? FALSE,
      '#required' => FALSE,
    ];

    $form['products']['full'] = [
      '#type' => 'checkbox',
      '#title' => $this->t('Full product synchronization'),
      '#description' => $this->t('By default we run a brief summary of the Product list that only includes: Guid, ProductCode, ProductDescription, DefaultPurchasePrice, DefaultSellPrice, SellPriceTier1, DefaultSupplierId. If you choose full, the entire payload will be called upon for each product during queue processing and be available in at \Drupal\commerce_unleashed\UnleashedManager::syncProductVariation'),
      '#default_value' => $config->get('products.full') ?? FALSE,
      '#required' => FALSE,
    ];

    $form['products']['cron'] = [
      '#type' => 'checkbox',
      '#title' => $this->t('Sync on cron'),
      '#default_value' => $config->get('products.cron'),
      '#required' => FALSE,
    ];

    $variation_types = $this->entityTypeManager->getStorage('commerce_product_variation_type')->loadMultiple();

    $variation_types_ids = [];
    foreach ($variation_types as $variation_type) {
      $variation_types_ids[$variation_type->id()] = $variation_type->label();
    }

    $form['products']['type'] = [
      '#type' => 'radios',
      '#title' => $this->t('Default product variation type'),
      '#description' => $this->t('Select default product variation type for synchronization.'),
      '#options' => $variation_types_ids,
      '#default_value' => $config->get('products.type'),
      '#required' => TRUE,
    ];

    $stores = $this->entityTypeManager->getStorage('commerce_store')->loadMultiple();

    $store_ids = [];
    foreach ($stores as $store) {
      $store_ids[$store->id()] = $store->label();
    }

    $form['products']['store'] = [
      '#type' => 'radios',
      '#title' => $this->t('Default store'),
      '#description' => $this->t('Select default store type for synchronization.'),
      '#options' => $store_ids,
      '#default_value' => $config->get('products.store'),
      '#required' => TRUE,
    ];

    $currencies = $this->entityTypeManager->getStorage('commerce_currency')->loadMultiple();

    $currency_codes = [];
    foreach ($currencies as $currency) {
      $currency_codes[$currency->id()] = $currency->label();
    }

    $form['products']['currency_code'] = [
      '#type' => 'radios',
      '#title' => $this->t('Unleashed price currency'),
      '#options' => $currency_codes,
      '#default_value' => $config->get('products.currency_code'),
      '#required' => TRUE,
    ];

    $form['purchase_orders'] = [
      '#type' => 'details',
      '#title' => 'Purchase orders',
      '#tree' => TRUE,
      '#open' => TRUE,
    ];

    $form['purchase_orders']['sync'] = [
      '#type' => 'checkbox',
      '#title' => $this->t('Sync Drupal orders'),
      '#description' => $this->t('Synchronize Drupal orders as purchase orders.'),
      '#default_value' => $config->get('purchase_orders.sync') ?? FALSE,
      '#required' => FALSE,
    ];

    $order_types = $this->entityTypeManager->getStorage('commerce_order_type')->loadMultiple();

    $order_types_ids = [];
    foreach ($order_types as $order_type) {
      $order_types_ids[$order_type->id()] = $order_type->label();
    }

    $form['purchase_orders']['types'] = [
      '#type' => 'checkboxes',
      '#title' => $this->t('Order types'),
      '#description' => $this->t('Select order types for synchronization.'),
      '#options' => $order_types_ids,
      '#default_value' => $config->get('purchase_orders.types') ?? FALSE,
      '#required' => TRUE,
    ];

    $form['purchase_orders']['supplier_code'] = [
      '#type' => 'textfield',
      '#title' => $this->t('Supplier code'),
      '#description' => $this->t('Default supplier code, if applicable.'),
      '#default_value' => $config->get('purchase_orders.supplier_code') ?? '',
      '#required' => FALSE,
    ];

    $form['stock'] = [
      '#type' => 'details',
      '#title' => 'Stock on hand',
      '#tree' => TRUE,
      '#open' => TRUE,
    ];

    $form['stock']['sync'] = [
      '#type' => 'checkbox',
      '#title' => $this->t('Sync stock on hand'),
      '#description' => $this->t('Synchronize stock on hand.'),
      '#default_value' => $config->get('stock.sync') ?? FALSE,
      '#required' => FALSE,
    ];

    $form['stock']['availability'] = [
      '#type' => 'checkbox',
      '#title' => $this->t('Enforce stock availability'),
      '#description' => $this->t('It is applied to any SKU which have entry in the table. If there is no entry for stock, product is not considered to be managed by Unleashed.'),
      '#default_value' => $config->get('stock.availability') ?? FALSE,
      '#required' => FALSE,
    ];

    $form['stock']['local'] = [
      '#type' => 'checkbox',
      '#title' => $this->t('Update local stock after order placement'),
      '#description' => $this->t('Update local stock table after order is placed. The cron periodically updates stock, but to keep more in sync in real time with less calls to Unleashed API.'),
      '#default_value' => $config->get('stock.local') ?? FALSE,
      '#required' => FALSE,
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
      ->set('products', $form_state->getValue('products'))
      ->set('purchase_orders', $form_state->getValue('purchase_orders'))
      ->set('stock', $form_state->getValue('stock'))
      ->save();
    parent::submitForm($form, $form_state);
  }

}
