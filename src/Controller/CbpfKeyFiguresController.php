<?php

namespace Drupal\ocha_key_figures\Controller;

use Drupal\Core\Cache\CacheBackendInterface;
use GuzzleHttp\ClientInterface;

/**
 * Page controller for Key Figures.
 */
class CbpfKeyFiguresController extends BaseKeyFiguresController {

  /**
   * {@inheritdoc}
   */
  protected $cacheId = 'cbpf';

  /**
   * {@inheritdoc}
   */
  public function __construct(ClientInterface $http_client, CacheBackendInterface $cache) {
    parent::__construct($http_client, $cache);

    $this->apiUrl .= 'cbpf/';
  }

}
