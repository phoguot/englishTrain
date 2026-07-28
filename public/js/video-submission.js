(function () {
  'use strict';

  function formatSize(bytes) {
    return (bytes / (1024 * 1024)).toFixed(1) + ' MB';
  }

  function uploadToR2(url, file, onProgress) {
    return new Promise(function (resolve, reject) {
      const xhr = new XMLHttpRequest();
      xhr.open('PUT', url);
      xhr.setRequestHeader('Content-Type', file.type);
      xhr.upload.addEventListener('progress', function (event) {
        if (event.lengthComputable) {
          onProgress(Math.round((event.loaded / event.total) * 100));
        }
      });
      xhr.addEventListener('load', function () {
        if (xhr.status >= 200 && xhr.status < 300) {
          resolve();
        } else {
          reject(new Error('Tải lên thất bại (mã ' + xhr.status + ').'));
        }
      });
      xhr.addEventListener('error', function () {
        reject(new Error('Mất kết nối khi tải lên.'));
      });
      xhr.send(file);
    });
  }

  function postJson(url, payload) {
    return fetch(url, {
      method: 'POST',
      headers: { 'Content-Type': 'application/json' },
      body: JSON.stringify(payload),
    }).then(function (response) {
      return response.json().then(function (data) {
        if (!response.ok) throw new Error(data.error || 'Có lỗi xảy ra, vui lòng thử lại.');
        return data;
      });
    });
  }

  // ── Nộp video (student) ────────────────────────────────────────────────
  document.querySelectorAll('[data-video-upload]').forEach(function (panel) {
    const input = panel.querySelector('[data-video-input]');
    const submitButton = panel.querySelector('[data-video-submit]');
    const progressWrap = panel.querySelector('[data-video-progress-wrap]');
    const progressBar = panel.querySelector('[data-video-progress]');
    const status = panel.querySelector('[data-video-status]');
    if (!input || !submitButton) return;

    const uploadUrlEndpoint = panel.dataset.uploadUrl;
    const uploadDoneEndpoint = panel.dataset.uploadDone;
    const csrf = panel.dataset.csrf;
    const maxMb = Number(panel.dataset.maxMb || 0);
    const accept = (panel.dataset.accept || '').split(',').filter(Boolean);

    function setStatus(message, isError) {
      if (!status) return;
      status.textContent = message;
      status.classList.toggle('field__help--error', Boolean(isError));
    }

    function setProgress(percent) {
      if (!progressBar) return;
      progressBar.style.width = percent + '%';
      progressBar.setAttribute('aria-valuenow', String(percent));
    }

    submitButton.addEventListener('click', function () {
      const file = input.files && input.files[0];
      if (!file) {
        setStatus('Vui lòng chọn file video.', true);
        return;
      }
      if (accept.length > 0 && accept.indexOf(file.type) === -1) {
        setStatus('Định dạng video không được hỗ trợ.', true);
        return;
      }
      if (maxMb > 0 && file.size > maxMb * 1024 * 1024) {
        setStatus('File quá lớn (tối đa ' + maxMb + ' MB).', true);
        return;
      }

      submitButton.disabled = true;
      input.disabled = true;
      if (progressWrap) progressWrap.hidden = false;
      setProgress(0);
      setStatus('Đang xin phép tải lên...', false);

      postJson(uploadUrlEndpoint, { filename: file.name, size: file.size, mime: file.type, _csrf: csrf })
        .then(function (data) {
          setStatus('Đang tải lên (' + formatSize(file.size) + ')...', false);
          return uploadToR2(data.url, file, setProgress).then(function () {
            return data.key;
          });
        })
        .then(function (key) {
          setStatus('Đang xác nhận...', false);
          return postJson(uploadDoneEndpoint, { key: key, size: file.size, _csrf: csrf });
        })
        .then(function () {
          setProgress(100);
          setStatus('Đã nộp video thành công. Đang tải lại trang...', false);
          window.location.reload();
        })
        .catch(function (error) {
          submitButton.disabled = false;
          input.disabled = false;
          setStatus(error.message || 'Có lỗi xảy ra, vui lòng thử lại.', true);
        });
    });
  });

  // ── Xem video trong popup (teacher + admin) ─────────────────────────────
  const videoModalEl = document.getElementById('videoModal');
  const videoModalPlayer = document.getElementById('videoModalPlayer');
  const videoModalStatus = videoModalEl ? videoModalEl.querySelector('[data-video-modal-status]') : null;
  const videoModalOpen = videoModalEl ? videoModalEl.querySelector('[data-video-modal-open]') : null;
  const videoModal = (videoModalEl && window.bootstrap && window.bootstrap.Modal)
    ? window.bootstrap.Modal.getOrCreateInstance(videoModalEl)
    : null;

  if (videoModalEl && videoModalPlayer) {
    // Đóng popup: dừng phát + gỡ src để không tải ngầm và không phát tiếng nền.
    videoModalEl.addEventListener('hidden.bs.modal', function () {
      videoModalPlayer.pause();
      videoModalPlayer.removeAttribute('src');
      videoModalPlayer.load();
      if (videoModalStatus) videoModalStatus.textContent = '';
      if (videoModalOpen) {
        videoModalOpen.hidden = true;
        videoModalOpen.removeAttribute('href');
      }
    });

    // Player nhúng không giải mã được (thường gặp trên mobile với .mov/HEVC, .mkv, .webm tùy thiết bị).
    // Đừng để im lặng — hướng người dùng sang trình phát gốc của thiết bị qua link fallback.
    videoModalPlayer.addEventListener('error', function () {
      // Bỏ qua "lỗi" phát sinh khi đóng popup (đã gỡ src): chỉ báo khi thực sự có video đang mở.
      if (!videoModalPlayer.getAttribute('src')) return;
      if (videoModalStatus) {
        videoModalStatus.textContent =
          'Trình duyệt không phát được định dạng video này. Hãy mở bằng trình phát của thiết bị bên dưới.';
      }
    });
  }

  document.querySelectorAll('[data-video-view]').forEach(function (panel) {
    const button = panel.querySelector('[data-video-view-btn]');
    const status = panel.querySelector('[data-video-view-status]');
    if (!button) return;

    button.addEventListener('click', function () {
      if (!videoModal || !videoModalPlayer) {
        if (status) status.textContent = 'Trình phát video chưa sẵn sàng, vui lòng tải lại trang.';
        return;
      }

      button.disabled = true;
      if (status) status.textContent = 'Đang lấy đường dẫn video...';

      fetch(panel.dataset.videoUrlRoute)
        .then(function (response) {
          return response.json().then(function (data) {
            if (!response.ok) throw new Error(data.error || 'Không lấy được video.');
            return data;
          });
        })
        .then(function (data) {
          if (status) status.textContent = '';
          if (videoModalStatus) videoModalStatus.textContent = '';
          // Luôn chuẩn bị link mở bằng trình phát gốc — trên mobile đây là cách xem đáng tin cậy nhất.
          if (videoModalOpen) {
            videoModalOpen.href = data.url;
            videoModalOpen.hidden = false;
          }
          videoModalPlayer.src = data.url;
          // Gọi load() để iOS Safari chắc chắn nhận src mới (nhất là sau khi src cũ đã bị gỡ khi đóng popup).
          videoModalPlayer.load();
          videoModal.show();
        })
        .catch(function (error) {
          if (status) status.textContent = error.message || 'Có lỗi xảy ra.';
        })
        .finally(function () {
          button.disabled = false;
        });
    });
  });
})();
