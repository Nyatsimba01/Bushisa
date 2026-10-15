'use strict';

/*
 * Confession Like microinteraction.
 * Progressive enhancement: without JS the original CSRF-protected POST form
 * still submits to confessions.php. With JS it submits to the JSON endpoint and
 * updates only this button, never scrolling or reloading the page.
 */
(() => {
  if (typeof window.fetch !== 'function' || typeof window.FormData !== 'function') return;

  const inFlight = new WeakSet();
  const canAnimate = !window.matchMedia || !window.matchMedia('(prefers-reduced-motion: reduce)').matches;

  document.addEventListener('submit', async event => {
    const form = event.target;
    if (!(form instanceof HTMLFormElement) || !form.matches('[data-confession-like-form]')) return;

    const endpoint = form.dataset.likeEndpoint;
    const button = form.querySelector('[data-confession-like-button]');
    const heart = form.querySelector('[data-like-heart]');
    const label = form.querySelector('[data-like-label]');
    const count = form.querySelector('[data-like-count]');
    const feedback = form.querySelector('[data-like-feedback]');
    const id = form.querySelector('input[name="confession_id"]')?.value || '';

    // If the enhancement hasn't loaded completely, retain native form submit.
    if (!endpoint || !(button instanceof HTMLButtonElement) ||
        !(heart instanceof HTMLElement) || !(label instanceof HTMLElement) ||
        !(count instanceof HTMLElement) || !(feedback instanceof HTMLElement)) return;

    event.preventDefault();
    if (inFlight.has(form)) return;

    inFlight.add(form);
    button.disabled = true;
    button.setAttribute('aria-busy', 'true');
    feedback.classList.remove('is-error');
    feedback.textContent = '';

    try {
      const response = await fetch(endpoint, {
        method: 'POST',
        body: new FormData(form),
        credentials: 'same-origin',
        headers: {
          Accept: 'application/json',
          'X-Requested-With': 'XMLHttpRequest',
        },
        redirect: 'error',
        cache: 'no-store',
      });

      const mime = response.headers.get('content-type') || '';
      if (!mime.includes('application/json')) throw new Error('Unable to update this like. Please try again.');
      const data = await response.json();

      if (!response.ok || data.success !== true ||
          typeof data.liked !== 'boolean' ||
          !Number.isSafeInteger(data.like_count) || data.like_count < 0) {
        throw new Error(typeof data.error === 'string'
          ? data.error : 'Unable to update this like. Please try again.');
      }

      const liked = data.liked;
      const total = data.like_count;
      label.textContent = liked ? 'Liked' : 'Like';
      heart.textContent = liked ? '♥' : '♡';
      count.textContent = String(total);
      button.classList.toggle('is-liked', liked);
      button.setAttribute('aria-pressed', String(liked));
      button.setAttribute('aria-label',
        (liked ? 'Unlike' : 'Like') + ' confession #' + id + ', ' +
        total + (total === 1 ? ' like' : ' likes'));

      // No intrusive "liked successfully" banner. Screen readers still get
      // an announcement, without moving focus or changing scroll position.
      feedback.textContent = (liked ? 'Liked.' : 'Like removed.') +
        ' ' + total + (total === 1 ? ' like.' : ' likes.');

      if (liked && canAnimate) {
        heart.classList.remove('is-popping');
        // Restart the small pop if the same confession is liked again.
        void heart.offsetWidth;
        heart.classList.add('is-popping');
        heart.addEventListener('animationend',
          () => heart.classList.remove('is-popping'), { once: true });
      } else {
        heart.classList.remove('is-popping');
      }
    } catch (error) {
      // Never show a false success or alter the count when the server fails.
      heart.classList.remove('is-popping');
      feedback.textContent = error instanceof Error
        ? error.message : 'Unable to update this like. Please try again.';
      feedback.classList.add('is-error');
    } finally {
      button.disabled = false;
      button.removeAttribute('aria-busy');
      inFlight.delete(form);
    }
  });
})();
