<?php

declare(strict_types=1);

namespace Drupal\commerce_unleashed\Events;

use Drupal\commerce\EventBase;

/**
 * Fired after a stock sync has written totals that changed.
 *
 * Dispatched once per run, after the transaction has committed, so a
 * subscriber reading the stock table sees what the sync wrote. Listen here to
 * reindex search, invalidate caches, or tell customers something is back.
 *
 * @see \Drupal\commerce_unleashed\Events\UnleashedEvents::STOCK_CHANGED
 */
class UnleashedStockChangedEvent extends EventBase {

  /**
   * Constructs the event.
   *
   * @param array<string, array{0: float|null, 1: float}> $changes
   *   The available quantity before and after, keyed by SKU. Before is NULL
   *   for a SKU the stock table had no row for.
   * @param bool $full
   *   Whether the run was a full pull rather than a delta.
   * @param bool $notify
   *   Whether the change is news. FALSE for the first full pull, which fills
   *   the per-warehouse store and corrects figures rather than reporting
   *   deliveries, and when whoever started the sync asked for quiet.
   */
  public function __construct(
    protected readonly array $changes,
    protected readonly bool $full,
    protected readonly bool $notify,
  ) {}

  /**
   * The available quantity before and after, keyed by SKU.
   *
   * @return array<string, array{0: float|null, 1: float}>
   *   Before (NULL when there was no row) and after, by SKU.
   */
  public function getChanges(): array {
    return $this->changes;
  }

  /**
   * Whether the run was a full pull.
   */
  public function isFull(): bool {
    return $this->full;
  }

  /**
   * Whether listeners should tell people about these changes.
   */
  public function shouldNotify(): bool {
    return $this->notify;
  }

}
