<?php

namespace Drupal\ocha_key_figures\Plugin\Field\FieldWidget;

use Drupal\Component\Utility\NestedArray;
use Drupal\Core\Field\FieldItemListInterface;
use Drupal\Core\Form\FormStateInterface;

/**
 * Plugin implementation of the 'key_figure' widget.
 *
 * @FieldWidget(
 *   id = "key_figure",
 *   label = @Translation("Key Figure - Simple"),
 *   field_types = {
 *     "key_figure"
 *   },
 *   multiple_values = FALSE,
 * )
 */
class KeyFigure extends KeyFigureBaseWidget {
=======
class KeyFigure extends WidgetBase {

  /**
   * The logger service.
   *
   * @var \Psr\Log\LoggerInterface
   */
  protected $logger;

  /**
   * The renderer service.
   *
   * @var \Drupal\Core\Render\RendererInterface
   */
  protected $renderer;

  /**
   * The OCHA Key Figures API client.
   *
   * @var \Drupal\ocha_key_figures\Controller\OchaKeyFiguresController
   */
  protected $ochaKeyFiguresApiClient;

  /**
   * Ajax wrapper ID.
   *
   * @var string
   */
  protected $ajaxWrapperId;

  /**
   * {@inheritdoc}
   */
  public function __construct(
    $plugin_id,
    $plugin_definition,
    FieldDefinitionInterface $field_definition,
    array $settings,
    array $third_party_settings,
    LoggerChannelFactoryInterface $logger_factory,
    RendererInterface $renderer,
    OchaKeyFiguresController $ocha_key_figure_api_client
  ) {
    parent::__construct(
      $plugin_id,
      $plugin_definition,
      $field_definition,
      $settings,
      $third_party_settings
    );
    $this->logger = $logger_factory->get('unocha_figure_widget');
    $this->renderer = $renderer;
    $this->ochaKeyFiguresApiClient = $ocha_key_figure_api_client;
  }

  /**
   * {@inheritdoc}
   */
  public static function create(
    ContainerInterface $container,
    array $configuration,
    $plugin_id,
    $plugin_definition
  ) {
    return new static(
      $plugin_id,
      $plugin_definition,
      $configuration['field_definition'],
      $configuration['settings'],
      $configuration['third_party_settings'],
      $container->get('logger.factory'),
      $container->get('renderer'),
      $container->get('ocha_key_figures.key_figures_controller')
    );
  }

  /**
   * {@inheritdoc}
   */
  protected function handlesMultipleValues() {
    return FALSE;
  }

