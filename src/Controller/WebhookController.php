<?php

namespace Drupal\ocha_key_figures\Controller;

use Drupal\Component\EventDispatcher\ContainerAwareEventDispatcher;
use Drupal\Core\Cache\Cache;
use Drupal\Core\Controller\ControllerBase;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Logger\LoggerChannelFactoryInterface;
use Drupal\group\Entity\Group;
use Drupal\ocha_key_figures\Event\KeyFiguresUpdated;
use Drupal\paragraphs\Entity\Paragraph;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpKernel\Exception\BadRequestHttpException;

/**
 * Controller for incoming webhooks.
 */
class WebhookController extends ControllerBase {

  /**
   * The logger factory.
   *
   * @var \Drupal\Core\Logger\LoggerChannelFactoryInterface
   */
  protected $loggerFactory;

  /**
   * The entity type manager.
   *
   * @var \Drupal\Core\Entity\EntityTypeManagerInterface
   */
  protected $entityTypeManager;

  /**
   * The event dispatcher.
   *
   * @var \Drupal\Component\EventDispatcher\ContainerAwareEventDispatcher
   */
  protected $eventDispatcher;

  /**
   * {@inheritdoc}
   */
  public function __construct(LoggerChannelFactoryInterface $logger_factory, EntityTypeManagerInterface $entity_type_manager, ContainerAwareEventDispatcher $event_dispatcher) {
    $this->loggerFactory = $logger_factory;
    $this->entityTypeManager = $entity_type_manager;
    $this->eventDispatcher = $event_dispatcher;
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

    foreach ($params['data'] as $record) {
      $is_new = $record['status'] == 'new';
      $paragraph_ids = [];

      if ($is_new) {
        // All numbers.
        $query = $this->entityTypeManager->getStorage('paragraph')->getQuery();
        $query->condition('field_country', $record['data']['iso3'])
          ->condition('field_year', $record['data']['year'])
          ->notExists('field_figures');

        $paragraph_ids = $query->execute();
      }
      else {
        // Individual numbers.
        $query = $this->entityTypeManager->getStorage('paragraph')->getQuery();
        $query->condition('field_figures', $record['data']['name'])
          ->condition('field_country', $record['data']['iso3'])
          ->condition('field_year', $record['data']['year']);

        $paragraph_ids = $query->execute();

        // All numbers.
        $query = $this->entityTypeManager->getStorage('paragraph')->getQuery();
        $query->condition('field_country', $record['data']['iso3'])
          ->condition('field_year', $record['data']['year'])
          ->notExists('field_figures');

        $paragraph_ids_all = $query->execute();
        $paragraph_ids = array_merge($paragraph_ids, $paragraph_ids_all);
      }

      if (!empty($ids)) {
        $bundles = [];
        $data = [];

        /** @var \Drupal\paragraphs\Entity\Paragraph[] $paragraphs */
        $paragraphs = $this->entityTypeManager->getStorage('paragraph')->loadMultiple($ids);
        foreach ($paragraphs as $paragraph) {
          // Invalidate cache.
          $tags = $paragraph->getCacheTagsToInvalidate();
          Cache::invalidateTags($tags);

          // Track bundles.
          $bundles[$paragraph->bundle()] = $paragraph->bundle();

          // Track parents.
          $parent = $paragraph;
          while ($parent && $parent instanceof Paragraph) {
            $parent = $paragraph->getParentEntity();
          }

          if (!isset($data[$parent->getEntityTypeId()])) {
            $data[$parent->getEntityTypeId()] = [];
          }

          if (!isset($data[$parent->getEntityTypeId()][$parent->id()])) {
            $data[$parent->getEntityTypeId()][$parent->id()] = [
              'new' => [],
              'updated' => [],
            ];
          }

          if ($is_new) {
            $data[$parent->getEntityTypeId()][$parent->id()]['new'][$record['data']['id']] = $record;
          }
          else {
            $data[$parent->getEntityTypeId()][$parent->id()]['updated'][$record['data']['id']] = $record;
          }
        }

        foreach ($bundles as $bundle) {
          $controller = ocha_key_figures_load_keyfigure_controller($bundle);
          $controller->invalidateCache();
        }

        // Get the event_dispatcher service and dispatch the event.
        $event = new KeyFiguresUpdated($data);
        $this->eventDispatcher->dispatch($event, KeyFiguresUpdated::EVENT_UPDATED);
      }
    }

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
