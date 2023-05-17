/**
 * @file
 * Nested checkboxes for Key Figures.
 */
(function () {
  'use strict';

  Drupal.behaviors.ochaKeyFigures = {
    attach: function (context) {
      let separator = '|-|';
      let figures = context.querySelector('[data-drupal-selector="edit-field-figures-id"]');
      if (!figures) {
        return;
      }

      for (var figure of figures.querySelectorAll('.form-type--checkbox')) {
        // Add dataId for sorting.
        figure.setAttribute('data-id', figure.querySelector('input').value);
      }

      // Update instructions on the sortable figures field.
      if (figures.querySelector('legend')) {
        figures.querySelector('legend').innerText += ' (Drag and drop the figures to change their display order).';
      }

      // Initialize drag and drop sorting.
      var el = figures.querySelector('.form-checkboxes');
      var sortable = Sortable.create(el, {
        store: {
          /**
           * Get the order of elements. Called once during initialization.
           * @param   {Sortable}  sortable
           * @returns {Array}
           */
          get: function (sortable) {
            var storage = context.querySelector('[data-drupal-selector="edit-field-figures-sort-order"]');
            var order = storage.value;
            return order ? order.split(separator) : [];
          },

          /**
           * Save the order of elements. Called onEnd (when the item is dropped).
           * @param {Sortable}  sortable
           */
          set: function (sortable) {
            var order = sortable.toArray();
            var storage = context.querySelector('[data-drupal-selector="edit-field-figures-sort-order"]');
            storage.value = order.join(separator);
          }
        }
      });
    }
  };

})();
