<?php

namespace Drupal\ocha_key_figures\Plugin\Field\FieldFormatter;

use Drupal\Core\Field\FieldItemListInterface;
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
   * {@inheritdoc}
   */
  public function viewElements(FieldItemListInterface $items, $langcode) {
    $format = $this->getSetting('format');
    $precision = $this->getSetting('precision');
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
        'max-age' => ocha_key_figures_get_max_age(),
      ],
    ];

    $fetch_all = FALSE;
    foreach ($items as $delta => $item) {
      if ($item->getFigureId() == '_all') {
        $fetch_all = TRUE;
        break;
      }
    }

    /** @var \Drupal\ocha_key_figures\Plugin\Field\FieldType\KeyFigure $first */
    $first = $items->first();

    // Get the data.
    $results = $this->ochaKeyFiguresApiClient->getKeyFigures($first->getFigureProvider(), $first->getFigureCountry(), $first->getFigureYear());

    // Build figures.
    $sparklines = FALSE;
    $data = $this->ochaKeyFiguresApiClient->buildKeyFigures($results, $sparklines);
    if (empty($data)) {
      return FALSE;
    }

    if ($format) {
      // Set dollar-sign prefix if data is financial.
      foreach ($data as &$fig) {
        if (isset($fig['valueType']) && $fig['valueType'] == 'amount') {
          $fig['prefix'] = $fig['unit'] ?? '$';
        }
      }
    }

    // Set suffix if needed.
    foreach ($data as &$fig) {
      if (isset($fig['valueType']) && $fig['valueType'] == 'percentage') {
        $fig['suffix'] = $fig['unit'] ?? '%';
      }
    }

    // If not _all, filter items.
    if (!$fetch_all) {

    }

    $json_ld = NULL;
    foreach ($data as $figure) {
      $figure['value'] = NumberFormatter::format($figure['value'], $langcode, $format, $precision, FALSE);
      $elements['#figures'][] = [
        '#theme' => 'ocha_key_figures_extended_figure__' . $theme_suggestions,
        '#figure' => $figure,
        '#jsonld' => $json_ld,
      ];
    }

    return $elements;
  }

}
