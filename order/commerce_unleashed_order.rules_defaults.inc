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

  // Post order action set.
  $rule = rules_action_set(array(
    'commerce_order' => array(
      'label' => 'Order',
      'type' => 'commerce_order',
    ),
  ));

  $rule->label = 'Post order to Unleashed';

  $rule->action('component_commerce_unleashed_customer_post', array('user:select' => 'commerce-order:owner'));
  $rule->action('commerce_unleashed_order_post', array('commerce_order:select' => 'commerce-order'));

  $rules['commerce_unleashed_order_post'] = $rule;

  // Post an order when saved as compleat.
  $rule = rules_reaction_rule();

  $rule->label = 'Post a completed order to Unleashed';
  $rule->active = FALSE;
  $rule->tags = array('Unleashed', 'Commerce');

  $rule->event('commerce_order_update');

  $rule->condition('data_is', array(
    'data:select' => 'commerce-order:state',
    'value' => 'compleated',
  ));

  $rule->action('component_commerce_unleashed_order_post', array(
    'order:select' => 'commerce-order',
  ));

  $rules['commerce_unleased_order_post_compleat'] = $rule;

  return $rules;

}
