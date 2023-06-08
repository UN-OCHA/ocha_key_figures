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
      '#default_value' => $data['id'],
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

    return $form;
  }

  /**
   * {@inheritdoc}
   */
  public function submitForm(array &$form, FormStateInterface $form_state) {

  }

}
