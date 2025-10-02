<?php

namespace Drupal\commerce_unleashed\Controller;

use Drupal\commerce_price\Price;
use Drupal\Core\Controller\ControllerBase;
use Drupal\Core\Database\Connection;
use Drupal\Core\Database\Query\PagerSelectExtender;
use Drupal\Core\Database\Query\TableSortExtender;
use Symfony\Component\HttpFoundation\Request;

/**
 * Returns responses for stock on hand routes.
 */
class StockOnHandController extends ControllerBase {

  /**
   * The database service.
   */
  protected Connection $database;

  /**
   * Constructs a StockOnHandController object.
   */
  public function __construct(Connection $database) {
    $this->database = $database;
  }

  /**
   * Displays a listing of database log messages.
   */
  public function overview(Request $request) {

    $rows = [];

    $header = [
      [
        'data' => $this->t('Title'),
        'field' => 'v.title',
        'class' => [RESPONSIVE_PRIORITY_MEDIUM],
      ],
      [
        'data' => $this->t('SKU'),
        'field' => 's.ProductCode',
        'class' => [RESPONSIVE_PRIORITY_MEDIUM],
      ],
      [
        'data' => $this->t('AllocatedQty'),
        'field' => 's.AllocatedQty',
        'sort' => 'desc',
        'class' => [RESPONSIVE_PRIORITY_LOW],
      ],
      [
        'data' => $this->t('AvailableQty'),
        'field' => 's.AvailableQty',
        'sort' => 'desc',
        'class' => [RESPONSIVE_PRIORITY_LOW],
      ],
      [
        'data' => $this->t('QtyOnHand'),
        'field' => 's.QtyOnHand',
        'sort' => 'desc',
        'class' => [RESPONSIVE_PRIORITY_LOW],
      ],
      [
        'data' => $this->t('ProductGuid'),
        'field' => 's.ProductGuid',
        'class' => [RESPONSIVE_PRIORITY_MEDIUM],
      ],
      [
        'data' => $this->t('Price'),
        'field' => 'v.price__number',
        'sort' => 'desc',
        'class' => [RESPONSIVE_PRIORITY_MEDIUM],
      ],
    ];

    $query = $this->database->select('commerce_unleashed_stock_on_hand', 's')
      ->extend(PagerSelectExtender::class)
      ->extend(TableSortExtender::class);
    $query->fields('s', [
      'ProductCode',
      'AllocatedQty',
      'AvailableQty',
      'QtyOnHand',
      'ProductGuid',
    ]);
    $query->leftJoin('commerce_product_variation_field_data', 'v', '[s].[ProductCode] = [v].[sku]');
    $query->fields('v', [
      'title',
      'product_id',
      'variation_id',
      'price__number',
      'price__currency_code',
    ]);

    $result = $query
      ->limit(50)
      ->orderByHeader($header)
      ->execute();

    foreach ($result as $item) {
      $rows[] = [
        'data' => [
          $item->title,
          $item->ProductCode,
          $item->AllocatedQty,
          $item->AvailableQty,
          $item->QtyOnHand,
          $item->ProductGuid,
          !empty($item->price__currency_code) ? new Price($item->price__number ?? '0', $item->price__currency_code) : '',
        ],
      ];
    }

    $build['table'] = [
      '#type' => 'table',
      '#header' => $header,
      '#rows' => $rows,
      '#empty' => $this->t('No stock on hand.'),
    ];
    $build['pager'] = ['#type' => 'pager'];

    return $build;

  }

}
