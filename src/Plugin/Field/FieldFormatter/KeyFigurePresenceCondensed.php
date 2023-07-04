<?php

namespace Drupal\ocha_key_figures\Plugin\Field\FieldFormatter;

use Drupal\Core\Field\FieldItemListInterface;

/**
 * Plugin implementation of the 'key_figure' formatter.
 *
 * @FieldFormatter(
 *   id = "key_figure_presence",
 *   label = @Translation("Key Figure - Condensed"),
 *   field_types = {
 *     "key_figure_presence"
 *   }
 * )
 */
class KeyFigurePresenceCondensed extends KeyFigureBase {

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

    $elements = [
      '#theme' => 'ocha_key_figures_figure_list__' . $theme_suggestions,
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

    $figures = $this->ochaKeyFiguresApiClient->buildKeyFigures($figures, FALSE);

    foreach ($figures as &$fig) {
      $this->addPrefixSuffix($fig, $langcode);
    }

    foreach ($figures as $figure) {
      $figure['value'] = $this->formatNumber($figure['value'], $langcode);
      $elements['#figures'][] = [
        '#theme' => 'ocha_key_figures_figure__' . $theme_suggestions,
        '#label' => $figure['name'],
        '#value' => $figure['value'],
        '#unit' => $figure['unit'],
        '#country' => $figure['country'],
        '#figure_id' => $figure['figure_id'] ?? '',
        '#figure' => $figure,
        '#year' => $figure['year'],
        '#value_prefix' => $figure['prefix'] ?? '',
        '#value_suffix' => $figure['suffix'] ?? '',
        '#cache' => [
          'max-age' => $this->ochaKeyFiguresApiClient->getMaxAge(),
          'tags' => $this->ochaKeyFiguresApiClient->getCacheTags($figure),
        ],
      ];
    }

    return $elements;
  }

}
