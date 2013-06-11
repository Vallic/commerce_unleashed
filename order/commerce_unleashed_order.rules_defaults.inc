<?php

/**
 * @file
 * Default rule configurations for Commerce Unleashed Order.
 */

/**
 * Implements hook_default_rules_configuration().
 */
function commerce_unleashed_order_default_rules_configuration() {
  $rules = array();

  $rule = rules_action_set(array(
    'commerce_order' => array(
      'label' => 'Order',
      'type' => 'commerce_order',
    ),
  ));

  $rule->label = 'Post order to Unleashed';

  $rule->action('component_commerce_unleashed_customer_post', array('user:select' => 'order:owner'));
  $rule->action('commerce_unleashed_order_post', array('commerce_order:select' => 'order'));

  $rules['commerce_unleashed_order_post'] = $rule;

  return $rules;

}
