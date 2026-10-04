'use strict';
// js/confessions.js

(function () {
  const NAMESPACE_NAME = 'Bushisa';
  const CONFESSIONS_ENDPOINT = 'api/confessions.php';
  const PAGE_SIZE = 15;
  const MAX_CONFESSION_LENGTH = 500;
  const SCROLL_THRESHOLD_PX = 200;
  const ANONYMOUS_NAME = 'Anonymous NUST Student';
  const CONFESSIONS_SELECTORS = ['[data-confessions-feed]', '#confessionsFeed', '.confessions-feed'];
  const FAB_SELECTORS = ['[data-confession-fab]', '#confessionFab', '.confession-fab'];
  const MODAL_SELECTORS = ['[data-confession-modal]', '#confessionModal', '.confession-modal'];
  const COUNTER_SELECTORS = ['[data-confession-counter]', '#confessionCounter', '.confession-counter'];
  const TEXTAREA_SELECTORS = ['[data-confession-textarea]', '#confessionTextarea', 'textarea[name="body"]'];
  const SUBMIT_SELECTORS = ['[data-confession-submit]', '#confessionSubmit', 'button[type="submit"]'];
  const CLOSE_SELECTORS = ['[data-confession-close]', '#confessionClose', '.confession-modal__close'];
  const FLAG_MENU_SELECTORS = ['[data-confession-flag-menu]', '#confessionFlagMenu', '.confession-flag-menu'];

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
   * @returns {(isoDate: string) => string}
   */
  const getRelativeTimeFormatter = () => {
    const namespace = getNamespace();
    return typeof namespace.formatRelativeTime === 'function' ? namespace.formatRelativeTime : (value) => value;
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
   * @returns {HTMLTextAreaElement | null}
   */
  const findTextArea = (selectors) => {
    for (const selector of selectors) {
      const element = document.querySelector(selector);
      if (element instanceof HTMLTextAreaElement) {
        return element;
      }
    }

    return null;
  };

  /**
   * @param {string[]} selectors
   * @returns {HTMLButtonElement | null}
   */
  const findButton = (selectors) => {
    for (const selector of selectors) {
      const element = document.querySelector(selector);
      if (element instanceof HTMLButtonElement) {
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
   * @returns {HTMLElement}
   */
  const ensureFeed = () => {
    const feed = ensureElement('section', CONFESSIONS_SELECTORS, document.body ?? document.documentElement);
    feed.classList.add('confessions-feed');
    feed.setAttribute('data-confessions-feed', 'true');
    return feed;
  };

  /**
   * @returns {HTMLButtonElement}
   */
  const ensureFab = () => {
    const existing = findButton(FAB_SELECTORS);
    if (existing) {
      existing.classList.add('confession-fab');
      existing.setAttribute('data-confession-fab', 'true');
      return existing;
    }

    const button = document.createElement('button');
    button.type = 'button';
    button.className = 'confession-fab';
    button.setAttribute('data-confession-fab', 'true');
    button.textContent = 'Write a Confession';
    (document.body ?? document.documentElement).appendChild(button);
    return button;
  };

  /**
   * @returns {HTMLElement}
   */
  const ensureModal = () => {
    const modal = ensureElement('div', MODAL_SELECTORS, document.body ?? document.documentElement);
    modal.classList.add('confession-modal');
    modal.setAttribute('data-confession-modal', 'true');
    modal.setAttribute('role', 'dialog');
    modal.setAttribute('aria-modal', 'true');
    modal.hidden = true;
    return modal;
  };

  /**
   * @returns {HTMLTextAreaElement}
   */
  const ensureTextarea = () => {
    const modal = ensureModal();
    let textarea = findTextArea(TEXTAREA_SELECTORS);
    if (textarea) {
      textarea.classList.add('confession-modal__textarea');
      textarea.setAttribute('data-confession-textarea', 'true');
      textarea.maxLength = MAX_CONFESSION_LENGTH;
      return textarea;
    }

    textarea = document.createElement('textarea');
    textarea.className = 'confession-modal__textarea';
    textarea.setAttribute('data-confession-textarea', 'true');
    textarea.maxLength = MAX_CONFESSION_LENGTH;
    textarea.rows = 6;
    textarea.placeholder = 'Share your anonymous confession...';
    modal.appendChild(textarea);
    return textarea;
  };

  /**
   * @returns {HTMLButtonElement}
   */
  const ensureSubmitButton = () => {
    const modal = ensureModal();
    let button = findButton(SUBMIT_SELECTORS);
    if (button) {
      button.classList.add('confession-modal__submit');
      button.setAttribute('data-confession-submit', 'true');
      return button;
    }

    button = document.createElement('button');
    button.type = 'submit';
    button.className = 'confession-modal__submit';
    button.setAttribute('data-confession-submit', 'true');
    button.textContent = 'Post anonymously';
    modal.appendChild(button);
    return button;
  };

  /**
   * @returns {HTMLButtonElement}
   */
  const ensureCloseButton = () => {
    const modal = ensureModal();
    let button = findButton(CLOSE_SELECTORS);
    if (button) {
      button.classList.add('confession-modal__close');
      button.setAttribute('data-confession-close', 'true');
      return button;
    }

    button = document.createElement('button');
    button.type = 'button';
    button.className = 'confession-modal__close';
    button.setAttribute('data-confession-close', 'true');
    button.textContent = 'Close';
    modal.appendChild(button);
    return button;
  };

  /**
   * @returns {HTMLSpanElement}
   */
  const ensureCounter = () => {
    const modal = ensureModal();
    let counter = findFirstElement(COUNTER_SELECTORS);
    if (counter instanceof HTMLSpanElement) {
      counter.classList.add('confession-counter');
      counter.setAttribute('data-confession-counter', 'true');
      return counter;
    }

    counter = document.createElement('span');
    counter.className = 'confession-counter';
    counter.setAttribute('data-confession-counter', 'true');
    modal.appendChild(counter);
    return counter;
  };

  /**
   * @returns {HTMLElement}
   */
  const ensureFlagMenu = () => {
    const menu = ensureElement('div', FLAG_MENU_SELECTORS, document.body ?? document.documentElement);
    menu.classList.add('confession-flag-menu');
    menu.setAttribute('data-confession-flag-menu', 'true');
    menu.hidden = true;
    return menu;
  };

  /**
   * @param {unknown} value
   * @returns {string}
   */
  const toText = (value) => (value ?? '').toString();

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
   * @param {unknown} responseData
   * @returns {Record<string, unknown>[]}
   */
  const extractConfessions = (responseData) => {
    if (Array.isArray(responseData)) {
      return responseData;
    }

    if (responseData && typeof responseData === 'object') {
      const data = /** @type {Record<string, unknown>} */ (responseData);
      const payload = data.confessions ?? data.items ?? data.data ?? [];
      if (Array.isArray(payload)) {
        return payload;
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
    }

    return false;
  };

  /**
   * @param {unknown} confession
   * @returns {Record<string, unknown>}
   */
  const normaliseConfession = (confession) => {
    const raw = confession ?? {};
    return {
      id: toText(raw.id ?? raw.confession_id ?? raw.confessionId ?? `conf-${Date.now()}-${Math.random().toString(16).slice(2)}`),
      body: toText(raw.body ?? raw.text ?? ''),
      created_at: toText(raw.created_at ?? raw.createdAt ?? raw.sent_at ?? raw.sentAt ?? ''),
      anonymous_username: ANONYMOUS_NAME,
      is_flagged: Boolean(raw.is_flagged ?? raw.isFlagged),
      expanded: false
    };
  };

  /**
   * @returns {{ items: Record<string, unknown>[], page: number, hasMore: boolean, isLoading: boolean, isSubmitting: boolean, requestToken: number, lastAppendedId: string | null }}
   */
  const getState = () => {
    window[NAMESPACE_NAME] = window[NAMESPACE_NAME] ?? {};
    const namespace = window[NAMESPACE_NAME];

    if (!namespace.__confessionsState) {
      namespace.__confessionsState = {
        items: [],
        page: 1,
        hasMore: true,
        isLoading: false,
        isSubmitting: false,
        requestToken: 0,
        lastAppendedId: null
      };
    }

    return namespace.__confessionsState;
  };

  /**
   * @returns {void}
   */
  const renderFeed = () => {
    const state = getState();
    const feed = ensureFeed();
    feed.replaceChildren();

    if (state.items.length === 0) {
      const empty = document.createElement('div');
      empty.className = 'confessions-feed__empty';
      empty.textContent = 'No confessions yet.';
      feed.appendChild(empty);
      return;
    }

    for (const confession of state.items) {
      feed.appendChild(createConfessionCard(confession));
    }
  };

  /**
   * @param {Record<string, unknown>} confession
   * @returns {HTMLElement}
   */
  const createConfessionCard = (confession) => {
    const card = document.createElement('article');
    card.className = 'confession-card';
    card.dataset.confessionId = toText(confession.id);
    card.setAttribute('tabindex', '0');

    const header = document.createElement('div');
    header.className = 'confession-card__header';

    const name = document.createElement('div');
    name.className = 'confession-card__anonymous';
    name.textContent = ANONYMOUS_NAME;

    const time = document.createElement('div');
    time.className = 'confession-card__time';
    time.textContent = getRelativeTimeFormatter()(toText(confession.created_at));

    header.append(name, time);

    const body = document.createElement('p');
    body.className = 'confession-card__body';
    body.textContent = textToDisplay(confession);

    const flagButton = document.createElement('button');
    flagButton.type = 'button';
    flagButton.className = 'confession-card__flag';
    flagButton.textContent = '⚑ Flag';
    flagButton.setAttribute('aria-expanded', 'false');

    const flagMenu = document.createElement('div');
    flagMenu.className = 'confession-card__flag-menu';
    flagMenu.hidden = true;

    const reasonSelect = document.createElement('select');
    reasonSelect.className = 'confession-card__reason';
    reasonSelect.setAttribute('aria-label', 'Flag reason');

    const reasons = ['Harassment', 'Hate Speech', 'Explicit Content', 'Spam', 'Other'];
    for (const reason of reasons) {
      const option = document.createElement('option');
      option.value = reason;
      option.textContent = reason;
      reasonSelect.appendChild(option);
    }

    const flagSubmit = document.createElement('button');
    flagSubmit.type = 'button';
    flagSubmit.className = 'confession-card__flag-submit';
    flagSubmit.textContent = 'Report';

    flagMenu.append(reasonSelect, flagSubmit);
    card.append(header, body, flagButton, flagMenu);

    flagButton.addEventListener('click', () => {
      const isOpen = !flagMenu.hidden;
      flagMenu.hidden = isOpen;
      flagButton.setAttribute('aria-expanded', String(!isOpen));
    });

    card.addEventListener('click', () => {
      toggleConfessionExpansion(card, body, confession);
    });

    body.addEventListener('click', (event) => {
      event.stopPropagation();
      toggleConfessionExpansion(card, body, confession);
    });

    flagSubmit.addEventListener('click', (event) => {
      event.stopPropagation();
      void submitFlag(confession, reasonSelect.value);
    });

    return card;
  };

  /**
   * @param {Record<string, unknown>} confession
   * @returns {string}
   */
  const textToDisplay = (confession) => {
    const state = getState();
    const expanded = Boolean(confession.expanded);
    if (expanded) {
      return toText(confession.body);
    }

    return truncateText(confession.body, 180);
  };

  /**
   * @param {HTMLElement} card
   * @param {HTMLElement} body
   * @param {Record<string, unknown>} confession
   * @returns {void}
   */
  const toggleConfessionExpansion = (card, body, confession) => {
    confession.expanded = !confession.expanded;
    body.textContent = textToDisplay(confession);
    card.classList.toggle('is-expanded', Boolean(confession.expanded));
  };

  /**
   * @param {number} page
   * @returns {Promise<unknown>}
   */
  const fetchConfessions = async (page) => {
    const apiFetch = getApiFetch();
    if (!apiFetch) {
      throw new Error('Confession feed is unavailable.');
    }

    return apiFetch(`${CONFESSIONS_ENDPOINT}?page=${page}&limit=${PAGE_SIZE}`);
  };

  /**
   * @param {boolean} reset
   * @returns {Promise<void>}
   */
  const loadConfessions = async (reset) => {
    const state = getState();
    if (state.isLoading) {
      return;
    }

    state.isLoading = true;
    const requestToken = ++state.requestToken;

    if (reset) {
      state.page = 1;
      state.hasMore = true;
      state.items = [];
      renderFeed();
    }

    try {
      const response = await fetchConfessions(state.page);
      if (requestToken !== state.requestToken) {
        return;
      }

      const incoming = extractConfessions(response).map(normaliseConfession);
      if (reset) {
        state.items = incoming;
      } else {
        const existingIds = new Set(state.items.map((item) => toText(item.id)));
        for (const confession of incoming) {
          if (!existingIds.has(toText(confession.id))) {
            state.items.push(confession);
          }
        }
      }

      state.hasMore = extractHasMore(response) || incoming.length === PAGE_SIZE;
      state.page += 1;
      renderFeed();
      bindFeedInteractions();
    } catch (error) {
      getToast()(error instanceof Error ? error.message : 'Could not load confessions.', 'error');
      if (state.items.length === 0) {
        renderFeed();
      }
    } finally {
      state.isLoading = false;
    }
  };

  /**
   * @returns {void}
   */
  const bindFeedInteractions = () => {
    const feed = ensureFeed();
    feed.querySelectorAll('.confession-card').forEach((card) => {
      if (!(card instanceof HTMLElement) || card.dataset.bound === 'true') {
        return;
      }

      card.dataset.bound = 'true';
      card.addEventListener('keydown', (event) => {
        if (event.key === 'Enter' || event.key === ' ') {
          event.preventDefault();
          const body = card.querySelector('.confession-card__body');
          const confession = getState().items.find((item) => toText(item.id) === toText(card.dataset.confessionId));
          if (body instanceof HTMLElement && confession) {
            toggleConfessionExpansion(card, body, confession);
          }
        }
      });
    });
  };

  /**
   * @returns {void}
   */
  const updateCounter = () => {
    const textarea = ensureTextarea();
    const counter = ensureCounter();
    counter.textContent = `${textarea.value.length}/${MAX_CONFESSION_LENGTH}`;
  };

  /**
   * @returns {void}
   */
  const openModal = () => {
    const modal = ensureModal();
    const textarea = ensureTextarea();
    modal.hidden = false;
    modal.setAttribute('aria-hidden', 'false');
    textarea.focus();
    updateCounter();
  };

  /**
   * @returns {void}
   */
  const closeModal = () => {
    const modal = ensureModal();
    modal.hidden = true;
    modal.setAttribute('aria-hidden', 'true');
    ensureTextarea().value = '';
    updateCounter();
  };

  /**
   * @param {string} body
   * @returns {Promise<unknown>}
   */
  const submitConfession = async (body) => {
    const apiFetch = getApiFetch();
    if (!apiFetch) {
      throw new Error('Confession submission is unavailable.');
    }

    return apiFetch(CONFESSIONS_ENDPOINT, {
      method: 'POST',
      body: {
        body,
        anonymous: true
      }
    });
  };

  /**
   * @returns {Promise<void>}
   */
  const handleSubmit = async () => {
    const state = getState();
    const textarea = ensureTextarea();
    const body = textarea.value.trim();
    if (!body) {
      getToast()('Write something before posting.', 'error');
      return;
    }

    if (body.length > MAX_CONFESSION_LENGTH) {
      getToast()('Confession is too long.', 'error');
      return;
    }

    if (state.isSubmitting) {
      return;
    }

    state.isSubmitting = true;
    const submitButton = ensureSubmitButton();
    submitButton.disabled = true;

    try {
      const response = await submitConfession(body);
      const incoming = extractConfessions(response).map(normaliseConfession);
      const created = incoming[0] ?? {
        id: `conf-${Date.now()}`,
        body,
        created_at: new Date().toISOString(),
        anonymous_username: ANONYMOUS_NAME,
        is_flagged: false,
        expanded: false
      };

      state.items.unshift(created);
      renderFeed();
      bindFeedInteractions();
      closeModal();
    } catch (error) {
      getToast()(error instanceof Error ? error.message : 'Could not post confession.', 'error');
    } finally {
      state.isSubmitting = false;
      submitButton.disabled = false;
    }
  };

  /**
   * @param {Record<string, unknown>} confession
   * @param {string} reason
   * @returns {Promise<void>}
   */
  const submitFlag = async (confession, reason) => {
    const apiFetch = getApiFetch();
    if (!apiFetch) {
      getToast()('Flagging is unavailable.', 'error');
      return;
    }

    if (!reason) {
      getToast()('Choose a reason.', 'error');
      return;
    }

    try {
      await apiFetch(CONFESSIONS_ENDPOINT, {
        method: 'POST',
        body: {
          confession_id: toText(confession.id),
          reason,
          action: 'flag'
        }
      });

      const card = ensureFeed().querySelector(`[data-confession-id="${CSS.escape(toText(confession.id))}"]`);
      if (card instanceof HTMLElement) {
        card.classList.add('is-flagged');
      }

      getToast()('Thanks for reporting this confession.', 'success');
    } catch (error) {
      getToast()(error instanceof Error ? error.message : 'Could not flag confession.', 'error');
    }
  };

  /**
   * @returns {void}
   */
  const handleScroll = () => {
    const feed = ensureFeed();
    if (window.innerHeight + window.scrollY >= document.body.offsetHeight - SCROLL_THRESHOLD_PX) {
      const state = getState();
      if (state.hasMore && !state.isLoading) {
        void loadConfessions(false);
      }
    }
  };

  /**
   * @returns {void}
   */
  const bindModalEvents = () => {
    const modal = ensureModal();
    const textarea = ensureTextarea();
    const submitButton = ensureSubmitButton();
    const closeButton = ensureCloseButton();
    const counter = ensureCounter();

    textarea.addEventListener('input', updateCounter);
    textarea.addEventListener('input', () => {
      if (textarea.value.length > MAX_CONFESSION_LENGTH) {
        textarea.value = textarea.value.slice(0, MAX_CONFESSION_LENGTH);
      }
      counter.textContent = `${textarea.value.length}/${MAX_CONFESSION_LENGTH}`;
    });

    submitButton.addEventListener('click', (event) => {
      event.preventDefault();
      void handleSubmit();
    });

    closeButton.addEventListener('click', (event) => {
      event.preventDefault();
      closeModal();
    });

    modal.addEventListener('click', (event) => {
      if (event.target === modal) {
        closeModal();
      }
    });
  };

  /**
   * @returns {void}
   */
  const bindFab = () => {
    const fab = ensureFab();
    fab.addEventListener('click', openModal);
  };

  /**
   * @returns {void}
   */
  const init = () => {
    ensureFeed();
    ensureModal();
    ensureFlagMenu();
    bindFab();
    bindModalEvents();
    updateCounter();
    void loadConfessions(true);
    window.addEventListener('scroll', handleScroll, { passive: true });
  };

  window[NAMESPACE_NAME] = window[NAMESPACE_NAME] ?? {};
  window[NAMESPACE_NAME].initConfessions = init;

  document.addEventListener('DOMContentLoaded', init);
})();
