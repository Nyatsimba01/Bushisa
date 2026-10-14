'use strict';

/*
 * Optional frontend-only demonstration:
 *   /preview/home.html?scrollDemo=1
 * Keeps the default Figma concept screens unchanged. Extends the same card
 * component with fictional, clearly labelled placeholders in 6-card batches.
 */
(() => {
  const url = new URL(window.location.href);
  if (url.searchParams.get('scrollDemo') !== '1') return;

  const grid = document.getElementById('scroll-demo-cards');
  const sentinel = document.getElementById('scroll-demo-sentinel');
  const loading = document.getElementById('scroll-demo-loading');
  const end = document.getElementById('scroll-demo-end');
  const originalEnd = document.querySelector('.caught-up');
  if (!grid || !sentinel || !loading || !end || !originalEnd) return;

  const MAX_DEMOS = 18;
  const BATCH_SIZE = 6;
  let loaded = 0;
  let working = false;
  const make = (tag, className, content) => {
    const node = document.createElement(tag);
    node.className = className;
    if (content !== undefined) node.textContent = content;
    return node;
  };

  const sample = (number) => {
    const article = make('article', 'profile-card scroll-demo-profile');
    article.setAttribute('aria-label', 'Illustrative layout placeholder ' + number);

    const picture = make('div', 'profile-picture scroll-demo-picture');
    picture.append(make('span', 'scroll-demo-initial', String(number).padStart(2, '0')));
    const overlay = make('div', 'photo-overlay');
    overlay.append(
      make('h3', '', 'Demo profile ' + number),
      make('p', '', 'Illustrative only · NUST concept')
    );
    picture.append(overlay);

    const body = make('div', 'profile-content');
    const prompt = make('div', 'prompt-box');
    prompt.append(
      make('p', 'prompt-label', 'CONVERSATION PROMPT'),
      make('p', 'prompt-value', 'This sample card demonstrates how additional profiles would appear while scrolling.')
    );
    const tags = make('div', 'tags');
    tags.append(make('span', 'tag', 'UI demonstration'), make('span', 'tag', 'Not a real account'));
    const actions = make('div', 'profile-actions');

    const button = (kind, icon, label) => {
      const b = make('button', 'circle-action' + (kind === 'like' ? ' like' : ''));
      b.type = 'button';
      b.dataset.previewAction = kind;
      b.setAttribute('aria-label', label + ' demo card ' + number);
      const img = make('img', '');
      img.src = 'assets/icons/' + icon + '.svg';
      img.alt = '';
      img.setAttribute('aria-hidden', 'true');
      b.append(img);
      return b;
    };

    actions.append(
      button('pass', 'pass', 'Pass on'),
      button('like', 'like', 'Like'),
      button('save', 'bookmark', 'Bookmark')
    );
    body.append(prompt, tags, actions);
    article.append(picture, body);
    return article;
  };

  let observer;
  const appendNext = () => {
    if (working || loaded >= MAX_DEMOS) return;
    working = true;
    loading.hidden = false;
    // A short delay visibly demonstrates asynchronous loading. No network call.
    window.setTimeout(() => {
      const fragment = document.createDocumentFragment();
      const nextCount = Math.min(MAX_DEMOS, loaded + BATCH_SIZE);
      while (loaded < nextCount) {
        loaded++;
        fragment.append(sample(loaded + 3));
      }
      grid.append(fragment);
      loading.hidden = true;
      working = false;
      if (loaded >= MAX_DEMOS) {
        observer?.disconnect();
        sentinel.hidden = true;
        end.hidden = false;
        originalEnd.hidden = false;
      }
    }, 250);
  };

  // The Figma screenshot has one "caught up" panel; defer it until the sample
  // continuation ends, rather than repeating that panel in every batch.
  originalEnd.hidden = true;
  end.hidden = true;
  grid.hidden = false;
  sentinel.hidden = false;
  document.body.classList.add('scroll-demo-active');

  if ('IntersectionObserver' in window) {
    observer = new IntersectionObserver((entries) => {
      if (entries.some((entry) => entry.isIntersecting)) appendNext();
    }, { rootMargin: '650px 0px' });
    observer.observe(sentinel);
  } else {
    window.addEventListener('scroll', () => {
      if (sentinel.getBoundingClientRect().top < window.innerHeight + 650) appendNext();
    }, { passive: true });
  }
})();
