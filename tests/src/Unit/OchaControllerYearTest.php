<?php

namespace Drupal\Tests\ocha_key_figures\Unit;

use Drupal\Core\Cache\CacheBackendInterface;
use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Config\ImmutableConfig;
use Drupal\Core\DependencyInjection\ContainerBuilder;
use Drupal\Core\Logger\LoggerChannelFactory;
use Drupal\ocha_key_figures\Controller\OchaKeyFiguresController;
use Drupal\Tests\UnitTestCase;
use GuzzleHttp\Client;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Psr7\Response;
use Psr\Log\LoggerInterface;

class OchaControllerYearTest extends UnitTestCase {

  /**
   * An http client.
   */
  protected $httpClient;

  /**
   * NULL cache.
   *
   * @var \Drupal\Core\Cache\CacheBackendInterface
   */
  protected $cache;

  /**
   * Instance of ConfigFactoryInterface.
   *
   * @var configFactory
   */
  protected $configFactory;

  /**
   * Instance of ImmutableConfig.
   *
   * @var config
   */
  protected $config;

  protected $container;

  protected $loggerFactory;

  protected $providers = [
    [
      'id' => 'one',
      'name' => 'One',
      'prefix' => 'one',
    ],
    [
      'id' => 'two',
      'name' => 'Two',
      'prefix' => 'Two',
    ],
  ];

  protected $apiData = [
    [
      'id' => 'cbpf_afg_2023_paid-amount',
      'iso3' => 'afg',
      'country' => 'Afghanistan',
      'year' => '2023',
      'name' => 'Paid amount',
      'value' => '48747623.47',
      'url' => 'https://cbpf.unocha.org/',
      'source' => 'CBPF',
      'description' => '',
      'tags' => [],
      'provider' => 'cbpf',
      'archived' => false,
      'value_type' => 'numeric',
      'unit' => 'USD',
      'figure_id' => 'paid-amount',
    ],
    [
      'id' => 'cbpf_afg_2023_pledged-amount',
      'iso3' => 'afg',
      'country' => 'Afghanistan',
      'year' => '2023',
      'name' => 'Pledged amount',
      'value' => '68856011.00',
      'url' => 'https://cbpf.unocha.org/',
      'source' => 'CBPF',
      'description' => '',
      'tags' => [],
      'provider' => 'cbpf',
      'archived' => false,
      'value_type' => 'numeric',
      'unit' => 'USD',
      'figure_id' => 'pledged-amount',
    ],
  ];

  /**
   * Do the initial setup.
   */
  public function setup(): void {
    parent::setUp();

    $this->container = new ContainerBuilder();

    $this->cache = $this->prophesize(CacheBackendInterface::class);

    $this->config = $this->prophesize(ImmutableConfig::class);
    $this->config->get('ocha_api_url')->willReturn('https://www.example.com/api/v1');
    $this->config->get('ocha_api_key')->willReturn('TEST_API_KEY');
    $this->config->get('ocha_app_name')->willReturn('TEST_API_NAME');
    $this->config->get('max_age')->willReturn(60);

    $this->configFactory = $this->prophesize(ConfigFactoryInterface::class);
    $this->configFactory->get('ocha_key_figures.settings')->willReturn($this->config->reveal());

    $l = $this->prophesize(LoggerInterface::class);

    // Check wich calls are made.
    $l->notice('Fetching data from @url', [
      '@url' => 'https://www.example.com/api/v1/me/providers',
    ])->will(function(){});
    $l->notice('Fetching data from @url', [
      '@url' => 'https://www.example.com/api/v1/one?iso3=bel&archived=0',
    ])->will(function(){});
    $l->notice('Fetching data from @url', [
      '@url' => 'https://www.example.com/api/v1/one?iso3=bel&year=' . date('Y') . '&archived=0',
    ])->will(function(){});
    $l->notice('Fetching data from @url', [
      '@url' => 'https://www.example.com/api/v1/one?iso3=bel&year=1999&archived=0',
    ])->will(function(){});
    $l->notice('Fetching data from @url', [
      '@url' => 'https://www.example.com/api/v1/one/ocha-presences/roap?year%5B0%5D=' . date('Y') . '&year%5B1%5D=' . date('Y') - 1 . '&order%5Byear%5D=DESC',
    ])->will(function(){});
    $l->notice('Fetching data from @url', [
      '@url' => 'https://www.example.com/api/v1/one/ocha-presences/roap/1999/figures',
    ])->will(function(){});

    $this->loggerFactory = $this->prophesize(LoggerChannelFactory::class);
    $this->loggerFactory->get('ocha_key_figures_fts_figures')->willReturn($l->reveal());

    $this->container->set('config.factory', $this->configFactory->reveal());
    $this->container->set('ocha_key_figures.cache', $this->cache->reveal());
    $this->container->set('logger.factory', $this->loggerFactory->reveal());
    \Drupal::setContainer($this->container);
  }

