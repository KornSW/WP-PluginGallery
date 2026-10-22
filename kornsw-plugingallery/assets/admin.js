(function () {
  'use strict';

  function onReady(callback) {
    if (document.readyState !== 'loading') {
      callback();
    } else {
      document.addEventListener('DOMContentLoaded', callback);
    }
  }

  onReady(function () {
    var modal = document.getElementById('kornsw-pg-modal');
    var closeButton = document.getElementById('kornsw-pg-modal-close');
    if (!modal) {
      return;
    }
    if (closeButton) {
      closeButton.addEventListener('click', function () {
        modal.classList.remove('is-open');
      });
    }
    modal.addEventListener('click', function (event) {
      if (event.target === modal) {
        modal.classList.remove('is-open');
      }
    });
    document.addEventListener('keydown', function (event) {
      if (event.key === 'Escape') {
        modal.classList.remove('is-open');
      }
    });
  });
})();
