<?php

namespace Drupal\ocha_key_figures\Controller;

use Drupal\Component\EventDispatcher\ContainerAwareEventDispatcher;
use Drupal\Core\Cache\Cache;
use Drupal\Core\Controller\ControllerBase;
use Drupal\Core\Entity\EntityFieldManager;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Logger\LoggerChannelFactoryInterface;
use Drupal\ocha_key_figures\Controller\OchaKeyFiguresController;
use Drupal\ocha_key_figures\Event\KeyFiguresUpdated;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpKernel\Exception\BadRequestHttpException;

/**
 * Controller for incoming webhooks.
 */
class WebhookController extends ControllerBase {

  /**
   * The OCHA Key Figures API client.
   *
   * @var \Drupal\ocha_key_figures\Controller\OchaKeyFiguresController
   */
  protected $ochaKeyFiguresApiClient;

  /**
   * The entity type manager.
   *
   * @var \Drupal\Core\Entity\EntityTypeManagerInterface
   */
  protected $entityTypeManager;

  /**
   * The entity field manager.
   *
   * @var \Drupal\Core\Entity\EntityFieldManager
   */
  protected $entityFieldManager;

  /**
   * The event dispatcher.
   *
   * @var \Drupal\Component\EventDispatcher\ContainerAwareEventDispatcher
   */
  protected $eventDispatcher;

  /**
   * The logger factory.
   *
   * @var \Drupal\Core\Logger\LoggerChannelFactoryInterface
   */
  protected $loggerFactory;

  /**
   * {@inheritdoc}
   */
  public function __construct(
    OchaKeyFiguresController $ocha_key_figure_api_client,
    EntityTypeManagerInterface $entity_type_manager,
    EntityFieldManager $entity_field_manager,
    ContainerAwareEventDispatcher $event_dispatcher,
    LoggerChannelFactoryInterface $logger_factory,
    ) {
    $this->ochaKeyFiguresApiClient = $ocha_key_figure_api_client;
    $this->entityTypeManager = $entity_type_manager;
    $this->entityFieldManager = $entity_field_manager;
    $this->eventDispatcher = $event_dispatcher;
    $this->loggerFactory = $logger_factory;
  }

  /**
   * Download a file.
   *
   * @param \Symfony\Component\HttpFoundation\Request $request
   *   Docstore API request.
   */
  public function listen(Request $request) {
    // Parse JSON.
    $params = $this->getRequestContent($request);

    if (!isset($params['data'])) {
      throw new BadRequestHttpException('Illegal payload');
    }

    $map = $this->entityFieldManager->getFieldMapByFieldType('key_figure');

    $type_ids = [];
    $event_data = [];
    $records = $params['data'] ?? [];
    $keyed_records = [];

    foreach ($records as $record) {
      $figure_id = $record['data']['id'];
      $keyed_records[$figure_id] = $record['data'];
      $is_new = $record['status'] == 'new';

      foreach ($map as $type => $info) {
        if (!isset($type_ids[$type])) {
          $type_ids[$type] = [];
        }

        foreach ($info as $name => $data) {
          // Check field displaying all values.
          $query = $this->entityTypeManager->getStorage($type)->getQuery();
          $query->condition($name . '.id', '_all');
          $query->condition($name . '.provider', $record['data']['provider']);
          $query->condition($name . '.country', $record['data']['iso3']);
          $query->condition($name . '.year', [1, 2, $record['data']['year']], 'IN');
          $result = $query->execute();

          // Check by Id for existing records.
          if (!$is_new) {
            $query = $this->entityTypeManager->getStorage($type)->getQuery();
            $query->condition($name . '.id', $figure_id);
            $result = array_merge($result, $query->execute());
          }

          // Add records for event.
          foreach ($result as $id) {
            if ($is_new) {
              $event_data[$type][$id]['new'][$figure_id] = $record['data'];

              // Invalidate cache for provider.
              $this->ochaKeyFiguresApiClient->invalidateCacheTagsByProvider($record['data']['provider']);
            }
            else {
              $event_data[$type][$id]['updated'][$figure_id] = $record['data'];

              // Invalidate cache for figure.
              $this->ochaKeyFiguresApiClient->invalidateCacheTagsByFigure($record['data']);
            }
          }

          // Merge results.
          $type_ids[$type] = array_merge($type_ids[$type], array_values($result));
        }
      }
    }

    // Update fields and clear cache.
    foreach ($type_ids as $type => $ids) {
      $ids = array_unique($ids);
      $info = $map[$type];

      $entities = $this->entityTypeManager->getStorage($type)->loadMultiple($ids);
      foreach ($entities as $entity) {
        // Loop all fields of entity.
        foreach ($info as $name => $data) {
          if ($entity->hasField($name)) {
            $field_data = $entity->get($name)->getValue();
            foreach ($field_data as &$row) {
              if (array_key_exists($row['id'], $keyed_records)) {
                $row['value'] = $keyed_records[$row['id']]['value'];
                $row['unit'] = $keyed_records[$row['id']]['unit'] ?? '';
              }
            }
            $entity->get($name)->setValue($field_data);
          }
        }

        // Always save.
        $entity->save();
        Cache::invalidateTags($entity->getCacheTagsToInvalidate());
      }
    }

    $event = new KeyFiguresUpdated($event_data);
    $this->eventDispatcher->dispatch($event, KeyFiguresUpdated::EVENT_UPDATED);

    return new JsonResponse('OK');
  }

  /**
   * Get the request content.
   *
   * @param \Symfony\Component\HttpFoundation\Request $request
   *   API Request.
   *
   * @return array
   *   Request content.
   *
   * @throw \Symfony\Component\HttpKernel\Exception\BadRequestHttpException
   *   Throw a 400 when the request doesn't have a valid JSON content.
   */
  public function getRequestContent(Request $request) {
    $content = json_decode($request->getContent(), TRUE);
    if (empty($content) || !is_array($content)) {
      throw new BadRequestHttpException('You have to pass a JSON object');
    }
    return $content;
  }

}
