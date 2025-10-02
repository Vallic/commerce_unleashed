<?php

namespace Drupal\commerce_unleashed\Events;

use Drupal\commerce\EventBase;

/**
 * Defines event before payload is queued.
 *
 * @see \Drupal\commerce_unleashed\Events\UnleashedEvents
 */
class UnleashedSyncEvent extends EventBase {

  public function __construct(protected array $payload, protected bool $skipSync = FALSE) {}

  /**
   * Gets the Unleashed payload.
   */
  public function getPayload(): array {
    return $this->payload;
  }

  /**
   * Set's the skipSync property.
   */
  public function setSkipSync(bool $skipSync): UnleashedSyncEvent {
    $this->skipSync = $skipSync;
    return $this;
  }

  /**
   * Determine if we're skipping this item to be queued.
   */
  public function skipSync(): bool {
    return $this->skipSync;
  }

}
