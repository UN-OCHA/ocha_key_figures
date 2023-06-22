<?php

namespace Drupal\ocha_key_figures\Plugin\Field\FieldWidget;

use Drupal\Component\Utility\NestedArray;
use Drupal\Core\Field\FieldItemListInterface;
use Drupal\Core\Form\FormStateInterface;

/**
 * Plugin implementation of the 'key_figure' widget.
 *
 * @FieldWidget(
 *   id = "key_figure_presence",
 *   label = @Translation("Key Figure - Simple"),
 *   field_types = {
 *     "key_figure_presence"
 *   },
 *   multiple_values = FALSE,
 * )
 */
class KeyFigurePresence extends KeyFigureBaseWidget {

  /**
   * {@inheritdoc}
   */
  protected function handlesMultipleValues() {
    return FALSE;
  }

  /**
   * {@inheritdoc}
   */
  public function formElement(FieldItemListInterface $items, $delta, array $element, array &$form, FormStateInterface $form_state) {
    $field_name = $this->fieldDefinition->getName();
    $field_parents = array_merge($form['#parents'], [$field_name]);
    $element_parents = array_merge($field_parents, ['widget', $delta]);
    $wrapper_id = $this->getAjaxWrapperId($field_parents, $delta);

    // Ensure the field title and description are displayed when the field
    // only accepts one value.
    if ($this->fieldDefinition->getFieldStorageDefinition()->getCardinality() == 1) {
      $element['#type'] = 'fieldset';
    }

    $item = $items[$delta];
    $values = $form_state->getValue(array_merge($field_parents, [$delta]));

    // Use the initial item values if there are no form input values.
    if (empty($values)) {
      $values = [
        'provider' => $item->getFigureProvider(),
        'ochapresence' => $item->getFigureOchaPresence(),
        'year' => $item->getFigureYear(),
        'id' => $item->getFigureId(),
        'label' => $item->getFigureLabel(),
        'value' => $item->getFigureValue(),
        'unit' => $item->getFigureUnit(),
      ];
    }

    // Unset some values based on the ajax form rebuild triggering.
    $trigger = $this->getTrigger($form_state, $element_parents);
    switch ($trigger) {
      case 'provider':
        unset($values['ochapresence'], $values['year'], $values['id'], $values['label'], $values['value'], $values['unit']);
        break;

      case 'ochapresence':
        unset($values['year'], $values['id'], $values['label'], $values['value'], $values['unit']);
        break;

      case 'year':
        unset($values['id'], $values['label'], $values['value'], $values['unit']);
        break;

      case 'id':
        unset($values['label'], $values['value'], $values['unit']);
        break;
    }

    // Clear the user input so that the form uses the default values.
    if (!empty($trigger)) {
      NestedArray::unsetValue($form_state->getUserInput(), array_merge($field_parents, [$delta]));
    }

    // Default values.
    $provider = $values['provider'] ?? NULL;
    $ochapresence = $values['ochapresence'] ?? NULL;
    $year = $values['year'] ?? NULL;
    $figure_id = $values['id'] ?? NULL;
    $label = $values['label'] ?? NULL;
    $value = $values['value'] ?? NULL;
    $unit = $values['unit'] ?? NULL;

    $show_no_data = FALSE;

    // Add the ajax wrapper.
    $element['#prefix'] = '<div id="' . $wrapper_id . '">';
    $element['#suffix'] = '</div>';

    $element['provider'] = $this->getDropdownForProvider($provider, $field_parents, $delta, $wrapper_id);

    // Extra fields to select the data from a provider.
    if (isset($provider) && !empty($provider)) {
      $label = NULL;
      $value = NULL;
      $unit = NULL;

      $element['ochapresence'] = $this->getDropdownForOchaPresense($provider, $ochapresence, $field_parents, $delta, $wrapper_id);

      // Get the list of years for the provider and ochapresence.
      if (!empty($ochapresence)) {
        $element['year'] = $this->getDropdownForOchaPresenceYears($provider, $ochapresence, $year, $field_parents, $delta, $wrapper_id);
      }

      // Get the list of figures for the provider, ochapresence and year.
      if (!empty($ochapresence) && !empty($year)) {
        $figures = $this->getOchaPresenceFigures($provider, $ochapresence, $year);
        if (empty($figures)) {
          $show_no_data = TRUE;
        }
        else {
          $figure_id = isset($figures[$figure_id]) ? $figure_id : NULL;

          $figure_options = array_map(function ($item) {
            return $item['name'];
          }, $figures);
          asort($figure_options);

          $element['id'] = [
            '#type' => 'select',
            '#multiple' => FALSE,
            '#title' => $this->t('Key Figures'),
            '#options' => $figure_options,
            '#default_value' => $figure_id,
            '#ajax' => $this->getAjaxSettings($this->t('Loading figure data...'), $field_parents, $delta, $wrapper_id),
            '#empty_option' => $this->t('- Select -'),
            '#empty_value' => '',
          ];

          // Preserve the label override.
          $label = $values['label'] ?? $figures[$figure_id]['name'] ?? NULL;
          $value = $figures[$figure_id]['value'] ?? NULL;
          $unit = $figures[$figure_id]['unit'] ?? NULL;
        }
      }
    }

    if ($show_no_data === TRUE) {
      $element['no_data'] = [
        '#type' => 'item',
        '#markup' => $this->t('No data available. This figure will not be saved.'),
      ];
    }
    else {
      if (isset($label)) {
        $element['label'] = [
          '#type' => 'textfield',
          '#title' => $this->t('Label'),
          '#default_value' => $label,
        ];
      }
      if (isset($value)) {
        $element['value'] = [
          '#type' => 'textfield',
          '#title' => $this->t('Value'),
          '#default_value' => $value,
          '#disabled' => TRUE,
        ];

        $element['unit'] = [
          '#type' => 'textfield',
          '#title' => $this->t('Unit'),
          '#default_value' => $unit,
          '#disabled' => TRUE,
        ];
      }
    }

    return $element;
  }

}
