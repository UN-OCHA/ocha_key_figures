<?php

namespace Drupal\ocha_key_figures\Controller;

use Drupal\Component\Utility\UrlHelper;
use Drupal\Core\Cache\Cache;
use Drupal\Core\Cache\CacheBackendInterface;
use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\DependencyInjection\ContainerInjectionInterface;
use Drupal\Core\Logger\LoggerChannelTrait;
use Drupal\Core\StringTranslation\StringTranslationTrait;
use Drupal\ocha_key_figures\Plugin\Field\FieldType\KeyFigure;
use GuzzleHttp\ClientInterface;
use GuzzleHttp\Exception\RequestException;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * Base controller for Key Figures.
 */
class OchaKeyFiguresController implements ContainerInjectionInterface {

  use LoggerChannelTrait;
  use StringTranslationTrait;

  /**
   * The HTTP client to fetch the files with.
   *
   * @var \GuzzleHttp\ClientInterface
   */
  protected $httpClient;

  /**
   * Cache backend.
   *
   * @var \Drupal\Core\Cache\CacheBackendInterface
   */
  protected $cacheBackend;

  /**
   * The configuration factory.
   *
   * @var \Drupal\Core\Config\ConfigFactoryInterface
   */
  protected $configFactory;

  /**
   * Cache Id.
   *
   * @var string
   */
  protected $cacheId = 'keyfigures';

  /**
   * API URL.
   *
   * @var string
   */
  protected $apiUrl = '';

  /**
   * API Key.
   *
   * @var string
   */
  protected $apiKey = '';

  /**
   * App name.
   *
   * @var string
   */
  protected $appName = '';

