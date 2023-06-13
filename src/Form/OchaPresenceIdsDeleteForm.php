<?php

namespace Drupal\ocha_key_figures\Form;

use Drupal\Core\Form\ConfirmFormBase;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\Url;
use Drupal\ocha_key_figures\Controller\OchaKeyFiguresController;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Provides the form for adding countries.
 */
class OchaPresenceIdsDeleteForm extends ConfirmFormBase {

  /**
   * The OCHA Key Figures API client.
   *
   * @var \Drupal\ocha_key_figures\Controller\OchaKeyFiguresController
   */
  protected $ochaKeyFiguresApiClient;

  /**
   * ID of the item to delete.
   *
   * @var string
   */
  protected $id;

  /**
   * ID of the item to delete.
   *
   * @var string
   */
  protected $external_id;

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
    return 'ocha_key_figures_presence_ids_delete';
  }

  /**
   * {@inheritdoc}
   */
  public function getCancelUrl() {
    return URL::fromRoute('ocha_key_figures.ocha_presences.edit', [
      'id' => $this->id,
      'external_id' => $this->external_id,
    ]);
  }

  /**
   * {@inheritdoc}
   */
  public function getQuestion() {
    return $this->t('Do you want to delete %id?', ['%id' => $this->id]);
  }

  /**
   * {@inheritdoc}
   */
  public function buildForm(array $form, FormStateInterface $form_state, string $id = '', string $external_id = '') {
    $this->ochaKeyFiguresApiClient->getOchaPresenceExternal($external_id);

    $this->id = $id;
    $this->external_id = $external_id;

    return parent::buildForm($form, $form_state);
  }

  /**
   * {@inheritdoc}
   */
  public function submitForm(array &$form, FormStateInterface $form_state) {
    $this->ochaKeyFiguresApiClient->deleteOchaPresenceExternal($this->external_id);

    $form_state->setRedirectUrl(URL::fromRoute('ocha_key_figures.ocha_presences.edit', [
      'id' => $this->id,
    ]));
  }

}
