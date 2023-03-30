/**
 * @file
 * Nested checkboxes for Key Figures.
 */
(function () {
  'use strict';

  Drupal.behaviors.ochaKeyFigures = {
    attach: function (context) {
      let separator = '|-|';
      let figures = context.querySelector('[data-drupal-selector="edit-field-figures"]');
      if (!figures) {
        return;
      }

      let activeFigures = context.querySelector('[data-drupal-selector="edit-field-active-sparklines"]');
      if (!activeFigures) {
        return;
      }

      for (var figure of figures.querySelectorAll('.form-checkbox')) {
        let activeFigure = activeFigures.querySelector('.form-checkbox[value="' + figure.value + '"]');
        let activeLabel = activeFigures.querySelector('[for="' + activeFigure.id + '"]');

        activeLabel.innerText = 'Show sparkline?';
        figure.closest('.form-checkboxes--child').nextElementSibling.appendChild(activeFigure);
        figure.closest('.form-checkboxes--child').nextElementSibling.appendChild(activeLabel);

        // Add dataId for sorting.
        activeFigure.closest('.form-checkboxes--checkbox').setAttribute('data-id', activeFigure.value);
      }

      // Drop the non-JS sparkline field from DOM.
      activeFigures.closest('.field--name-field-active-sparklines').remove();
      context.querySelector('[data-drupal-selector="edit-field-sorted-sparklines-wrapper"]').style.display = 'none';

      // Update instructions on the sortable figures field.
      if (figures.querySelector('.description')) {
        figures.querySelector('.description').innerText += ' Drag and drop the figures to change their display order.';
      }

      // Initialize drag and drop sorting.
      var el = document.querySelector('#field-figures-wrapper .form-checkboxes');
      var sortable = Sortable.create(el, {
        store: {
          /**
           * Get the order of elements. Called once during initialization.
           * @param   {Sortable}  sortable
           * @returns {Array}
           */
          get: function (sortable) {
            var storage = context.querySelector('[data-drupal-selector="edit-field-sorted-sparklines-0-value"]');
            var order = storage.value;
            return order ? order.split(separator) : [];
          },

          /**
           * Save the order of elements. Called onEnd (when the item is dropped).
           * @param {Sortable}  sortable
           */
          set: function (sortable) {
            var order = sortable.toArray();
            var storage = context.querySelector('[data-drupal-selector="edit-field-sorted-sparklines-0-value"]');
            storage.value = order.join(separator);
          }
        }
      });
    }
  };

})();
