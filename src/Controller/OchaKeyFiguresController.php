<?php

namespace Drupal\ocha_key_figures\Controller;

use Drupal\Component\Utility\UrlHelper;
use Drupal\Core\Cache\Cache;
use Drupal\Core\Cache\CacheBackendInterface;
use Drupal\Core\Controller\ControllerBase;
use Drupal\ocha_key_figures\Plugin\Field\FieldType\KeyFigure;
use GuzzleHttp\ClientInterface;
use GuzzleHttp\Exception\RequestException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * Base controller for Key Figures.
 */
class OchaKeyFiguresController extends ControllerBase {

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
  public function __construct(ClientInterface $http_client, CacheBackendInterface $cache) {
    $this->httpClient = $http_client;
    $this->cacheBackend = $cache;

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
      $results[$row['name']] = $row;
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
      if (!isset($results[$row['name']])) {
        $results[$row['name']] = $row;
        $results[$row['name']]['values'] = [];
      }

      $results[$row['name']]['values'][] = [
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
            $date = new \DateTime(implode('-', $date_parts));
          }
          $results[$row['name']]['values'][] = [
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

  public function getFiguresWithFigureId(string $provider, array $iso3, string $year) : array {
    // Fetch data.
    $prefix = $this->getPrefix($provider);

    $query = [
      'archived' => 0,
      'iso3' => $iso3,
      'year' => $year,
    ];

    // Special case for year.
    if ($year == 1) {
      // No need to filter.
      unset($query['year']);
    }
    elseif ($year == 2) {
      $query['year'] = date('Y');
    }

    $figures = $this->getData($prefix, $query);

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
   * Get aggregated figure by figure id.
   */
  public function getFigureByFigureId(string $provider, array $iso3, string $year, string $figure_id) : array {
    // Fetch data.
    $prefix = $this->getPrefix($provider);

    $query = [
      'iso3' => $iso3,
      'year' => $year,
      'figure_id' => $figure_id,
    ];

    $figures = $this->getData($prefix, $query);

    $data = [];
    if (!empty($figures)) {
      foreach ($figures as $figure) {
        if (empty($data)) {
          $data = $figure;
          $data['figure_list'] = [];
          $data['cache_tags'] = $this->getCacheTags($figure);
        }
        else {
          switch ($data['value_type']) {
            case 'amount':
            case 'numeric':
              $data['value'] += $figure['value'];
              break;

            case 'percentage':
              $data['value'] = ($data['value'] + $figure['value']) / 2;
              break;

            case 'list':
              // Value is comnma separated list.
              $values = explode(',', $data['value']);
              $values = array_map('trim', $values);

              $new_values = explode(',', $figure['value']);
              $new_values = array_map('trim', $new_values);
              $data['value'] = implode(', ', array_unique(array_merge($values, $new_values)));
              break;

            default:
              // @todo needs more logic.
              $data['value'] += $figure['value'];

          }
          $data['figure_list'][] = $figure;
          $data['cache_tags'] += $this->getCacheTags($figure);
        }
      }

      $data['cache_tags'] = array_unique($data['cache_tags']);
    }

    return $data;
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
    }
    catch (RequestException $exception) {
      $this->getLogger('ocha_key_figures_fts_figures')->error('Fetching data from @url failed with @message', [
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

    $body = $response->getBody() . '';
    $results = json_decode($body, TRUE);

    // Cache data.
    if ($use_cache) {
      $this->cacheBackend->set($cid, $results, time() + $this->cacheDuration, $cache_tags);
    }

    return $results;
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
   * Get iso3 codes from OCHA Presence Id.
   */
  public function getOchaPresenceIso3(string $ocha_presence_id) : array {
    $ochapresences = $this->getData('ocha_presences/' . $ocha_presence_id);

    $iso3s = [];
    foreach ($ochapresences['countries'] as $country) {
      $iso3s[] = $country['id'];
    }

    return $iso3s;
  }
}
