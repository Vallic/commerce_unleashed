<?php

/**
 * @file
 * Provide views data for the Unleashed module.
 */

/**
 * Implements hook_views_data().
 */
function commerce_unleashed_views_data(): array {
  $data['commerce_unleashed_stock_on_hand'][]['table'] = [];
  $data['commerce_unleashed_stock_on_hand']['table']['group'] = 'Unleashed';
  $data['commerce_unleashed_stock_on_hand']['table']['base'] = [
    'field' => 'stock',
    'title' => 'Unleashed stock',
  ];
  $data['commerce_unleashed_stock_on_hand']['ProductCode'] = [
    'title' => 'ProductCode',
    'field' => [
      'id' => 'standard',
    ],
    'filter' => [
      'id' => 'string',
    ],
    'argument' => [
      'id' => 'string',
    ],
    'relationship' => [
      'base' => 'commerce_product_variation_field_data',
      'base field' => 'sku',
      'id' => 'standard',
      'label' => 'Product variation',
    ],
  ];
  $data['commerce_unleashed_stock_on_hand']['AvailableQty'] = [
    'title' => 'AvailableQty',
    'field' => [
      'id' => 'standard',
    ],
    'argument' => [
      'id' => 'numeric',
    ],
    'filter' => [
      'id' => 'numeric',
    ],
    'sort' => [
      'id' => 'standard',
    ],
  ];
  $data['commerce_unleashed_stock_on_hand']['AllocatedQty'] = [
    'title' => 'AllocatedQty',
    'field' => [
      'id' => 'standard',
    ],
    'argument' => [
      'id' => 'numeric',
    ],
    'filter' => [
      'id' => 'numeric',
    ],
    'sort' => [
      'id' => 'standard',
    ],
  ];
  $data['commerce_unleashed_stock_on_hand']['QtyOnHand'] = [
    'title' => 'QtyOnHand',
    'field' => [
      'id' => 'standard',
    ],
    'argument' => [
      'id' => 'numeric',
    ],
    'filter' => [
      'id' => 'numeric',
    ],
    'sort' => [
      'id' => 'standard',
    ],
  ];
  $data['commerce_unleashed_stock_on_hand']['ProductGuid'] = [
    'title' => 'Product Guid',
    'field' => [
      'id' => 'standard',
    ],
    'argument' => [
      'id' => 'numeric',
    ],
    'filter' => [
      'id' => 'numeric',
    ],
    'sort' => [
      'id' => 'standard',
    ],
  ];
  $data['commerce_unleashed_stock_on_hand']['timestamp'] = [
    'title' => 'Stock update timestamp',
    'field' => [
      'id' => 'date',
    ],
    'argument' => [
      'id' => 'numeric',
    ],
    'filter' => [
      'id' => 'numeric',
    ],
    'sort' => [
      'id' => 'standard',
    ],
  ];
  return $data;
}

/**
 * Implements hook_views_data_alter().
 */
function commerce_unleashed_views_data_alter(array &$data): void {
  $data['commerce_product_variation_field_data']['stock_on_hand'] = [
    'title' => t('Stock on hand'),
    'real field' => 'sku',
    'relationship' => [
      'group' => 'Product Variation',
      'help' => t('Reference stock to the product variation (sku).'),
      'id' => 'standard',
      'base' => 'commerce_unleashed_stock_on_hand',
      'base field' => 'ProductCode',
    ],
  ];
}
