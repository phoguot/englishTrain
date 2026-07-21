(function () {
  'use strict';

  const reduceMotion = window.matchMedia('(prefers-reduced-motion: reduce)').matches;
  const menuButton = document.querySelector('[data-menu-button]');
  const menu = document.querySelector('[data-menu]');

  function closeMenu() {
    if (!menuButton || !menu) return;
    menu.classList.remove('is-open');
    menuButton.setAttribute('aria-expanded', 'false');
  }

  if (menuButton && menu) {
    menuButton.addEventListener('click', function () {
      const isOpen = menu.classList.toggle('is-open');
      menuButton.setAttribute('aria-expanded', String(isOpen));
    });

    document.addEventListener('keydown', function (event) {
      if (event.key === 'Escape' && menu.classList.contains('is-open')) {
        closeMenu();
        menuButton.focus();
      }
    });

    menu.querySelectorAll('a').forEach(function (link) {
      link.addEventListener('click', closeMenu);
    });
  }

  document.querySelectorAll('form[data-confirm-delete]').forEach(function (form) {
    form.addEventListener('submit', function (event) {
      if (!window.confirm('Bạn có chắc muốn xóa tài khoản này? Thao tác không thể hoàn tác.')) {
        event.preventDefault();
      }
    });
  });

  document.querySelectorAll('form[data-confirm-delete-assignment]').forEach(function (form) {
    form.addEventListener('submit', function (event) {
      if (!window.confirm('Bạn có chắc muốn xóa bài tập này? Toàn bộ bài nộp của học sinh cũng sẽ bị xóa, không thể hoàn tác.')) {
        event.preventDefault();
      }
    });
  });

  document.querySelectorAll('form[data-confirm-delete-video]').forEach(function (form) {
    form.addEventListener('submit', function (event) {
      if (!window.confirm('Bạn có chắc muốn xóa video này khỏi hệ thống lưu trữ? Học sinh sẽ cần nộp lại, không thể hoàn tác.')) {
        event.preventDefault();
      }
    });
  });

  document.querySelectorAll('form[data-confirm-unlink]').forEach(function (form) {
    form.addEventListener('submit', function (event) {
      if (!window.confirm('Ngắt liên kết đăng nhập này?')) {
        event.preventDefault();
      }
    });
  });

  // Trang log lỗi hệ thống: chọn tất cả + xác nhận xóa 1 dòng / xóa nhiều dòng đã chọn.
  document.querySelectorAll('form[data-log-form]').forEach(function (form) {
    const selectAll = form.querySelector('[data-log-select-all]');
    const boxes = form.querySelectorAll('[data-log-checkbox]');

    if (selectAll) {
      selectAll.addEventListener('change', function () {
        boxes.forEach(function (box) {
          box.checked = selectAll.checked;
        });
      });
    }

    form.addEventListener('submit', function (event) {
      const submitter = event.submitter;
      if (submitter && submitter.hasAttribute('data-log-delete-one')) {
        if (!window.confirm('Xóa dòng log này? Thao tác không thể hoàn tác.')) {
          event.preventDefault();
        }
        return;
      }

      // Nút xóa nhiều: cần ít nhất 1 dòng được chọn.
      let checked = 0;
      boxes.forEach(function (box) {
        if (box.checked) checked += 1;
      });
      if (checked === 0) {
        window.alert('Vui lòng chọn ít nhất một dòng log để xóa.');
        event.preventDefault();
        return;
      }
      if (!window.confirm('Xóa ' + checked + ' dòng log đã chọn? Thao tác không thể hoàn tác.')) {
        event.preventDefault();
      }
    });
  });

  document.querySelectorAll('[data-counter]').forEach(function (counter) {
    const target = Number(counter.dataset.counter || 0);
    const pad = Number(counter.dataset.pad || 0);

    function render(value) {
      counter.textContent = String(value).padStart(pad, '0');
    }

    if (reduceMotion || target <= 0) {
      render(target);
      return;
    }

    const duration = 520;
    const startedAt = performance.now();

    function tick(now) {
      const progress = Math.min((now - startedAt) / duration, 1);
      const eased = 1 - Math.pow(1 - progress, 3);
      render(Math.round(target * eased));
      if (progress < 1) window.requestAnimationFrame(tick);
    }

    window.requestAnimationFrame(tick);
  });
})();
