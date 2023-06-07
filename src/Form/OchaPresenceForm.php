<?php

namespace Drupal\ocha_key_figures\Form;

use Drupal\Core\Form\FormBase;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\Database\Database;
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
      '#type' => 'textfield',
      '#default_value' => $data['id'],
      '#disabled' => TRUE,
    );

    $form['name'] = array(
      '#type' => 'textfield',
      '#default_value' => $data['name'],
    );

    $form['office_type'] = array(
      '#type' => 'textfield',
      '#default_value' => $data['office_type'],
    );

    return $form;
  }

  /**
   * {@inheritdoc}
   */
  public function submitForm(array &$form, FormStateInterface $form_state) {

  }

}
