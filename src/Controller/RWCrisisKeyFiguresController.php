<?php

namespace Drupal\ocha_key_figures\Controller;

use Drupal\Core\Cache\CacheBackendInterface;
use GuzzleHttp\ClientInterface;

/**
 * Page controller for Key Figures.
 */
class RWCrisisKeyFiguresController extends BaseKeyFiguresController {

  /**
   * {@inheritdoc}
   */
  protected $cacheId = 'rw_crisis';

  /**
   * {@inheritdoc}
   */
  public function __construct(ClientInterface $http_client, CacheBackendInterface $cache) {
    parent::__construct($http_client, $cache);

    $this->apiUrl .= 'rw-crisis/';
  }

  /**
   * {@inheritdoc}
   */
  public function getKeyFigures(string $iso3, $year = '') : array {
    $query = [
      'archived' => FALSE,
      'iso3' => $iso3,
    ];

    $grouped = FALSE;
    if ($year) {
      $query['year'] = $year;
    }
    else {
      $grouped = TRUE;
    }

    $data = $this->getData('', $query);

    // Sort the values by newest first.
    usort($data, function ($a, $b) {
      return strcmp($b['year'], $a['year']);
    });

    foreach ($data as &$row) {
      $row['date'] = new \DateTime($row['year'] . '-01-01');
      if (isset($row['updated']) && !empty($row['updated'])) {
        $row['date'] = new \DateTime(substr($row['updated'], 0, 10));
      }
    }

    $results = [];
    if (!$grouped) {
      foreach ($data as $row) {
        $results[$row['name']] = $row;
      }
    }
    else {
      foreach ($data as $row) {
        if (!isset($results[$row['name']])) {
          $results[$row['name']] = $row;
          $results[$row['name']]['values'] = [$row];
        }
        else {
          $results[$row['name']]['values'][] = $row;
        }
      }
    }

    return $results;
  }

}
