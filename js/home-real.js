'use strict';

/* Home-only enhancements; no fake student profiles or simulated activity. */
(() => {
  // A profile may have an outdated photo URL in the development database.
  // Gracefully show the user's initial without changing or guessing the photo.
  const replaceBrokenPhoto = (img) => {
    if (!(img instanceof HTMLImageElement)) return;
    const card = img.closest('.figma-profile-card');
    if (card && img.parentElement?.classList.contains('profile-picture')) {
      const label = card.querySelector('.photo-overlay h3')?.textContent?.trim() || 'Student';
      const first = label.charAt(0).toUpperCase() || 'B';
      const fallback = document.createElement('div');
      fallback.className = 'figma-photo-fallback';
      fallback.setAttribute('role', 'img');
      fallback.setAttribute('aria-label', 'No profile photo available');
      fallback.textContent = first;
      img.replaceWith(fallback);
      return;
    }
    if (img.closest('.chat-circle')) {
      const label = img.closest('.chat-circle')?.querySelector('span:last-child')?.textContent?.trim() || 'B';
      const fallback = document.createElement('span');
      fallback.className = 'avatar avatar--fallback';
      fallback.setAttribute('aria-hidden', 'true');
      fallback.textContent = label.charAt(0).toUpperCase();
      img.replaceWith(fallback);
    }
  };

  // Capture includes images appended by automatic pagination.
  document.addEventListener('error', (event) => {
    if (event.target instanceof HTMLImageElement) replaceBrokenPhoto(event.target);
  }, true);

  for (const img of document.querySelectorAll('.profile-picture > img, .chat-circle img.avatar')) {
    if (img instanceof HTMLImageElement && img.complete && img.naturalWidth === 0) {
      replaceBrokenPhoto(img);
    }
  }
})();