  /**
   * Cache live.
   *
   * @var int
   */
  protected $cacheDuration = 60 * 60;

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container) {
    return new static(
      $container->get('http_client'),
      $container->get('ocha_key_figures.cache'),
      $container()->get('config.factory'),
    );
  }

  /**
   * {@inheritdoc}
   */
  protected function config($name) {
    return $this->configFactory->get($name);
  }

  /**
   * {@inheritdoc}
   */
  public function __construct(ClientInterface $http_client, CacheBackendInterface $cache, ConfigFactoryInterface $config_factory) {
    $this->httpClient = $http_client;
    $this->cacheBackend = $cache;
    $this->configFactory = $config_factory;

    $this->apiUrl = $this->config('ocha_key_figures.settings')->get('ocha_api_url');
    $this->apiKey = $this->config('ocha_key_figures.settings')->get('ocha_api_key');
    $this->appName = $this->config('ocha_key_figures.settings')->get('ocha_app_name');
    $this->cacheDuration = $this->config('ocha_key_figures.settings')->get('max_age');

    // Make sure it ends with a slash.
    $this->apiUrl = rtrim($this->apiUrl, '/') . '/';
  }

  /**
   * Get max age.
   */
  public function getMaxAge() {
    return $this->cacheDuration;
  }

  /**
   * Fetch Key Figures.
   *
   * @param string $iso3
   *   ISO3 of the country we want Key Figures for.
   * @param string $year
   *   Optional year.
   * @param string $show_all
   *   Show also archived figures.
   *
   * @return array<string, mixed>
   *   Raw results.
   */
  protected function fetchKeyFigures(string $provider, string $iso3, $year = '', $show_all = FALSE) : array {
    $query = [
      'iso3' => $iso3,
      'year' => $year,
    ];

    if (!$show_all) {
      $query['archived'] = 0;
    }

    // Special case for year.
    if (empty($year) || $year == 1) {
      // No need to filter.
      unset($query['year']);
    }
    elseif ($year == 2) {
      $query['year'] = date('Y');
    }

    $prefix = $this->getPrefix($provider);
    $data = $this->getData($prefix, $query);

    foreach ($data as $key => $row) {
      $data[$key]['date'] = new \DateTime($row['year'] . '-01-01');
      if (isset($row['updated']) && !empty($row['updated'])) {
        $data[$key]['date'] = new \DateTime(substr($row['updated'], 0, 10));
      }
    }

    // Sort the values by newest first.
    usort($data, function ($a, $b) {
      return (int) ($b['date'] > $a['date']);
    });

    return $data;
  }

  /**
   * Fetch Key Figures.
   *
   * @param string $iso3
   *   ISO3 of the country we want Key Figures for.
   * @param string $year
   *   Optional year.
   * @param string $show_all
   *   Show also archived figures.
   *
   * @return array<string, mixed>
   *   Raw results.
   */
  public function getKeyFigures(string $provider, string $iso3, $year = '', $show_all = FALSE) : array {
    $data = $this->fetchKeyFigures($provider, $iso3, $year, $show_all);

    $results = [];
    foreach ($data as $row) {
      $results[$row['id']] = $row;
    }

    return $results;
  }

  /**
   * Fetch Key Figures.
   *
   * @param string $iso3
   *   ISO3 of the country we want Key Figures for.
   * @param string $year
   *   Optional year.
   * @param string $show_all
   *   Show also archived figures.
   *
   * @return array<string, mixed>
   *   Raw results.
   */
  public function getKeyFiguresGrouped(string $provider, string $iso3, $year = '', $show_all = FALSE) : array {
    $data = $this->fetchKeyFigures($provider, $iso3, $year, $show_all);

    $results = [];

    foreach ($data as $row) {
      if (!isset($results[$row['id']])) {
        $results[$row['id']] = $row;
        $results[$row['id']]['values'] = [];
      }

      $results[$row['id']]['values'][] = [
        'date' => $row['date'],
        'value' => $row['value'],
      ];

      // Merge historic values if present.
      if (isset($row['historic_values']) && is_array($row['historic_values'])) {
        foreach ($row['historic_values'] as $fig) {
          // Handle invalid dates.
          try {
            $date = new \DateTime(substr($fig['date'], 0, 10));
          } catch (\Throwable $th) {
            $date_parts = explode('-', substr($fig['date'], 0, 10));
            // Change day to 1.
            $date_parts[2] = '01';
            if ($date_parts[1] > 12) {
              $date_parts[1] = 12;
            }
            $date = new \DateTime(implode('-', $date_parts));
          }
          $results[$row['id']]['values'][] = [
            'date' => $date,
            'value' => $fig['value'],
          ];
        }
      }
    }

    return $results;
  }

  /**
   * Query figures.
   *
   * @param string $path
   *   API path.
   * @param array $query
   *   Query options.
   * @param bool $use_cache
   *   Use caching.
   *
   * @return array<string, mixed>
   *   Raw results.
   */
  public function query(string $provider, string $path, array $query = [], bool $use_cache = TRUE) : array {
    // Fetch data.
    $prefix = $this->getPrefix($provider);
    return $this->getData($prefix . '/' . $path, $query, $use_cache);
  }

  public function getFigure(string $provider, string $id) : array {
    $data = $this->query($provider, strtolower($id));
    $data['cache_tags'] = $this->getCacheTags($data);

    return $data;
  }

  /**
   * Get figure by figure id.
   */
  public function getFigureByFigureId(string $provider, string $country, string $year, string $figure_id) : array {
    $query = [
      'iso3' => $country,
      'year' => $year,
      'archived' => 0,
      'figure_id' => $figure_id,
      'order' => [
        'year' => 'desc',
      ],
    ];

    if ($year == 2) {
      unset($query['year']);
      // Get current and previous year.
      $query['year'] = [
        date('Y'),
        date('Y') - 1,
      ];
    }

    $data = $this->query($provider, '', $query);
    if (!$data) {
      return [];
    }

    // Keep first record.
    $data = reset($data);
    $data['cache_tags'] = $this->getCacheTags($data);

    return $data;
  }

  /**
   * Get the figures available for the figure provider, country and year.
   *
   * @param string $provider
   *   Provider.
   * @param string|array $country
   *   ISO3 code of a country.
   * @param string $year
   *   Year.
   *
   * @return array
   *   Associative array keyed by figure ID and with figures data as values.
   */
  public function getFigures($provider, $country, $year) {
    $query = [
      'iso3' => $country,
      'year' => $year,
      'archived' => 0,
    ];

    // Special case for year.
    if ($year == 1) {
      unset($query['year']);
    }
    elseif ($year == 2) {
      $query['year'] = date('Y');
    }

    $data = $this->query($provider, '', $query);

    $figures = [];

    if (!empty($data)) {
      foreach ($data as $item) {
        $figures[$item['id']] = $item;
      }
    }

    asort($figures);

    return $figures;
  }

  /**
   * Fetch Key Figures.
   *
   * @param string $path
   *   API path.
   * @param array $query
   *   Query options.
   * @param bool $use_cache
   *   Use caching.
   *
   * @return array<string, mixed>
   *   Raw results.
   */
  public function getData(string $path, array $query = [], bool $use_cache = TRUE) : array {
    $endpoint = $this->apiUrl;
    $api_key = $this->apiKey;
    $app_name = $this->appName;

    if (empty($endpoint)) {
      return [];
    }

    $cid = $this->buildCacheId($path, $query);

    // Return cached data.
    if ($use_cache && $cache = $this->cacheBackend->get($cid)) {
      return $cache->data;
    }

    // Cache tags.
    $cache_tags = [
      $this->cacheId,
    ];

    $path_parts = explode('/', $path);
    for ($i = 0; $i < count($path_parts); $i++) {
      $cache_tags[] = $this->cacheId . ':' . implode(':', array_slice($path_parts, 0, $i + 1));
    }

    $headers = [
      'API-KEY' => $api_key,
      'ACCEPT' => 'application/json',
      'APP-NAME' => $app_name,
    ];

    // Construct full URL without ending /.
    $fullUrl = rtrim($endpoint . $path, '/');

    if (!empty($query)) {
      $fullUrl = $fullUrl . '?' . UrlHelper::buildQuery($query);
    }

    try {
      $this->getLogger('ocha_key_figures_fts_figures')->notice('Fetching data from @url', [
        '@url' => $fullUrl,
      ]);

      $response = $this->httpClient->request(
        'GET',
        $fullUrl,
        ['headers' => $headers],
      );

      $body = $response->getBody() . '';
      $results = json_decode($body, TRUE, 512, JSON_THROW_ON_ERROR);
    }
    catch (RequestException $exception) {
      $this->getLogger('ocha_key_figures_fts_figures')->error('Fetching data from @url failed with @message', [
        '@url' => $fullUrl,
        '@message' => $exception->getMessage(),
      ]);
      $results = [];
    }

    if (!is_array($results)) {
      $results = [];
    }

    // Cache data.
    if ($use_cache) {
      $this->cacheBackend->set($cid, $results, time() + $this->cacheDuration, $cache_tags);
    }

    return $results;
  }

  /**
   * Fetch Key Figures.
   *
   * @param string $path
   *   API path.
   * @param array $query
   *   Query options.
   * @param bool $use_cache
   *   Use caching.
   *
   * @return array<string, mixed>
   *   Raw results.
   */
  public function setData(string $path, array $data = [], $method = 'PUT') : array {
    $endpoint = $this->apiUrl;
    $api_key = $this->apiKey;
    $app_name = $this->appName;

    if (empty($endpoint)) {
      return [];
    }

    // Cache tags.
    $cache_tags = [
      $this->cacheId,
    ];

    $path_parts = explode('/', $path);
    for ($i = 0; $i < count($path_parts); $i++) {
      $cache_tags[] = $this->cacheId . ':' . implode(':', array_slice($path_parts, 0, $i + 1));
    }

    $headers = [
      'API-KEY' => $api_key,
      'ACCEPT' => 'application/json',
      'CONTENT-TYPE' => 'application/ld+json',
      'APP-NAME' => $app_name,
    ];

    // Construct full URL without ending /.
    $fullUrl = rtrim($endpoint . $path, '/');

    if (!empty($query)) {
      $fullUrl = $fullUrl . '?' . UrlHelper::buildQuery($query);
    }

    try {
      $this->getLogger('ocha_key_figures_fts_figures')->notice('Updating data at @url', [
        '@url' => $fullUrl,
      ]);

      $response = $this->httpClient->request(
        $method,
        $fullUrl,
        [
          'headers' => $headers,
          'body' => json_encode($data),
        ],
      );
    }
    catch (RequestException $exception) {
      $this->getLogger('ocha_key_figures_fts_figures')->error('Updating data on @url failed with @message', [
        '@url' => $fullUrl,
        '@message' => $exception->getMessage(),
      ]);

      if ($exception->getCode() === 404) {
        throw new NotFoundHttpException();
      }
      else {
        throw $exception;
      }
    }

    // Invalidate the cache.
    cache::invalidateTags($cache_tags);

    $body = $response->getBody() . '';
    $results = json_decode($body, TRUE);

    return $results ?? [];
  }

  /**
   * Build key figures.
   *
   * @param array<string, mixed> $results
   *   Raw results from API.
   * @param bool $sparklines
   *   Add sparklines.
   *
   * @return array<string, mixed>
   *   Results.
   */
  public function buildKeyFigures(array $results, bool $sparklines) : array {
    $figures = $this->parseKeyFigures($results);

    // Add the trend and sparkline.
    if ($sparklines) {
      foreach ($figures as $index => $figure) {
        if (isset($figure['values'])) {
          if (!isset($figure['value_type']) || $figure['value_type'] === 'numeric') {
            $figure['trend'] = $this->getKeyFigureTrend($figure['values']);
            $figure['sparkline'] = $this->getKeyFigureSparkline($figure['values']);
          }
        }

        $figures[$index] = $figure;
      }
    }

    return $figures;
  }

  /**
   * Parse the Key figures data, validating and sorting the figures.
   *
   * @param array $figures
   *   Figures data from the API.
   *
   * @return array
   *   Array of figures data prepared for display perserving the order but
   *   putting the "recent" ones at the top. Each figure contains the title,
   *   figure, formatted update date, source with a url to the latest source
   *   document and the value history to build the sparkline and the trend.
   */
  protected function parseKeyFigures(array $figures) {
    // Maximum number of days since the last update to still consider the
    // figure as recent.
    $number_of_days = 7;
    $now = new \DateTime();
    $recent = [];
    $standard = [];

    foreach ($figures as $item) {
      // Set the figure status and format its date.
      $item['status'] = 'standard';

      $days_ago = $item['date']->diff($now)->days;
      if (isset($item['updated']) && !empty($item['updated'])) {
        $item['updated'] = new \DateTime($item['updated']);
        $days_ago = $item['updated']->diff($now)->days;
      }

      if ($days_ago < $number_of_days) {
        $item['status'] = 'recent';
        if ($days_ago === 0) {
          $item['updated'] = $this->t('Updated today');
        }
        elseif ($days_ago === 1) {
          $item['updated'] = $this->t('Updated yesterday');
        }
        else {
          $item['updated'] = $this->t('Updated @days days ago', [
            '@days' => $days_ago,
          ]);
        }
        $recent[$item['name']] = $item;
      }
      else {
        $item['updated'] = $this->t('Updated @date', [
          '@date' => $item['date']->format('j M Y'),
        ]);
        $standard[$item['name']] = $item;
      }
    }

    // Preserve the figures order but put recently updated first.
    return array_merge($recent, $standard);
  }

  /**
   * Get the sparkline data for the given key figure history values.
   *
   * @param array $values
   *   Key figure history values.
   */
  protected function getKeyFigureSparkline(array $values) {
    if (empty($values)) {
      return NULL;
    }

    // Find max and min values.
    $numbers = array_column($values, 'value');
    $max = max($numbers);
    $min = min($numbers);

    // Skip if there was no change.
    if ($max === $min) {
      return NULL;
    }

    // Sort the values by newest first.
    usort($values, function ($a, $b) {
      if (isset($a['updated']) && isset($b['updated'])) {
        return strcmp($b['updated'], $a['updated']);
      }

      return (int) ($b['date'] > $a['date']);
    });

    // The values are ordered by newest first. We retrieve the number of
    // days between the newest and oldest days for the x axis.
    $last = reset($values)['date'];
    $oldest = end($values)['date'];
    $span = $last->diff($oldest)->days;
    if ($span == 0) {
      return NULL;
    }

    // View box dimensions for the sparkline.
    $height = 40;
    $width = 120;

    // Calculate the coordinates of each value.
    $points = [];
    foreach ($values as $value) {
      $diff = $oldest->diff($value['date'])->days;
      $x = ($width / $span) * $diff;
      $y = $height - ((($value['value'] - $min) / ($max - $min)) * $height);
      $points[] = round($x, 2) . ',' . round($y, 2);
    }

    $sparkline = [
      'points' => $points,
    ];

    return $sparkline;
  }

  /**
   * Get the trend data for the given key figure history values.
   *
   * @param array $values
   *   Key figure history values.
   */
  protected function getKeyFigureTrend(array $values) {
    if (count($values) < 2) {
      return NULL;
    }

    // The values are ordered by newest first. We want the 2 most recent values
    // to compute the trend.
    $first = reset($values);
    $second = next($values);

    if ($second['value'] === 0) {
      $percentage = 100;
    }
    else {
      $percentage = (int) round((1 - $first['value'] / $second['value']) * 100);
    }

    if ($percentage === 0) {
      $message = $this->t('No change');
    }
    elseif ($percentage < 0) {
      $message = $this->t('@percentage% increase', [
        '@percentage' => abs($percentage),
      ]);
    }
    else {
      $message = $this->t('@percentage% decrease', [
        '@percentage' => abs($percentage),
      ]);
    }

    $trend = [
      'message' => $message,
      'since' => $this->t('since @date', [
        '@date' => $second['date']->format('j M Y'),
      ]),
    ];

    return $trend;
  }

  /**
   * Get countries.
   */
  public function getCountries(string $provider) {
    // Fetch data.
    $countries = [];
    $prefix = $this->getPrefix($provider);
    $data = $this->getData($prefix . '/countries');
    foreach ($data as $row) {
      $countries[$row['value']] = $row['label'];
    }

    asort($countries);

    return $countries;
  }

  /**
   * Get countries.
   */
  public function getYears(string $provider) {
    // Fetch data.
    $years = [];
    $prefix = $this->getPrefix($provider);
    $data = $this->getData($prefix . '/years');
    foreach ($data as $row) {
      $years[$row['value']] = $row['label'];
    }

    return $years;
  }

  /**
   * Invalidate cache.
   */
  public function invalidateCache($path = '', $query = []) {
    $cid = $this->buildCacheId($path, $query);
    $this->cacheBackend->invalidate($cid);
  }

  /**
   * Invalidate cache.
   */
  public function invalidateCacheTagsByProvider($provider) {
    Cache::invalidateTags([
      $this->cacheId . ':' . $this->getPrefix($provider),
    ]);
  }

  /**
   * Invalidate cache.
   */
  public function invalidateCacheTagsByFigure(array $figure) {
    Cache::invalidateTags([
      $this->cacheId . ':' . $this->getPrefix($figure['provider']) . ':' . $figure['id'],
    ]);
  }

  /**
   * Get cache tags
   */
  public function getCacheTags(array $figure) {
    if (!isset($figure['provider'])) {
      return [];
    }

    return [
      $this->cacheId,
      $this->cacheId . ':' . $this->getPrefix($figure['provider']),
      $this->cacheId . ':' . $this->getPrefix($figure['provider']) . ':' . $figure['id'],
    ];
  }

  /**
   * Get cache tags
   */
  public function getCacheTagsForFigure(KeyFigure $figure) {
    return [
      $this->cacheId,
      $this->cacheId . ':' . $this->getPrefix($figure->getFigureProvider()),
      $this->cacheId . ':' . $this->getPrefix($figure->getFigureProvider()) . ':' . $figure->getFigureId(),
    ];
  }

  /**
   * Get the list of supported figure providers.
   *
   * @return array
   *   List of suported providers.
   */
  public function getSupportedProviders() {
    $options = [];

    $can_read = $this->getData('me/providers');
    foreach ($can_read as $provider) {
      $options[$provider['id']] = $provider['name'];
    }

    asort($options);
    return $options;
  }

  /**
   * Get prefix for a provider.
   */
  public function getPrefix($provider_id) {
    if ($provider_id == 'me') {
      return 'me';
    }

    $can_read = $this->getData('me/providers');
    foreach ($can_read as $provider) {
      if ($provider['id'] == $provider_id) {
        return $provider['prefix'];
      }
    }

    return $provider_id;
  }

  /**
   * Build cache Id.
   */
  protected function buildCacheId($path = '', $query = []) {
    $cache_id = $this->cacheId;

    if (empty($path) || $path == '') {
      return $cache_id;
    }

    $cache_id .= ':' . str_replace('/', ':', $path);

    if (empty($query)) {
      return $cache_id;
    }

    $cache_id .= ':' . md5(json_encode($query));

    return $cache_id;
  }

  /**
   * Get OCHA Presences for provider.
   */
  public function getOchaPresencesByProvider(string $provider) : array {
    $prefix = $this->getPrefix($provider);
    $ochapresences = $this->getData($prefix . '/ocha-presences');

    $options = [];
    foreach ($ochapresences as $option) {
      $options[$option['value']] = $option['label'];
    }

    asort($options);

    return $options;
  }

  /**
   * Get OCHA Presence years for provider.
   */
  public function getOchaPresenceYearsByProvider(string $provider, string $ocha_presence_id) : array {
    $prefix = $this->getPrefix($provider);
    $years = $this->getData($prefix . '/ocha-presences/' . $ocha_presence_id . '/years');

    $options = [];
    foreach ($years as $option) {
      $options[$option['value']] = $option['label'];
    }

    asort($options);

    return $options;
  }

  /**
   * Get OCHA Presences.
   */
  public function getOchaPresences() : array {
    return $this->getData('ocha_presences');
  }

  /**
   * Get OCHA Presence.
   */
  public function getOchaPresence(string $id) : array {
    return $this->getData('ocha_presences/' . strtolower($id));
  }

  /**
   * Delete OCHA Presence.
   */
  public function deleteOchaPresence(string $id) : void {
    $this->setData('ocha_presences/' . strtolower($id), [], 'DELETE');
  }

  /**
   * Set OCHA Presence.
   */
  public function setOchaPresence(string $id, $data, $new = FALSE) : array {
    if ($new) {
      return $this->setData('ocha_presences', $data, 'POST');
    }
    else {
      return $this->setData('ocha_presences/' . strtolower($id), $data);
    }
  }

  /**
   * Get OCHA Presence.
   */
  public function getOchaPresenceExternal(string $id) : array {
    return $this->getData('ocha_presence_external_ids/' . strtolower($id));
  }

  /**
   * Delete OCHA Presence.
   */
  public function deleteOchaPresenceExternal(string $id) : void {
    $this->setData('ocha_presence_external_ids/' . strtolower($id), [], 'DELETE');
  }

  /**
   * Set OCHA Presence.
   */
  public function setOchaPresenceExternal(string $id, $data, $new = FALSE) : array {
    if ($new) {
      return $this->setData('ocha_presence_external_ids', $data, 'POST');
    }
    else {
      return $this->setData('ocha_presence_external_ids/' . strtolower($id), $data);
    }
  }

  /**
   * Get OCHA Presence figures.
   */
  public function getOchaPresenceFigures(string $provider, string $ocha_presence_id, string $year, $figure_ids = []) : array {
    $prefix = $this->getPrefix($provider);
    $query = [];

    $url = '';
    $query = [];
    if (!empty($figure_ids)) {
      $query['figure_id'] = $figure_ids;
    }

    if ($year == 2) {
      $query['year'] = [
        date('Y'),
        date('Y') - 1,
      ];
      $query['order'] = [
        'year' => 'DESC',
      ];
      $url = $prefix . '/ocha-presences/' . $ocha_presence_id;
    }
    else {
      $url = $prefix . '/ocha-presences/' . $ocha_presence_id . '/' . $year . '/figures';
    }

    $data = $this->getData($url, $query);

    $output = [];
    $seen = [];

    // Keep most recent data based on figure_id and year.
    foreach ($data as $keyfigure) {
      if (!isset($seen[$keyfigure['figure_id']])) {
          $seen[$keyfigure['figure_id']] = $keyfigure['year'];
          $output[] = $keyfigure;
      }
      elseif ($seen[$keyfigure['figure_id']] == $keyfigure['year']) {
          $output[] = $keyfigure;
      }
    }

    return $output;
  }

  /**
   * Get OCHA Presence figures.
   */
  public function getOchaPresenceFiguresParsed(string $provider, string $ocha_presence_id, string $year, $figure_ids = []) : array {
    $data = $this->getOchaPresenceFigures($provider, $ocha_presence_id, $year, $figure_ids);

    $figures = [];

    // Build aggregated figure.
    foreach ($data as $item) {
      if (!isset($figures[$item['figure_id']])) {
        $figures[$item['figure_id']] = $item;
        $figures[$item['figure_id']]['figure_list'] = [$item];
        $figures[$item['figure_id']]['cache_tags'] = $this->getCacheTags($item);
      }
      else {
        $figures[$item['figure_id']]['figure_list'][] = $item;
        $figures[$item['figure_id']]['cache_tags'] += $this->getCacheTags($item);
      }
    }

    foreach ($figures as $key => $row) {
      // We need unique tags.
      $figures[$key]['cache_tags'] = array_unique($figures[$key]['cache_tags']);

      // Make sure we have a date.
      $figures[$key]['date'] = new \DateTime($row['year'] . '-01-01');
      if (isset($row['updated']) && !empty($row['updated'])) {
        $figures[$key]['date'] = new \DateTime(substr($row['updated'], 0, 10));
      }

      // Aggregated values and descriptions.
      if (count($row['figure_list']) > 1) {
        $values = [];
        $descriptions = [];

        foreach ($row['figure_list'] as $f) {
          $values[] = $f['value'];
          if (isset($f['description']) && !empty($f['description'])) {
            $descriptions[] = $f['description'];
          }
        }

        $new_value = $row['value'];
        switch ($row['value_type']) {
          case 'amount':
          case 'numeric':
            $new_value = 0;
            foreach ($values as $value) {
              $new_value += $value;
            }
            break;

          case 'percentage':
            $new_value = 0;
            foreach ($values as $value) {
              $new_value += $value;
            }
            $new_value = round($new_value / count($values), 2);
            break;

          case 'list':
            $new_list = [];
            foreach ($values as $value) {
              $listitems = explode(',', $value);
              $listitems = array_map('trim', $listitems);
              $new_list = array_merge($new_list, $listitems);
            }

            $new_value = implode(', ', array_unique($new_list));
            break;

          default:
            // @todo needs more logic.
            $figures[$item['figure_id']]['value'] += $item['value'];
        }

        $figures[$key]['description'] = implode(', ', array_unique($descriptions));
        $figures[$key]['value'] = $new_value;
      }
    }

    return $figures;
  }

  /**
   * Get figure by figure id.
   */
  public function getOchaPresenceFigureByFigureId(string $provider, string $ocha_presence_id, string $year, string $figure_id) : array {
    $prefix = $this->getPrefix($provider);

    $url = '';
    $query = [];
    $query['figure_id'] = $figure_id;

    if ($year == 2) {
      $query['year'] = [
        date('Y'),
        date('Y') - 1,
      ];
      $query['order'] = [
        'year' => 'DESC',
      ];
      $url = $prefix . '/ocha-presences/' . $ocha_presence_id;
    }
    else {
      $url = $prefix . '/ocha-presences/' . $ocha_presence_id . '/' . $year . '/figures';
    }

    $data = $this->getData($url, $query);

    $figures = [];
    $seen = [];

    // Keep most recent data based on figure_id and year.
    foreach ($data as $keyfigure) {
      if (!isset($seen[$keyfigure['figure_id']])) {
          $seen[$keyfigure['figure_id']] = $keyfigure['year'];
          $figures[] = $keyfigure;
      }
      elseif ($seen[$keyfigure['figure_id']] == $keyfigure['year']) {
          $figures[] = $keyfigure;
      }
    }

    return $figures;

    $data = [];
    if (!empty($figures)) {
      foreach ($figures as $figure) {
        if (empty($data[$figure['figure_id']])) {
          $data[$figure['figure_id']] = $figure;
          $data[$figure['figure_id']]['figure_list'] = [];
          $data[$figure['figure_id']]['cache_tags'] = $this->getCacheTags($figure);
        }
        else {
          switch ($data[$figure['figure_id']]['value_type']) {
            case 'amount':
            case 'numeric':
              $data[$figure['figure_id']]['value'] += $figure['value'];
              break;

            case 'percentage':
              $data[$figure['figure_id']]['value'] = ($data[$figure['figure_id']]['value'] + $figure['value']) / 2;
              break;

            case 'list':
              // Value is comnma separated list.
              $values = explode(',', $data[$figure['figure_id']]['value']);
              $values = array_map('trim', $values);

              $new_values = explode(',', $figure['value']);
              $new_values = array_map('trim', $new_values);
              $data[$figure['figure_id']]['value'] = implode(', ', array_unique(array_merge($values, $new_values)));
              break;

            default:
              // @todo needs more logic.
              $data[$figure['figure_id']]['value'] += $figure['value'];

          }
          $data[$figure['figure_id']]['figure_list'][] = $figure;
          $data[$figure['figure_id']]['cache_tags'] += $this->getCacheTags($figure);
          $data[$figure['figure_id']]['cache_tags'] = array_unique($data[$figure['figure_id']]['cache_tags']);
        }
      }

    }

    return $data;

  }

  /**
   * Get the list of allowed values.
   *
   * @return array
   *   List of options.
   */
  public function getExternalLookup(string $provider) {
    $options = [];

    $query = [
      'provider' => $provider,
    ];
    $data = $this->getData('external_lookups', $query);

    foreach ($data as $lookup) {
      $options[$lookup['id']] = $lookup['name'];
      if (isset($lookup['year']) && !empty($lookup['year'])) {
        $options[$lookup['id']] .= ' (' . $lookup['year'] . ')';
      }
    }

    asort($options);
    return $options;
  }

}
