<?php

declare(strict_types=1);

namespace Drupal\Tests\commerce_unleashed\Unit;

use Drupal\commerce_unleashed\UnleashedClient;
use Drupal\commerce_unleashed\UnleashedManager;
use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Config\ImmutableConfig;
use Drupal\Core\Database\Connection;
use Drupal\Core\Datetime\DateFormatterInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Logger\LoggerChannelFactoryInterface;
use Drupal\Core\Logger\LoggerChannelInterface;
use Drupal\Tests\UnitTestCase;
use PHPUnit\Framework\MockObject\MockObject;
use Symfony\Component\EventDispatcher\EventDispatcherInterface;

/**
 * Builds a real manager over a mocked client, settings and database.
 */
abstract class ManagerTestBase extends UnitTestCase {

  /**
   * The mocked API client.
   */
  protected UnleashedClient&MockObject $client;

  /**
   * The logger channel every message goes to.
   */
  protected LoggerChannelInterface&MockObject $logger;

  /**
   * A manager with the given settings, keyed by their config path.
   *
   * @param array<string, mixed> $settings
   *   Settings by dotted key, e.g. 'stock.warehouses'.
   * @param \Drupal\Core\Database\Connection|null $connection
   *   The database, or NULL for a bare mock.
   * @param \Symfony\Component\EventDispatcher\EventDispatcherInterface|null $dispatcher
   *   The event dispatcher, or NULL for a bare mock.
   */
  protected function manager(array $settings = [], ?Connection $connection = NULL, ?EventDispatcherInterface $dispatcher = NULL): UnleashedManager {
    $config = $this->createMock(ImmutableConfig::class);
    $config->method('get')->willReturnCallback(static fn (string $key): mixed => $settings[$key] ?? NULL);
    $config_factory = $this->createMock(ConfigFactoryInterface::class);
    $config_factory->method('get')->willReturn($config);

    $manager = new UnleashedManager(
      $this->createMock(EntityTypeManagerInterface::class),
      $config_factory,
      $dispatcher ?? $this->createMock(EventDispatcherInterface::class),
      $this->createMock(DateFormatterInterface::class),
      $connection ?? $this->createMock(Connection::class),
    );

    $this->client = $this->createMock(UnleashedClient::class);
    (new \ReflectionProperty(UnleashedManager::class, 'unleashedClient'))->setValue($manager, $this->client);

    $this->logger = $this->createMock(LoggerChannelInterface::class);
    $logger_factory = $this->createMock(LoggerChannelFactoryInterface::class);
    $logger_factory->method('get')->willReturn($this->logger);
    $manager->setLoggerFactory($logger_factory);

    return $manager;
  }

  /**
   * One page of an API list response.
   */
  protected static function page(array $items, int $pages = 1, int $page = 1): array {
    return [
      'Pagination' => ['NumberOfPages' => $pages, 'PageNumber' => $page],
      'Items' => $items,
    ];
  }

}
