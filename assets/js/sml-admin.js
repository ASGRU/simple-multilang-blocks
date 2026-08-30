(function () {
  'use strict';

  function setNames(row, index) {
    row.querySelectorAll('[data-field]').forEach(function (field) {
      var key = field.getAttribute('data-field');
      if (key === 'default') {
        field.name = 'sml_default_language';
        field.value = index;
        return;
      }
      field.name = 'sml_languages[' + index + '][' + key + ']';
    });
  }

  function nextIndex() {
    var rows = document.querySelectorAll('#sml-language-rows tr');
    return rows.length;
  }

  document.addEventListener('click', function (event) {
    var add = event.target.closest('#sml-add-language');
    if (add) {
      var template = document.getElementById('sml-language-row-template');
      var body = document.getElementById('sml-language-rows');
      if (!template || !body) return;
      var fragment = template.content.cloneNode(true);
      var row = fragment.querySelector('tr');
      setNames(row, nextIndex());
      body.appendChild(fragment);
      return;
    }
    var remove = event.target.closest('.sml-remove-language');
    if (remove) {
      var rowToRemove = remove.closest('tr');
      var bodyToUpdate = rowToRemove && rowToRemove.parentNode;
      if (!rowToRemove || !bodyToUpdate || bodyToUpdate.querySelectorAll('tr').length <= 1) return;
      rowToRemove.remove();
      bodyToUpdate.querySelectorAll('tr').forEach(function (row, index) {
        row.querySelectorAll('input[name^="sml_languages"]').forEach(function (field) {
          field.name = field.name.replace(/sml_languages\[\d+\]/, 'sml_languages[' + index + ']');
        });
        var radio = row.querySelector('input[type="radio"][name="sml_default_language"]');
        if (radio) radio.value = index;
      });
    }
  });

  document.addEventListener('change', function (event) {
    var select = event.target.closest('.sml-language-preset');
    if (!select || !select.value) return;
    var row = select.closest('tr');
    try {
      var preset = JSON.parse(select.value);
      ['slug', 'code', 'name', 'flag'].forEach(function (key) {
        var input = row.querySelector('[name$="[' + key + ']"]') || row.querySelector('[data-field="' + key + '"]');
        if (input) input.value = preset[key] || '';
      });
    } catch (ignore) {}
    select.value = '';
  });
}());
