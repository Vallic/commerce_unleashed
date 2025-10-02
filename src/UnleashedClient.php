<?php

namespace Drupal\commerce_unleashed;

use Drupal\Component\Serialization\Json;
use Drupal\Core\Logger\LoggerChannelTrait;
use GuzzleHttp\Client;
use GuzzleHttp\ClientInterface;
use Symfony\Component\HttpKernel\Exception\BadRequestHttpException;

class UnleashedClient {

  use LoggerChannelTrait;

  protected ClientInterface $client;

  public const UNLEASHED_API = 'https://api.unleashedsoftware.com/';

  public function __construct(protected string $apiId, protected string $apiKey, protected bool $logging = FALSE) {
    $this->client = new Client();
  }

  /**
   * Method to process requests towards Unleashed API.
   */
  protected function request(string $method, string $endpoint, array $payload = [], string $query = ''): array {
    $endpoint_url = sprintf('%s%s', self::UNLEASHED_API, $endpoint);
    if (!empty($query)) {
      $endpoint_url .= '?' . $query;
    }

    try {
      $request_data = [
        'headers' => [
          'Content-Type' => 'application/json',
          'Accept' => 'application/json',
          'api-auth-id' => $this->apiId,
          'api-auth-signature' => $this->getSignature($query),
          'client-type' => 'vallic/commerce_unleashed',
          'User-Agent' => sprintf('Commerce Core - Drupal 11 - https://www.drupal.org/project/commerce_unleashed (Language=PHP/%s;)', PHP_VERSION),
        ],
      ];

      if ($payload) {
        $request_data['body'] = Json::encode($payload);
        if ($this->logging) {
          $this->getLogger('commerce_unleashed')->info(json_encode($payload, JSON_THROW_ON_ERROR));
        }
      }

      $response = $this->client->request($method, $endpoint_url, $request_data);
    }
    catch (\Exception $exception) {
      $error = $exception->getMessage();
      $this->getLogger('commerce_unleashed')->error($error);
      $data = Json::decode($exception->getResponse()->getBody()) ?? [];

      if (isset($data['Description'])) {
        return ['error' => $data['Description']];
      }
      throw new BadRequestHttpException($exception->getMessage());
    }

    $contents = $response->getBody()->getContents();

    if ($this->logging) {
      $this->getLogger('commerce_unleashed')->info($contents);
    }

    return Json::decode($contents);
  }

  /**
   * Generate signature.
   */
  protected function getSignature($query): string {
    return base64_encode(hash_hmac('sha256', $query, $this->apiKey, TRUE));
  }

  /**
   * Returns products, paginated.
   *
   * @see https://apidocs.unleashedsoftware.com/Products
   */
  public function getProducts(string $query = '', ?int $page_number = NULL): array {
    return $this->request('GET', $page_number ? 'Products/' . $page_number : 'Products', [], $query);
  }

  /**
   * Get single product by guid.
   *
   * @see https://apidocs.unleashedsoftware.com/Products
   */
  public function getProduct($guid): array {
    return $this->request('GET', 'Products/' . $guid);
  }

  /**
   * Get single product by guid.
   *
   * @see https://apidocs.unleashedsoftware.com/Products
   */
  public function getProductBySku($sku): array {
    return $this->request('GET', 'Products?productCode=' . $sku);
  }

  /**
   * Create product with or without guid.
   *
   * @see https://apidocs.unleashedsoftware.com/Products
   */
  public function createProduct(array $payload, ?string $guid): array {
    return $this->request('POST', $guid ? 'Products/' . $guid : 'Products', $payload);
  }

  /**
   * Update product by guid.
   *
   * @see https://apidocs.unleashedsoftware.com/Products
   */
  public function updateProduct($payload, $guid): array {
    return $this->request('POST', 'Products/' . $guid, $payload);
  }

  /**
   * Returns purchase orders, paginated.
   *
   * @see https://apidocs.unleashedsoftware.com/Purchases
   */
  public function getPurchaseOrders($query = ''): array {
    return $this->request('GET', 'PurchaseOrders', [], $query);
  }

  /**
   * Get purchase order by guid.
   *
   * @see https://apidocs.unleashedsoftware.com/Purchases
   */
  public function getPurchaseOrder($guid): array {
    return $this->request('GET', 'PurchaseOrders/' . $guid);
  }

  /**
   * Create purchase order with or without guid.
   *
   * @see https://apidocs.unleashedsoftware.com/Purchases
   */
  public function createPurchaseOrder($payload, ?string $guid): array {
    return $this->request('POST', $guid ? 'PurchaseOrders/' . $guid : 'PurchaseOrders', $payload);
  }

  /**
   * Update purchase order.
   *
   * @see https://apidocs.unleashedsoftware.com/Purchases
   */
  public function updatePurchaseOrder($payload, $guid): void {
    $this->request('PUT', 'PurchaseOrders/' . $guid, $payload);
  }

  /**
   * Complete purchase order.
   *
   * @see https://apidocs.unleashedsoftware.com/Purchases
   */
  public function completePurchaseOrder($guid): void {
    $this->request('POST', 'PurchaseOrders/' . $guid . '/Complete');
  }

  /**
   * Returns customers, paginated.
   *
   * @see https://apidocs.unleashedsoftware.com/Customers
   */
  public function getCustomers($query = ''): array {
    return $this->request('GET', 'Customers', [], $query);
  }

  /**
   * Get customer by guid.
   *
   * @see https://apidocs.unleashedsoftware.com/Customers
   */
  public function getCustomer($guid): array {
    return $this->request('GET', 'Customers/' . $guid);
  }

  /**
   * Create customer with or without guid.
   *
   * @see https://apidocs.unleashedsoftware.com/Customers
   */
  public function createCustomer($payload): array {
    return $this->request('POST', 'Customers', $payload);
  }

  /**
   * Update customer.
   *
   * @see https://apidocs.unleashedsoftware.com/Customers
   */
  public function updateCustomer($guid, $payload): void {
    $this->request('PUT', 'Customers/{$guid}', $payload);
  }

  /**
   * Returns stock states, paginated.
   *
   * @see https://apidocs.unleashedsoftware.com/StockOnHand
   */
  public function getStockOnHand(string $query = '', ?int $page_number = NULL): array {
    return $this->request('GET', $page_number ? 'StockOnHand/' . $page_number : 'StockOnHand', [], $query);
  }

  /**
   * Get stock for product by guid.
   *
   * @see https://apidocs.unleashedsoftware.com/StockOnHand
   */
  public function getStockItem($guid): array {
    return $this->request('GET', 'StockOnHand/' . $guid);
  }

  /**
   * Create or update Stock Adjustment.
   *
   * @see https://apidocs.unleashedsoftware.com/StockAdjustments
   */
  public function createStockAdjustment($payload, ?string $quid): array {
    return $this->request('POST', $quid ? 'StockAdjustments/' . $quid : 'StockAdjustments', $payload);
  }

}
