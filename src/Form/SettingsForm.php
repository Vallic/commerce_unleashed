<?php

namespace Drupal\commerce_unleashed\Form;

use Drupal\commerce_unleashed\UnleashedManager;
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

    $form['products']['include_attributes'] = [
      '#type' => 'checkbox',
      '#title' => $this->t('Read attribute sets'),
      '#description' => $this->t('Reads the complete product record, attribute set included. With this off, the sync asks for a brief record instead: Guid, ProductCode, ProductDescription, DefaultPurchasePrice, DefaultSellPrice, SellPriceTier1 and DefaultSupplierId, with no product group, supplier, obsolete flag or attribute set. The two are mutually exclusive at the API — a brief read returns no attributes even when they are asked for — so leave this on unless nothing you do depends on those fields.'),
      '#default_value' => $config->get('products.include_attributes') ?? TRUE,
      '#required' => FALSE,
    ];

    $form['products']['include_obsolete'] = [
      '#type' => 'checkbox',
      '#title' => $this->t('Include obsolete products'),
      '#description' => $this->t('Unleashed leaves products marked obsolete out of a product read unless they are asked for. Tick this where the back catalog matters — an obsolete product is still one the store may hold stock of and have sold — and leave it off for a storefront that only lists current lines. Expect the catalog to be substantially larger with this on.'),
      '#default_value' => $config->get('products.include_obsolete') ?? FALSE,
      '#required' => FALSE,
    ];

    $form['products']['full'] = [
      '#type' => 'checkbox',
      '#title' => $this->t('Fetch each product individually'),
      '#description' => $this->t('Fetches every product again, one API request per product, and hands the result to \Drupal\commerce_unleashed\UnleashedManager::syncProductVariation. This is expensive — a catalog of 9,000 products costs 9,000 requests per run — and it does NOT return the attribute set, which the single-product endpoint omits. With "Read attribute sets" on, the list read already carries the complete record, so this is only for something that endpoint returns and the list does not.'),
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

    $product_types = $this->entityTypeManager->getStorage('commerce_product_type')->loadMultiple();

    $product_types_ids = [];
    foreach ($product_types as $product_type) {
      $product_types_ids[$product_type->id()] = $product_type->label();
    }

    $form['products']['product_type'] = [
      '#type' => 'radios',
      '#title' => $this->t('Default product type'),
      '#description' => $this->t('Select the product type new products are created as. This is a PRODUCT type, not a variation type — leaving it unset falls back to the variation type above, which only works on sites where the two share a machine name.'),
      '#options' => $product_types_ids,
      '#default_value' => $config->get('products.product_type'),
    ];

    $form['products']['price_sync'] = [
      '#type' => 'checkbox',
      '#title' => $this->t('Update product variation price from Unleashed'),
      '#description' => $this->t('Turn this off where the Drupal price is authoritative, or where the Unleashed price is not the price this storefront quotes. A price is still set when a variation is first created, because a variation cannot be saved without one; subscribers to the product variation event can replace it.'),
      '#default_value' => $config->get('products.price_sync') ?? TRUE,
    ];

    $price_fields = ['DefaultSellPrice' => $this->t('Default sell price')];
    foreach (range(1, 10) as $tier) {
      $price_fields['SellPriceTier' . $tier] = $this->t('Sell price tier @tier', ['@tier' => $tier]);
    }

    $form['products']['price_field'] = [
      '#type' => 'select',
      '#title' => $this->t('Unleashed price field'),
      '#description' => $this->t('Which price to read from the Unleashed product. Sell price tiers are how Unleashed models customer-group pricing, so a trade storefront usually wants a tier rather than the default sell price.'),
      '#options' => $price_fields,
      '#default_value' => $config->get('products.price_field') ?: 'DefaultSellPrice',
    ];

    $form['products']['page_size'] = [
      '#type' => 'number',
      '#title' => $this->t('Page size, delta read'),
      '#description' => $this->t('How many products to ask for per request when reading only what changed. A delta returns just the modified records, so a large page costs Unleashed little and saves requests. Defaults to @default.', ['@default' => UnleashedManager::DEFAULT_PAGE_SIZE]),
      '#default_value' => $config->get('products.page_size') ?: UnleashedManager::DEFAULT_PAGE_SIZE,
      '#min' => UnleashedManager::MIN_PAGE_SIZE,
      '#max' => UnleashedManager::MAX_PAGE_SIZE,
    ];

    $form['products']['page_size_full'] = [
      '#type' => 'number',
      '#title' => $this->t('Page size, full read'),
      '#description' => $this->t('How many products to ask for per request when reading the whole catalog. Each request genuinely builds this many complete records, which is expensive for Unleashed to serve — they ask integrators not to use the @max maximum for that reason. Smaller pages cost more requests and less strain. Defaults to @default.', ['@max' => UnleashedManager::MAX_PAGE_SIZE, '@default' => UnleashedManager::FULL_SYNC_PAGE_SIZE]),
      '#default_value' => $config->get('products.page_size_full') ?: UnleashedManager::FULL_SYNC_PAGE_SIZE,
      '#min' => UnleashedManager::MIN_PAGE_SIZE,
      '#max' => UnleashedManager::MAX_PAGE_SIZE,
    ];

    $stores = $this->entityTypeManager->getStorage('commerce_store')->loadMultiple();

    $store_ids = [];
    foreach ($stores as $store) {
      $store_ids[$store->id()] = $store->label();
    }

    $form['products']['store'] = [
      '#type' => 'radios',
      '#title' => $this->t('Default store'),
      '#description' => $this->t('Select default store for synchronization.'),
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
      '#states' => [
        'required' => [
          ':input[name="purchase_orders[sync]"]' => ['value' => 1],
        ],
      ],
    ];

    $form['purchase_orders']['supplier_code'] = [
      '#type' => 'textfield',
      '#title' => $this->t('Supplier code'),
      '#description' => $this->t('Default supplier code, required for purchase orders. You can alter it via event subscriber programmatically for any order. @see \Drupal\commerce_unleashed\Events\UnleashedEvents::UNLEASHED_PURCHASE_ORDER'),
      '#default_value' => $config->get('purchase_orders.supplier_code') ?? '',
      '#states' => [
        'required' => [
          ':input[name="purchase_orders[sync]"]' => ['value' => 1],
        ],
      ],
    ];

    $form['purchase_orders']['shipping_sku'] = [
      '#type' => 'textfield',
      '#title' => $this->t('Shipping SKU'),
      '#description' => $this->t('SKU of the product from Unleashed that will be used for shipping.'),
      '#default_value' => $config->get('purchase_orders.shipping_sku') ?? '',
      '#states' => [
        'required' => [
          ':input[name="purchase_orders[sync]"]' => ['value' => 1],
        ],
      ],
    ];

    $form['purchase_orders']['complete'] = [
      '#type' => 'checkbox',
      '#title' => $this->t('Complete orders from Drupal'),
      '#description' => $this->t('Once the order is completed in Drupal, send an complete call to Unleashed'),
      '#default_value' => $config->get('purchase_orders.complete') ?? FALSE,
      '#required' => FALSE,
    ];

    $form['sales_orders'] = [
      '#type' => 'details',
      '#title' => 'Sales orders',
      '#tree' => TRUE,
      '#open' => TRUE,
    ];

    $form['sales_orders']['sync'] = [
      '#type' => 'checkbox',
      '#title' => $this->t('Sync Drupal orders'),
      '#description' => $this->t('Synchronize Drupal orders as purchase orders.'),
      '#default_value' => $config->get('sales_orders.sync') ?? FALSE,
      '#required' => FALSE,
    ];

    $form['sales_orders']['types'] = [
      '#type' => 'checkboxes',
      '#title' => $this->t('Order types'),
      '#description' => $this->t('Select order types for synchronization.'),
      '#options' => $order_types_ids,
      '#default_value' => $config->get('sales_orders.types') ?? FALSE,
      '#states' => [
        'required' => [
          ':input[name="sales_orders[sync]"]' => ['value' => 1],
        ],
      ],
    ];

    $form['sales_orders']['warehouse_code'] = [
      '#type' => 'textfield',
      '#title' => $this->t('Warehouse code'),
      '#description' => $this->t('Default warehouse code, required for sales orders. You can alter it via event subscriber programmatically for any order. @see \Drupal\commerce_unleashed\Events\UnleashedEvents::UNLEASHED_PURCHASE_ORDER'),
      '#default_value' => $config->get('sales_orders.warehouse_code') ?? '',
      '#states' => [
        'required' => [
          ':input[name="sales_orders[sync]"]' => ['value' => 1],
        ],
      ],
    ];

    $form['sales_orders']['shipping_sku'] = [
      '#type' => 'textfield',
      '#title' => $this->t('Shipping SKU'),
      '#description' => $this->t('SKU of the product from Unleashed that will be used for shipping.'),
      '#default_value' => $config->get('sales_orders.shipping_sku') ?? '',
      '#states' => [
        'required' => [
          ':input[name="sales_orders[sync]"]' => ['value' => 1],
        ],
      ],
    ];

    $form['sales_orders']['complete'] = [
      '#type' => 'checkbox',
      '#title' => $this->t('Complete orders from Drupal'),
      '#description' => $this->t('Once the order is completed in Drupal, send an complete call to Unleashed'),
      '#default_value' => $config->get('sales_orders.complete') ?? FALSE,
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

    $form['stock']['page_size'] = [
      '#type' => 'number',
      '#title' => $this->t('Page size'),
      '#description' => $this->t('How many stock-on-hand rows to ask for per request. Defaults to @default.', ['@default' => UnleashedManager::DEFAULT_PAGE_SIZE]),
      '#default_value' => $config->get('stock.page_size') ?: UnleashedManager::DEFAULT_PAGE_SIZE,
      '#min' => UnleashedManager::MIN_PAGE_SIZE,
      '#max' => UnleashedManager::MAX_PAGE_SIZE,
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
      ->set('sales_orders', $form_state->getValue('sales_orders'))
      ->set('stock', $form_state->getValue('stock'))
      ->save();
    parent::submitForm($form, $form_state);
  }

}
