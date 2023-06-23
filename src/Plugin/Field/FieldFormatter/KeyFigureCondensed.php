<?php

namespace Drupal\ocha_key_figures\Plugin\Field\FieldFormatter;

use Drupal\Core\Field\FieldItemListInterface;

/**
 * Plugin implementation of the 'key_figure' formatter.
 *
 * @FieldFormatter(
 *   id = "key_figure",
 *   label = @Translation("Key Figure - Condensed"),
 *   field_types = {
 *     "key_figure"
 *   }
 * )
 */
class KeyFigureCondensed extends KeyFigureBase {

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
    foreach ($items as $delta => $item) {
      if ($item->getFigureId() == '_all') {
        $fetch_all = TRUE;
        break;
      }
    }

    if ($fetch_all) {
      /** @var \Drupal\ocha_key_figures\Plugin\Field\FieldType\KeyFigure $first */
      $first = $items->first();
      $figures = $this->getFigures($first->getFigureProvider(), $first->getFigureCountry(), $first->getFigureYear());

      $allowed_figure_ids = $this->getFieldSetting('allowed_figure_ids');
      if (!empty($allowed_figure_ids)) {
        $allowed_figure_ids = array_flip(preg_split('/,\s*/', trim(strtolower($allowed_figure_ids))));
        foreach ($figures as $id => $figure) {
          if (!isset($allowed_figure_ids[$figure['figure_id']])) {
            unset($figures[$id]);
          }
        }
      }

      foreach ($figures as $figure) {
        $this->addPrefixSuffix($figure, $langcode);

        $value = $this->formatNumber($figure['value'], $langcode);
        $elements['#figures'][] = [
          '#theme' => 'ocha_key_figures_figure__' . $theme_suggestions,
          '#label' => $figure['name'],
          '#value' => $value,
          '#unit' => $figure['unit'] ?? '',
          '#country' => $figure['country'],
          '#year' => $figure['year'],
          '#cache' => [
            'max-age' => $this->ochaKeyFiguresApiClient->getMaxAge(),
            'tags' => $this->ochaKeyFiguresApiClient->getCacheTags($figure),
          ],
        ];
      }
    }
    else {
      /** @var \Drupal\ocha_key_figures\Plugin\Field\FieldType\KeyFigure $item */
      foreach ($items as $delta => $item) {
        $label = $item->getFigureLabel();
        $value = $item->getFigureValue();
        $unit = $item->getFigureUnit();

        if ($item->getFigureProvider() != 'manual') {
          $data = $this->ochaKeyFiguresApiClient->getFigure($item->getFigureProvider(), strtolower($item->getFigureId()));

          if (isset($data['value'], $data['value_type'])) {
            $value = $data['value'];
            $unit = $data['unit'] ?? '';

            $this->addPrefixSuffix($data, $langcode);
          }
          else {
            $value = (string) $this->t('N/A');
          }
        }

        if (isset($label, $value)) {
          $value = $this->formatNumber($value, $langcode);
          $elements['#figures'][$delta] = [
            '#theme' => 'ocha_key_figures_figure__' . $this->viewMode,
            '#label' => $label,
            '#value' => $value,
            '#unit' => $unit,
            '#country' => $item->getFigureCountry(),
            '#year' => $item->getFigureYear(),
            '#cache' => [
              'max-age' => $this->ochaKeyFiguresApiClient->getMaxAge(),
              'tags' => $this->ochaKeyFiguresApiClient->getCacheTagsForFigure($item),
            ],
          ];
        }
      }
    }

    return $elements;
  }

}
