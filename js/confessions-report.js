'use strict';

/* Private, accessible Confessions report dialog. No user IDs or identities are
   gathered from the feed. PHP verifies the confession and its author. */
(() => {
  const modal = document.querySelector('[data-report-modal]');
  if (!(modal instanceof HTMLElement)) return;

  const form = modal.querySelector('[data-report-form]');
  const hiddenId = modal.querySelector('[data-report-id]');
  const targetLabel = modal.querySelector('[data-report-target]');
  const reason = modal.querySelector('#cf-report-reason');
  const closeButtons = Array.from(modal.querySelectorAll('[data-report-close]'));
  if (!(form instanceof HTMLFormElement) || !(hiddenId instanceof HTMLInputElement)) return;

  let opener = null;
  let previousOverflow = null;

  function syncFocus() {
    const element = reason instanceof HTMLSelectElement ? reason : closeButtons[0];
    if (element instanceof HTMLElement) element.focus({ preventScroll: true });
  }

  function openReport(button) {
    const id = button.dataset.reportConfession || '';
    if (!/^\d+$/.test(id)) return;
    hiddenId.value = id;
    if (targetLabel) targetLabel.textContent = 'Reporting confession #' + id;
    opener = button;
    previousOverflow = document.body.style.overflow;
    modal.hidden = false;
    document.body.style.overflow = 'hidden';
    syncFocus();
  }

  function closeReport() {
    modal.hidden = true;
    document.body.style.overflow = previousOverflow === null ? '' : previousOverflow;
    previousOverflow = null;
    if (opener && opener.isConnected) opener.focus({ preventScroll: true });
    opener = null;
  }

  document.addEventListener('click', event => {
    if (!(event.target instanceof Element)) return;
    const trigger = event.target.closest('[data-report-confession]');
    if (trigger instanceof HTMLButtonElement) openReport(trigger);
  });

  closeButtons.forEach(button => button.addEventListener('click', closeReport));
  modal.addEventListener('click', event => {
    if (event.target === modal) closeReport();
  });

  document.addEventListener('keydown', event => {
    if (modal.hidden) return;
    if (event.key === 'Escape') {
      event.preventDefault();
      closeReport();
      return;
    }
    if (event.key !== 'Tab') return;
    const focusable = Array.from(modal.querySelectorAll(
      'button:not([disabled]), select:not([disabled]), textarea:not([disabled]), input:not([type="hidden"]):not([disabled])'
    )).filter(control => control instanceof HTMLElement && control.getClientRects().length > 0);
    if (!focusable.length) return;
    const first = focusable[0];
    const last = focusable[focusable.length - 1];
    if (event.shiftKey && document.activeElement === first) {
      event.preventDefault();
      last.focus();
    } else if (!event.shiftKey && document.activeElement === last) {
      event.preventDefault();
      first.focus();
    }
  });

  // On validation errors the server returns this same dialog already open.
  if (!modal.hidden) {
    if (targetLabel) targetLabel.textContent = 'Reporting confession #' + hiddenId.value;
    previousOverflow = document.body.style.overflow;
    document.body.style.overflow = 'hidden';
    syncFocus();
  }
})();
