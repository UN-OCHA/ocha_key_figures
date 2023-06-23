<?php

namespace Drupal\ocha_key_figures\Plugin\Field\FieldWidget;

use Drupal\Component\Utility\NestedArray;
use Drupal\Core\Field\FieldItemListInterface;
use Drupal\Core\Form\FormStateInterface;

/**
 * Plugin implementation of the 'key_figure_presence_multiple' widget.
 *
 * @FieldWidget(
 *   id = "key_figure_presence_multiple",
 *   label = @Translation("Key Figure Presence - Multiple"),
 *   field_types = {
 *     "key_figure_presence"
 *   },
 *   multiple_values = TRUE,
 * )
 */
class KeyFigurePresenceMultiple extends KeyFigureBaseWidget {

  /**
   * {@inheritdoc}
   */
  protected function handlesMultipleValues() {
    return TRUE;
  }

  /**
   * {@inheritdoc}
   */
  public function formElement(FieldItemListInterface $items, $delta, array $element, array &$form, FormStateInterface $form_state) {
    $field_name = $this->fieldDefinition->getName();
    $field_parents = array_merge($form['#parents'], [$field_name]);
    $element_parents = array_merge($field_parents, ['widget']);
    $wrapper_id = $this->getAjaxWrapperId($field_parents);

    $values = $form_state->getValue($field_parents);

    // Use the initial item values if there are no form input values.
    $trigger = $this->getTrigger($form_state, $element_parents);
    if (!$trigger) {
      foreach ($items as $item) {
        $values['provider'] = $item->getFigureProvider();
        $values['ochapresence'] = $item->getFigureOchaPresence();
        $values['year'] = $item->getFigureYear();
        $values['id'][] = $item->getFigureId();
      }
    }
    else {
      // Unset some values based on the ajax form rebuild triggering.
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
      NestedArray::unsetValue($form_state->getUserInput(), $field_parents);
    }

    // Default values.
    $provider = $values['provider'] ?? NULL;
    $ochapresence = $values['ochapresence'] ?? NULL;
    $year = $values['year'] ?? NULL;
    $figure_ids = $values['id'] ?? [];

    $show_no_data = FALSE;

    // Add the ajax wrapper.
    $element['#prefix'] = '<div id="' . $wrapper_id . '">';
    $element['#suffix'] = '</div>';

    $element['provider'] = $this->getDropdownForProvider($provider, $field_parents, '', $wrapper_id);

    // Extra fields to select the data from a provider.
    if (isset($provider) && !empty($provider)) {
      $label = NULL;
      $value = NULL;
      $unit = NULL;

      $element['ochapresence'] = $this->getDropdownForOchaPresense($provider, $ochapresence, $field_parents, '', $wrapper_id);

      // Get the list of years for the provider and ochapresence.
      if (!empty($ochapresence)) {
        $element['year'] = $this->getDropdownForOchaPresenceYears($provider, $ochapresence, $year, $field_parents, '', $wrapper_id);
      }

      // Get the list of figures for the provider, ochapresence and year.
      if (!empty($ochapresence) && !empty($year)) {
        $figures = $this->getOchaPresenceFigures($provider, $ochapresence, $year);
        if (empty($figures)) {
          $show_no_data = TRUE;
        }
        else {
          if (!empty($figure_ids)) {
            $figure_ids = array_diff_key($figure_ids, $figures);
          }

          $figure_options = $this->getOptionsForFigures($figures, $figure_ids);

          // Add an "all" option.
          $figure_options = [
            '_all' => $this->t('Display all')
          ] + $figure_options;

          $element['id'] = [
            '#type' => 'checkboxes',
            '#multiple' => TRUE,
            '#title' => $this->t('Key Figures'),
            '#options' => $figure_options,
            '#default_value' => $figure_ids,
            '#attributes' => [
              'class' => ['ocha-key-figures__list'],
            ],
            '#description' => $this->t('Drag and drop the figures to change their display order'),
          ];

          $element['sort_order'] = [
            '#type' => 'textarea',
            '#title' => $this->t('Sort order'),
            '#default_value' => implode($this->separator, array_keys($figure_options)),
            '#wrapper_attributes' => [
              'class' => ['hidden'],
            ],
          ];

          $element['sort_order']['#attached']['library'][] = 'ocha_key_figures/admin';
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

  /**
   * {@inheritdoc}
   */
  public function massageFormValues(array $values, array $form, FormStateInterface $form_state) {
    if (!isset($values['provider'])) {
      return [];
    }

    if (!isset($values['ochapresence'])) {
      return [];
    }

    if (!isset($values['year'])) {
      return [];
    }

    if (!isset($values['id'])) {
      return [];
    }

    $sort_order = explode($this->separator, $values['sort_order']);
    $figures = $this->getOchaPresenceFigures($values['provider'], $values['ochapresence'], $values['year']);

    $ids = $values['id'];
    $ids = array_filter($ids);
    $ids = array_intersect($sort_order, $ids);

    $data = [];
    foreach ($ids as $id) {
      if ($id == '_all') {
        $data[] = [
          'provider' => $values['provider'],
          'ochapresence' => $values['ochapresence'],
          'year' => $values['year'],
          'id' => $id,
          'value' => '_all',
          'label' => '_all',
        ];
      }
      elseif (isset($figures[$id])) {
        $data[] = [
          'provider' => $values['provider'],
          'ochapresence' => $values['ochapresence'],
          'year' => $values['year'],
          'id' => $id,
          'value' => $figures[$id]['value'],
          'label' => $figures[$id]['name'],
        ];
      }
    }

    return $data;
  }

}
