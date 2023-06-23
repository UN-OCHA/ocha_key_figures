<?php

namespace Drupal\ocha_key_figures\Plugin\Field\FieldFormatter;

use Drupal\Core\Field\FieldDefinitionInterface;
use Drupal\Core\Field\FieldItemListInterface;
use Drupal\Core\Form\FormStateInterface;
use Drupal\ocha_key_figures\Controller\OchaKeyFiguresController;

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
          unset($filtered_results[$id]);
        }
      }
    }

    // Build figures.
    $data = $this->ochaKeyFiguresApiClient->buildKeyFigures($filtered_results, $sparklines);
    if (empty($data)) {
      return FALSE;
    }

    foreach ($data as &$fig) {
      $this->addPrefixSuffix($fig, $langcode);
    }

    if ($this->getSetting('output_json_ld') == 'yes') {
      $elements['#jsonld'] = $this->buildJsonLd($data, $first);
    }

    foreach ($data as $figure) {
      $figure['value'] = $this->formatNumber($figure['value'], $langcode);
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

}
