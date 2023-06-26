<?php

namespace Drupal\ocha_key_figures\Plugin\Field\FieldFormatter;

use Drupal\Core\Field\FieldItemListInterface;

/**
 * Plugin implementation of the 'key_figure_presence_extended' formatter.
 *
 * @FieldFormatter(
 *   id = "key_figure_presence_extended",
 *   label = @Translation("Key Figure - Extended"),
 *   field_types = {
 *     "key_figure_presence"
 *   }
 * )
 */
class KeyFigurePresenceExtended extends KeyFigureExtended {

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

    /** @var \Drupal\ocha_key_figures\Plugin\Field\FieldType\KeyFigurePresence $first */
    $first = $items->first();

    // Make sure we have at least 1 figure.
    if (!$first) {
      return [];
    }

    $sparklines = FALSE;
    if ($this->getSetting('display_sparklines') == 'all') {
      $sparklines = TRUE;
    }
    elseif ($this->getSetting('display_sparklines') == 'single') {
      $sparklines = TRUE;
    }

    // If not _all, filter items.
    if (!$fetch_all) {
      $figures = $this->getOchaPresenceFigures($first->getFigureProvider(), $first->getFigureOchaPresence(), $first->getFigureYear(), array_keys($selected_figures));
    }
    else {
      $figures = $this->getOchaPresenceFigures($first->getFigureProvider(), $first->getFigureOchaPresence(), $first->getFigureYear());

      $allowed_figure_ids = $this->getFieldSetting('allowed_figure_ids');
      if (!empty($allowed_figure_ids)) {
        $allowed_figure_ids = array_flip(preg_split('/,\s*/', trim(strtolower($allowed_figure_ids))));
        foreach ($figures as $id => $figure) {
          if (!isset($allowed_figure_ids[$figure['figure_id']])) {
            unset($figures[$id]);
          }
        }
      }
    }

    $figures = $this->ochaKeyFiguresApiClient->buildKeyFigures($figures, $sparklines);

    foreach ($figures as &$fig) {
      $this->addPrefixSuffix($fig, $langcode);
    }

    if ($this->getSetting('output_json_ld') == 'yes') {
      $elements['#jsonld'] = $this->buildJsonLd($figures, $first);
    }

    foreach ($figures as $figure) {
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
