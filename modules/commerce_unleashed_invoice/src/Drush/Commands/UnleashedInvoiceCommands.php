<?php

declare(strict_types=1);

namespace Drupal\commerce_unleashed_invoice\Drush\Commands;

use Drupal\commerce_order\Entity\OrderInterface;
use Drupal\commerce_unleashed_invoice\InvoiceSync;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drush\Attributes as CLI;
use Drush\Commands\AutowireTrait;
use Drush\Commands\DrushCommands;

/**
 * Invoice commands for the Unleashed integration.
 */
final class UnleashedInvoiceCommands extends DrushCommands {

  use AutowireTrait;

  public function __construct(
    private readonly InvoiceSync $invoiceSync,
    private readonly EntityTypeManagerInterface $entityTypeManager,
  ) {
    parent::__construct();
  }

  /**
   * Reads invoices from Unleashed for orders that do not have one.
   *
   * One API request per order, so this is deliberately opt-in and bounded
   * rather than something cron does. A backlog of completed orders can be
   * tens of thousands; read them in batches you are happy to spend.
   */
  #[CLI\Command(name: 'commerce-unleashed:invoices:backfill', aliases: ['cu-invoices'])]
  #[CLI\Option(name: 'state', description: 'Order state to read invoices for. Defaults to the configured trigger states.')]
  #[CLI\Option(name: 'limit', description: 'How many orders to read. Each is one API request.')]
  #[CLI\Option(name: 'since', description: 'Only orders placed on or after this date (YYYY-MM-DD).')]
  #[CLI\Option(name: 'store', description: 'Only orders in this store ID.')]
  #[CLI\Usage(name: 'drush commerce-unleashed:invoices:backfill --limit=50', description: 'Read 50 orders that have no invoice yet.')]
  #[CLI\Usage(name: 'drush commerce-unleashed:invoices:backfill --since=2026-01-01 --store=2', description: 'Read this year\'s trade orders.')]
  public function backfill(array $options = ['state' => NULL, 'limit' => 100, 'since' => NULL, 'store' => NULL]): int {
    $states = $options['state'] ? [(string) $options['state']] : $this->invoiceSync->triggerStates();
    if ($states === []) {
      $this->io()->error('No order states configured, and none given with --state.');

      return self::EXIT_FAILURE;
    }

    $limit = max(1, (int) $options['limit']);
    $order_storage = $this->entityTypeManager->getStorage('commerce_order');

    $query = $order_storage->getQuery()
      ->accessCheck(FALSE)
      ->condition('state', $states, 'IN')
      ->sort('order_id', 'DESC')
      // Asking for more than the limit so orders that already have an invoice
      // can be skipped without the run stopping short of what was asked for.
      ->range(0, $limit * 4);

    if ($options['since']) {
      $since = strtotime((string) $options['since']);
      if ($since === FALSE) {
        $this->io()->error(sprintf('Could not read --since=%s as a date.', $options['since']));

        return self::EXIT_FAILURE;
      }
      $query->condition('placed', $since, '>=');
    }

    if ($options['store']) {
      $query->condition('store_id', (int) $options['store']);
    }

    $ids = $query->execute();
    if (!$ids) {
      $this->io()->writeln('No orders matched.');

      return self::EXIT_SUCCESS;
    }

    $counts = ['created' => 0, 'updated' => 0, 'unchanged' => 0, 'deleted' => 0, 'none' => 0, 'failed' => 0];
    $read = 0;

    foreach (array_chunk($ids, 50) as $chunk) {
      foreach ($order_storage->loadMultiple($chunk) as $order) {
        if ($read >= $limit) {
          break 2;
        }
        if (!$order instanceof OrderInterface || $this->hasInvoice($order)) {
          continue;
        }
        $read++;
        $counts[$this->invoiceSync->syncOrder($order)]++;
      }
      $order_storage->resetCache($chunk);
    }

    $this->io()->writeln(sprintf(
      '%d order(s) read: %d created, %d updated, %d unchanged, %d retired, %d not invoiced yet, %d failed.',
      $read,
      $counts['created'],
      $counts['updated'],
      $counts['unchanged'],
      $counts['deleted'],
      $counts['none'],
      $counts['failed'],
    ));

    return $counts['failed'] > 0 ? self::EXIT_FAILURE : self::EXIT_SUCCESS;
  }

  /**
   * Whether an order already carries one of this module's invoices.
   */
  private function hasInvoice(OrderInterface $order): bool {
    $ids = $this->entityTypeManager->getStorage('commerce_invoice')->getQuery()
      ->accessCheck(FALSE)
      ->condition('type', InvoiceSync::INVOICE_TYPE)
      ->condition('orders', $order->id())
      ->range(0, 1)
      ->execute();

    return (bool) $ids;
  }

}
