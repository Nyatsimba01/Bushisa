'use strict';

/* Figma You/Profile progressive enhancements; form values are saved by PHP. */
(() => {
  const prompt = document.querySelector('#you-prompt');
  const chips = Array.from(document.querySelectorAll('[data-prompt-choice]'));

  function refreshPromptChips() {
    const current = prompt instanceof HTMLTextAreaElement ? prompt.value.trim() : '';
    for (const chip of chips) {
      chip.setAttribute('aria-pressed', chip.dataset.promptChoice === current ? 'true' : 'false');
    }
  }

  for (const chip of chips) {
    chip.setAttribute('aria-pressed', 'false');
    chip.addEventListener('click', () => {
      if (!(prompt instanceof HTMLTextAreaElement) || prompt.disabled) return;
      prompt.value = chip.dataset.promptChoice || '';
      refreshPromptChips();
      prompt.focus();
    });
  }
  if (prompt instanceof HTMLTextAreaElement) {
    prompt.addEventListener('input', refreshPromptChips);
    refreshPromptChips();
  }

  // A fixed Figma navigation dock can obscure the bottom of focused controls.
  // Nudge only the active editor into the clear area; leave ordinary scroll alone.
  const dock = document.querySelector('.bottom-nav');
  const mobileScreen = window.matchMedia('(max-width: 760px)');
  const reducedMotion = window.matchMedia('(prefers-reduced-motion: reduce)');

  document.addEventListener('focusin', (event) => {
    const control = event.target;
    if (!(control instanceof HTMLElement) || !control.closest('.you-main')) return;
    if (!control.matches('input:not([type="hidden"]):not([type="file"]), textarea, select, button')) return;
    if (!(dock instanceof HTMLElement) || !mobileScreen.matches) return;

    requestAnimationFrame(() => {
      if (!control.isConnected || document.activeElement !== control) return;
      if (window.getComputedStyle(dock).display === 'none') return;

      const field = control.getBoundingClientRect();
      const nav = dock.getBoundingClientRect();
      const desiredBottom = nav.top - 14;
      const overlap = field.bottom - desiredBottom;
      if (overlap > 0 && field.top < nav.top) {
        window.scrollBy({
          top: Math.ceil(overlap),
          behavior: reducedMotion.matches ? 'auto' : 'smooth'
        });
      }
    });
  });

  // Graceful fallback when an uploaded image path is missing in the local setup.
  for (const image of document.querySelectorAll('.you-avatar:not(.you-avatar--initial), .you-photo-slot__preview')) {
    if (!(image instanceof HTMLImageElement)) continue;
    const missingPhoto = () => {
      if (image.classList.contains('you-avatar')) {
        const username = document.querySelector('.you-identity h2')?.textContent?.trim() || 'B';
        const fallback = document.createElement('span');
        fallback.className = 'you-avatar you-avatar--initial';
        fallback.setAttribute('aria-hidden', 'true');
        fallback.textContent = username.charAt(0).toUpperCase() || 'B';
        image.replaceWith(fallback);
      } else {
        image.remove();
      }
    };
    image.addEventListener('error', missingPhoto, { once: true });
    if (image.complete && image.naturalWidth === 0) missingPhoto();
  }
})();
