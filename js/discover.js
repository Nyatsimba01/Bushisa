'use strict';
// js/discover.js

(function () {
  const NAMESPACE_NAME = 'Bushisa';
  const DISCOVER_ENDPOINT = 'api/discover.php';
  const SWIPE_ENDPOINT = 'api/swipe.php';
  const BATCH_SIZE = 10;
  const PREFETCH_THRESHOLD = 2;
  const SWIPE_DISTANCE = 100;
  const FILTER_DEBOUNCE_MS = 500;
  const MAX_BIO_LENGTH = 120;
  const DEFAULT_FACULTIES = [
    'Applied Sciences',
    'Built Environment',
    'Communication & Information Science',
    'Commerce & Law',
    'Industrial Technology',
    'Medicine',
    'Science & Technology Education'
  ];
  const DEFAULT_GENDERS = ['Male', 'Female'];
  const CARD_SELECTORS = ['[data-discover-cards]', '#discoverCards', '.discover-cards'];
  const EMPTY_SELECTORS = ['[data-discover-empty]', '#discoverEmptyState', '.discover-empty-state'];
  const FILTER_FORM_SELECTORS = ['[data-discover-filters]', '#discoverFilters', '.discover-filters'];
  const FILTER_TOGGLE_SELECTORS = ['[data-discover-filters-toggle]', '#discoverFiltersToggle', '.discover-filters-toggle'];
  const FILTER_PANEL_SELECTORS = ['[data-discover-filters-panel]', '#discoverFiltersPanel', '.discover-filters__panel'];
  const MATCH_OVERLAY_SELECTORS = ['[data-match-overlay]', '#matchOverlay', '.match-overlay'];
  const STATUS_SELECTORS = ['[data-discover-status]', '#discoverStatus', '.discover-status'];

  /**
   * @returns {Record<string, unknown>}
   */
  const getNamespace = () => window[NAMESPACE_NAME] ?? {};

  /**
   * @returns {(message: string, type?: 'success' | 'error' | 'info') => void}
   */
  const getToast = () => {
    const namespace = getNamespace();
    return typeof namespace.showToast === 'function' ? namespace.showToast : () => {};
  };

  /**
   * @returns {(fn: Function, ms: number) => Function}
   */
  const getDebounce = () => {
    const namespace = getNamespace();
    return typeof namespace.debounce === 'function' ? namespace.debounce : createDebounce;
  };

  /**
   * @returns {(endpoint: string, options?: RequestInit) => Promise<unknown>}
   */
  const getApiFetch = () => {
    const namespace = getNamespace();
    return typeof namespace.apiFetch === 'function' ? namespace.apiFetch : null;
  };

  /**
   * @param {string[]} selectors
   * @returns {HTMLElement | null}
   */
  const findFirstElement = (selectors) => {
    for (const selector of selectors) {
      const element = document.querySelector(selector);
      if (element instanceof HTMLElement) {
        return element;
      }
    }

    return null;
  };

  /**
   * @param {string[]} selectors
   * @returns {HTMLFormElement | null}
   */
  const findForm = (selectors) => {
    for (const selector of selectors) {
      const element = document.querySelector(selector);
      if (element instanceof HTMLFormElement) {
        return element;
      }
    }

    return null;
  };

  /**
   * @param {string} tagName
   * @param {string[]} selectors
   * @param {ParentNode} parent
   * @returns {HTMLElement}
   */
  const ensureElement = (tagName, selectors, parent) => {
    const existing = findFirstElement(selectors);
    if (existing) {
      return existing;
    }

    const element = document.createElement(tagName);
    parent.appendChild(element);
    return element;
  };

  /**
   * @returns {HTMLDivElement}
   */
  const ensureCardMount = () => {
    const mount = ensureElement('div', CARD_SELECTORS, document.body ?? document.documentElement);
    mount.setAttribute('data-discover-cards', 'true');
    mount.classList.add('discover-cards');
    return mount;
  };

  /**
   * @returns {HTMLElement}
   */
  const ensureEmptyState = () => {
    const mount = ensureElement('div', EMPTY_SELECTORS, document.body ?? document.documentElement);
    mount.setAttribute('data-discover-empty', 'true');
    mount.classList.add('discover-empty-state');
    mount.hidden = true;
    return mount;
  };

  /**
   * @returns {HTMLElement}
   */
  const ensureMatchOverlay = () => {
    const mount = ensureElement('div', MATCH_OVERLAY_SELECTORS, document.body ?? document.documentElement);
    mount.setAttribute('data-match-overlay', 'true');
    mount.classList.add('match-overlay');
    mount.hidden = true;
    mount.setAttribute('role', 'dialog');
    mount.setAttribute('aria-modal', 'true');
    return mount;
  };

  /**
   * @returns {HTMLElement | null}
   */
  const getStatusElement = () => findFirstElement(STATUS_SELECTORS);

  /**
   * @returns {HTMLFormElement | null}
   */
  const getFilterForm = () => findForm(FILTER_FORM_SELECTORS);

  /**
   * @returns {HTMLElement | null}
   */
  const getFilterToggle = () => findFirstElement(FILTER_TOGGLE_SELECTORS);

  /**
   * @returns {HTMLElement | null}
   */
  const getFilterPanel = () => findFirstElement(FILTER_PANEL_SELECTORS);

  /**
   * @param {unknown} value
   * @returns {string}
   */
  const toText = (value) => (value ?? '').toString();

  /**
   * @param {Function} fn
   * @param {number} ms
   * @returns {Function}
   */
  const createDebounce = (fn, ms) => {
    let timeoutId = null;

    return (...args) => {
      if (timeoutId !== null) {
        window.clearTimeout(timeoutId);
      }

      timeoutId = window.setTimeout(() => {
        timeoutId = null;
        fn(...args);
      }, ms);
    };
  };

  /**
   * @param {string} text
   * @param {number} limit
   * @returns {string}
   */
  const truncateText = (text, limit) => {
    const cleanText = toText(text).trim();
    if (cleanText.length <= limit) {
      return cleanText;
    }

    return `${cleanText.slice(0, limit - 1).trimEnd()}…`;
  };

  /**
   * @param {string} isoDate
   * @returns {number | null}
   */
  const calculateAge = (isoDate) => {
    const birthDate = new Date(isoDate);
    if (Number.isNaN(birthDate.getTime())) {
      return null;
    }

    const today = new Date();
    let age = today.getFullYear() - birthDate.getFullYear();
    const monthDifference = today.getMonth() - birthDate.getMonth();

    if (monthDifference < 0 || (monthDifference === 0 && today.getDate() < birthDate.getDate())) {
      age -= 1;
    }

    return age;
  };

  /**
   * @param {unknown} value
   * @returns {string}
   */
  const normaliseCandidateId = (value) => toText(value).trim();

  /**
   * @param {unknown} candidate
   * @returns {Record<string, unknown>}
   */
  const normaliseCandidate = (candidate) => {
    const rawCandidate = candidate ?? {};
    return {
      id: normaliseCandidateId(rawCandidate.id ?? rawCandidate.user_id ?? rawCandidate.profile_id),
      display_name: toText(rawCandidate.display_name ?? rawCandidate.displayName ?? 'Unknown'),
      faculty: toText(rawCandidate.faculty ?? ''),
      year_of_study: toText(rawCandidate.year_of_study ?? rawCandidate.yearOfStudy ?? ''),
      bio: toText(rawCandidate.bio ?? ''),
      date_of_birth: toText(rawCandidate.date_of_birth ?? rawCandidate.dob ?? rawCandidate.birth_date ?? ''),
      profile_photo_path: toText(rawCandidate.profile_photo_path ?? rawCandidate.photo_url ?? rawCandidate.avatar_url ?? ''),
      gender: toText(rawCandidate.gender ?? ''),
      age: rawCandidate.age ?? null
    };
  };

  /**
   * @param {unknown} responseData
   * @returns {Record<string, unknown>[]}
   */
  const extractCandidates = (responseData) => {
    if (Array.isArray(responseData)) {
      return responseData.map(normaliseCandidate);
    }

    if (responseData && typeof responseData === 'object') {
      const data = /** @type {Record<string, unknown>} */ (responseData);
      const candidates = data.candidates ?? data.results ?? data.items ?? [];
      if (Array.isArray(candidates)) {
        return candidates.map(normaliseCandidate);
      }
    }

    return [];
  };

  /**
   * @param {unknown} responseData
   * @returns {boolean}
   */
  const extractHasMore = (responseData) => {
    if (responseData && typeof responseData === 'object' && !Array.isArray(responseData)) {
      const data = /** @type {Record<string, unknown>} */ (responseData);
      if (typeof data.has_more === 'boolean') {
        return data.has_more;
      }

      if (typeof data.hasMore === 'boolean') {
        return data.hasMore;
      }

      if (typeof data.next_cursor !== 'undefined' || typeof data.nextCursor !== 'undefined') {
        return true;
      }

      if (typeof data.next_page !== 'undefined' || typeof data.nextPage !== 'undefined') {
        return true;
      }
    }

    return false;
  };

  /**
   * @param {unknown} responseData
   * @returns {number | null}
   */
  const extractNextPage = (responseData) => {
    if (responseData && typeof responseData === 'object' && !Array.isArray(responseData)) {
      const data = /** @type {Record<string, unknown>} */ (responseData);
      const nextPage = data.next_page ?? data.nextPage;
      if (typeof nextPage === 'number') {
        return nextPage;
      }
    }

    return null;
  };

  /**
   * @param {unknown} responseData
   * @returns {string | null}
   */
  const extractNextCursor = (responseData) => {
    if (responseData && typeof responseData === 'object' && !Array.isArray(responseData)) {
      const data = /** @type {Record<string, unknown>} */ (responseData);
      const nextCursor = data.next_cursor ?? data.nextCursor;
      if (typeof nextCursor === 'string' && nextCursor.trim().length > 0) {
        return nextCursor;
      }
    }

    return null;
  };

  /**
   * @param {unknown} responseData
   * @returns {string | null}
   */
  const extractMatchId = (responseData) => {
    if (responseData && typeof responseData === 'object' && !Array.isArray(responseData)) {
      const data = /** @type {Record<string, unknown>} */ (responseData);
      const matchId = data.match_id ?? data.matchId ?? data.match?.id;
      if (matchId !== undefined && matchId !== null) {
        return normaliseCandidateId(matchId);
      }
    }

    return null;
  };

  /**
   * @param {unknown} responseData
   * @returns {boolean}
   */
  const extractMatched = (responseData) => {
    if (responseData && typeof responseData === 'object' && !Array.isArray(responseData)) {
      const data = /** @type {Record<string, unknown>} */ (responseData);
      if (typeof data.matched === 'boolean') {
        return data.matched;
      }
    }

    return false;
  };

  /**
   * @returns {{ gender: string[], ageMin: string, ageMax: string, faculties: string[] }}
   */
  const readFilterState = () => {
    const form = getFilterForm();
    if (!form) {
      return { gender: [], ageMin: '', ageMax: '', faculties: [] };
    }

    const gender = Array.from(form.querySelectorAll('input[name="gender"]:checked')).map((input) => {
      if (input instanceof HTMLInputElement) {
        return input.value.trim();
      }

      return '';
    }).filter(Boolean);

    const ageMinField = form.querySelector('input[name="age_min"], input[name="ageMin"]');
    const ageMaxField = form.querySelector('input[name="age_max"], input[name="ageMax"]');
    const facultyField = form.querySelector('select[name="faculty"], select[name="faculties"]');
    const facultyMultiField = form.querySelector('select[multiple][name="faculty"], select[multiple][name="faculties"]');

    const faculties = [];
    const facultySelect = facultyMultiField instanceof HTMLSelectElement ? facultyMultiField : facultyField instanceof HTMLSelectElement ? facultyField : null;
    if (facultySelect) {
      for (const option of Array.from(facultySelect.selectedOptions)) {
        if (option.value.trim().length > 0) {
          faculties.push(option.value.trim());
        }
      }
    }

    return {
      gender,
      ageMin: ageMinField instanceof HTMLInputElement ? ageMinField.value.trim() : '',
      ageMax: ageMaxField instanceof HTMLInputElement ? ageMaxField.value.trim() : '',
      faculties
    };
  };

  /**
   * @param {{ gender: string[], ageMin: string, ageMax: string, faculties: string[] }} filters
   * @param {number} page
   * @param {string[] | null} cursorIds
   * @param {string | null} cursor
   * @returns {URLSearchParams}
   */
  const buildDiscoverParams = (filters, page, cursorIds, cursor) => {
    const params = new URLSearchParams();
    params.set('limit', String(BATCH_SIZE));
    params.set('page', String(page));
    params.set('offset', String((page - 1) * BATCH_SIZE));

    if (cursor) {
      params.set('cursor', cursor);
    }

    for (const gender of filters.gender) {
      params.append('gender[]', gender);
    }

    if (filters.ageMin) {
      params.set('age_min', filters.ageMin);
    }

    if (filters.ageMax) {
      params.set('age_max', filters.ageMax);
    }

    for (const faculty of filters.faculties) {
      params.append('faculty[]', faculty);
    }

    if (cursorIds && cursorIds.length > 0) {
      for (const candidateId of cursorIds) {
        params.append('exclude_ids[]', candidateId);
      }
    }

    return params;
  };

  /**
   * @param {Record<string, unknown>} candidate
   * @returns {number | null}
   */
  const resolveCandidateAge = (candidate) => {
    if (typeof candidate.age === 'number') {
      return candidate.age;
    }

    return calculateAge(toText(candidate.date_of_birth));
  };

  /**
   * @param {Record<string, unknown>} candidate
   * @returns {string}
   */
  const resolvePhotoUrl = (candidate) => {
    const path = toText(candidate.profile_photo_path);
    if (!path) {
      return '';
    }

    return path;
  };

  /**
   * @param {Record<string, unknown>} candidate
   * @returns {HTMLElement}
   */
  const createCard = (candidate) => {
    const card = document.createElement('article');
    card.className = 'discover-card';
    card.dataset.candidateId = toText(candidate.id);
    card.setAttribute('tabindex', '0');
    card.setAttribute('aria-label', `${toText(candidate.display_name)}, profile card`);
    card.style.touchAction = 'none';

    const imageWrap = document.createElement('div');
    imageWrap.className = 'discover-card__image-wrap';

    const image = document.createElement('img');
    image.className = 'discover-card__image';
    image.alt = `${toText(candidate.display_name)} profile photo`;
    image.loading = 'lazy';
    image.decoding = 'async';
    image.dataset.src = resolvePhotoUrl(candidate);
    image.src = 'data:image/gif;base64,R0lGODlhAQABAAAAACw=';
    imageWrap.appendChild(image);

    const body = document.createElement('div');
    body.className = 'discover-card__body';

    const heading = document.createElement('h3');
    heading.className = 'discover-card__name';
    heading.textContent = toText(candidate.display_name);

    const meta = document.createElement('p');
    meta.className = 'discover-card__meta';
    const age = resolveCandidateAge(candidate);
    const metaParts = [];
    if (candidate.faculty) {
      metaParts.push(toText(candidate.faculty));
    }
    if (candidate.year_of_study) {
      metaParts.push(`Year ${toText(candidate.year_of_study)}`);
    }
    if (age !== null) {
      metaParts.push(`${age} years old`);
    }
    meta.textContent = metaParts.join(' • ');

    const bio = document.createElement('p');
    bio.className = 'discover-card__bio';
    bio.textContent = truncateText(candidate.bio, MAX_BIO_LENGTH);

    const actions = document.createElement('div');
    actions.className = 'discover-card__actions';

    const passButton = document.createElement('button');
    passButton.type = 'button';
    passButton.className = 'discover-card__action discover-card__action--pass';
    passButton.textContent = '❌ Pass';

    const likeButton = document.createElement('button');
    likeButton.type = 'button';
    likeButton.className = 'discover-card__action discover-card__action--like';
    likeButton.textContent = '💚 Like';

    passButton.addEventListener('click', () => handleActionButtonClick(card, 'pass'));
    likeButton.addEventListener('click', () => handleActionButtonClick(card, 'like'));

    actions.append(passButton, likeButton);
    body.append(heading, meta, bio, actions);
    card.append(imageWrap, body);

    return card;
  };

  /**
   * @param {HTMLElement} card
   * @param {number} index
   * @returns {void}
   */
  const setCardDepth = (card, index) => {
    const scale = index === 0 ? 1 : Math.max(0.92, 1 - index * 0.03);
    const translateY = index === 0 ? 0 : Math.min(24, index * 8);
    card.style.zIndex = String(100 - index);
    card.style.transform = `translateY(${translateY}px) scale(${scale})`;
    card.style.opacity = index > 2 ? '0' : '1';
    card.style.pointerEvents = index === 0 ? 'auto' : 'none';
    card.dataset.depth = String(index);
  };

  /**
   * @returns {HTMLElement | null}
   */
  const getTopCard = () => {
    const mount = ensureCardMount();
    const top = mount.firstElementChild;
    return top instanceof HTMLElement ? top : null;
  };

  /**
   * @param {HTMLElement} card
   * @returns {boolean}
   */
  const isTopCard = (card) => card === getTopCard();

  /**
   * @param {HTMLElement} card
   * @param {number} dx
   * @returns {void}
   */
  const applyDragTransform = (card, dx) => {
    const rotate = Math.max(-14, Math.min(14, dx / 18));
    card.style.transition = 'none';
    card.style.transform = `translateX(${dx}px) rotate(${rotate}deg)`;
  };

  /**
   * @param {HTMLElement} card
   * @returns {void}
   */
  const resetCardPosition = (card) => {
    const depth = Number(card.dataset.depth ?? '0');
    const scale = depth === 0 ? 1 : Math.max(0.92, 1 - depth * 0.03);
    const translateY = depth === 0 ? 0 : Math.min(24, depth * 8);
    card.style.transition = 'transform 180ms ease, opacity 180ms ease';
    card.style.transform = `translateY(${translateY}px) scale(${scale})`;
    card.style.opacity = depth > 2 ? '0' : '1';
  };

  /**
   * @param {HTMLElement} card
   * @param {'like' | 'pass'} direction
   * @returns {void}
   */
  const animateCardAway = (card, direction) => {
    const translateX = direction === 'like' ? '140vw' : '-140vw';
    const rotate = direction === 'like' ? '18deg' : '-18deg';
    card.style.transition = 'transform 240ms ease, opacity 240ms ease';
    card.style.opacity = '0';
    card.style.transform = `translateX(${translateX}) rotate(${rotate})`;
  };

  /**
   * @param {Record<string, unknown>} candidate
   * @param {'like' | 'pass'} direction
   * @returns {Promise<Record<string, unknown> | null>}
   */
  const submitSwipe = async (candidate, direction) => {
    const apiFetch = getApiFetch();
    if (!apiFetch) {
      throw new Error('Discovery services are unavailable.');
    }

    const response = await apiFetch(SWIPE_ENDPOINT, {
      method: 'POST',
      body: {
        swiped_id: candidate.id,
        direction
      }
    });

    if (response && typeof response === 'object') {
      return /** @type {Record<string, unknown>} */ (response);
    }

    return null;
  };

  /**
   * @param {string | null} matchId
   * @param {Record<string, unknown>} candidate
   * @returns {void}
   */
  const showMatchOverlay = (matchId, candidate) => {
    const overlay = ensureMatchOverlay();
    const title = document.createElement('h2');
    title.textContent = "It's a Match! 🎉";

    const message = document.createElement('p');
    message.textContent = `You and ${toText(candidate.display_name)} liked each other.`;

    const actions = document.createElement('div');
    actions.className = 'match-overlay__actions';

    const messageButton = document.createElement('button');
    messageButton.type = 'button';
    messageButton.className = 'match-overlay__button';
    messageButton.textContent = 'Send a message';
    messageButton.dataset.matchId = matchId ?? '';

    const closeButton = document.createElement('button');
    closeButton.type = 'button';
    closeButton.className = 'match-overlay__close';
    closeButton.textContent = 'Not now';

    actions.append(messageButton, closeButton);
    overlay.replaceChildren(title, message, actions);
    overlay.hidden = false;
    overlay.setAttribute('aria-hidden', 'false');

    const focusTarget = messageButton;
    window.requestAnimationFrame(() => {
      focusTarget.focus();
    });

    messageButton.addEventListener('click', handleMatchMessageClick);
    closeButton.addEventListener('click', hideMatchOverlay);
  };

  /**
   * @returns {void}
   */
  const hideMatchOverlay = () => {
    const overlay = ensureMatchOverlay();
    overlay.hidden = true;
    overlay.setAttribute('aria-hidden', 'true');
    overlay.replaceChildren();
  };

  /**
   * @param {HTMLElement} card
   * @returns {{ x: number, y: number } | null}
   */
  const getDragState = (card) => {
    const dragX = card.dataset.dragStartX;
    const dragY = card.dataset.dragStartY;
    if (typeof dragX !== 'string' || typeof dragY !== 'string') {
      return null;
    }

    return {
      x: Number(dragX),
      y: Number(dragY)
    };
  };

  /**
   * @param {HTMLElement} card
   * @param {number} clientX
   * @param {number} clientY
   * @returns {void}
   */
  const setDragState = (card, clientX, clientY) => {
    card.dataset.dragStartX = String(clientX);
    card.dataset.dragStartY = String(clientY);
    card.dataset.isDragging = 'true';
  };

  /**
   * @param {HTMLElement} card
   * @returns {void}
   */
  const clearDragState = (card) => {
    delete card.dataset.dragStartX;
    delete card.dataset.dragStartY;
    delete card.dataset.isDragging;
  };

  /**
   * @param {HTMLElement} card
   * @param {'like' | 'pass'} direction
   * @returns {Promise<void>}
   */
  const handleSwipe = async (card, direction) => {
    const state = getState();
    if (state.isSwiping) {
      return;
    }

    const candidateId = toText(card.dataset.candidateId);
    const candidate = state.queue.find((entry) => toText(entry.id) === candidateId);
    if (!candidate) {
      return;
    }

    state.isSwiping = true;
    animateCardAway(card, direction);

    try {
      const response = await submitSwipe(candidate, direction);
      removeTopCandidate(candidateId);

      if (extractMatched(response)) {
        showMatchOverlay(extractMatchId(response), candidate);
      }
    } catch (error) {
      const toast = getToast();
      toast(error instanceof Error ? error.message : 'Could not save your choice.', 'error');
      resetCardPosition(card);
    } finally {
      state.isSwiping = false;
      refreshDeck();
      maybePrefetch();
    }
  };

  /**
   * @param {HTMLElement} card
   * @param {'like' | 'pass'} direction
   * @returns {void}
   */
  const handleActionButtonClick = (card, direction) => {
    void handleSwipe(card, direction);
  };

  /**
   * @param {HTMLElement} card
   * @param {PointerEvent} event
   * @returns {void}
   */
  const handlePointerDown = (card, event) => {
    if (!isTopCard(card) || getState().isSwiping) {
      return;
    }

    if (event.target instanceof HTMLElement && event.target.closest('button, a, input, select, textarea, label')) {
      return;
    }

    card.setPointerCapture(event.pointerId);
    setDragState(card, event.clientX, event.clientY);
    card.style.transition = 'none';
  };

  /**
   * @param {HTMLElement} card
   * @param {PointerEvent} event
   * @returns {void}
   */
  const handlePointerMove = (card, event) => {
    const dragState = getDragState(card);
    if (!dragState || card.dataset.isDragging !== 'true') {
      return;
    }

    const dx = event.clientX - dragState.x;
    const dy = event.clientY - dragState.y;
    if (Math.abs(dx) < Math.abs(dy) && Math.abs(dx) < 10) {
      return;
    }

    applyDragTransform(card, dx);
  };

  /**
   * @param {HTMLElement} card
   * @param {PointerEvent} event
   * @returns {void}
   */
  const handlePointerUp = (card, event) => {
    const dragState = getDragState(card);
    if (!dragState || card.dataset.isDragging !== 'true') {
      return;
    }

    const dx = event.clientX - dragState.x;
    clearDragState(card);

    if (dx > SWIPE_DISTANCE) {
      void handleSwipe(card, 'like');
      return;
    }

    if (dx < -SWIPE_DISTANCE) {
      void handleSwipe(card, 'pass');
      return;
    }

    resetCardPosition(card);
  };

  /**
   * @param {HTMLElement} card
   * @returns {void}
   */
  const attachCardHandlers = (card) => {
    if (card.dataset.bound === 'true') {
      return;
    }

    const pointerDownHandler = (event) => handlePointerDown(card, event);
    const pointerMoveHandler = (event) => handlePointerMove(card, event);
    const pointerUpHandler = (event) => handlePointerUp(card, event);

    card.addEventListener('pointerdown', pointerDownHandler);
    card.addEventListener('pointermove', pointerMoveHandler);
    card.addEventListener('pointerup', pointerUpHandler);
    card.addEventListener('pointercancel', pointerUpHandler);
    card.addEventListener('keydown', handleCardKeydown);
    card.dataset.bound = 'true';
  };

  /**
   * @param {KeyboardEvent} event
   * @returns {void}
   */
  const handleCardKeydown = (event) => {
    const card = event.currentTarget instanceof HTMLElement ? event.currentTarget : null;
    if (!card || !isTopCard(card) || getState().isSwiping) {
      return;
    }

    if (event.key === 'ArrowLeft') {
      event.preventDefault();
      void handleSwipe(card, 'pass');
      return;
    }

    if (event.key === 'ArrowRight') {
      event.preventDefault();
      void handleSwipe(card, 'like');
    }
  };

  /**
   * @returns {IntersectionObserver | null}
   */
  const createImageObserver = () => {
    if (!('IntersectionObserver' in window)) {
      return null;
    }

    return new IntersectionObserver(handleImageIntersection, {
      rootMargin: '150px 0px',
      threshold: 0.01
    });
  };

  /**
   * @param {IntersectionObserverEntry[]} entries
   * @param {IntersectionObserver} observer
   * @returns {void}
   */
  const handleImageIntersection = (entries, observer) => {
    for (const entry of entries) {
      const image = entry.target;
      if (!(image instanceof HTMLImageElement) || !entry.isIntersecting) {
        continue;
      }

      const src = image.dataset.src ?? '';
      if (src) {
        image.src = src;
      }

      image.addEventListener('load', handleImageLoad);
      image.addEventListener('error', handleImageError);
      observer.unobserve(image);
    }
  };

  /**
   * @param {Event} event
   * @returns {void}
   */
  const handleImageLoad = (event) => {
    const image = event.currentTarget instanceof HTMLImageElement ? event.currentTarget : null;
    if (image) {
      image.classList.add('is-loaded');
    }
  };

  /**
   * @param {Event} event
   * @returns {void}
   */
  const handleImageError = (event) => {
    const image = event.currentTarget instanceof HTMLImageElement ? event.currentTarget : null;
    if (image) {
      image.removeAttribute('src');
      image.alt = 'Profile photo unavailable';
      image.classList.add('is-fallback');
    }
  };

  /**
   * @returns {void}
   */
  const observeImages = () => {
    const state = getState();
    if (!state.imageObserver) {
      return;
    }

    const mount = ensureCardMount();
    mount.querySelectorAll('img[data-src]').forEach((image) => {
      if (image instanceof HTMLImageElement) {
        state.imageObserver.observe(image);
      }
    });
  };

  /**
   * @returns {void}
   */
  const setEmptyStateVisible = () => {
    const emptyState = ensureEmptyState();
    emptyState.hidden = false;
    emptyState.textContent = 'No new people right now — check back later!';
  };

  /**
   * @returns {void}
   */
  const hideEmptyState = () => {
    ensureEmptyState().hidden = true;
  };

  /**
   * @param {string} message
   * @returns {void}
   */
  const setStatusMessage = (message) => {
    const status = getStatusElement();
    if (status) {
      status.textContent = message;
    }
  };

  /**
   * @returns {void}
   */
  const clearStatusMessage = () => setStatusMessage('');

  /**
   * @returns {{ queue: Record<string, unknown>[], page: number, hasMore: boolean, isLoading: boolean, isSwiping: boolean, requestToken: number, nextCursor: string | null, imageObserver: IntersectionObserver | null, seenIds: Set<string> }}
   */
  const getState = () => {
    window[NAMESPACE_NAME] = window[NAMESPACE_NAME] ?? {};
    const namespace = window[NAMESPACE_NAME];

    if (!namespace.__discoverState) {
      namespace.__discoverState = {
        queue: [],
        page: 1,
        hasMore: true,
        isLoading: false,
        isSwiping: false,
        pendingReset: false,
        requestToken: 0,
        nextCursor: null,
        imageObserver: createImageObserver(),
        seenIds: new Set()
      };
    }

    return namespace.__discoverState;
  };

  /**
   * @param {string} candidateId
   * @returns {void}
   */
  const removeTopCandidate = (candidateId) => {
    const state = getState();
    state.queue = state.queue.filter((candidate) => normaliseCandidateId(candidate.id) !== candidateId);

    const mount = ensureCardMount();
    const card = Array.from(mount.children).find((child) => {
      return child instanceof HTMLElement && child.dataset.candidateId === candidateId;
    });

    if (card instanceof HTMLElement) {
      card.remove();
    }
  };

  /**
   * @returns {void}
   */
  const refreshDeck = () => {
    const mount = ensureCardMount();
    const cards = Array.from(mount.children).filter((child) => child instanceof HTMLElement);

    if (cards.length === 0) {
      setEmptyStateVisible();
      return;
    }

    hideEmptyState();

    cards.forEach((card, index) => {
      setCardDepth(card, index);
      if (index === 0) {
        attachCardHandlers(card);
      }
    });

    observeImages();
  };

  /**
   * @returns {void}
   */
  const renderQueue = () => {
    const state = getState();
    const mount = ensureCardMount();
    mount.replaceChildren();

    for (const candidate of state.queue) {
      const card = createCard(candidate);
      mount.appendChild(card);
    }

    refreshDeck();
  };

  /**
   * @param {boolean} reset
   * @returns {Promise<void>}
   */
  const fetchCandidates = async (reset) => {
    const state = getState();
    const apiFetch = getApiFetch();
    if (!apiFetch) {
      throw new Error('Discovery services are unavailable.');
    }

    if (state.isLoading) {
      if (reset) {
        state.pendingReset = true;
      }
      return;
    }

    state.isLoading = true;
    const requestToken = state.requestToken + 1;
    state.requestToken = requestToken;
    setStatusMessage('Loading profiles...');

    const filters = readFilterState();
    if (reset) {
      state.page = 1;
      state.nextCursor = null;
      state.queue = [];
      state.seenIds = new Set();
      renderQueue();
    }

    const cursorIds = Array.from(state.seenIds);
    const params = buildDiscoverParams(filters, state.page, cursorIds, state.nextCursor);

    try {
      const response = await apiFetch(`${DISCOVER_ENDPOINT}?${params.toString()}`);
      if (state.requestToken !== requestToken) {
        return;
      }

      const nextCandidates = extractCandidates(response).filter((candidate) => {
        const candidateId = normaliseCandidateId(candidate.id);
        return candidateId.length > 0 && !state.seenIds.has(candidateId);
      });

      for (const candidate of nextCandidates) {
        const candidateId = normaliseCandidateId(candidate.id);
        state.seenIds.add(candidateId);
        state.queue.push(candidate);
      }

      const nextPage = extractNextPage(response);
      state.nextCursor = extractNextCursor(response);
      state.page = nextPage ?? state.page + 1;
      state.hasMore = extractHasMore(response) || nextCandidates.length === BATCH_SIZE;

      renderQueue();

      if (state.queue.length === 0) {
        setEmptyStateVisible();
      } else {
        clearStatusMessage();
        window.setTimeout(maybePrefetch, 0);
      }
    } catch (error) {
      const toast = getToast();
      toast(error instanceof Error ? error.message : 'Could not load profiles.', 'error');
      setStatusMessage('Could not load profiles right now.');
      if (state.queue.length === 0) {
        setEmptyStateVisible();
      }
    } finally {
      state.isLoading = false;
      if (state.pendingReset) {
        state.pendingReset = false;
        void fetchCandidates(true);
      }
    }
  };

  /**
   * @returns {Promise<void>}
   */
  const prefetchCandidates = async () => {
    const state = getState();
    if (!state.hasMore || state.isLoading) {
      return;
    }

    if (state.queue.length > PREFETCH_THRESHOLD) {
      return;
    }

    await fetchCandidates(false);
  };

  /**
   * @returns {void}
   */
  const maybePrefetch = () => {
    const state = getState();
    if (state.queue.length <= PREFETCH_THRESHOLD && state.hasMore) {
      void prefetchCandidates();
    }
  };

  /**
   * @returns {void}
   */
  const applyFilters = () => {
    const state = getState();
    state.hasMore = true;
    state.page = 1;
    state.nextCursor = null;
    state.queue = [];
    state.seenIds = new Set();
    renderQueue();
    void fetchCandidates(true);
  };

  /**
   * @param {Event} event
   * @returns {void}
   */
  const handleFilterSubmit = (event) => {
    event.preventDefault();
    applyFilters();
  };

  /**
   * @returns {void}
   */
  const handleFilterToggleClick = () => {
    const toggle = getFilterToggle();
    const panel = getFilterPanel();
    if (!toggle || !panel) {
      return;
    }

    const expanded = toggle.getAttribute('aria-expanded') === 'true';
    toggle.setAttribute('aria-expanded', String(!expanded));
    panel.hidden = expanded;
  };

  /**
   * @returns {void}
   */
  const initialiseFilterPanel = () => {
    const form = getFilterForm();
    const toggle = getFilterToggle();
    const panel = getFilterPanel();
    const apiFetch = getApiFetch();
    const debounce = getDebounce();

    if (toggle && panel) {
      toggle.setAttribute('aria-expanded', toggle.getAttribute('aria-expanded') ?? 'false');
      if (!panel.hasAttribute('data-open')) {
        panel.hidden = true;
      }
      toggle.addEventListener('click', handleFilterToggleClick);
    }

    if (!form) {
      return;
    }

    if (!form.querySelector('select[multiple][name="faculty"], select[multiple][name="faculties"]')) {
      const facultySelect = form.querySelector('select[name="faculty"], select[name="faculties"]');
      if (facultySelect instanceof HTMLSelectElement) {
        const existingOptions = Array.from(facultySelect.options).filter((option) => option.value.trim().length > 0);
        if (existingOptions.length === 0) {
          facultySelect.multiple = true;
          facultySelect.innerHTML = '';

          for (const faculty of DEFAULT_FACULTIES) {
            const option = document.createElement('option');
            option.value = faculty;
            option.textContent = faculty;
            facultySelect.appendChild(option);
          }
        }
      }
    }

    const genderFields = Array.from(form.querySelectorAll('input[name="gender"]'));
    if (genderFields.length === 0) {
      const gendersContainer = form.querySelector('[data-gender-filters]');
      if (gendersContainer instanceof HTMLElement) {
        gendersContainer.replaceChildren();
        for (const gender of DEFAULT_GENDERS) {
          const label = document.createElement('label');
          const checkbox = document.createElement('input');
          checkbox.type = 'checkbox';
          checkbox.name = 'gender';
          checkbox.value = gender;
          label.append(checkbox, document.createTextNode(` ${gender}`));
          gendersContainer.appendChild(label);
        }
      }
    }

    const scheduleRefresh = debounce(() => {
      applyFilters();
    }, FILTER_DEBOUNCE_MS);

    form.addEventListener('submit', handleFilterSubmit);
    form.addEventListener('input', scheduleRefresh);
    form.addEventListener('change', scheduleRefresh);

    if (!apiFetch) {
      getToast()('Discovery services are unavailable.', 'error');
    }
  };

  /**
   * @param {MouseEvent} event
   * @returns {void}
   */
  const handleMatchMessageClick = (event) => {
    const button = event.currentTarget instanceof HTMLButtonElement ? event.currentTarget : null;
    if (!button) {
      return;
    }

    const matchId = button.dataset.matchId ?? '';
    if (matchId.length === 0) {
      return;
    }

    hideMatchOverlay();
    window.location.href = `chat.php?match_id=${encodeURIComponent(matchId)}`;
  };

  /**
   * @returns {void}
   */
  const initialiseDiscovery = () => {
    const state = getState();
    state.imageObserver = state.imageObserver ?? createImageObserver();
    initialiseFilterPanel();
    void fetchCandidates(true);
  };

  /**
   * @returns {void}
   */
  const init = () => {
    ensureCardMount();
    ensureEmptyState();
    ensureMatchOverlay();
    getStatusElement();
    initialiseDiscovery();
  };

  window[NAMESPACE_NAME] = window[NAMESPACE_NAME] ?? {};
  window[NAMESPACE_NAME].initDiscover = init;

  document.addEventListener('DOMContentLoaded', init);
})();
