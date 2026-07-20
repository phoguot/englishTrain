(function () {
  'use strict';

  document.querySelectorAll('form[data-confirm-publish]').forEach(function (form) {
    form.addEventListener('submit', function (event) {
      if (!window.confirm('Xuất bản report này? Sau khi xuất bản sẽ không thể sửa.')) {
        event.preventDefault();
      }
    });
  });

  const toolbar = document.querySelector('[data-report-toolbar]');
  const editor = document.querySelector('#content');
  if (!toolbar || !editor) return;

  toolbar.querySelectorAll('button[data-open]').forEach(function (button) {
    button.addEventListener('click', function () {
      const start = editor.selectionStart;
      const end = editor.selectionEnd;
      const open = button.dataset.open || '';
      const close = button.dataset.close || '';
      const selected = editor.value.slice(start, end);
      editor.setRangeText(open + selected + close, start, end, 'select');
      editor.focus();
    });
  });
})();
