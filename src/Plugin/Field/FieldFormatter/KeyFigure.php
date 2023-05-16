<?php

namespace Drupal\ocha_key_figures\Plugin\Field\FieldFormatter;

use Drupal\Core\Field\FieldItemListInterface;
use Drupal\Core\Field\FormatterBase;
use Drupal\Core\Form\FormStateInterface;
use Drupal\ocha_key_figures\Helpers\NumberFormatter;

/**
 * Plugin implementation of the 'key_figure' formatter.
 *
 * @FieldFormatter(
 *   id = "key_figure",
 *   label = @Translation("Key Figure"),
 *   field_types = {
 *     "key_figure"
 *   }
 * )
 */
class KeyFigure extends FormatterBase {

  /**
   * {@inheritdoc}
   */
  public static function defaultSettings() {
    return [
      'format' => 'decimal',
      'precision' => 1,
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
   *   1, 1.23 million with a precision of 2. Defaults to 1.'),
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

    return $summary;
  }

  /**
   * {@inheritdoc}
   */
  public function viewElements(FieldItemListInterface $items, $langcode) {
    $format = $this->getSetting('format');
    $precision = $this->getSetting('precision');

    $elements = [
      '#theme' => 'ocha_key_figures_figure_list__' . $this->viewMode,
    ];

    /** @var \Drupal\ocha_key_figures\Plugin\Field\FieldType\KeyFigure $item */
    foreach ($items as $delta => $item) {
      $label = $item->getFigureLabel();
      $value = $item->getFigureValue();
      if (isset($label, $value)) {
        $value = NumberFormatter::format($value, $langcode, $format, $precision, FALSE);
        $elements['#figures'][$delta] = [
          '#theme' => 'ocha_key_figures_figure__' . $this->viewMode,
          '#label' => $label,
          '#value' => $value,
          '#unit' => $item->getFigureUnit(),
          '#country' => $item->getFigureCountry(),
          '#year' => $item->getFigureYear(),
        ];
      }
    }

    return $elements;
  }

}
