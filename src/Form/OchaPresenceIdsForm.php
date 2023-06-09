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
class OchaPresenceIdsForm extends FormBase {

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
  public function buildForm(array $form, FormStateInterface $form_state, string $id = '', string $external_id = '') {
    $data = $this->ochaKeyFiguresApiClient->getOchaPresenceExternal($external_id);

    $form['id'] = array(
      '#title' => $this->t('Id'),
      '#type' => 'textfield',
      '#default_value' => $id,
      '#disabled' => TRUE,
    );

    $form['external_id'] = array(
      '#title' => $this->t('Id'),
      '#type' => 'textfield',
      '#default_value' => $external_id,
      '#disabled' => TRUE,
    );

    $form['provider'] = array(
      '#title' => $this->t('Provider'),
      '#type' => 'select',
      '#options' => $this->ochaKeyFiguresApiClient->getSupportedProviders(),
      '#default_value' => $data['provider']['id'],
    );

    $form['year'] = array(
      '#title' => $this->t('Year'),
      '#type' => 'textfield',
      '#default_value' => $data['year'],
    );

    $default_external_ids = [];
    foreach ($data['external_ids'] as $external_ids) {
      $default_external_ids[] = $external_ids['id'];
    }
    $form['external_ids'] = [
      '#title' => $this->t('External options'),
      '#type' => 'checkboxes',
      '#multiple' => TRUE,
      '#options' => $this->ochaKeyFiguresApiClient->getExternalLookup($data['provider']['id']),
      '#default_value' => $default_external_ids,
    ];

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
      '#url' => Url::fromRoute('ocha_key_figures.ocha_presences.edit', [
        'id' => $id,
      ]),
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
    $external_id = $form_state->getValue(['external_id']);
    $data = $this->ochaKeyFiguresApiClient->getOchaPresenceExternal($external_id);

    $data['ocha_presence'] = $form_state->getValue('id');
    $data['provider'] = $form_state->getValue('provider');
    $data['year'] = $form_state->getValue('year');
    $data['external_ids'] = array_values(array_filter($form_state->getValue('external_ids')));

    $data = $this->ochaKeyFiguresApiClient->setOchaPresenceExternal($external_id, $data);
  }

}
