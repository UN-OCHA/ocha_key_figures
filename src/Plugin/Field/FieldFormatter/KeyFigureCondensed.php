<?php

namespace Drupal\ocha_key_figures\Plugin\Field\FieldFormatter;

use Drupal\Core\Field\FieldDefinitionInterface;
use Drupal\Core\Field\FieldItemListInterface;
use Drupal\Core\Form\FormStateInterface;
use Drupal\ocha_key_figures\Controller\BaseKeyFiguresController;
use Drupal\ocha_key_figures\Helpers\NumberFormatter;
use Symfony\Component\DependencyInjection\ContainerInterface;

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
    $format = $this->getSetting('format');
    $precision = $this->getSetting('precision');
    $theme_suggestions = implode('__', [
      $this->viewMode,
      $items->getEntity()->getEntityTypeId(),
      $items->getEntity()->bundle(),
      $items->getFieldDefinition()->getName(),
    ]);

    $elements = [
      '#theme' => 'ocha_key_figures_figure_list__' . $theme_suggestions,
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
      foreach ($figures as $figure) {
        if (isset($figure['valueType']) && $figure['valueType'] == 'amount') {
          $figure['unit'] = $figure['unit'] ?? '$';
        }
        if (isset($figure['valueType']) && $figure['valueType'] == 'percentage') {
          $figure['unit'] = $figure['unit'] ?? '%';
        }

        $value = NumberFormatter::format($figure['value'], $langcode, $format, $precision, FALSE);
        $elements['#figures'][] = [
          '#theme' => 'ocha_key_figures_figure__' . $theme_suggestions,
          '#label' => $figure['name'],
          '#value' => $value,
          '#unit' => $figure['unit'] ?? '',
          '#country' => $figure['country'],
          '#year' => $figure['year'],
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
          if (empty($value)) {
            $data = $this->ochaKeyFiguresApiClient->query($item->getFigureProvider(), $item->getFigureId());
            $value = $data['value'];
            $unit = $data['unit'] ?? '';
          }
        }

        if (isset($label, $value)) {
          $value = NumberFormatter::format($value, $langcode, $format, $precision, FALSE);
          $elements['#figures'][$delta] = [
            '#theme' => 'ocha_key_figures_figure__' . $this->viewMode,
            '#label' => $label,
            '#value' => $value,
            '#unit' => $unit,
            '#country' => $item->getFigureCountry(),
            '#year' => $item->getFigureYear(),
          ];
        }
      }
    }

    return $elements;
  }

}
