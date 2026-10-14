'use strict';

/* Bushisa Users Management: the controls are ordinary GET filters so
   pressing Enter works without JavaScript. This adds convenient auto-apply. */
(() => {
  const form = document.getElementById('admin-users-filter-form');
  if (form instanceof HTMLFormElement) {
    form.querySelectorAll('select').forEach(select => {
      select.addEventListener('change', () => {
        if (typeof form.requestSubmit === 'function') form.requestSubmit();
        else form.submit();
      });
    });
  }

  const overlay = document.querySelector('.admin-users-detail-overlay');
  const close = overlay?.querySelector('.admin-users-detail__close');
  if (!(overlay instanceof HTMLElement) || !(close instanceof HTMLAnchorElement)) return;

  const dialog = overlay.querySelector('[role="dialog"]');
  if (!(dialog instanceof HTMLElement)) return;

  // Keep keyboard focus in the open account summary until it is closed.
  close.focus({ preventScroll: true });
  const previousOverflow = document.body.style.overflow;
  document.body.style.overflow = 'hidden';

  document.addEventListener('keydown', event => {
    if (event.key === 'Escape') {
      event.preventDefault();
      window.location.assign(close.href);
    } else if (event.key === 'Tab') {
      const links = Array.from(dialog.querySelectorAll('a[href]'));
      if (!links.length) return;
      const first = links[0], last = links[links.length - 1];
      if (event.shiftKey && document.activeElement === first) {
        event.preventDefault();
        last.focus();
      } else if (!event.shiftKey && document.activeElement === last) {
        event.preventDefault();
        first.focus();
      }
    }
  });

  overlay.addEventListener('click', event => {
    if (event.target === overlay) window.location.assign(close.href);
  });

  window.addEventListener('pagehide', () => {
    document.body.style.overflow = previousOverflow;
  }, { once: true });
})();
