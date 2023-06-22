<?php

namespace Drupal\ocha_key_figures\Plugin\Field\FieldFormatter;

use Drupal\Core\Field\FieldDefinitionInterface;
use Drupal\Core\Field\FieldItemListInterface;
use Drupal\Core\Form\FormStateInterface;
use Drupal\ocha_key_figures\Controller\OchaKeyFiguresController;
use Drupal\ocha_key_figures\Helpers\NumberFormatter;

/**
 * Plugin implementation of the 'key_figure' formatter.
 *
 * @FieldFormatter(
 *   id = "key_figure_extended",
 *   label = @Translation("Key Figure - Extended"),
 *   field_types = {
 *     "key_figure"
 *   }
 * )
 */
class KeyFigureExtended extends KeyFigureBase {

  /**
   * Options for sparklines.
   */
  protected $sparklineOptions = [];

  /**
   * {@inheritdoc}
   */
  public function __construct($plugin_id, $plugin_definition, FieldDefinitionInterface $field_definition, array $settings, $label, $view_mode, array $third_party_settings, OchaKeyFiguresController $ocha_key_figure_api_client) {
    parent::__construct($plugin_id, $plugin_definition, $field_definition, $settings, $label, $view_mode, $third_party_settings, $ocha_key_figure_api_client);

    $this->sparklineOptions = [
      'no' => $this->t('No'),
      'all' => $this->t('For all years'),
      'single' => $this->t('For single year'),
    ];
  }

  /**
   * {@inheritdoc}
   */
  public static function defaultSettings() {
    return [
      'display_sparklines' => 'no',
      'output_json_ld' => 'no',
    ] + parent::defaultSettings();
  }

  /**
   * {@inheritdoc}
   */
  public function settingsForm(array $form, FormStateInterface $form_state) {
    $elements = parent::settingsForm($form, $form_state);

    $elements['display_sparklines'] = [
      '#type' => 'select',
      '#title' => $this->t('Display sparklines'),
      '#options' => $this->sparklineOptions,
      '#default_value' => $this->getSetting('display_sparklines') ?? 'no',
    ];

    $elements['output_json_ld'] = [
      '#type' => 'select',
      '#title' => $this->t('Output Json Ld'),
      '#options' => [
        'no' => $this->t('No'),
        'yes' => $this->t('Yes'),
      ],
      '#default_value' => $this->getSetting('output_json_ld') ?? 'no',
    ];

    return $elements;
  }

  /**
   * {@inheritdoc}
   */
  public function settingsSummary() {
    $summary = parent::settingsSummary();

    $summary[] = $this->t('Display sparklines: @value', [
      '@value' => $this->sparklineOptions[$this->getSetting('display_sparklines') ?? 'no'],
    ]);
    $summary[] = $this->t('Output Json Ld: @value', [
      '@value' => $this->getSetting('output_json_ld') ?? 'no',
    ]);

    return $summary;
  }

