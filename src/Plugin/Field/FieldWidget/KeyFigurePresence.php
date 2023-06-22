<?php

namespace Drupal\ocha_key_figures\Plugin\Field\FieldWidget;

use Drupal\Component\Utility\Html;
use Drupal\Component\Utility\NestedArray;
use Drupal\Core\Ajax\AjaxResponse;
use Drupal\Core\Ajax\ReplaceCommand;
use Drupal\Core\Field\FieldDefinitionInterface;
use Drupal\Core\Field\FieldItemListInterface;
use Drupal\Core\Field\WidgetBase;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\Logger\LoggerChannelFactoryInterface;
use Drupal\Core\Render\RendererInterface;
use Drupal\ocha_key_figures\Controller\OchaKeyFiguresController;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\HttpFoundation\Request;

/**
 * Plugin implementation of the 'key_figure' widget.
 *
 * @FieldWidget(
 *   id = "key_figure_presence",
 *   label = @Translation("Key Figure - Simple"),
 *   field_types = {
 *     "key_figure_presence"
 *   }
 * )
 */
class KeyFigurePresence extends WidgetBase {

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
  public static function defaultSettings() {
    return [
      'allowed_providers' => [],
      'allowed_figure_ids' => [],
    ] + parent::defaultSettings();
  }

  /**
   * {@inheritdoc}
   */
  public function settingsForm(array $form, FormStateInterface $form_state) {
    return [
      'allowed_providers' => [
        '#type' => 'textfield',
        '#title' => $this->t('Allowed providers'),
        '#default_value' => $this->getSetting('allowed_providers'),
        '#description' => $this->t('Comma separated list of allowed providers'),
        '#required' => FALSE,
      ],
      'allowed_figure_ids' => [
        '#type' => 'textfield',
        '#title' => $this->t('Allowed figure IDs'),
        '#default_value' => $this->getSetting('allowed_figure_ids'),
        '#description' => $this->t('Comma separated list of allowed figure ids ("figure_id" field)'),
        '#required' => FALSE,
      ],
    ];
  }

  /**
   * {@inheritdoc}
   */
  public function settingsSummary() {
    $summary = [];

    $allowed_providers = $this->getSetting('allowed_providers');
    if (!empty($allowed_providers)) {
      $summary[] = $this->t('Allowed providers: @allowed_providers', [
        '@allowed_providers' => $allowed_providers,
      ]);
    }

    $allowed_figure_ids = $this->getSetting('allowed_figure_ids');
    if (!empty($allowed_figure_ids)) {
      $summary[] = $this->t('Allowed figure IDs: @allowed_figure_ids', [
        '@allowed_figure_ids' => $allowed_figure_ids,
      ]);
    }

    return $summary;
  }

  /**
   * {@inheritdoc}
   */
  protected function handlesMultipleValues() {
    return FALSE;
  }

