'use strict';

/*
 * Full-screen fictional Find Match profile concept.
 * All profile data comes from the hard-coded sample map pins. This code does
 * not fetch user accounts, connect to PHP, send likes, or reveal locations.
 */
(() => {
  if (!document.querySelector('[data-fm-map]')) return;

  const extras = {
    tari: {
      vibe: 'Thoughtful conversations',
      about: 'A playlist for every study session, a growing reading list, and a soft spot for shared campus experiences. Tari likes exchanging recommendations and hearing different perspectives.',
      prompt: 'What makes an ordinary campus afternoon memorable?',
      answer: 'Finding a new song, a good book, or an unexpected conversation.',
      tags: ['Books', 'Music', 'Campus life'],
      looking: 'People who appreciate curiosity and genuine conversation.'
    },
    amara: {
      vibe: 'Creative and curious',
      about: 'Games, interesting technology and imaginative stories keep Amara curious. A weekend is best spent discovering a new game idea or talking about films.',
      prompt: 'What could you talk about for hours?',
      answer: 'The game mechanics and plot twists that make stories unforgettable.',
      tags: ['Gaming', 'Technology', 'Films'],
      looking: 'Someone who enjoys trying new things and sharing great stories.'
    },
    kuda: {
      vibe: 'Creative eye for details',
      about: 'Kuda notices shapes, colours and small details around campus. Photography and creative projects are favourite ways to connect with the community.',
      prompt: 'What inspires your creativity?',
      answer: 'Unexpected details that tell a story without words.',
      tags: ['Photography', 'Art', 'Community'],
      looking: 'People who like making things and encouraging others.'
    },
    rudo: {
      vibe: 'Always exploring',
      about: 'Sports, new food spots and weekend adventures bring out Rudo’s energetic side. Every shared experience is an opportunity for a good story.',
      prompt: 'Your ideal break between busy weeks?',
      answer: 'A match, a new place to explore, or a meal worth recommending.',
      tags: ['Sport', 'Travel', 'Food'],
      looking: 'People with a sense of adventure and a love of new experiences.'
    },
    mika: {
      vibe: 'Music and movie enthusiast',
      about: 'Mika likes collecting recommendations for playlists and films, reading something engaging, and finding reasons to make a regular week more interesting.',
      prompt: 'What is your favourite way to unwind?',
      answer: 'Sharing new music and discovering a film worth discussing.',
      tags: ['Music', 'Films', 'Reading'],
      looking: 'Thoughtful people who enjoy stories and sharing recommendations.'
    }
  };

  const overlay = document.createElement('div');
  overlay.className = 'fm-full-overlay';
  overlay.hidden = true;
  overlay.innerHTML = [
    '<div class="fm-full-shade" aria-hidden="true"></div>',
    '<section class="fm-full-panel" role="dialog" aria-modal="true" aria-labelledby="fm-full-name" aria-describedby="fm-full-disclaimer">',
    '<header class="fm-full-top">',
    '<span class="fm-full-brand">♡ Bushisa <small>Fictional profile design</small></span>',
    '<button type="button" class="fm-full-return" data-full-close>← Back to map</button>',
    '</header>',
    '<div class="fm-full-hero">',
    '<div class="fm-full-art" aria-hidden="true">',
    '<div class="fm-full-orbit"></div><span class="fm-demo-portrait fm-full-avatar" data-full-avatar></span>',
    '<span class="fm-full-illustration-caption">Illustrated sample avatar</span>',
    '</div>',
    '<div class="fm-full-overview">',
    '<span class="fm-full-eyebrow">JUST A LITTLE MORE ABOUT...</span>',
    '<h2 id="fm-full-name" tabindex="-1" data-full-name></h2>',
    '<p class="fm-full-area" data-full-area></p>',
    '<p class="fm-full-intro" data-full-intro></p>',
    '<div class="fm-full-vibe" data-full-vibe></div>',
    '</div>',
    '</div>',
    '<div class="fm-full-details">',
    '<section class="fm-full-detail-card"><span class="fm-full-section-tag">01 · THE INTRODUCTION</span>',
    '<h3>A little about me</h3><p data-full-about></p></section>',
    '<section class="fm-full-detail-card"><span class="fm-full-section-tag">02 · WHAT WE ENJOY</span>',
    '<h3>Interests</h3><div class="fm-full-chips" data-full-tags></div>',
    '<p class="fm-full-small">These are fictional interests, not a real compatibility calculation.</p></section>',
    '<section class="fm-full-detail-card fm-full-detail-card--peach"><span class="fm-full-section-tag">03 · A CONVERSATION STARTER</span>',
    '<h3 data-full-prompt></h3><p data-full-answer></p></section>',
    '<section class="fm-full-detail-card"><span class="fm-full-section-tag">04 · CONNECTION STYLE</span>',
    '<h3>Hoping to meet</h3><p data-full-looking></p></section>',
    '</div>',
    '<footer class="fm-full-footer">',
    '<p id="fm-full-disclaimer"><strong>Concept preview only.</strong> All names, portraits and answers here are fictional. No real students, live locations, compatibility scores or connection requests are involved.</p>',
    '<div class="fm-full-footer-actions">',
    '<button type="button" class="fm-full-dismiss" data-full-close>Back to map</button>',
    '<button type="button" class="fm-full-disabled" disabled aria-label="Sending connection requests is not enabled in this fictional preview">Send connection request · Coming later</button>',
    '</div>',
    '</footer>',
    '</section>'
  ].join('');
  document.body.appendChild(overlay);

  const panel = overlay.querySelector('.fm-full-panel');
  const title = overlay.querySelector('[data-full-name]');
  const closeButtons = Array.from(overlay.querySelectorAll('[data-full-close]'));
  const shade = overlay.querySelector('.fm-full-shade');
  let returnPin = null;
  let previousOverflow = null;

  const fill = (selector, value) => {
    const target = overlay.querySelector(selector);
    if (target) target.textContent = value;
  };

  function closeFull() {
    if (overlay.hidden) return;
    overlay.hidden = true;
    document.body.style.overflow = previousOverflow === null ? '' : previousOverflow;
    previousOverflow = null;
    if (returnPin && returnPin.isConnected) returnPin.focus({ preventScroll: true });
    returnPin = null;
  }

  function openFull(person, pin) {
    if (!person || !extras[person.id]) return;
    const more = extras[person.id];
    returnPin = pin instanceof HTMLElement ? pin : null;
    fill('[data-full-name]', person.name);
    fill('[data-full-area]', person.area + ' · broad illustrative campus area');
    fill('[data-full-intro]', person.description);
    fill('[data-full-vibe]', more.vibe);
    fill('[data-full-about]', more.about);
    fill('[data-full-prompt]', more.prompt);
    fill('[data-full-answer]', more.answer);
    fill('[data-full-looking]', more.looking);
    const portrait = overlay.querySelector('[data-full-avatar]');
    portrait.style.setProperty('--demo-bg', person.bg);
    portrait.style.setProperty('--demo-skin', person.skin);
    portrait.style.setProperty('--demo-shirt', person.shirt);
    const chips = overlay.querySelector('[data-full-tags]');
    chips.replaceChildren();
    for (const tag of more.tags) {
      const chip = document.createElement('span');
      chip.textContent = tag;
      chips.appendChild(chip);
    }
    if (previousOverflow === null) previousOverflow = document.body.style.overflow;
    overlay.hidden = false;
    document.body.style.overflow = 'hidden';
    panel.scrollTop = 0;
    title.focus({ preventScroll: true });
  }

  document.addEventListener('bushisa:demo-profile', event => {
    const detail = event.detail || {};
    openFull(detail.person, detail.returnPin);
  });
  closeButtons.forEach(button => button.addEventListener('click', closeFull));
  shade.addEventListener('click', closeFull);

  document.addEventListener('keydown', event => {
    if (overlay.hidden) return;
    if (event.key === 'Escape') {
      event.preventDefault();
      closeFull();
    } else if (event.key === 'Tab') {
      const focusable = closeButtons.filter(button => !button.disabled);
      const first = focusable[0];
      const last = focusable[focusable.length - 1];
      if (event.shiftKey && (document.activeElement === first || document.activeElement === title)) {
        event.preventDefault();
        last.focus();
      } else if (!event.shiftKey && document.activeElement === last) {
        event.preventDefault();
        first.focus();
      }
    }
  });
})();
