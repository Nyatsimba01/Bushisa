'use strict';

// Automatically append eligible server-provided profiles to the existing Figma cards.
// The server decides eligibility, order, and pagination; the browser never invents matches.
(() => {
  const grid = document.querySelector('[data-discovery-grid]');
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
    Array.from(grid.querySelectorAll('[data-candidate-id]'))
      .map((item) => item.getAttribute('data-candidate-id'))
      .filter(Boolean)
  );

  const make = (tag, className, content) => {
    const element = document.createElement(tag);
    if (className) element.className = className;
    if (content !== undefined) element.textContent = String(content);
    return element;
  };

  const makeButton = (candidate, direction, text, label) => {
    const button = make('button', 'discover-card__action ' + (direction === 'like'
      ? 'discover-card__action--like home-like' : 'home-pass'), text);
    button.type = 'button';
    button.dataset.swipeTarget = String(candidate.user_id);
    button.dataset.swipeDirection = direction;
    button.setAttribute('aria-label', label + ' ' + candidate.display_name);
    button.title = label;
    return button;
  };

  const makeCard = (candidate) => {
    const name = String(candidate.display_name || 'Student');
    const age = Number(candidate.age);
    const faculty = String(candidate.faculty || 'NUST');
    const article = make('article', 'profile-card');
    article.dataset.candidateId = String(candidate.user_id);

    const photo = make('div', 'profile-card__image');
    const photoPath = String(candidate.profile_photo_path || '').trim();
    if (photoPath) {
      const img = make('img', 'home-photo');
      img.src = photoPath.startsWith('http') ? photoPath : photoPath.replace(/^\/+/, '');
      img.alt = name + ' profile photo';
      img.loading = 'lazy';
      img.decoding = 'async';
      img.addEventListener('error', () => {
        img.replaceWith(make('span', 'home-photo avatar--fallback', name.charAt(0).toUpperCase()));
      }, { once: true });
      photo.append(img);
    } else {
      photo.append(make('span', 'home-photo avatar--fallback', name.charAt(0).toUpperCase()));
    }

    const caption = make('div', 'home-photo-overlay');
    caption.append(
      make('h3', 'profile-card__name', name + (Number.isFinite(age) && age >= 18 ? ', ' + age : '')),
      make('p', '', faculty + ' · NUST')
    );
    photo.append(caption);

    const body = make('div', 'profile-card__body');
    const prompt = make('div', 'home-conversation-prompt');
    prompt.append(
      make('p', 'home-eyebrow', 'ABOUT ME'),
      make('p', 'profile-card__bio', String(candidate.bio || 'This student has not added a bio yet.'))
    );
    const actions = make('div', 'card-actions home-card-actions');
    actions.append(
      makeButton({...candidate, display_name: name}, 'pass', '×', 'Pass on'),
      makeButton({...candidate, display_name: name}, 'like', '♡', 'Like')
    );
    const report = make('a', 'home-report', 'Safety & reporting');
    report.href = 'community_guidelines.php';
    actions.append(report);
    body.append(prompt, actions);
    article.append(photo, body);
    return article;
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
      if (!Array.isArray(data.profiles) || typeof data.has_more !== 'boolean') {
        throw new Error('Unexpected discovery response');
      }

      const fragment = document.createDocumentFragment();
      for (const item of data.profiles) {
        const id = Number(item.user_id);
        if (!Number.isSafeInteger(id) || id <= cursor || seen.has(String(id))) continue;
        seen.add(String(id));
        fragment.append(makeCard(item));
      }
      grid.append(fragment);
      hasMore = data.has_more;
      if (hasMore) {
        const next = Number(data.next_cursor);
        if (!Number.isSafeInteger(next) || next <= cursor) {
          throw new Error('Invalid next profile cursor');
        }
        cursor = next;
      }
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
