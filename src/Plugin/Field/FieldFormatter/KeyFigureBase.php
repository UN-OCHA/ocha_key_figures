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
    $summary[] = $this->t('Output currency symbol: @value', [
      '@value' => $this->getSetting('currency_symbol') ?? 'yes',
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
    $figures = [];

    $data = $this->ochaKeyFiguresApiClient->getFigures($provider, $country, $year);
    if (!empty($data)) {
      foreach ($data as $item) {
        $figures[$item['figure_id']] = $item;
      }
    }

    asort($figures);

    return $figures;
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
   * @param array $figure_ids
   *   List of figure IDs.
   *
   * @return array
   *   Associative array keyed by figure ID and with figures data as values.
   */
  protected function getOchaPresenceFigures($provider, $ocha_presence_id, $year, $figure_ids = []) {
    $figures = $this->ochaKeyFiguresApiClient->getOchaPresenceFiguresParsed($provider, $ocha_presence_id, $year, $figure_ids);

    asort($figures);
    return $figures;
  }

  /**
   * Add prefix, suffix if needed.
   */
  protected function addPrefixSuffix(array &$figure, $langcode = 'en') {
    $percentage_formatted = $this->getSetting('percentage');
    $currency_symbol = $this->getSetting('currency_symbol');

    // Set currency prefix if data is financial.
    if (isset($figure['value_type']) && $figure['value_type'] == 'amount') {
      $figure['prefix'] = !empty($figure['unit']) ? $figure['unit'] : 'USD';
      if ($currency_symbol == 'yes') {
        $figure['prefix'] = NumberFormatter::getCurrencySymbol($langcode, $figure['prefix']);
      }
    }

    // Set percentage suffix if needed.
    if (isset($figure['value_type']) && $figure['value_type'] == 'percentage') {
      $figure['unit'] = !empty($figure['unit']) ? $figure['unit'] : '%';
      $figure['suffix'] = $figure['unit'];
      if ($percentage_formatted != 'yes') {
        $figure['value'] /= 100;
      }
    }
  }

  /**
   * Format numeric data.
   */
  protected function formatNumber($value, $langcode) {
    $format = $this->getSetting('format');
    $precision = $this->getSetting('precision');
    $strict = FALSE;

    return NumberFormatter::format($value, $langcode, $format, $precision, $strict);
  }

  /**
   * Build json-ld output.
   */
  protected function buildJsonLd($data, $item) {
    if (empty($data)) {
      return NULL;
    }

    // Initialize data for JSON-LD.
    $json_data = [];
    foreach ($data as $row) {
      $json_data[$row['name']] = $row['value'];
      $country = $row['country'] ?? '';
    }

    $name = $this->t('@title of @country', [
      '@title' => 'Key figures',
      '@country' => $country,
    ]);
    $description = $this->t('Easily discoverable topline numbers for humanitarian crises in @country', [
      '@country' => $country,
    ]);

    $metadata = $this->getMetaDataByProvider($item->getFigureProvider());
    $metadata += [
      'name' => $name,
      'short_name' => 'Key figures',
      'spatialCoverage' => $country,
      'description' => $description,
      'temporalCoverage' => $item->getFigureYear() === FALSE ? '2000-01-01/..' : $item->getFigureYear(),
    ];

    return $this->addJsonLdData($metadata, $json_data);
  }

  /**
   * Get meta data for json ld.
   */
  protected function getMetaDataByProvider(string $provider) {
    switch ($provider) {
      case 'oct':
        return [
          'publisher' => [
            '@type' => 'Organization',
            'sameAs' => 'https://ror.org/00aahzn97',
            'name' => 'OCHA Contribution Tracking (OCT)',
          ],
          'creator' => [
            '@type' => 'Organization',
            'sameAs' => 'https://ror.org/00aahzn97',
            'name' => 'OCHA Contribution Tracking (OCT)',
          ],
          'license' => [
            '@type' => 'CreativeWork',
            'name' => 'Creative Commons Attribution for Intergovernmental Organisations',
            'url' => 'https://data.humdata.org/faqs/licenses#auto-faq-_Data_Licenses_Content_-Creative_Commons_Attribution_for_Intergovernmental_Organisations__CC_BY_IGO_-a',
          ],
        ];

      case 'fts':
        return [
          'publisher' => [
            '@type' => 'Organization',
            'sameAs' => 'https://ror.org/00aahzn97',
            'name' => 'OCHA Financial Tracking System (FTS)',
          ],
          'creator' => [
            '@type' => 'Organization',
            'sameAs' => 'https://ror.org/00aahzn97',
            'name' => 'OCHA Financial Tracking System (FTS)',
          ],
          'license' => [
            '@type' => 'CreativeWork',
            'name' => 'Creative Commons Attribution for Intergovernmental Organisations',
            'url' => 'https://data.humdata.org/faqs/licenses#auto-faq-_Data_Licenses_Content_-Creative_Commons_Attribution_for_Intergovernmental_Organisations__CC_BY_IGO_-a',
          ],
        ];

      case 'rw-crisis':
        return [
          'publisher' => [
            '@type' => 'Organization',
            'name' => 'ReliefWeb',
          ],
          'creator' => [
            '@type' => 'Organization',
            'name' => 'ReliefWeb',
          ],
          'license' => [
            '@type' => 'CreativeWork',
            'name' => 'Creative Commons Attribution for Intergovernmental Organisations',
            'url' => 'https://data.humdata.org/faqs/licenses#auto-faq-_Data_Licenses_Content_-Creative_Commons_Attribution_for_Intergovernmental_Organisations__CC_BY_IGO_-a',
          ],
        ];

      case 'inform-risk':
        return [
          'publisher' => [
            '@type' => 'Organization',
            'name' => 'ACAPS',
          ],
          'creator' => [
            '@type' => 'Organization',
            'name' => 'ACAPS',
          ],
          'license' => [
            '@type' => 'CreativeWork',
            'name' => 'Creative Commons Attribution International',
            'url' => 'https://data.humdata.org/faqs/licenses#auto-faq-_Data_Licenses_Content_-Creative_Commons_Attribution_International_CC_BY_-a',
          ],
        ];

      case 'cbpf':
      case 'cerf':
        return [
          'publisher' => [
            '@type' => 'Organization',
            'sameAs' => 'https://ror.org/00aahzn97',
            'name' => 'United Nations Office for the Coordination of Humanitarian Affairs',
          ],
          'creator' => [
            '@type' => 'Organization',
            'sameAs' => 'https://ror.org/00aahzn97',
            'name' => 'United Nations Office for the Coordination of Humanitarian Affairs',
          ],
          'license' => [
            '@type' => 'CreativeWork',
            'name' => 'Creative Commons Attribution International',
            'url' => 'https://data.humdata.org/faqs/licenses#auto-faq-_Data_Licenses_Content_-Creative_Commons_Attribution_International_CC_BY_-a',
          ],
        ];

    }

    // Default to OCHA.
    return [
      'publisher' => [
        '@type' => 'Organization',
        'sameAs' => 'https://ror.org/00aahzn97',
        'name' => 'United Nations Office for the Coordination of Humanitarian Affairs',
      ],
      'creator' => [
        '@type' => 'Organization',
        'sameAs' => 'https://ror.org/00aahzn97',
        'name' => 'United Nations Office for the Coordination of Humanitarian Affairs',
      ],
      'license' => [
        '@type' => 'CreativeWork',
        'name' => 'Creative Commons Attribution International',
        'url' => 'https://data.humdata.org/faqs/licenses#auto-faq-_Data_Licenses_Content_-Creative_Commons_Attribution_International_CC_BY_-a',
      ],
    ];
  }

  /**
   * Add json ld data.
   */
  protected function addJsonLdData($metadata, $data) {
    $label_column = [];
    $value_column = [];
    $keywords = [];

    // Default keyword.
    $keywords[] = 'ReliefWeb > Numbers > ' . $metadata['spatialCoverage'] . ' > ' . $metadata['short_name'];

    foreach ($data as $label => $value) {
      $label_column[] = [
        'csvw:value' => $label,
        'csvw:primaryKey' => $label,
      ];

      $value_column[] = [
        'csvw:value' => $value,
        'csvw:primaryKey' => $value,
      ];

      $keywords[] = 'ReliefWeb > Numbers > ' . $metadata['spatialCoverage'] . ' > ' . $metadata['short_name'] . ' > ' . $label;
    }

    $json_ld = [
      '@context' => [
        'https://schema.org',
        [
          'csvw' => 'http://www.w3.org/ns/csvw#',
        ],
      ],
      '@type' => 'Dataset',
      'keywords' => $keywords,
      'isAccessibleForFree' => TRUE,
      'mainEntity' => [
        '@type' => 'csvw:Table',
        'csvw:tableSchema' => [
          'csvw:columns' => [
            [
              'csvw:name' => 'Label',
              'csvw:datatype' => 'string',
              'csvw:cells' => $label_column,
            ],
            [
              'csvw:name' => 'Value',
              'csvw:datatype' => 'number',
              'csvw:cells' => $value_column,
            ],
          ],
        ],
      ],
    ] + $metadata;

    return json_encode($json_ld);
  }

}
