'use strict';

// Shared enhancement for the You and Join screens.
// The text input still accepts pasted/typed emojis when JavaScript is off.
(() => {
  const buttons = document.querySelectorAll('[data-emoji-target][data-emoji]');
  for (const button of buttons) {
    button.addEventListener('click', () => {
      const field = document.getElementById(button.dataset.emojiTarget || '');
      if (!(field instanceof HTMLInputElement) || field.disabled) return;

      const symbol = button.dataset.emoji || '';
      if (!symbol) return;
      const from = field.selectionStart ?? field.value.length;
      const to = field.selectionEnd ?? field.value.length;
      field.focus({ preventScroll: true });
      field.setRangeText(symbol, from, to, 'end');
      field.dispatchEvent(new Event('input', { bubbles: true }));
    });
  }
})();