  /**
   * Get the name of the element that triggered the form.
   *
   * @param \Drupal\Core\Form\FormStateInterface $form_state
   *   The form state.
   * @param array $element_parents
   *   The list of parents of the current key figure field element.
   *
   * @return string|null
   *   The name of the subfield of the element that was the trigger if any.
   */
  protected function getTrigger(FormStateInterface $form_state, array $element_parents) {
    $triggering_element = $form_state->getTriggeringElement();
    if (!empty($triggering_element['#array_parents'])) {
      $triggering_element_parents = $triggering_element['#array_parents'];
      $triggering_element_name = array_pop($triggering_element_parents);
      if ($triggering_element_parents === $element_parents) {
        return $triggering_element_name;
      }
    }
    return NULL;
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
        'ochapresence' => $item->getFigureOchaPresence(),
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
        unset($values['ochapresence'], $values['year'], $values['id'], $values['label'], $values['value'], $values['unit']);
        break;

      case 'ochapresence':
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
    $ochapresence = $values['ochapresence'] ?? NULL;
    $year = $values['year'] ?? NULL;
    $figure_id = $values['id'] ?? NULL;
    $label = $values['label'] ?? NULL;
    $value = $values['value'] ?? NULL;
    $unit = $values['unit'] ?? NULL;

    $show_no_data = FALSE;

    // Add the ajax wrapper.
    $element['#prefix'] = '<div id="' . $wrapper_id . '">';
    $element['#suffix'] = '</div>';

    // Get list of providers.
    $providers = [];

    $providers = $this->getSupportedProviders();

    $element['provider'] = [
      '#type' => 'select',
      '#title' => $this->t('Provider'),
      '#options' => $providers,
      '#default_value' => $provider,
      '#ajax' => $this->getAjaxSettings($this->t('Loading figure data...'), $field_parents, $delta, $wrapper_id),
      '#empty_option' => $this->t('- Select -'),
      '#empty_value' => '',
    ];

    // Extra fields to select the data from a provider.
    if (isset($provider) && !empty($provider)) {
      $label = NULL;
      $value = NULL;
      $unit = NULL;

      // Get the list of ochapresences for the provider.
      $ochapresences = $this->getFigureOchaPresences($provider);
      if (empty($ochapresences)) {
        $show_no_data = TRUE;
      }
      else {
        $ochapresence = isset($ochapresences[$ochapresence]) ? $ochapresence : NULL;

        $element['ochapresence'] = [
          '#type' => 'select',
          '#title' => $this->t('ochapresence'),
          '#options' => $ochapresences,
          '#default_value' => $ochapresence,
          '#ajax' => $this->getAjaxSettings($this->t('Loading figure data...'), $field_parents, $delta, $wrapper_id),
          '#empty_option' => $this->t('- Select -'),
          '#empty_value' => '',
        ];
      }

      // Get the list of years for the provider and ochapresence.
      if (!empty($ochapresence)) {
        $years = $this->getOchaPresenceYearsByProvider($provider, $ochapresence);
        if (empty($years)) {
          $show_no_data = TRUE;
        }
        else {
          if ($year > 2) {
            $year = isset($years[$year]) ? $year : NULL;
          }

          // Add option for current year and any year.
          $years = [
            '2' => $this->t('Current year'),
          ] + $years;

          $element['year'] = [
            '#type' => 'select',
            '#title' => $this->t('Year'),
            '#options' => $years,
            '#default_value' => $year,
            '#ajax' => $this->getAjaxSettings($this->t('Loading figure data...'), $field_parents, $delta, $wrapper_id),
            '#empty_option' => $this->t('- Select -'),
            '#empty_value' => '',
          ];
        }
      }

      // Get the list of figures for the provider, ochapresence and year.
      if (!empty($ochapresence) && !empty($year)) {
        $figures = $this->getFigures($provider, $ochapresence, $year);
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
            '#type' => 'select',
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
      if (isset($label)) {
        $element['label'] = [
          '#type' => 'textfield',
          '#title' => $this->t('Label'),
          '#default_value' => $label,
        ];
      }
      if (isset($value)) {
        $element['value'] = [
          '#type' => 'textfield',
          '#title' => $this->t('Value'),
          '#default_value' => $value,
          '#disabled' => TRUE,
        ];

        $element['unit'] = [
          '#type' => 'textfield',
          '#title' => $this->t('Unit'),
          '#default_value' => $unit,
          '#disabled' => TRUE,
        ];
      }
    }

    return $element;
  }

  /**
   * Get the list of supported providers.
   *
   * @return array
   *   Associative array of providers keyed by IDs.
   */
  protected function getSupportedProviders() {
    $supported_providers = $this->ochaKeyFiguresApiClient->getSupportedProviders();
    $allowed_providers = $this->getSetting('allowed_providers');
    if (!empty($allowed_providers)) {
      $allowed_providers = array_flip(preg_split('/,\s*/', trim(strtolower($allowed_providers))));
      $supported_providers = array_intersect_key($supported_providers, $allowed_providers);
    }
    return $supported_providers;
  }

  /**
   * Get the contries available for the figure provider.
   *
   * @param string $provider
   *   Provider.
   *
   * @return array
   *   Associative array keyed by ochapresence ids and with ochapresence
   *   names as values.
   */
  protected function getFigureOchaPresences($provider) {
    return $this->ochaKeyFiguresApiClient->getOchaPresencesByProvider($provider);
  }

  /**
   * Get the OCHA Presences available for the figure provider.
   *
   * @param string $provider
   *   Provider.
   * @param string $ocha_presence_id
   *   OCHA Presence Id.
   *
   * @return array
   *   Associative array with year as keys and values.
   */
  protected function getOchaPresenceYearsByProvider($provider, $ocha_presence_id) {
    return $this->ochaKeyFiguresApiClient->getOchaPresenceYearsByProvider($provider, $ocha_presence_id);
  }

  /**
   * Get the figures available for the figure provider, OCHA Presence and year.
   *
   * @param string $provider
   *   Provider.
   * @param string $ocha_presence_id
   *   ISO3 code of a ocha_presence_id.
   * @param string $year
   *   Year.
   *
   * @return array
   *   Associative array keyed by figure ID and with figures data as values.
   */
  protected function getFigures($provider, $ocha_presence_id, $year) {
    $data = $this->ochaKeyFiguresApiClient->getOchaPresenceFigures($provider, $ocha_presence_id, $year);

    $allowed_figure_ids = $this->getSetting('allowed_figure_ids');
    if (!empty($allowed_figure_ids)) {
      $allowed_figure_ids = array_flip(preg_split('/,\s*/', trim(strtolower($allowed_figure_ids))));
    }

    $figures = [];
    if (!empty($data)) {
      foreach ($data as $item) {
        if (empty($allowed_figure_ids) || isset($item['figure_id'], $allowed_figure_ids[$item['figure_id']])) {
          $figures[$item['figure_id']] = $item;
          $figures[$item['figure_id']]['figure_list'] = [];
        }
      }
    }

    asort($figures);

    return $figures;
  }

  /**
   * Get the base ajax settings for the operation in the widget.
   *
   * @param string $message
   *   The message to display while the request is being performed.
   * @param array $field_parents
   *   The parents of the field.
   * @param int $delta
   *   The delta of the field element.
   * @param string $wrapper_id
   *   The ID of the wrapping HTML element which is going to be replaced.
   *
   * @return array
   *   Array with the ajax settings.
   */
  protected function getAjaxSettings($message, array $field_parents, $delta, $wrapper_id) {
    $path = array_merge($field_parents, ['widget', $delta]);

    return [
      'callback' => [static::class, 'rebuildWidgetForm'],
      'options' => [
        'query' => [
          // This will be used in the ::rebuildWidgetForm() callback to
          // retrieve the widget.
          'element_path' => implode('/', $path),
        ],
      ],
      'wrapper' => $wrapper_id,
      'effect' => 'fade',
      'progress' => [
        'type' => 'throbber',
        'message' => $message,
      ],
      'disable-refocus' => TRUE,
    ];
  }

  /**
   * Get the ajax wrapper id for the field.
   *
   * @param array $field_parents
   *   The parents of the field.
   * @param int $delta
   *   The delta of the field element.
   *
   * @return string
   *   Wrapper ID.
   */
  protected function getAjaxWrapperId(array $field_parents, $delta) {
    return Html::getUniqueId(implode('-', $field_parents) . '-' . $delta . '-ajax-wrapper');
  }

  /**
   * Rebuild form.
   *
   * @param array $form
   *   The build form.
   * @param \Drupal\Core\Form\FormStateInterface $form_state
   *   The form state.
   * @param \Symfony\Component\HttpFoundation\Request $request
   *   The current request.
   *
   * @return \Drupal\Core\Ajax\AjaxResponse
   *   The ajax response of the ajax upload.
   */
  public static function rebuildWidgetForm(array &$form, FormStateInterface $form_state, Request $request) {
    // Retrieve the updated widget.
    $parameter = $request->query->get('element_path');
    $path = is_string($parameter) ? explode('/', trim($parameter)) : NULL;

    $response = new AjaxResponse();

    if (isset($path)) {
      // The array parents are populated in the WidgetBase::afterBuild().
      $element = NestedArray::getValue($form, $path);

      if (isset($element)) {
        // Remove the weight field as it's been handled by the tabledrag script
        // and would appear twice otherwise.
        unset($element['_weight']);

        // This will replace the widget with the new one in the form.
        $response->addCommand(new ReplaceCommand(NULL, $element));
      }
    }

    return $response;
  }

}