  /**
   * {@inheritdoc}
   */
  public function viewElements(FieldItemListInterface $items, $langcode) {
    $format = $this->getSetting('format');
    $precision = $this->getSetting('precision');
    $percentage_formatted = $this->getSetting('percentage');
    $currency_symbol = $this->getSetting('currency_symbol');

    $theme_suggestions = implode('__', [
      $this->viewMode,
      $items->getEntity()->getEntityTypeId(),
      $items->getEntity()->bundle(),
      $items->getFieldDefinition()->getName(),
    ]);

    $view_all = '';
    $view_all_info = '';

    $elements = [
      '#theme' => 'ocha_key_figures_extended_figure_list__' . $theme_suggestions,
      '#view_all' => $view_all,
      '#view_all_info' => $view_all_info,
      '#weight' => 99,
      '#cache' => [
        'max-age' => $this->ochaKeyFiguresApiClient->getMaxAge(),
      ],
    ];

    $fetch_all = FALSE;
    $selected_figures = [];
    foreach ($items as $item) {
      if ($item->getFigureId() == '_all') {
        $fetch_all = TRUE;
      }
      else {
        $selected_figures[$item->getFigureId()] = $item->getFigureLabel();
      }
    }

    /** @var \Drupal\ocha_key_figures\Plugin\Field\FieldType\KeyFigure $first */
    $first = $items->first();

    // Make sure we have at least 1 figure.
    if (!$first) {
      return [];
    }

    $year = $first->getFigureYear();
    $sparklines = FALSE;
    if ($this->getSetting('display_sparklines') == 'all') {
      $sparklines = TRUE;
      $year = '';
    }
    elseif ($this->getSetting('display_sparklines') == 'single') {
      $sparklines = TRUE;
    }

    // Get the data.
    if ($sparklines) {
      $results = $this->ochaKeyFiguresApiClient->getKeyFiguresGrouped($first->getFigureProvider(), $first->getFigureCountry(), $year);
    }
    else {
      $results = $this->ochaKeyFiguresApiClient->getKeyFigures($first->getFigureProvider(), $first->getFigureCountry(), $year);
    }

    // If not _all, filter items.
    $filtered_results = $results;
    if (!$fetch_all) {
      $filtered_results = [];
      foreach ($selected_figures as $figure_id => $figure_name) {
        if (isset($results[$figure_id])) {
          $filtered_results[$figure_id] = $results[$figure_id];
        }
      }
    }

    $allowed_figure_ids = $this->getFieldSetting('allowed_figure_ids');
    if (!empty($allowed_figure_ids)) {
      $allowed_figure_ids = array_flip(preg_split('/,\s*/', trim(strtolower($allowed_figure_ids))));
      foreach ($filtered_results as $id => $figure) {
        if (!isset($allowed_figure_ids[$figure['figure_id']])) {
          unset($figures[$id]);
        }
      }
    }

    // Build figures.
    $data = $this->ochaKeyFiguresApiClient->buildKeyFigures($filtered_results, $sparklines);
    if (empty($data)) {
      return FALSE;
    }

    foreach ($data as &$fig) {
      // Set currency prefix if data is financial.
      if (isset($fig['value_type']) && $fig['value_type'] == 'amount') {
        $fig['prefix'] = $fig['unit'] ?? 'USD';
        if ($currency_symbol == 'yes') {
          $fig['prefix'] = NumberFormatter::getCurrencySymbol($langcode, $fig['prefix']);
        }
      }

      // Set percentage suffix if needed.
      if (isset($fig['value_type']) && $fig['value_type'] == 'percentage') {
        $fig['suffix'] = $fig['unit'] ?? '%';
        if ($percentage_formatted != 'yes') {
          $fig['value'] /= 100;
        }
      }
    }

    $json_ld = NULL;
    if ($this->getSetting('output_json_ld') == 'yes') {
      $json_ld = $this->buildJsonLd($data, $first);
      $elements['#jsonld'] = $json_ld;
    }

    foreach ($data as $figure) {
      $figure['value'] = NumberFormatter::format($figure['value'], $langcode, $format, $precision, FALSE);
      $elements['#figures'][] = [
        '#theme' => 'ocha_key_figures_extended_figure__' . $theme_suggestions,
        '#figure' => $figure,
        '#cache' => [
          'max-age' => $this->ochaKeyFiguresApiClient->getMaxAge(),
          'tags' => $this->ochaKeyFiguresApiClient->getCacheTags($figure),
        ],
      ];
    }

    return $elements;
  }

  /**
   * Build json-ld output
   */
  protected function buildJsonLd($data, $item) {
    // Initialize data for JSON-LD.
    $json_data = [];
    foreach ($data as $row) {
      $json_data[$row['name']] = $row['value'];
      $country = $row['country'];
    }

    $name = t('@title of @country', [
      '@title' => 'Key figures',
      '@country' => $country,
    ]);
    $description = t('Easily discoverable topline numbers for humanitarian crises in @country', [
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
