<?php

namespace Drupal\ocha_key_figures\Plugin\Field\FieldWidget;

use Drupal\Component\Utility\Html;
use Drupal\Component\Utility\NestedArray;
use Drupal\Core\Ajax\AjaxResponse;
use Drupal\Core\Ajax\ReplaceCommand;
use Drupal\Core\Field\FieldDefinitionInterface;
use Drupal\Core\Field\WidgetBase;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\Logger\LoggerChannelFactoryInterface;
use Drupal\Core\Render\RendererInterface;
use Drupal\ocha_key_figures\Controller\OchaKeyFiguresController;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\HttpFoundation\Request;

/**
 * Base widget.
 */
abstract class KeyFigureBaseWidget extends WidgetBase {

  /**
   * Separator used by PHP and js.
   *
   * @var string
   */
  protected $separator = '|-|';

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
      'allow_manual' => 'yes',
    ] + parent::defaultSettings();
  }

  /**
   * {@inheritdoc}
   */
  public function settingsForm(array $form, FormStateInterface $form_state) {
    return [
      'allow_manual' => [
        '#type' => 'select',
        '#options' => [
          'yes' => $this->t('Yes'),
          'no' => $this->t('No'),
        ],
        '#title' => $this->t('Allow manual numbers'),
        '#default_value' => $this->getSetting('allow_manual'),
        '#required' => TRUE,
      ],
    ];
  }

  /**
   * {@inheritdoc}
   */
  public function settingsSummary() {
    $summary = [];

    $summary[] = $this->t('Allow manual figures: @allow_manual', [
      '@allow_manual' => $this->getSetting('allow_manual'),
    ]);

    return $summary;
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
    $data = $this->ochaKeyFiguresApiClient->query($provider, 'countries');
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
    $data = $this->ochaKeyFiguresApiClient->query($provider, 'years', [
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
   *   Associative array keyed by ID and with figures data as values.
   */
  protected function getFigures($provider, $country, $year) {
    $query = [
      'iso3' => $country,
      'year' => $year,
      'archived' => 0,
    ];

    // Special case for year.
    if ($year == 1) {
      // No need to filter.
      unset($query['year']);
    }
    elseif ($year == 2) {
      $query['year'] = date('Y');
    }

    $data = $this->ochaKeyFiguresApiClient->query($provider, '', $query);
    $figures = [];
    if (!empty($data)) {
      foreach ($data as $item) {
        $figures[$item['figure_id']] = $item;
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
  protected function getAjaxSettings($message, array $field_parents, $delta, $wrapper_id) {
    if ($delta == '') {
      $path = array_merge($field_parents, ['widget']);
    }
    else {
      $path = array_merge($field_parents, ['widget', $delta]);
    }

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
  protected function getAjaxWrapperId(array $field_parents, $delta = '') {
    if ($delta == '') {
      return Html::getUniqueId(implode('-', $field_parents) . '-ajax-wrapper');
    }

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

  /**
   * Get the OCHA presences available for the figure provider.
   *
   * @param string $provider
   *   Provider.
   *
   * @return array
   *   Associative array keyed by ochapresence iso3 code and with ochapresence names as
   *   values.
   */
  protected function getFigureOchaPresences($provider) {
    return $this->ochaKeyFiguresApiClient->getOchaPresencesByProvider($provider);
  }

  /**
   * Get the OCHA Presences years available.
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
  protected function getOchaPresenceFigures($provider, $ocha_presence_id, $year) {
    // Special case for year.
    if ($year == 2) {
      $year = date('Y');
    }

    $figures = $this->ochaKeyFiguresApiClient->getOchaPresenceFiguresParsed($provider, $ocha_presence_id, $year);

    asort($figures);
    return $figures;
  }

  /**
   * Get provider dropdown.
   */
  protected function getDropdownForProvider($provider, $field_parents, $delta, $wrapper_id, $allow_manual = FALSE) {
    // Get list of providers.
    $providers = $this->ochaKeyFiguresApiClient->getSupportedProviders();

    $allowed_providers = $this->getFieldSetting('allowed_providers');
    if (!empty($allowed_providers)) {
      $allowed_providers = array_flip(preg_split('/,\s*/', trim(strtolower($allowed_providers))));
      $providers = array_intersect_key($providers, $allowed_providers);
    }

    if ($allow_manual) {
      $providers = [
        'manual' => $this->t('Manual'),
      ] + $providers;
    }

    return [
      '#type' => 'select',
      '#title' => $this->t('Provider'),
      '#options' => $providers,
      '#default_value' => $provider,
      '#ajax' => $this->getAjaxSettings($this->t('Loading figure data...'), $field_parents, $delta, $wrapper_id),
      '#empty_option' => $this->t('- Select -'),
      '#empty_value' => '',
    ];
  }

  /**
   * Get country dropdown.
   */
  protected function getDropdownForCountry($provider, $country, $field_parents, $delta, $wrapper_id) {
    $countries = $this->getFigureCountries($provider);

    return [
      '#type' => 'select',
      '#title' => $this->t('Country'),
      '#options' => $countries,
      '#default_value' => $country,
      '#ajax' => $this->getAjaxSettings($this->t('Loading figure data...'), $field_parents, $delta, $wrapper_id),
      '#empty_option' => $this->t('- Select -'),
      '#empty_value' => '',
    ];
  }

  /**
   * Get years dropdown.
   */
  protected function getDropdownForYears($provider, $country, $year, $field_parents, $delta, $wrapper_id) {
    $years = $this->getFigureYears($provider, $country);

    if ($year > 2) {
      $year = isset($years[$year]) ? $year : NULL;
    }

    // Add option for current year and any year.
    $years = [
      '1' => $this->t('Any year'),
      '2' => $this->t('Current year'),
    ] + $years;

    return [
      '#type' => 'select',
      '#title' => $this->t('Year'),
      '#options' => $years,
      '#default_value' => $year,
      '#ajax' => $this->getAjaxSettings($this->t('Loading figure data...'), $field_parents, $delta, $wrapper_id),
      '#empty_option' => $this->t('- Select -'),
      '#empty_value' => '',
    ];
  }

  /**
   * Get OCHA presence dropdown.
   */
  protected function getDropdownForOchaPresense($provider, $ochapresence, $field_parents, $delta, $wrapper_id) {
    $ochapresences = $this->getFigureOchaPresences($provider);

    return [
      '#type' => 'select',
      '#title' => $this->t('OCHA regional or country office'),
      '#options' => $ochapresences,
      '#default_value' => $ochapresence,
      '#ajax' => $this->getAjaxSettings($this->t('Loading figure data...'), $field_parents, $delta, $wrapper_id),
      '#empty_option' => $this->t('- Select -'),
      '#empty_value' => '',
    ];
  }

  /**
   * Get the OCHA Presences years available.
   *
   * @param string $provider
   *   Provider.
   * @param string $ocha_presence_id
   *   OCHA Presence Id.
   *
   * @return array
   *   Associative array with year as keys and values.
   */
  protected function getDropdownForOchaPresenceYears($provider, $ocha_presence_id, $year, $field_parents, $delta, $wrapper_id) {
    $years = $this->getOchaPresenceYearsByProvider($provider, $ocha_presence_id);
    if ($year > 2) {
      $year = isset($years[$year]) ? $year : NULL;
    }

    // Add option for current year.
    $years = [
      '2' => $this->t('Current year'),
    ] + $years;

    return [
      '#type' => 'select',
      '#title' => $this->t('Year'),
      '#options' => $years,
      '#default_value' => $year,
      '#ajax' => $this->getAjaxSettings($this->t('Loading figure data...'), $field_parents, $delta, $wrapper_id),
      '#empty_option' => $this->t('- Select -'),
      '#empty_value' => '',
    ];
  }

  /**
   * Get figures dropdown options.
   */
  protected function getOptionsForFigures(array $figures, array $figure_ids) {
    // Array of "Figure Ids" not Ids.
    $allowed_figure_figure_ids = $this->getFieldSetting('allowed_figure_ids');
    if (!empty($allowed_figure_figure_ids)) {
      $allowed_figure_figure_ids = array_flip(preg_split('/,\s*/', trim(strtolower($allowed_figure_figure_ids))));
    }

    // Add options in order.
    $figure_options = [];
    foreach ($figure_ids as $figure_id) {
      if (!$figure_id) {
        continue;
      }

      if ($figure_id == '_all') {
        continue;
      }

      if (empty($allowed_figure_figure_ids)) {
        $figure_options[$figure_id] = $figures[$figure_id]['name'];
      }
      else {
        if (isset($allowed_figure_figure_ids[$figures[$figure_id]['figure_id']])) {
          $figure_options[$figure_id] = $figures[$figure_id]['name'];
        }
      }

      unset($figures[$figure_id]);
    }

    $figure_options_unselected = [];
    foreach ($figures as $figure_id => $figure) {
      if (empty($allowed_figure_figure_ids)) {
        $figure_options_unselected[$figure_id] = $figure['name'];
      }
      else {
        if (isset($allowed_figure_figure_ids[$figure['figure_id']])) {
          $figure_options_unselected[$figure_id] = $figure['name'];
        }
      }
    }

    asort($figure_options_unselected);
    $figure_options += $figure_options_unselected;

    return $figure_options;
  }

}
