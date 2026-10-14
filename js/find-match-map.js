'use strict';

/*
 * Fictional, presentation-only Find Match avatar-pin prototype.
 * No GPS, geolocation, server reads, real student accounts, saved matches,
 * tracking or map visibility permissions. Never treat these demo pins as real.
 */
(() => {
  const board = document.querySelector('[data-fm-map]');
  if (!board) return;

  const panel = board.closest('.fm-map-panel');
  const heading = panel?.querySelector('.fm-map-heading');
  const title = board.querySelector('[data-zone-title]');
  const description = board.querySelector('[data-zone-description]');
  const status = board.querySelector('.fm-map-status');
  const zones = Array.from(board.querySelectorAll('[data-zone-name]'));
  if (!panel || !heading || !title || !description || !status) return;

  const profiles = [
    {
      id: 'tari', name: 'Tari', area: 'Study spaces', x: 30, y: 39,
      interests: 'Books · Music · Campus life',
      description: 'A good playlist, a new book, and thoughtful conversations.',
      detail: 'Enjoys sharing study playlists and book recommendations.',
      connected: false, bg: '#795064', skin: '#edb18e', shirt: '#e7c8af'
    },
    {
      id: 'amara', name: 'Amara', area: 'Campus commons', x: 79, y: 37,
      interests: 'Gaming · Technology · Films',
      description: 'Curious about games, creative tech, and great stories.',
      detail: 'Always has something interesting to recommend.',
      connected: false, bg: '#5b667f', skin: '#9f674b', shirt: '#f1c6ae'
    },
    {
      id: 'kuda', name: 'Kuda', area: 'Teaching areas', x: 32, y: 76,
      interests: 'Photography · Art · Community',
      description: 'Notices the little details other people walk past.',
      detail: 'Likes photos, sketches, and creative campus projects.',
      connected: false, bg: '#626b56', skin: '#c88c63', shirt: '#dbc2ef'
    },
    {
      id: 'rudo', name: 'Rudo', area: 'Library area', x: 61, y: 69,
      interests: 'Sport · Travel · Food',
      description: 'A weekend adventure sounds like a good story.',
      detail: 'Enjoys sharing match-day highlights and new places to try.',
      connected: false, bg: '#795d4c', skin: '#e2a174', shirt: '#bdd7dc'
    },
    {
      id: 'mika', name: 'Mika', area: 'Campus commons', x: 84, y: 66,
      interests: 'Music · Films · Reading',
      description: 'Collects recommendations for music and movie nights.',
      detail: 'A sample connection, not a real matched account.',
      connected: true, bg: '#72526e', skin: '#b87858', shirt: '#efbfa7'
    }
  ];

  let mode = 'discover';
  let activeZone = null;
  let openedPin = null;

  const tag = heading.querySelector('.fm-map-tag');
  if (tag) tag.textContent = 'Fictional demo';
  const subhead = heading.querySelector('p');
  if (subhead) {
    subhead.textContent = 'Tap illustrated sample avatars to see how discovery cards could work. No real students or locations are shown.';
  }
  board.setAttribute('aria-label', 'Interactive campus concept with fictional illustrated profile pins; no live or precise locations.');

  const toolbar = document.createElement('div');
  toolbar.className = 'fm-demo-toolbar';
  toolbar.innerHTML = [
    '<div class="fm-demo-toolbar-copy">',
    '<span class="fm-demo-kicker">INTERACTIVE DESIGN PREVIEW</span>',
    '<strong>See how discovery could feel.</strong>',
    '<small>Five fictional profiles, placed illustratively — not based on GPS.</small>',
    '</div>',
    '<div class="fm-demo-controls">',
    '<div class="fm-demo-switch" role="group" aria-label="Fictional map display mode">',
    '<button type="button" data-demo-mode="discover" aria-pressed="true">Discover <span>5</span></button>',
    '<button type="button" data-demo-mode="connections" aria-pressed="false">Connections <span>1</span></button>',
    '</div>',
    '<button type="button" class="fm-demo-reset" data-demo-reset hidden>All campus areas ×</button>',
    '</div>'
  ].join('');
  board.parentNode.insertBefore(toolbar, board);
  const reset = toolbar.querySelector('[data-demo-reset]');
  const modeButtons = Array.from(toolbar.querySelectorAll('[data-demo-mode]'));

  const layer = document.createElement('div');
  layer.className = 'fm-demo-pin-layer';
  board.appendChild(layer);

  const card = document.createElement('section');
  card.className = 'fm-demo-profile';
  card.hidden = true;
  card.setAttribute('aria-label', 'Fictional student profile preview');
  card.innerHTML = [
    '<div class="fm-demo-profile-top">',
    '<span class="fm-demo-portrait fm-demo-portrait-large" data-profile-avatar role="img" aria-label="Illustrated fictional avatar"></span>',
    '<div class="fm-demo-person">',
    '<span class="fm-demo-label" data-profile-badge>FICTIONAL PROFILE</span>',
    '<h3 tabindex="-1" data-profile-name></h3>',
    '<span data-profile-area></span>',
    '</div>',
    '<button type="button" class="fm-demo-close" data-profile-close aria-label="Close fictional profile preview">×</button>',
    '</div>',
    '<p class="fm-demo-intro" data-profile-intro></p>',
    '<div class="fm-demo-interests"><span>INTERESTS</span><strong data-profile-interests></strong></div>',
    '<p class="fm-demo-detail" data-profile-detail></p>',
    '<p class="fm-demo-disclaimer">Illustrative profile only · No live location or account actions</p>'
  ].join('');
  board.appendChild(card);

  const cardTitle = card.querySelector('[data-profile-name]');
  const closeButton = card.querySelector('[data-profile-close]');
  const markerButtons = new Map();

  function applyPortrait(element, person) {
    element.style.setProperty('--demo-bg', person.bg);
    element.style.setProperty('--demo-skin', person.skin);
    element.style.setProperty('--demo-shirt', person.shirt);
    element.setAttribute('aria-label', 'Illustrated avatar for fictional ' + person.name);
  }

  function updateStatus() {
    status.hidden = false;
    const items = profiles.filter(person => (mode === 'discover' || person.connected) &&
      (!activeZone || person.area === activeZone));
    if (items.length === 0) {
      title.textContent = 'No sample pins in this view.';
      description.textContent = 'Try another campus area or switch map views. This is only a fictional demonstration.';
    } else if (activeZone) {
      title.textContent = activeZone + ' · ' + items.length + ' sample ' + (items.length === 1 ? 'avatar' : 'avatars');
      description.textContent = 'Tap a pin for a fictional profile preview. These positions do not represent anyone’s whereabouts.';
    } else {
      title.textContent = mode === 'discover'
        ? 'A little preview of what’s possible.'
        : 'A sample connection, just for the design.';
      description.textContent = mode === 'discover'
        ? 'Choose a fictional avatar pin to open a mini profile card. Actual people are not displayed.'
        : 'This connection is fictional. Your actual matches are not placed on this map.';
    }
  }

  function closeCard(restoreFocus = false) {
    card.hidden = true;
    if (openedPin) {
      openedPin.setAttribute('aria-pressed', 'false');
      if (restoreFocus && openedPin.isConnected) openedPin.focus();
    }
    openedPin = null;
    updateStatus();
  }

  function showProfile(person, pin) {
    if (openedPin && openedPin !== pin) openedPin.setAttribute('aria-pressed', 'false');
    openedPin = pin;
    pin.setAttribute('aria-pressed', 'true');
    applyPortrait(card.querySelector('[data-profile-avatar]'), person);
    card.querySelector('[data-profile-badge]').textContent =
      person.connected ? 'FICTIONAL SAMPLE CONNECTION' : 'FICTIONAL DISCOVERY PROFILE';
    cardTitle.textContent = person.name;
    card.querySelector('[data-profile-area]').textContent = person.area + ' · broad area only';
    card.querySelector('[data-profile-intro]').textContent = person.description;
    card.querySelector('[data-profile-interests]').textContent = person.interests;
    card.querySelector('[data-profile-detail]').textContent = person.detail;
    card.hidden = false;
    status.hidden = true;
  }

  function makePin(person) {
    const pin = document.createElement('button');
    pin.type = 'button';
    pin.className = 'fm-demo-pin';
    pin.style.setProperty('--demo-x', person.x + '%');
    pin.style.setProperty('--demo-y', person.y + '%');
    pin.setAttribute('aria-label', 'Preview ' + person.name + ', fictional profile in ' + person.area);
    pin.setAttribute('aria-pressed', 'false');
    const avatar = document.createElement('span');
    avatar.className = 'fm-demo-portrait';
    avatar.setAttribute('aria-hidden', 'true');
    applyPortrait(avatar, person);
    avatar.setAttribute('aria-hidden', 'true');
    const name = document.createElement('span');
    name.className = 'fm-demo-pin-name';
    name.textContent = person.name;
    pin.append(avatar, name);
    pin.addEventListener('click', () => {
      if (openedPin === pin) closeCard(true);
      else showProfile(person, pin);
    });
    return pin;
  }

  function renderPins() {
    closeCard(false);
    markerButtons.clear();
    layer.replaceChildren();
    const visible = profiles.filter(person =>
      (mode === 'discover' || person.connected) && (!activeZone || person.area === activeZone));
    visible.forEach(person => {
      const pin = makePin(person);
      markerButtons.set(person.id, pin);
      layer.appendChild(pin);
    });
    reset.hidden = !activeZone;
    modeButtons.forEach(button => button.setAttribute('aria-pressed', String(button.dataset.demoMode === mode)));
    zones.forEach(zone => zone.setAttribute('aria-pressed', String(zone.dataset.zoneName === activeZone)));
    updateStatus();
  }

  modeButtons.forEach(button => {
    button.addEventListener('click', () => {
      const nextMode = button.dataset.demoMode;
      if (nextMode !== 'discover' && nextMode !== 'connections') return;
      mode = nextMode;
      activeZone = null;
      renderPins();
    });
  });
  zones.forEach(zone => {
    zone.addEventListener('click', () => {
      activeZone = activeZone === zone.dataset.zoneName ? null : zone.dataset.zoneName;
      renderPins();
    });
  });
  reset.addEventListener('click', () => {
    activeZone = null;
    renderPins();
  });
  closeButton.addEventListener('click', () => closeCard(true));
  document.addEventListener('keydown', event => {
    if (event.key === 'Escape' && !card.hidden) closeCard(true);
  });

  renderPins();
})();