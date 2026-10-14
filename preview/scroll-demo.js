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


  const icon = (name) => {
    const img = make('img', '');
    img.src = 'assets/icons/' + name + '.svg';
    img.alt = '';
    img.setAttribute('aria-hidden', 'true');
    return img;
  };

  // Fictional examples only. Every extra card keeps the Figma card sections.
  const examples = [
    { prompt: 'What is your favourite way to spend a free afternoon?', answer: 'Sketching campus buildings.', tags: ['🎨 Art', '☕ Coffee', '📚 Reading'] },
    { prompt: 'What small thing always makes your day better?', answer: 'A good playlist between lectures.', tags: ['🎵 Music', '🌱 Nature', '🎧 Playlists'] },
    { prompt: 'What would your dream student club be?', answer: 'A photography and storytelling club.', tags: ['📷 Photography', '💬 Stories', '🌍 Exploring'] }
  ];

  const demoButton = (action, iconName, label, className = '') => {
    const button = make('button', className);
    button.type = 'button';
    button.dataset.previewAction = action;
    button.setAttribute('aria-label', label + ' — preview only');
    button.append(icon(iconName));
    return button;
  };

  const sample = (number) => {
    const data = examples[(number - 4) % examples.length];
    const article = make('article', 'profile-card scroll-demo-profile');
    article.setAttribute('aria-label', 'Illustrative profile layout ' + number);

    const picture = make('div', 'profile-picture scroll-demo-picture');
    picture.append(make('span', 'scroll-demo-initial', String(number).padStart(2, '0')));

    const photoButton = demoButton('photo', 'camera', 'Like sample photo', 'overlay-action');
    photoButton.append(document.createTextNode(' Like photo'));
    picture.append(photoButton);

    const overlay = make('div', 'photo-overlay');
    overlay.append(
      make('h3', '', 'Demo profile ' + number + ', ' + (20 + (number % 5))),
      make('p', '', 'Illustrative academic details · NUST')
    );
    picture.append(overlay);

    const body = make('div', 'profile-content');
    const prompt = make('div', 'prompt-box');
    prompt.append(
      make('p', 'prompt-label', 'CONVERSATION PROMPT'),
      make('p', 'prompt-value', data.prompt)
    );
    const answer = make('div', 'answer-line');
    const likeAnswer = demoButton('answer', 'nav-heart', 'Like sample answer', 'small-action');
    likeAnswer.append(document.createTextNode(' Like answer'));
    answer.append(likeAnswer, make('span', 'sample-answer', data.answer));
    prompt.append(answer);

    const tags = make('div', 'tags');
    for (const tag of data.tags) tags.append(make('span', 'tag', tag));

    const feedback = make('div', 'photo-feedback');
    const likePhoto = demoButton('photo', 'nav-heart', 'Like sample photo', 'small-action');
    likePhoto.append(document.createTextNode(' Like photo'));
    feedback.append(likePhoto, make('span', '', 'Add a note (optional)'));

    const actions = make('div', 'profile-actions');
    actions.append(
      demoButton('pass', 'pass', 'Pass', 'circle-action'),
      demoButton('like', 'like', 'Like', 'circle-action like'),
      demoButton('save', 'bookmark', 'Bookmark', 'circle-action')
    );

    const confess = demoButton('confess', 'confess', 'Write a sample confession', 'confess-button');
    const desktop = make('span', 'desktop-text', 'Confess about…');
    const mobile = make('span', 'mobile-text', 'Write a confession');
    confess.append(desktop, mobile);

    body.append(prompt, tags, feedback, actions, confess);
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
