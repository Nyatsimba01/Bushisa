'use strict';
/* The illustrated campus board describes broad areas only. It never requests
   GPS coordinates or exposes a person's position or profile. */
(() => {
  const board = document.querySelector('[data-fm-map]');
  if (!board) return;
  const title = board.querySelector('[data-zone-title]');
  const description = board.querySelector('[data-zone-description]');
  const buttons = Array.from(board.querySelectorAll('[data-zone-name]'));
  buttons.forEach(button => {
    button.addEventListener('click', () => {
      buttons.forEach(other => other.setAttribute('aria-pressed', String(other === button)));
      title.textContent = button.dataset.zoneName || 'Campus area';
      description.textContent = 'No verified, opted-in profiles are available in this area yet. This is an illustrative campus area, not a live location.';
    });
  });
})();