  /**
   * Set HTTP response.
   */
  protected function setHttpDataResult($data): void {
    $mock = new MockHandler([
      new Response(200, [], json_encode($data)),
    ]);

    $handlerStack = HandlerStack::create($mock);
    $this->httpClient = new Client(['handler' => $handlerStack]);

    $this->container->set('http_client', $this->httpClient);
    \Drupal::setContainer($this->container);
  }

  /**
   * Set HTTP responses.
   */
  protected function setHttpDataResults($data): void {
    $mock = new MockHandler();

    foreach ($data as $row) {
      $mock->append(new Response(200, [], json_encode($row)));
    }

    $handlerStack = HandlerStack::create($mock);
    $this->httpClient = new Client(['handler' => $handlerStack]);

    $this->container->set('http_client', $this->httpClient);
    \Drupal::setContainer($this->container);
  }

  /**
   * Test providers.
   */
  public function testGetSupportedProviders() {
    $expected = [
      'one' => 'One',
      'two' => 'Two',
    ];

    $this->setHttpDataResult($this->providers);

    $controller = new OchaKeyFiguresController(
      $this->httpClient,
      $this->cache->reveal(),
      $this->configFactory->reveal(),
    );

    $providers = $controller->getSupportedProviders();

    $this->assertSame($providers, $expected);
  }

  /**
   * Test getFigures any year.
   */
  public function testGetFiguresAnyYear() {
    $this->setHttpDataResults([
      $this->providers,
      $this->apiData,
    ]);

    $controller = new OchaKeyFiguresController(
      $this->httpClient,
      $this->cache->reveal(),
      $this->configFactory->reveal(),
    );

    $data = $controller->getFigures('one', 'bel', 1);

    $this->assertSame($data['cbpf_afg_2023_paid-amount']['id'], 'cbpf_afg_2023_paid-amount');
  }

  /**
   * Test getFigures current year.
   */
  public function testGetFiguresCurrentYear() {
    $this->setHttpDataResults([
      $this->providers,
      $this->apiData,
    ]);

    $controller = new OchaKeyFiguresController(
      $this->httpClient,
      $this->cache->reveal(),
      $this->configFactory->reveal(),
    );

    $data = $controller->getFigures('one', 'bel', 2);

    $this->assertSame($data['cbpf_afg_2023_paid-amount']['id'], 'cbpf_afg_2023_paid-amount');
  }

  /**
   * Test getFigures current year.
   */
  public function testGetFigures1999() {
    $this->setHttpDataResults([
      $this->providers,
      $this->apiData,
    ]);

    $controller = new OchaKeyFiguresController(
      $this->httpClient,
      $this->cache->reveal(),
      $this->configFactory->reveal(),
    );

    $data = $controller->getFigures('one', 'bel', 1999);

    $this->assertSame($data['cbpf_afg_2023_paid-amount']['id'], 'cbpf_afg_2023_paid-amount');
  }

  /**
   * Test getOchaPresenceFigures current year.
   */
  public function testgetOchaPresenceFiguresCurrentYear() {
    $this->setHttpDataResults([
      $this->providers,
      $this->apiData,
    ]);

    $controller = new OchaKeyFiguresController(
      $this->httpClient,
      $this->cache->reveal(),
      $this->configFactory->reveal(),
    );

    $data = $controller->getOchaPresenceFigures('one', 'roap', 2);

    $this->assertSame($data[0]['id'], 'cbpf_afg_2023_paid-amount');
  }

  /**
   * Test getOchaPresenceFigures for 1999.
   */
  public function testgetOchaPresenceFigures1999() {
    $this->setHttpDataResults([
      $this->providers,
      $this->apiData,
    ]);

    $controller = new OchaKeyFiguresController(
      $this->httpClient,
      $this->cache->reveal(),
      $this->configFactory->reveal(),
    );

    $data = $controller->getOchaPresenceFigures('one', 'roap', 1999);

    $this->assertSame($data[0]['id'], 'cbpf_afg_2023_paid-amount');
  }
}
