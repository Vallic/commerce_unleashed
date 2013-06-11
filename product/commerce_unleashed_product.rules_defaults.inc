<?php

/**
 * @file
 * Default rule configurations for Commerce Unleashed Product.
 */

/**
 * Implements hook_default_rules_configuration().
 */
function commerce_unleashed_product_default_rules_configuration() {
  $rules = array();

  $rule = rules_action_set(array(
    'product' => array(
      'label' => 'Product',
      'type' => 'commerce_product',
    ),
  ));

  $rule->label = 'Post product to Unleashed';

  $rule->action('commerce_unleashed_product_post', array('commerce_product:select' => 'product'));

  $rules['commerce_unleashed_product_post'] = $rule;

  return $rules;

}
