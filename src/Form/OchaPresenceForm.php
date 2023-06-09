<?php

namespace Drupal\ocha_key_figures\Form;

use Drupal\Core\Form\FormBase;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\Url;
use Drupal\ocha_key_figures\Controller\OchaKeyFiguresController;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Provides the form for adding countries.
 */
class OchaPresenceForm extends FormBase {

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
  public function buildForm(array $form, FormStateInterface $form_state, string $id = '') {
    $data = $this->ochaKeyFiguresApiClient->getOchaPresence($id);

    $form['id'] = array(
      '#title' => $this->t('Id'),
      '#type' => 'textfield',
      '#default_value' => $data['id'],
      '#disabled' => TRUE,
    );

    $form['name'] = array(
      '#title' => $this->t('Name'),
      '#type' => 'textfield',
      '#default_value' => $data['name'],
    );

    $form['office_type'] = array(
      '#title' => $this->t('Office type'),
      '#type' => 'textfield',
      '#default_value' => $data['office_type'],
    );

    $form['external_ids'] = [
      '#type' => 'table',
      '#header' => [
        $this->t('Id'),
        $this->t('Provider'),
        $this->t('Year'),
        $this->t('External Ids'),
        $this->t('Edit'),
      ],
      '#rows' => [],
    ];

    foreach ($data['ocha_presence_external_ids'] as $row) {
      $table_row = [
        $row['id'],
        $row['provider']['name'],
        $row['year'],
      ];

      $cell = [];
      foreach ($row['external_ids'] as $ids_row) {
        $cell[] = $ids_row['name'];
      }
      $table_row[] = implode(', ', $cell);

      $table_row[] = [
        'data' => [
          '#type' => 'link',
          '#title' => $this->t('Edit'),
          '#url' => Url::fromRoute('ocha_key_figures.ocha_presences.ids.edit', [
            'id' => $id,
            'external_id' => $row['id'],
          ]),
        ],
      ];

      $form['external_ids']['#rows'][] = $table_row;
    }

    $form['actions'] = [
      '#type' => 'actions',
    ];

    $form['actions']['submit'] = [
      '#type' => 'submit',
      '#value' => $this->t('Save'),
    ];

    $form['actions']['cancel'] = [
      '#type' => 'link',
      '#title' => t('Cancel'),
      '#url' => Url::fromRoute('ocha_key_figures.ocha_presences'),
      '#attributes' => [
        'class' => [
          'button',
          'cancel',
        ],
      ],
      '#weight' => 30,
    ];

    return $form;
  }

  /**
   * {@inheritdoc}
   */
  public function submitForm(array &$form, FormStateInterface $form_state) {
    $data = [

    ];
  }

}
