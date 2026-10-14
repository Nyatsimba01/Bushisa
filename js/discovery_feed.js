'use strict';

// Automatically append eligible server-provided profiles to the existing Figma cards.
// The server decides eligibility, order, and pagination; the browser never invents matches.
(() => {
  const grid = document.querySelector('[data-discovery-continuation]') || document.querySelector('[data-discovery-grid]');
  const pagination = document.querySelector('[data-discovery-pagination]');
  if (!(grid instanceof HTMLElement) || !(pagination instanceof HTMLElement)) {
    return;
  }

  const sentinel = pagination.querySelector('[data-discovery-sentinel]');
  const loading = pagination.querySelector('[data-discovery-loading]');
  const errorBox = pagination.querySelector('[data-discovery-error]');
  const retryButton = pagination.querySelector('[data-discovery-retry]');
  const endBox = pagination.querySelector('[data-discovery-end]');
  let cursor = Number(pagination.dataset.after || 0);
  let hasMore = pagination.dataset.hasMore === 'true';
  let inFlight = false;
  let pausedAfterError = false;
  let observer = null;
  const seen = new Set(
    Array.from(document.querySelectorAll('[data-candidate-id]'))
      .map((item) => item.getAttribute('data-candidate-id'))
      .filter(Boolean)
  );

  // The first batch and later batches are rendered by php/discovery_cards.php.
  // Parse only markup from our authenticated same-origin API and deduplicate IDs.
  const appendServerCards = (html) => {
    const template = document.createElement('template');
    template.innerHTML = html;
    const fragment = document.createDocumentFragment();

    for (const card of template.content.querySelectorAll('.figma-profile-card[data-candidate-id]')) {
      const id = card.getAttribute('data-candidate-id');
      if (!id || !/^[0-9]+$/.test(id) || seen.has(id)) continue;
      seen.add(id);
      fragment.append(card);
    }

    grid.append(fragment);
  };

  const updateStatus = () => {
    if (loading) loading.hidden = !inFlight;
    if (errorBox) errorBox.hidden = !pausedAfterError;
    if (endBox) endBox.hidden = hasMore;
    if (sentinel instanceof HTMLElement) sentinel.hidden = !hasMore || pausedAfterError;
    if (!hasMore && observer) observer.disconnect();
  };

  const loadNext = async () => {
    if (inFlight || pausedAfterError || !hasMore) return;
    inFlight = true;
    updateStatus();

    try {
      if (!Number.isSafeInteger(cursor) || cursor < 0) {
        throw new Error('Invalid pagination cursor');
      }
      const url = new URL('api/discovery_feed.php', document.baseURI);
      url.searchParams.set('after', String(cursor));

      const response = await fetch(url.toString(), {
        credentials: 'same-origin',
        headers: { 'Accept': 'application/json' },
        cache: 'no-store'
      });
      if (response.status === 401) {
        window.location.assign('login.php');
        return;
      }
      if (!response.ok) throw new Error('Could not load more profiles');
      const data = await response.json();
      if (typeof data.html !== 'string' || typeof data.has_more !== 'boolean') {
        throw new Error('Unexpected discovery response');
      }

      // Validate the cursor before touching the visible feed. A partial
      // append followed by an invalid cursor would otherwise be hard to retry.
      const next = data.has_more ? Number(data.next_cursor) : null;
      if (data.has_more && (!Number.isSafeInteger(next) || next <= cursor)) {
        throw new Error('Invalid next profile cursor');
      }

      appendServerCards(data.html);
      hasMore = data.has_more;
      if (hasMore) cursor = next;
    } catch (error) {
      pausedAfterError = true;
      if (errorBox) errorBox.querySelector('button')?.focus({ preventScroll: true });
    } finally {
      inFlight = false;
      updateStatus();
    }
  };

  if (retryButton instanceof HTMLButtonElement) {
    retryButton.addEventListener('click', () => {
      pausedAfterError = false;
      void loadNext();
    });
  }

  updateStatus();
  if (!hasMore) return;

  if ('IntersectionObserver' in window && sentinel instanceof HTMLElement) {
    observer = new IntersectionObserver((entries) => {
      if (entries.some((entry) => entry.isIntersecting)) void loadNext();
    }, { rootMargin: '650px 0px', threshold: 0 });
    observer.observe(sentinel);
  } else {
    // Graceful fallback for browsers without IntersectionObserver.
    const onScroll = () => {
      if (!hasMore) {
        window.removeEventListener('scroll', onScroll);
        return;
      }
      if (sentinel instanceof HTMLElement && sentinel.getBoundingClientRect().top < window.innerHeight + 650) {
        void loadNext();
      }
    };
    window.addEventListener('scroll', onScroll, { passive: true });
    onScroll();
  }
})();
