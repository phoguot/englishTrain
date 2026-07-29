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

  function fetchJson(url) {
    const controller = typeof AbortController === 'function' ? new AbortController() : null;
    const timer = controller ? window.setTimeout(function () {
      controller.abort();
    }, 20000) : null;

    return fetch(url, controller ? { signal: controller.signal } : undefined)
      .then(function (response) {
        const contentType = response.headers.get('content-type') || '';
        if (contentType.indexOf('application/json') === -1) {
          return response.text().then(function () {
            throw new Error(response.ok
              ? 'Máy chủ trả về dữ liệu không đúng định dạng.'
              : 'Không lấy được video (mã ' + response.status + ').');
          });
        }

        return response.json().then(function (data) {
          if (!response.ok) throw new Error(data.error || 'Không lấy được video.');
          return data;
        });
      })
      .catch(function (error) {
        if (error && error.name === 'AbortError') {
          throw new Error('Kết nối quá lâu, vui lòng thử lại.');
        }
        throw error;
      })
      .finally(function () {
        if (timer) window.clearTimeout(timer);
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
  let manualBackdrop = null;
  let manualModalOpen = false;

  function resetVideoModal() {
    if (videoModalPlayer) {
      videoModalPlayer.pause();
      videoModalPlayer.removeAttribute('src');
      videoModalPlayer.load();
    }
    if (videoModalStatus) videoModalStatus.textContent = '';
    if (videoModalOpen) {
      videoModalOpen.hidden = true;
      videoModalOpen.removeAttribute('href');
    }
  }

  function hideManualVideoModal() {
    if (!videoModalEl || !manualModalOpen) return;

    resetVideoModal();
    videoModalEl.classList.remove('show');
    videoModalEl.style.display = 'none';
    videoModalEl.setAttribute('aria-hidden', 'true');
    videoModalEl.removeAttribute('aria-modal');
    videoModalEl.removeAttribute('role');
    document.body.classList.remove('modal-open');
    document.body.style.removeProperty('overflow');
    document.body.style.removeProperty('padding-right');

    if (manualBackdrop) {
      manualBackdrop.remove();
      manualBackdrop = null;
    }
    manualModalOpen = false;
  }

  function showManualVideoModal() {
    if (!videoModalEl) return false;

    manualModalOpen = true;
    videoModalEl.style.display = 'block';
    videoModalEl.removeAttribute('aria-hidden');
    videoModalEl.setAttribute('aria-modal', 'true');
    videoModalEl.setAttribute('role', 'dialog');
    videoModalEl.classList.add('show');
    document.body.classList.add('modal-open');
    document.body.style.overflow = 'hidden';

    if (!manualBackdrop) {
      manualBackdrop = document.createElement('div');
      manualBackdrop.className = 'modal-backdrop fade show';
      manualBackdrop.addEventListener('click', hideManualVideoModal);
      document.body.appendChild(manualBackdrop);
    }

    const closeButton = videoModalEl.querySelector('[data-bs-dismiss="modal"]');
    if (closeButton instanceof HTMLElement) closeButton.focus();

    return true;
  }

  function showVideoModal() {
    if (!videoModalEl || !videoModalPlayer) return false;

    if (videoModal) {
      try {
        videoModal.show();
        window.setTimeout(function () {
          const isVisible = videoModalEl.classList.contains('show')
            || videoModalEl.style.display === 'block';
          if (!isVisible) showManualVideoModal();
        }, 350);
        return true;
      } catch (error) {
        return showManualVideoModal();
      }
    }

    return showManualVideoModal();
  }

  if (videoModalEl && videoModalPlayer) {
    // Đóng popup: dừng phát + gỡ src để không tải ngầm và không phát tiếng nền.
    videoModalEl.addEventListener('hidden.bs.modal', function () {
      resetVideoModal();
    });

    videoModalEl.querySelectorAll('[data-bs-dismiss="modal"]').forEach(function (closeButton) {
      closeButton.addEventListener('click', hideManualVideoModal);
    });

    document.addEventListener('keydown', function (event) {
      if (event.key === 'Escape') hideManualVideoModal();
    });

    // Player nhúng không giải mã được (thường gặp trên mobile với .mov/HEVC, .mkv, .webm tùy thiết bị).
    // Đừng để im lặng — hướng người dùng sang trình phát gốc của thiết bị qua link fallback.
    videoModalPlayer.addEventListener('error', function () {
      // Bỏ qua "lỗi" phát sinh khi đóng popup (đã gỡ src): chỉ báo khi thực sự có video đang mở.
      if (!videoModalPlayer.getAttribute('src')) return;
      if (videoModalStatus) {
        videoModalStatus.textContent =
          'Trình duyệt không phát được định dạng video này. Hãy bấm nút mở bằng trình phát của thiết bị trong popup này.';
      }
    });

    videoModalPlayer.addEventListener('loadedmetadata', function () {
      if (videoModalStatus) videoModalStatus.textContent = '';
    });
  }

  document.querySelectorAll('[data-video-view]').forEach(function (panel) {
    const button = panel.querySelector('[data-video-view-btn]');
    const status = panel.querySelector('[data-video-view-status]');
    if (!button) return;

    const originalLabel = button.textContent;

    function setStatus(message, isError) {
      if (!status) return;
      status.textContent = message;
      status.classList.toggle('field__help--error', Boolean(isError));
    }

    function setLoading(isLoading) {
      button.disabled = isLoading;
      if (isLoading) {
        button.setAttribute('data-state', 'loading');
        button.textContent = 'Đang mở...';
        return;
      }

      button.removeAttribute('data-state');
      button.textContent = originalLabel;
    }

    button.addEventListener('click', function () {
      if (!panel.dataset.videoUrlRoute) {
        setStatus('Thiếu đường dẫn xem video, vui lòng tải lại trang.', true);
        return;
      }

      setLoading(true);
      setStatus('Đang lấy đường dẫn video...', false);

      fetchJson(panel.dataset.videoUrlRoute)
        .then(function (data) {
          if (typeof data.url !== 'string' || data.url === '') {
            throw new Error('Không nhận được đường dẫn video.');
          }

          if (!videoModalEl || !videoModalPlayer) {
            window.location.href = data.url;
            return;
          }

          setStatus('Đã lấy đường dẫn. Đang mở popup xem video...', false);
          if (videoModalStatus) videoModalStatus.textContent = '';
          // Luôn chuẩn bị link mở bằng trình phát gốc — trên mobile đây là cách xem đáng tin cậy nhất.
          if (videoModalOpen) {
            videoModalOpen.href = data.url;
            videoModalOpen.hidden = false;
          }
          videoModalPlayer.src = data.url;
          // Gọi load() để iOS Safari chắc chắn nhận src mới (nhất là sau khi src cũ đã bị gỡ khi đóng popup).
          videoModalPlayer.load();
          if (videoModalStatus) videoModalStatus.textContent = 'Đang tải video...';
          if (showVideoModal()) {
            setStatus('Đã mở popup xem video.', false);
            return;
          }

          window.location.href = data.url;
        })
        .catch(function (error) {
          setStatus(error.message || 'Có lỗi xảy ra.', true);
        })
        .finally(function () {
          setLoading(false);
        });
    });
  });
})();
