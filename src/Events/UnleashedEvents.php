<?php

namespace Drupal\commerce_unleashed\Events;

/**
 * Defines events for the Commerce Unleashed module.
 */
final class UnleashedEvents {

  /**
   * Name of the event fired before creating queue item.
   *
   * @Event
   *
   * @see \Drupal\commerce_unleashed\Events\UnleashedSyncEvent
   */
  const UNLEASHED_SYNC_EVENT = 'commerce_unleashed.event.sync';

  /**
   * Name of the event fired when sending product variation to Unleashed.
   *
   * @Event
   *
   * @see \Drupal\commerce_unleashed\Events\UnleashedProductVariationEvent
   */
  const UNLEASHED_PRODUCT_VARIATION = 'commerce_unleashed.event.product_variation';

  /**
   * Name of the event fired when sending product variation to Unleashed.
   *
   * @Event
   *
   * @see \Drupal\commerce_unleashed\Events\UnleashedProductEvent
   */
  const UNLEASHED_PRODUCT = 'commerce_unleashed.event.product';

  /**
   * Name of the event fired when sending order to Unleashed.
   *
   * @Event
   *
   * @see \Drupal\commerce_unleashed\Events\UnleashedOrderEvent
   */
  const UNLEASHED_ORDER = 'commerce_unleashed.event.order';

}