  /**
   * {@inheritdoc}
   */
  public function formElement(FieldItemListInterface $items, $delta, array $element, array &$form, FormStateInterface $form_state) {
    $field_name = $this->fieldDefinition->getName();
    $field_parents = array_merge($form['#parents'], [$field_name]);
    $element_parents = array_merge($field_parents, ['widget', $delta]);
    $wrapper_id = $this->getAjaxWrapperId($field_parents, $delta);

    // Ensure the field title and description are displayed when the field
    // only accepts one value.
    if ($this->fieldDefinition->getFieldStorageDefinition()->getCardinality() == 1) {
      $element['#type'] = 'fieldset';
    }

    $item = $items[$delta];
    $values = $form_state->getValue(array_merge($field_parents, [$delta]));

    // Use the initial item values if there are no form input values.
    if (empty($values)) {
      $values = [
        'provider' => $item->getFigureProvider(),
        'country' => $item->getFigureCountry(),
        'year' => $item->getFigureYear(),
        'id' => $item->getFigureId(),
        'label' => $item->getFigureLabel(),
        'value' => $item->getFigureValue(),
        'unit' => $item->getFigureUnit(),
      ];
    }

    // Unset some values based on the ajax form rebuild triggering.
    $trigger = $this->getTrigger($form_state, $element_parents);
    switch ($trigger) {
      case 'provider':
        unset($values['country'], $values['year'], $values['id'], $values['label'], $values['value'], $values['unit']);
        break;

      case 'country':
        unset($values['year'], $values['id'], $values['label'], $values['value'], $values['unit']);
        break;

      case 'year':
        unset($values['id'], $values['label'], $values['value'], $values['unit']);
        break;

      case 'id':
        unset($values['label'], $values['value'], $values['unit']);
        break;
    }

    // Clear the user input so that the form uses the default values.
    if (!empty($trigger)) {
      NestedArray::unsetValue($form_state->getUserInput(), array_merge($field_parents, [$delta]));
    }

    // Default values.
    $provider = $values['provider'] ?? NULL;
    $country = $values['country'] ?? NULL;
    $year = $values['year'] ?? NULL;
    $figure_id = $values['id'] ?? NULL;
    $label = $values['label'] ?? NULL;
    $value = $values['value'] ?? NULL;
    $unit = $values['unit'] ?? NULL;

    $show_no_data = FALSE;

    $allow_manual = $this->getSetting('allow_manual') == 'yes';
    $manual = $provider === 'manual';

    // Add the ajax wrapper.
    $element['#prefix'] = '<div id="' . $wrapper_id . '">';
    $element['#suffix'] = '</div>';

    $element['provider'] = $this->getDropdownForProvider($provider, $field_parents, $delta, $wrapper_id, TRUE);

    // Extra fields to select the data from a provider.
    if (isset($provider) && !empty($provider) && !$manual) {
      $label = NULL;
      $value = NULL;
      $unit = NULL;

      // Get the list of countries for the provider.
      $element['country'] = $this->getDropdownForCountry($provider, $country, $field_parents, $delta, $wrapper_id);

      // Get the list of years for the provider and country.
      if (!empty($country)) {
        $element['year'] = $this->getDropdownForYears($provider, $country, $year, $field_parents, $delta, $wrapper_id);
      }

      // Get the list of figures for the provider, country and year.
      if (!empty($country) && !empty($year)) {
        $figures = $this->getFigures($provider, $country, $year);
        if (empty($figures)) {
          $show_no_data = TRUE;
        }
        else {
          $figure_id = isset($figures[$figure_id]) ? $figure_id : NULL;

          $figure_options = array_map(function ($item) {
            return $item['name'];
          }, $figures);
          asort($figure_options);

          $element['id'] = [
            '#type' => $manual ? 'hidden' : 'select',
            '#multiple' => FALSE,
            '#title' => $this->t('Key Figures'),
            '#options' => $figure_options,
            '#default_value' => $figure_id,
            '#ajax' => $this->getAjaxSettings($this->t('Loading figure data...'), $field_parents, $delta, $wrapper_id),
            '#empty_option' => $this->t('- Select -'),
            '#empty_value' => '',
          ];

          // Preserve the label override.
          $label = $values['label'] ?? $figures[$figure_id]['name'] ?? NULL;
          $value = $figures[$figure_id]['value'] ?? NULL;
          $unit = $figures[$figure_id]['unit'] ?? NULL;
        }
      }
    }

    if ($show_no_data === TRUE) {
      $element['no_data'] = [
        '#type' => 'item',
        '#markup' => $this->t('No data available. This figure will not be saved.'),
      ];
    }
    else {
      if ($manual || isset($label)) {
        $element['label'] = [
          '#type' => 'textfield',
          '#title' => $this->t('Label'),
          '#default_value' => $label,
          '#access' => $allow_manual,
        ];
      }
      if ($manual || isset($value)) {
        $element['value'] = [
          '#type' => 'textfield',
          '#title' => $this->t('Value'),
          '#default_value' => $value,
          '#disabled' => !$manual,
          '#access' => $allow_manual,
        ];

        $element['unit'] = [
          '#type' => 'textfield',
          '#title' => $this->t('Unit'),
          '#default_value' => $unit,
          '#disabled' => !$manual,
          '#access' => $allow_manual,
        ];
      }
    }

    return $element;
  }

}
