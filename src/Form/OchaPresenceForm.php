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
  public function buildForm(array $form, FormStateInterface $form_state, string $id = 'add') {
    $add = TRUE;
    $data = [];

    if ($id !== 'add') {
      $data = $this->ochaKeyFiguresApiClient->getOchaPresence($id);
      $add = FALSE;
    }

    $form['add'] = [
      '#type' => 'value',
      '#value' => $add,
    ];

    $form['id'] = [
      '#title' => $this->t('Id'),
      '#type' => 'textfield',
      '#default_value' => $data['id'] ?? '',
      '#disabled' => !$add,
    ];

    $form['name'] = [
      '#title' => $this->t('Name'),
      '#type' => 'textfield',
      '#default_value' => $data['name'] ?? '',
    ];

    $form['office_type'] = [
      '#title' => $this->t('Office type'),
      '#type' => 'textfield',
      '#default_value' => $data['office_type'] ?? '',
    ];

    if (!$add) {
      $form['external_ids'] = [
        '#type' => 'table',
        '#header' => [
          $this->t('Id'),
          $this->t('Provider'),
          $this->t('Year'),
          $this->t('External Ids'),
          $this->t('Edit'),
          $this->t('Delete'),
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

        $table_row[] = [
          'data' => [
            '#type' => 'link',
            '#title' => $this->t('Delete'),
            '#url' => Url::fromRoute('ocha_key_figures.ocha_presences.ids.delete', [
              'id' => $id,
              'external_id' => $row['id'],
            ]),
          ],
        ];

        $form['external_ids']['#rows'][] = $table_row;
      }
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

    if (!$add) {
      $form['actions']['delete'] = [
        '#type' => 'link',
        '#title' => t('Delete'),
        '#url' => Url::fromRoute('ocha_key_figures.ocha_presences.delete', [
          'id' => $id,
        ]),
        '#attributes' => [
          'class' => [
            'button',
            'delete',
          ],
        ],
        '#weight' => 99,
      ];
    }

    return $form;
  }

  /**
   * {@inheritdoc}
   */
  public function submitForm(array &$form, FormStateInterface $form_state) {
    $add = $form_state->getValue('add');

    $data = [
      'id' => $form_state->getValue('id'),
      'name' => $form_state->getValue('name'),
      'office_type' => $form_state->getValue('office_type'),
    ];

    $data = $this->ochaKeyFiguresApiClient->setOchaPresence($form_state->getValue('id'), $data, $add);
    if ($add) {
      $form_state->setRedirectUrl(URL::fromRoute('ocha_key_figures.ocha_presences.edit', [
        'id' => $data['id'],
      ]));
    }
    else {
      $form_state->setRedirectUrl(URL::fromRoute('ocha_key_figures.ocha_presences'));
    }
  }

}
