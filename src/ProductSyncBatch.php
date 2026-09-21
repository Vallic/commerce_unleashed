<?php

namespace Drupal\commerce_unleashed;

use Drupal\Core\StringTranslation\TranslatableMarkup;

/**
 * Walks the whole catalog one page per batch operation.
 *
 * A full read is a hundred-odd API requests and tens of thousands of products.
 * Done in one PHP process it holds every page it has seen, and a failure
 * anywhere loses the lot. A batch gives each page its own request, so memory
 * is returned between pages and progress survives whatever happens next.
 *
 * It also paces the read. Unleashed meter their API by the month and have
 * asked integrators not to hammer it; a batch yields between operations
 * rather than issuing a hundred requests back to back as fast as the network
 * allows.
 */
final class ProductSyncBatch {

  /**
   * Builds the batch definition for a full catalog read.
   *
   * @param string $query
   *   The query to read with, without a page number.
   * @param int $pages
   *   How many pages the read covers.
   * @param bool $complete
   *   Whether this read covers the whole catalog. A read narrowed by a filter
   *   does not, and must not be recorded as one.
   *
   * @return array
   *   A batch definition for batch_set().
   */
  public static function definition(string $query, int $pages, bool $complete = TRUE): array {
    $operations = [];
    for ($page = 1; $page <= $pages; $page++) {
      $operations[] = [[self::class, 'syncPage'], [$query, $page, $pages, $complete]];
    }

    return [
      'title' => new TranslatableMarkup('Reading the Unleashed catalog'),
      'operations' => $operations,
      'finished' => [self::class, 'finished'],
      'progress_message' => new TranslatableMarkup('Page @current of @total.'),
    ];
  }

  /**
   * Reads one page and queues what it holds.
   */
  public static function syncPage(string $query, int $page, int $pages, bool $complete, array &$context): void {
    $context['results'] += ['queued' => 0, 'pages' => 0, 'failed' => []];
    $context['results']['complete'] = $complete;

    try {
      $result = \Drupal::service('commerce_unleashed.manager')->syncProductPage($query, $page);
      $context['results']['queued'] += $result['items'];
      $context['results']['pages']++;
    }
    catch (\Throwable $e) {
      // One page failing is not a reason to abandon the rest: the pages that
      // did arrive are already queued, and the run reports what it missed so
      // it can be read again rather than starting over.
      $context['results']['failed'][] = $page;
      \Drupal::logger('commerce_unleashed')->error('Product page @page of @pages failed: @message', [
        '@page' => $page,
        '@pages' => $pages,
        '@message' => $e->getMessage(),
      ]);
    }

    $context['message'] = new TranslatableMarkup('Read page @page of @pages.', [
      '@page' => $page,
      '@pages' => $pages,
    ]);
  }

  /**
   * Records the run, so the next cron reads a delta rather than everything.
   */
  public static function finished(bool $success, array $results, array $operations): void {
    $failed = $results['failed'] ?? [];

    // The timestamp is what turns every later read into a modifiedSince
    // delta, and it means "the whole catalog has been read up to here". It is
    // written only for a read that was both COMPLETE and unfiltered. A run
    // narrowed by a filter never saw what the filter excluded, so recording
    // it would have cron skip that part of the catalog forever; and a run
    // that lost pages would never look at them again, because a delta from
    // now on will not mention a product that did not change since.
    if ($success && !$failed && ($results['complete'] ?? FALSE)) {
      \Drupal::keyValue('commerce_unleashed')->set('products', \Drupal::time()->getRequestTime());
    }

    $logger = \Drupal::logger('commerce_unleashed');
    if ($failed) {
      $logger->warning('Full product sync finished with @count page(s) unread: @pages. The delta marker was NOT set, so the next full sync still starts from the beginning.', [
        '@count' => count($failed),
        '@pages' => implode(', ', $failed),
      ]);

      return;
    }

    $logger->info('Full product sync read @pages page(s) and queued @queued product(s).', [
      '@pages' => $results['pages'] ?? 0,
      '@queued' => $results['queued'] ?? 0,
    ]);
  }

}
