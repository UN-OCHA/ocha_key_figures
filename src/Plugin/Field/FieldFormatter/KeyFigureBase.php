<?php

namespace Drupal\ocha_key_figures\Plugin\Field\FieldFormatter;

use Drupal\Core\Field\FieldDefinitionInterface;
use Drupal\Core\Field\FieldItemListInterface;
use Drupal\Core\Field\FormatterBase;
use Drupal\Core\Form\FormStateInterface;
use Drupal\ocha_key_figures\Controller\OchaKeyFiguresController;
use Drupal\ocha_key_figures\Helpers\NumberFormatter;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Base class of the 'key_figure' formatter.
 */
class KeyFigureBase extends FormatterBase {

  /**
   * The OCHA Key Figures API client.
   *
   * @var \Drupal\ocha_key_figures\Controller\OchaKeyFiguresController
   */
  protected $ochaKeyFiguresApiClient;

  /**
   * {@inheritdoc}
   */
  public function __construct($plugin_id, $plugin_definition, FieldDefinitionInterface $field_definition, array $settings, $label, $view_mode, array $third_party_settings, OchaKeyFiguresController $ocha_key_figure_api_client) {
    parent::__construct($plugin_id, $plugin_definition, $field_definition, $settings, $label, $view_mode, $third_party_settings);

    $this->ochaKeyFiguresApiClient = $ocha_key_figure_api_client;
  }

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container, array $configuration, $plugin_id, $plugin_definition) {
    return new static(
      $plugin_id,
      $plugin_definition,
      $configuration['field_definition'],
      $configuration['settings'],
      $configuration['label'],
      $configuration['view_mode'],
      $configuration['third_party_settings'],
      $container->get('ocha_key_figures.key_figures_controller')
    );
  }

  /**
   * {@inheritdoc}
   */
  public static function defaultSettings() {
    return [
      'format' => 'decimal',
      'precision' => 1,
      'percentage' => 'yes',
      'currency_symbol' => 'yes',
    ] + parent::defaultSettings();
  }

  /**
   * {@inheritdoc}
   */
  public function settingsForm(array $form, FormStateInterface $form_state) {
    $elements['format'] = [
      '#type' => 'select',
      '#title' => $this->t('Figure format'),
      '#options' => NumberFormatter::getSupportedFormats(),
      '#default_value' => $this->getSetting('format') ?? 'decimal',
      '#description' => $this->t('Format for the numeric figures.'),
    ];

    $elements['precision'] = [
      '#type' => 'select',
      '#title' => $this->t('Precision'),
      '#options' => array_combine(range(1, 5), range(1, 5)),
      '#default_value' => $this->getSetting('precision') ?? 1,
      '#description' => $this->t('Number of decimal digits in compact form: 1.2 million with a precision of
        1, 1.23 million with a precision of 2. Defaults to 1.'),
    ];

    $elements['currency_symbol'] = [
      '#type' => 'select',
      '#title' => $this->t('Use currency symbol'),
      '#options' => [
        'yes' => $this->t('Yes'),
        'no' => $this->t('No, use currency code'),
      ],
      '#default_value' => $this->getSetting('currency_symbol') ?? 'yes',
    ];

    $elements['percentage'] = [
      '#type' => 'select',
      '#title' => $this->t('Format percentages'),
      '#options' => [
        'yes' => $this->t('Yes'),
        'no' => $this->t('No, output raw value'),
      ],
      '#default_value' => $this->getSetting('percentage') ?? 'yes',
    ];

    return $elements;
  }

  /**
   * {@inheritdoc}
   */
  public function settingsSummary() {
    $summary = [];

    $format = $this->getSetting('format') ?? 'decimal';
    $formats = NumberFormatter::getSupportedFormats();
    $summary[] = $this->t('Format: @value', [
      '@value' => $formats[$format] ?? ucfirst($format),
    ]);
    $summary[] = $this->t('Precision: @value', [
      '@value' => $this->getSetting('precision') ?? 1,
    ]);
    $summary[] = $this->t('Output style percentages: @value', [
      '@value' => $this->getSetting('percentage') ?? 'yes',
    ]);

    return $summary;
  }

  /**
   * {@inheritdoc}
   */
  public function viewElements(FieldItemListInterface $items, $langcode) {
    $elements = [];

    return $elements;
  }

  /**
   * Get the figures available for the figure provider, country and year.
   *
   * @param string $provider
   *   Provider.
   * @param string|array $country
   *   ISO3 code of a country.
   * @param string $year
   *   Year.
   *
   * @return array
   *   Associative array keyed by figure ID and with figures data as values.
   */
  protected function getFigures($provider, $country, $year) {
    $data = $this->ochaKeyFiguresApiClient->query($provider, '', [
      'iso3' => $country,
      'year' => $year,
      'archived' => 0,
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

}
