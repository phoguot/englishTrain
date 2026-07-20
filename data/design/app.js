(function () {
  'use strict';

  const reduceMotion = window.matchMedia('(prefers-reduced-motion: reduce)').matches;

  const menuButton = document.querySelector('[data-menu-button]');
  const menu = document.querySelector('[data-menu]');

  if (menuButton && menu) {
    menuButton.addEventListener('click', function () {
      const isOpen = menu.classList.toggle('is-open');
      menuButton.setAttribute('aria-expanded', String(isOpen));
    });

    document.addEventListener('keydown', function (event) {
      if (event.key === 'Escape' && menu.classList.contains('is-open')) {
        menu.classList.remove('is-open');
        menuButton.setAttribute('aria-expanded', 'false');
        menuButton.focus();
      }
    });
  }

  const announcement = document.querySelector('[data-announcement]');
  const announcementClose = document.querySelector('[data-announcement-close]');
  let lastScrollY = window.scrollY;

  if (announcement && announcementClose) {
    announcementClose.addEventListener('click', function () {
      announcement.hidden = true;
    });

    window.addEventListener('scroll', function () {
      const currentScrollY = window.scrollY;
      announcement.classList.toggle('is-retracted', currentScrollY > lastScrollY && currentScrollY > 96);
      lastScrollY = currentScrollY;
    }, { passive: true });
  }

  document.querySelectorAll('[data-status-choice]').forEach(function (button) {
    button.addEventListener('click', function () {
      const group = button.closest('[data-status-group]');
      if (!group) return;
      group.querySelectorAll('[data-status-choice]').forEach(function (item) {
        item.setAttribute('aria-pressed', String(item === button));
      });
    });
  });

  const uploadButton = document.querySelector('[data-upload-demo]');
  if (uploadButton) {
    uploadButton.addEventListener('click', function () {
      const bar = document.querySelector('[data-upload-progress]');
      const label = document.querySelector('[data-upload-label]');
      if (!bar || !label) return;

      uploadButton.dataset.state = 'loading';
      uploadButton.textContent = 'Đang tải lên';
      const values = [25, 50, 75, 100];
      let index = 0;
      const timer = window.setInterval(function () {
        const value = values[index];
        bar.dataset.progress = String(value);
        label.textContent = value === 100 ? 'Đã tải video minh họa.' : 'Đang tải lên ' + value + '%';
        index += 1;
        if (index === values.length) {
          window.clearInterval(timer);
          delete uploadButton.dataset.state;
          uploadButton.textContent = 'Tải lại video';
        }
      }, 450);
    });
  }

  document.querySelectorAll('[data-demo-save]').forEach(function (button) {
    button.addEventListener('click', function () {
      showToast(button.dataset.demoSave || 'Đã lưu thay đổi minh họa.');
    });
  });

  document.querySelectorAll('[data-filter]').forEach(function (button) {
    button.addEventListener('click', function () {
      const row = button.closest('.filter-row');
      if (!row) return;
      row.querySelectorAll('[data-filter]').forEach(function (item) {
        item.classList.toggle('is-active', item === button);
        item.setAttribute('aria-pressed', String(item === button));
      });
    });
  });

  document.querySelectorAll('[data-counter]').forEach(function (counter) {
    const target = Number(counter.dataset.counter || 0);
    const suffix = counter.dataset.suffix || '';
    const pad = Number(counter.dataset.pad || 0);
    if (reduceMotion) {
      counter.textContent = String(target).padStart(pad, '0') + suffix;
      return;
    }
    const startedAt = performance.now();

    function tick(now) {
      const progress = Math.min((now - startedAt) / 700, 1);
      const value = Math.round(target * progress);
      counter.textContent = String(value).padStart(pad, '0') + suffix;
      if (progress < 1) window.requestAnimationFrame(tick);
    }

    window.requestAnimationFrame(tick);
  });

  document.querySelectorAll('[data-celebrate]').forEach(function (button) {
    button.addEventListener('click', function () {
      if (reduceMotion) return;
      const box = button.getBoundingClientRect();
      const originX = box.left + box.width / 2;
      const originY = box.top + box.height / 2;

      for (let index = 0; index < 8; index += 1) {
        const star = document.createElement('span');
        const angle = (Math.PI * 2 * index) / 8;
        star.className = 'star-burst';
        star.style.left = originX + 'px';
        star.style.top = originY + 'px';
        star.style.setProperty('--star-x', Math.cos(angle) * 58 + 'px');
        star.style.setProperty('--star-y', Math.sin(angle) * 58 + 'px');
        document.body.appendChild(star);
        window.setTimeout(function () { star.remove(); }, 520);
      }
    });
  });

  function showToast(message) {
    let region = document.querySelector('.toast-region');
    if (!region) {
      region = document.createElement('div');
      region.className = 'toast-region';
      region.setAttribute('aria-live', 'polite');
      document.body.appendChild(region);
    }

    const toast = document.createElement('div');
    toast.className = 'toast';
    const text = document.createElement('span');
    text.textContent = message;
    const close = document.createElement('button');
    close.className = 'btn btn--quiet';
    close.type = 'button';
    close.textContent = 'Đóng';
    close.addEventListener('click', function () { toast.remove(); });
    toast.append(text, close);
    region.appendChild(toast);
    window.setTimeout(function () { toast.remove(); }, 5000);
  }
}());
