<?php

namespace Drupal\ocha_key_figures\Controller;

use Drupal\Core\Controller\ControllerBase;
use Drupal\Core\Url;
use Drupal\ocha_key_figures\Controller\OchaKeyFiguresController;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Provides the form for adding countries.
 */
class OchaPresenceController extends ControllerBase {

  /**
   * The OCHA Key Figures API client.
   *
   * @var \Drupal\ocha_key_figures\Controller\OchaKeyFiguresController
   */
  protected $ochaKeyFiguresApiClient;

  /**
   * {@inheritdoc}
   */
  public function __construct(OchaKeyFiguresController $ocha_key_figure_api_client) {
    $this->ochaKeyFiguresApiClient = $ocha_key_figure_api_client;
  }

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container) {
    return new static(
      $container->get('ocha_key_figures.key_figures_controller')
    );
  }

  /**
   * {@inheritdoc}
   */
  public function getFormId() {
    return 'ocha_key_figures_presence';
  }

  /**
   * {@inheritdoc}
   */
  public function list() {
    $data = $this->ochaKeyFiguresApiClient->getOchaPresences();

    $form['presences'] = [
      '#type' => 'table',
      '#header' => [
        $this->t('Id'),
        $this->t('Name'),
        $this->t('Office type'),
        $this->t('Operations'),
      ],
      '#rows' => [],
    ];

    foreach ($data as $row) {
      $form['presences']['#rows'][] = [
        $row['id'],
        $row['name'],
        $row['office_type'],
        [
          'data' => [
            '#type' => 'link',
            '#title' => $this->t('Edit'),
            '#url' => Url::fromRoute('ocha_key_figures.ocha_presences.edit', [
              'id' => $row['id'],
            ]),
          ],
        ]
      ];
    }

    return $form;
  }

}
