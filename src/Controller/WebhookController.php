<?php

namespace Drupal\ocha_key_figures\Controller;

use Drupal\Core\Cache\Cache;
use Drupal\Core\Controller\ControllerBase;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Logger\LoggerChannelFactoryInterface;
use Drupal\group\Entity\Group;
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
   * {@inheritdoc}
   */
  public function __construct(LoggerChannelFactoryInterface $logger_factory, EntityTypeManagerInterface $entity_type_manager) {
    $this->loggerFactory = $logger_factory;
    $this->entityTypeManager = $entity_type_manager;
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
      $ids = [];

      if ($is_new) {
        // All numbers.
        $query = $this->entityTypeManager->getStorage('paragraph')->getQuery();
        $query->condition('field_country', $record['data']['iso3'])
          ->condition('field_year', $record['data']['year'])
          ->notExists('field_figures');

        $ids = $query->execute();
      }
      else {
        // Individual numbers.
        $query = $this->entityTypeManager->getStorage('paragraph')->getQuery();
        $query->condition('field_figures', $record['data']['name'])
          ->condition('field_country', $record['data']['iso3'])
          ->condition('field_year', $record['data']['year']);

        $ids = $query->execute();

        // All numbers.
        $query = $this->entityTypeManager->getStorage('paragraph')->getQuery();
        $query->condition('field_country', $record['data']['iso3'])
          ->condition('field_year', $record['data']['year'])
          ->notExists('field_figures');

        $ids_all = $query->execute();
        $ids = array_merge($ids, $ids_all);
      }

      if (!empty($ids)) {
        $bundles = [];
        $groups = [];

        /** @var \Drupal\paragraphs\Entity\Paragraph[] $paragraphs */
        $paragraphs = $this->entityTypeManager->getStorage('paragraph')->loadMultiple($ids);
        foreach ($paragraphs as $paragraph) {
          // Invalidate cache.
          $tags = $paragraph->getCacheTagsToInvalidate();
          Cache::invalidateTags($tags);

          // Track bundles.
          $bundles[$paragraph->bundle()] = $paragraph->bundle();

          // Get group to send emails.
          $parent = $paragraph->getParentEntity();
          if ($parent && $parent instanceof Group) {
            if (!isset($groups[$parent->id()])) {
              $groups[$parent->id()] = [
                'new' => [],
                'updated' => [],
              ];
            }

            if ($is_new) {
              $groups[$parent->id()]['new'][$record['data']['id']] = $record;
            }
            else {
              $groups[$parent->id()]['updated'][$record['data']['id']] = $record;
            }
          }
        }

        foreach ($bundles as $bundle) {
          $controller = ocha_key_figures_load_keyfigure_controller($bundle);
          $controller->invalidateCache();
        }
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
