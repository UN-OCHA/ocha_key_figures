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
use Drupal\ocha_key_figures\Controller\BaseKeyFiguresController;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\HttpFoundation\Request;

/**
 * Plugin implementation of the 'key_figure' widget.
 *
 * @FieldWidget(
 *   id = "key_figure_multiple",
 *   label = @Translation("Key Figure - Multiple"),
 *   field_types = {
 *     "key_figure"
 *   },
 *   multiple_values = TRUE,
 * )
 */
class KeyFigureMultiple extends WidgetBase {

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
   * @var \Drupal\ocha_key_figures\Controller\BaseKeyFiguresController
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
    BaseKeyFiguresController $ocha_key_figure_api_client
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
    return parent::defaultSettings();
  }

  /**
   * {@inheritdoc}
   */
  public function settingsForm(array $form, FormStateInterface $form_state) {
    return [];
  }

  /**
   * {@inheritdoc}
   */
  public function settingsSummary() {
    $summary = [];

    return $summary;
  }

  /**
   * {@inheritdoc}
   */
  protected function handlesMultipleValues() {
    return TRUE;
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
    $element_parents = array_merge($field_parents, ['widget']);
    $wrapper_id = $this->getAjaxWrapperId($field_parents);

    $values = $form_state->getValue($field_parents);

    $trigger = $this->getTrigger($form_state, $element_parents);
    if (!$trigger) {
      foreach ($items as $item) {
        $values['provider'] = $item->getFigureProvider();
        $values['country'] = $item->getFigureCountry();
        $values['year'] = $item->getFigureYear();
        $values['id'][] = $item->getFigureId();
      }
    }
    else {
      // Unset some values based on the ajax form rebuild triggering.
      switch ($trigger) {
        case 'provider':
          unset($values['country'], $values['year'], $values['id']);
          break;

        case 'country':
          unset($values['year'], $values['id']);
          break;

        case 'year':
          unset($values['id']);
          break;

      }

      // Clear the user input so that the form uses the default values.
      NestedArray::unsetValue($form_state->getUserInput(), $field_parents);
    }

    // Default values.
    $provider = $values['provider'] ?? NULL;
    $country = $values['country'] ?? NULL;
    $year = $values['year'] ?? NULL;
    $figure_ids = $values['id'] ?? [];
    $label = $values['label'] ?? NULL;
    $value = $values['value'] ?? NULL;
    $unit = $values['unit'] ?? NULL;

    $show_no_data = FALSE;

    // Add the ajax wrapper.
    $element['#prefix'] = '<div id="' . $wrapper_id . '">';
    $element['#suffix'] = '</div>';

    // Get list of providers.
    $providers = $this->ochaKeyFiguresApiClient->getSupportedProviders();

    $element['provider'] = [
      '#type' => 'select',
      '#title' => $this->t('Provider'),
      '#options' => $providers,
      '#default_value' => $provider,
      '#ajax' => $this->getAjaxSettings($this->t('Loading figure data...'), $field_parents, $wrapper_id),
      '#empty_option' => $this->t('- Select -'),
      '#empty_value' => '',
    ];

    // Extra fields to select the data from a provider.
    if (isset($provider)) {
      // Get the list of countries for the provider.
      $countries = $this->getFigureCountries($provider);
      if (empty($countries)) {
        $show_no_data = TRUE;
      }
      else {
        $country = isset($countries[$country]) ? $country : NULL;

        $element['country'] = [
          '#type' => 'select',
          '#title' => $this->t('Country'),
          '#options' => $countries,
          '#default_value' => $country,
          '#ajax' => $this->getAjaxSettings($this->t('Loading figure data...'), $field_parents, $wrapper_id),
          '#empty_option' => $this->t('- Select -'),
          '#empty_value' => '',
        ];
      }

      // Get the list of years for the provider and country.
      if (!empty($country)) {
        $years = $this->getFigureYears($provider, $country);
        if (empty($years)) {
          $show_no_data = TRUE;
        }
        else {
          $year = isset($years[$year]) ? $year : NULL;

          $element['year'] = [
            '#type' => 'select',
            '#title' => $this->t('Year'),
            '#options' => $years,
            '#default_value' => $year,
            '#ajax' => $this->getAjaxSettings($this->t('Loading figure data...'), $field_parents, $wrapper_id),
            '#empty_option' => $this->t('- Select -'),
            '#empty_value' => '',
          ];
        }
      }

      // Get the list of figures for the provider, country and year.
      if (!empty($country) && !empty($year)) {
        $figures = $this->getFigures($provider, $country, $year);
        if (empty($figures)) {
          $show_no_data = TRUE;
        }
        else {
          if (!empty($values['id'])) {
            $figure_ids = array_diff_key($values['id'], $figures);
          }

          $figure_options = array_map(function ($item) {
            return $item['name'];
          }, $figures);
          asort($figure_options);

          $element['id'] = [
            '#type' => 'checkboxes',
            '#multiple' => TRUE,
            '#title' => $this->t('Id'),
            '#options' => $figure_options,
            '#default_value' => $figure_ids,
            '#attributes' => [
              'class' => ['ocha-key-figures__list'],
            ],
          ];

          $element['sort_order'] = [
            '#type' => 'textarea',
            '#title' => $this->t('Sort order'),
          ];

          $element['sort_order']['#attached']['library'][] = 'ocha_key_figures/admin';
        }
      }
    }

    if ($show_no_data === TRUE) {
      $element['no_data'] = [
        '#type' => 'item',
        '#markup' => $this->t('No data available. This figure will not be saved.'),
      ];
    }

    return $element;
  }

  /**
   * {@inheritdoc}
   */
  public function massageFormValues(array $values, array $form, FormStateInterface $form_state) {
    if (!isset($values['provider'])) {
      return [];
    }

    if (!isset($values['country'])) {
      return [];
    }

    if (!isset($values['year'])) {
      return [];
    }

    if (!isset($values['id'])) {
      return [];
    }

    $figures = $this->getFigures($values['provider'], $values['country'], $values['year']);

    $ids = $values['id'];
    $ids = array_filter($ids);

    $data = [];
    foreach ($ids as $id) {
      if (isset($figures[$id])) {
        $data[] = [
          'provider' => $values['provider'],
          'country' => $values['country'],
          'year' => $values['year'],
          'id' => $id,
          'value' => $figures[$id]['value'],
          'label' => $figures[$id]['name'],
        ];
      }
    }

    return $data;
  }

  /**
   * Get the contries available for the figure provider.
   *
   * @param string $provider
   *   Provider.
   *
   * @return array
   *   Associative array keyed by country iso3 code and with country names as
   *   values.
   */
  protected function getFigureCountries($provider) {
    $data = $this->ochaKeyFiguresApiClient->query($provider . '/countries');
    $countries = [];
    if (!empty($data)) {
      foreach ($data as $item) {
        $countries[$item['value']] = $item['label'];
      }
    }

    asort($countries);

    return $countries;
  }

  /**
   * Get the years available for the figure provider and given country.
   *
   * @param string $provider
   *   Provider.
   * @param string $country
   *   ISO3 code of a country.
   *
   * @return array
   *   Associative array with year as keys and values.
   */
  protected function getFigureYears($provider, $country) {
    $data = $this->ochaKeyFiguresApiClient->query($provider . '/years', [
      'iso3' => $country,
      'order' => [
        'year' => 'desc',
      ],
    ]);
    $years = [];
    if (!empty($data)) {
      foreach ($data as $item) {
        $years[$item['value']] = $item['label'];
      }
    }
    // @todo add a "Latest" year option to instruct to always fetch the most
    // recent figure if available?
    return $years;
  }

  /**
   * Get the figures available for the figure provider, country and year.
   *
   * @param string $provider
   *   Provider.
   * @param string $country
   *   ISO3 code of a country.
   * @param string $year
   *   Year.
   *
   * @return array
   *   Associative array keyed by figure ID and with figures data as values.
   */
  protected function getFigures($provider, $country, $year) {
    $data = $this->ochaKeyFiguresApiClient->query($provider, [
      'iso3' => $country,
      'year' => $year,
      'archived' => FALSE,
    ]);
    $figures = [];
    if (!empty($data)) {
      foreach ($data as $item) {
        $figures[$item['id']] = $item;
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
   * @param string $wrapper_id
   *   The ID of the wrapping HTML element which is going to be replaced.
   *
   * @return array
   *   Array with the ajax settings.
   */
  protected function getAjaxSettings($message, array $field_parents, $wrapper_id) {
    $path = array_merge($field_parents, ['widget']);

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
   *
   * @return string
   *   Wrapper ID.
   */
  protected function getAjaxWrapperId(array $field_parents) {
    return Html::getUniqueId(implode('-', $field_parents) . '-ajax-wrapper');
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
