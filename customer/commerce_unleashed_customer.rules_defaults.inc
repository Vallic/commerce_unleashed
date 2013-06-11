<?php

/**
 * @file
 * Default rule configurations for Commerce Unleashed Customer.
 */

/**
 * Implements hook_default_rules_configuration().
 */
function commerce_unleashed_customer_default_rules_configuration() {
  $rules = array();

  $rule = rules_action_set(array(
    'user' => array(
      'label' => 'User',
      'type' => 'user',
    ),
  ));

  $rule->label = 'Post customer to Unleashed';

  $rule->action('commerce_unleashed_customer_post', array('user:select' => 'user'));

  $rules['commerce_unleashed_customer_post'] = $rule;

  return $rules;

}
