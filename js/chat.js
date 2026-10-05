'use strict';
// js/chat.js

(function () {
  const NAMESPACE_NAME = 'Bushisa';
  const MESSAGES_ENDPOINT = 'api/messages.php';
  const MATCHES_ENDPOINT = 'api/matches.php';
  const READ_ENDPOINT = 'api/messages.php/read';
  const PAGE_SIZE = 20;
  const MATCHES_POLL_MS = 8000;
  const THREAD_POLL_MS = 5000;
  const MAX_MESSAGE_LENGTH = 4000;
  const CHAT_SELECTORS = ['[data-chat-app]', '#chatApp', '.chat-app'];
  const MATCH_LIST_SELECTORS = ['[data-match-list]', '#matchList', '.match-list'];
  const THREAD_SELECTORS = ['[data-chat-thread]', '#chatThread', '.chat-thread'];
  const COMPOSER_SELECTORS = ['[data-chat-composer]', '#chatComposer', '.chat-composer'];
  const INPUT_SELECTORS = ['[data-chat-input]', '#chatInput', 'textarea[name="body"]', 'textarea[name="message"]'];
  const SEND_BUTTON_SELECTORS = ['[data-chat-send]', '#chatSend', 'button[type="submit"]'];
  const BLOCK_NOTICE_SELECTORS = ['[data-chat-blocked-notice]', '#chatBlockedNotice', '.chat-blocked-notice'];
  const MATCH_STATUS_SELECTORS = ['[data-chat-status]', '#chatStatus', '.chat-status'];
  const ACTIVE_MATCH_SELECTORS = ['[data-active-match-id]', '#activeMatchId', '.active-match-id'];
  const SENTINEL_TOP_CLASS = 'chat-thread__sentinel--top';

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
   * @returns {(value: string) => string}
   */
  const getSanitiser = () => {
    const namespace = getNamespace();
    return typeof namespace.sanitiseHTML === 'function' ? namespace.sanitiseHTML : (value) => value;
  };

  /**
   * @returns {(isoDate: string) => string}
   */
  const getRelativeTimeFormatter = () => {
    const namespace = getNamespace();
    return typeof namespace.formatRelativeTime === 'function' ? namespace.formatRelativeTime : (value) => value;
  };

  /**
   * @returns {string | null}
   */
  const getCurrentUserId = () => {
    const namespace = getNamespace();
    const namespaceId = toId(namespace.currentUserId);
    if (namespaceId) {
      return namespaceId;
    }

    const bodyId = toId(document.body?.dataset.currentUserId);
    if (bodyId) {
      return bodyId;
    }

    const meta = document.querySelector('meta[name="current-user-id"]');
    if (meta instanceof HTMLMetaElement) {
      return toId(meta.content);
    }

    return null;
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
   * @returns {HTMLTextAreaElement | HTMLInputElement | null}
   */
  const findInputElement = (selectors) => {
    for (const selector of selectors) {
      const element = document.querySelector(selector);
      if (element instanceof HTMLTextAreaElement || element instanceof HTMLInputElement) {
        return element;
      }
    }

    return null;
  };

  /**
   * @param {string[]} selectors
   * @returns {HTMLButtonElement | null}
   */
  const findButtonElement = (selectors) => {
    for (const selector of selectors) {
      const element = document.querySelector(selector);
      if (element instanceof HTMLButtonElement) {
        return element;
      }
    }

    return null;
  };

  /**
   * @param {string[]} selectors
   * @returns {HTMLFormElement | null}
   */
  const findFormElement = (selectors) => {
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
   * @returns {HTMLElement}
   */
  const ensureChatApp = () => {
    const mount = ensureElement('div', CHAT_SELECTORS, document.body ?? document.documentElement);
    mount.classList.add('chat-app');
    mount.setAttribute('data-chat-app', 'true');
    return mount;
  };

  /**
   * @returns {HTMLElement}
   */
  const ensureMatchList = () => {
    const mount = ensureElement('aside', MATCH_LIST_SELECTORS, ensureChatApp());
    mount.classList.add('match-list');
    mount.setAttribute('data-match-list', 'true');
    return mount;
  };

  /**
   * @returns {HTMLElement}
   */
  const ensureThread = () => {
    const mount = ensureElement('section', THREAD_SELECTORS, ensureChatApp());
    mount.classList.add('chat-thread');
    mount.setAttribute('data-chat-thread', 'true');
    return mount;
  };

  /**
   * @returns {HTMLFormElement}
   */
  const ensureComposer = () => {
    const existing = findFormElement(COMPOSER_SELECTORS);
    if (existing) {
      existing.classList.add('chat-composer');
      existing.setAttribute('data-chat-composer', 'true');
      return existing;
    }

    const form = document.createElement('form');
    form.className = 'chat-composer';
    form.setAttribute('data-chat-composer', 'true');
    ensureThread().appendChild(form);
    return form;
  };

  /**
   * @returns {HTMLElement}
   */
  const ensureBlockedNotice = () => {
    const mount = ensureElement('div', BLOCK_NOTICE_SELECTORS, ensureThread());
    mount.classList.add('chat-blocked-notice');
    mount.setAttribute('data-chat-blocked-notice', 'true');
    mount.hidden = true;
    return mount;
  };

  /**
   * @returns {HTMLElement}
   */
  const ensureStatus = () => {
    const mount = ensureElement('div', MATCH_STATUS_SELECTORS, ensureChatApp());
    mount.classList.add('chat-status');
    mount.setAttribute('data-chat-status', 'true');
    return mount;
  };

  /**
   * @returns {HTMLElement}
   */
  const ensureActiveMatchIdField = () => {
    const mount = ensureElement('input', ACTIVE_MATCH_SELECTORS, ensureChatApp());
    if (mount instanceof HTMLInputElement) {
      mount.type = 'hidden';
      mount.setAttribute('data-active-match-id', 'true');
    }
    return mount;
  };

  /**
   * @returns {HTMLElement}
   */
  const ensureThreadList = () => {
    const thread = ensureThread();
    let list = thread.querySelector('[data-message-list]');
    if (!(list instanceof HTMLElement)) {
      list = document.createElement('div');
      list.setAttribute('data-message-list', 'true');
      list.className = 'chat-thread__messages';
      thread.insertBefore(list, thread.firstChild);
    }
    return list;
  };

  /**
   * @returns {HTMLElement}
   */
  const ensureTopSentinel = () => {
    const list = ensureThreadList();
    let sentinel = list.querySelector(`.${SENTINEL_TOP_CLASS}`);
    if (!(sentinel instanceof HTMLElement)) {
      sentinel = document.createElement('div');
      sentinel.className = `chat-thread__sentinel ${SENTINEL_TOP_CLASS}`;
      sentinel.setAttribute('aria-hidden', 'true');
      list.prepend(sentinel);
    }
    return sentinel;
  };

  /**
   * @returns {HTMLTextAreaElement}
   */
  const ensureMessageInput = () => {
    const composer = ensureComposer();
    let input = findInputElement(INPUT_SELECTORS);
    if (input instanceof HTMLTextAreaElement) {
      input.classList.add('chat-composer__input');
      input.setAttribute('data-chat-input', 'true');
      input.maxLength = MAX_MESSAGE_LENGTH;
      return input;
    }

    if (input instanceof HTMLInputElement) {
      const textarea = document.createElement('textarea');
      textarea.className = 'chat-composer__input';
      textarea.setAttribute('data-chat-input', 'true');
      textarea.rows = 2;
      textarea.placeholder = 'Write a message...';
      textarea.maxLength = MAX_MESSAGE_LENGTH;
      input.replaceWith(textarea);
      return textarea;
    }

    const textarea = document.createElement('textarea');
    textarea.className = 'chat-composer__input';
    textarea.setAttribute('data-chat-input', 'true');
    textarea.rows = 2;
    textarea.placeholder = 'Write a message...';
    textarea.maxLength = MAX_MESSAGE_LENGTH;
    composer.appendChild(textarea);
    return textarea;
  };

  /**
   * @returns {HTMLButtonElement}
   */
  const ensureSendButton = () => {
    const composer = ensureComposer();
    let button = findButtonElement(SEND_BUTTON_SELECTORS);
    if (button) {
      button.classList.add('chat-composer__send');
      button.setAttribute('data-chat-send', 'true');
      return button;
    }

    button = document.createElement('button');
    button.type = 'submit';
    button.className = 'chat-composer__send';
    button.setAttribute('data-chat-send', 'true');
    button.textContent = 'Send';
    composer.appendChild(button);
    return button;
  };

  /**
   * @returns {HTMLElement}
   */
  const ensureEmptyState = () => {
    const thread = ensureThread();
    let empty = thread.querySelector('[data-thread-empty]');
    if (!(empty instanceof HTMLElement)) {
      empty = document.createElement('div');
      empty.setAttribute('data-thread-empty', 'true');
      empty.className = 'chat-thread__empty';
      thread.appendChild(empty);
    }
    return empty;
  };

  /**
   * @param {unknown} value
   * @returns {string}
   */
  const textValue = (value) => (value ?? '').toString();

  /**
   * @param {unknown} value
   * @returns {boolean}
   */
  const toBoolean = (value) => value === true || value === 'true' || value === 1 || value === '1';

  /**
   * @param {unknown} value
   * @returns {string | null}
   */
  const toId = (value) => {
    const id = textValue(value).trim();
    return id.length > 0 ? id : null;
  };

  /**
   * @param {unknown} value
   * @returns {string}
   */
  const truncatePreview = (value) => {
    const text = textValue(value).trim();
    if (text.length <= 60) {
      return text;
    }

    return `${text.slice(0, 59).trimEnd()}…`;
  };

  /**
   * @returns {{ matches: Record<string, unknown>[], activeMatchId: string | null, conversations: Map<string, { messages: Record<string, unknown>[], loadedPages: Set<number>, hasMoreOlder: boolean, oldestPage: number, newestMessageId: string | null, lastSeenMessageId: string | null, blocked: boolean, isLoadingOlder: boolean, isLoadingFresh: boolean, lastFetchedAt: number }>, isLoadingMatches: boolean, matchesPollTimer: number | null, threadPollTimer: number | null, readObserver: IntersectionObserver | null, topObserver: IntersectionObserver | null, activeVisible: boolean, pendingRead: string | null, ws: WebSocket | null, reconnectAttempts: number, wsTimer: number | null, pendingFetchToken: number }}
   */
  const getState = () => {
    window[NAMESPACE_NAME] = window[NAMESPACE_NAME] ?? {};
    const namespace = window[NAMESPACE_NAME];

    if (!namespace.__chatState) {
      namespace.__chatState = {
        matches: [],
        activeMatchId: null,
        conversations: new Map(),
        isLoadingMatches: false,
        matchesPollTimer: null,
        threadPollTimer: null,
        readObserver: null,
        topObserver: null,
        activeVisible: false,
        pendingRead: null,
        ws: null,
        reconnectAttempts: 0,
        wsTimer: null,
        pendingFetchToken: 0
      };
    }

    return namespace.__chatState;
  };

  /**
   * @param {unknown} responseData
   * @returns {Record<string, unknown>[]}
   */
  const extractRecords = (responseData) => {
    if (Array.isArray(responseData)) {
      return responseData;
    }

    if (responseData && typeof responseData === 'object') {
      const data = /** @type {Record<string, unknown>} */ (responseData);
      const payload = data.matches ?? data.messages ?? data.data ?? data.items ?? [];
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

      if (typeof data.next_page !== 'undefined' || typeof data.nextPage !== 'undefined') {
        return true;
      }
    }

    return false;
  };

  /**
   * @param {unknown} responseData
   * @returns {boolean}
   */
  const extractBlocked = (responseData) => {
    if (!responseData || typeof responseData !== 'object' || Array.isArray(responseData)) {
      return false;
    }

    const data = /** @type {Record<string, unknown>} */ (responseData);
    return toBoolean(data.blocked) || toBoolean(data.blocked_by_me) || toBoolean(data.blockedByMe) || toBoolean(data.you_blocked_user) || toBoolean(data.youBlockedUser);
  };

  /**
   * @param {unknown} responseData
   * @returns {string | null}
   */
  const extractMatchId = (responseData) => {
    if (!responseData || typeof responseData !== 'object' || Array.isArray(responseData)) {
      return null;
    }

    const data = /** @type {Record<string, unknown>} */ (responseData);
    const matchId = data.match_id ?? data.matchId ?? data.id ?? data.match?.id;
    return toId(matchId);
  };

  /**
   * @param {unknown} responseData
   * @returns {Record<string, unknown>[]}
   */
  const normaliseMatchesResponse = (responseData) => extractRecords(responseData).map((match) => {
    const raw = match ?? {};
    return {
      id: toId(raw.id ?? raw.match_id ?? raw.matchId) ?? '',
      display_name: textValue(raw.display_name ?? raw.displayName ?? raw.name ?? 'Unknown'),
      last_message: textValue(raw.last_message ?? raw.lastMessage ?? ''),
      unread_count: Number(raw.unread_count ?? raw.unreadCount ?? 0) || 0,
      last_activity_at: textValue(raw.last_activity_at ?? raw.lastActivityAt ?? raw.updated_at ?? raw.updatedAt ?? raw.last_message_at ?? raw.lastMessageAt ?? ''),
      profile_photo_path: textValue(raw.profile_photo_path ?? raw.photo_url ?? ''),
      blocked: toBoolean(raw.blocked) || toBoolean(raw.blocked_by_me) || toBoolean(raw.blockedByMe),
      blocked_by_me: toBoolean(raw.blocked_by_me) || toBoolean(raw.blockedByMe),
      is_online: toBoolean(raw.is_online ?? raw.isOnline),
      match: raw
    };
  }).filter((match) => match.id.length > 0);

  /**
   * @param {unknown} responseData
   * @returns {Record<string, unknown>[]}
   */
  const normaliseMessagesResponse = (responseData) => extractRecords(responseData).map((message) => {
    const raw = message ?? {};
    return {
      id: toId(raw.id ?? raw.message_id ?? raw.messageId) ?? `temp-${Date.now()}-${Math.random().toString(16).slice(2)}`,
      match_id: toId(raw.match_id ?? raw.matchId ?? raw.match?.id ?? raw.match) ?? '',
      sender_id: toId(raw.sender_id ?? raw.senderId ?? raw.user_id ?? raw.userId ?? raw.from_id ?? raw.fromId) ?? '',
      body: textValue(raw.body ?? raw.message ?? ''),
      sent_at: textValue(raw.sent_at ?? raw.sentAt ?? raw.created_at ?? raw.createdAt ?? ''),
      is_read: toBoolean(raw.is_read ?? raw.isRead),
      status: textValue(raw.status ?? '')
    };
  }).filter((message) => message.id.length > 0);

  /**
   * @param {unknown} responseData
   * @returns {boolean}
   */
  const responseIndicatesSuccess = (responseData) => {
    if (!responseData || typeof responseData !== 'object' || Array.isArray(responseData)) {
      return true;
    }

    const data = /** @type {Record<string, unknown>} */ (responseData);
    if (typeof data.success === 'boolean') {
      return data.success;
    }

    return true;
  };

  /**
   * @param {unknown} responseData
   * @returns {string | null}
   */
  const extractErrorMessage = (responseData) => {
    if (!responseData || typeof responseData !== 'object' || Array.isArray(responseData)) {
      return null;
    }

    const data = /** @type {Record<string, unknown>} */ (responseData);
    const message = data.error ?? data.message;
    return typeof message === 'string' && message.trim().length > 0 ? message : null;
  };

  /**
   * @param {string} value
   * @returns {string}
   */
  const formatDateTime = (value) => {
    if (!value) {
      return '';
    }

    const date = new Date(value);
    if (Number.isNaN(date.getTime())) {
      return value;
    }

    return date.toLocaleString();
  };

  /**
   * @param {string} value
   * @returns {number}
   */
  const messageOrderValue = (value) => {
    const date = new Date(value);
    const time = date.getTime();
    return Number.isNaN(time) ? 0 : time;
  };

  /**
   * @param {string | null} matchId
   * @returns {{ messages: Record<string, unknown>[], loadedPages: Set<number>, hasMoreOlder: boolean, oldestPage: number, newestMessageId: string | null, lastSeenMessageId: string | null, blocked: boolean, isLoadingOlder: boolean, isLoadingFresh: boolean, lastFetchedAt: number }}
   */
  const ensureConversationState = (matchId) => {
    const state = getState();
    const key = matchId ?? '';
    if (!state.conversations.has(key)) {
      state.conversations.set(key, {
        messages: [],
        loadedPages: new Set(),
        hasMoreOlder: true,
        oldestPage: 1,
        newestMessageId: null,
        lastSeenMessageId: null,
        blocked: false,
        isLoadingOlder: false,
        isLoadingFresh: false,
        lastFetchedAt: 0
      });
    }

    return state.conversations.get(key);
  };

  /**
   * @returns {string | null}
   */
  const getActiveMatchId = () => {
    const input = ensureActiveMatchIdField();
    const value = input instanceof HTMLInputElement ? toId(input.value) : null;
    if (value) {
      return value;
    }

    const state = getState();
    return state.activeMatchId;
  };

  /**
   * @param {string | null} matchId
   * @returns {void}
   */
  const setActiveMatchId = (matchId) => {
    const field = ensureActiveMatchIdField();
    if (field instanceof HTMLInputElement) {
      field.value = matchId ?? '';
    }

    getState().activeMatchId = matchId;
  };

  /**
   * @returns {void}
   */
  const renderMatches = () => {
    const state = getState();
    const list = ensureMatchList();
    const activeMatchId = getActiveMatchId();
    const sortedMatches = [...state.matches].sort((left, right) => {
      const leftTime = messageOrderValue(textValue(left.last_activity_at));
      const rightTime = messageOrderValue(textValue(right.last_activity_at));
      return rightTime - leftTime;
    });

    list.replaceChildren();

    if (sortedMatches.length === 0) {
      const empty = document.createElement('div');
      empty.className = 'match-list__empty';
      empty.textContent = 'No matches yet.';
      list.appendChild(empty);
      return;
    }

    for (const match of sortedMatches) {
      const button = document.createElement('button');
      button.type = 'button';
      button.className = 'match-list__item';
      button.dataset.matchId = toId(match.id) ?? '';
      button.setAttribute('aria-pressed', String(toId(match.id) === activeMatchId));

      if (Number(match.unread_count) > 0) {
        button.classList.add('is-unread');
      }

      if (toId(match.id) === activeMatchId) {
        button.classList.add('is-active');
      }

      const avatar = document.createElement('div');
      avatar.className = 'match-list__avatar';
      avatar.textContent = textValue(match.display_name).slice(0, 1).toUpperCase();

      const content = document.createElement('div');
      content.className = 'match-list__content';

      const name = document.createElement('div');
      name.className = 'match-list__name';
      name.textContent = textValue(match.display_name);
      name.style.fontWeight = Number(match.unread_count) > 0 ? '700' : '400';

      const preview = document.createElement('div');
      preview.className = 'match-list__preview';
      preview.textContent = truncatePreview(match.last_message);

      const meta = document.createElement('div');
      meta.className = 'match-list__meta';
      meta.textContent = textValue(match.last_activity_at) ? getRelativeTimeFormatter()(textValue(match.last_activity_at)) : '';

      const badge = document.createElement('span');
      badge.className = 'match-list__badge';
      badge.textContent = String(Number(match.unread_count) || 0);
      badge.hidden = Number(match.unread_count) <= 0;

      content.append(name, preview, meta);
      button.append(avatar, content, badge);
      button.addEventListener('click', () => {
        void selectMatch(toId(match.id));
      });
      list.appendChild(button);
    }
  };

  /**
   * @param {Record<string, unknown>} message
   * @param {boolean} isOwnMessage
   * @param {boolean} isPending
   * @returns {HTMLElement}
   */
  const createMessageBubble = (message, isOwnMessage, isPending) => {
    const bubble = document.createElement('article');
    bubble.className = `chat-message ${isOwnMessage ? 'is-sent' : 'is-received'}`;
    if (isPending) {
      bubble.classList.add('is-pending');
    }

    const body = document.createElement('div');
    body.className = 'chat-message__bubble';
    body.textContent = getSanitiser()(textValue(message.body));

    const meta = document.createElement('button');
    meta.type = 'button';
    meta.className = 'chat-message__meta';
    meta.textContent = isPending ? 'sending…' : message.status === 'failed' ? 'retry' : formatDateTime(textValue(message.sent_at));
    meta.title = formatDateTime(textValue(message.sent_at));

    const retryButton = document.createElement('button');
    retryButton.type = 'button';
    retryButton.className = 'chat-message__retry';
    retryButton.textContent = 'Retry';
    retryButton.hidden = textValue(message.status) !== 'failed';

    bubble.append(body, meta, retryButton);
    bubble.dataset.messageId = toId(message.id) ?? '';
    bubble.dataset.matchId = toId(message.match_id) ?? '';
    bubble.dataset.senderId = toId(message.sender_id) ?? '';
    bubble.setAttribute('tabindex', '0');
    bubble.setAttribute('aria-label', `${isOwnMessage ? 'You' : 'Them'}: ${textValue(message.body)}`);
    bubble.addEventListener('click', toggleMessageTimestamp);
    bubble.addEventListener('keydown', handleBubbleKeydown);

    if (retryButton.hidden === false) {
      retryButton.addEventListener('click', () => {
        void retryMessageSend(bubble);
      });
    }

    return bubble;
  };

  /**
   * @param {HTMLElement} bubble
   * @returns {void}
   */
  const toggleMessageTimestamp = (bubble) => {
    bubble.classList.toggle('is-time-visible');
  };

  /**
   * @param {KeyboardEvent} event
   * @returns {void}
   */
  const handleBubbleKeydown = (event) => {
    if (event.key === 'Enter' || event.key === ' ') {
      event.preventDefault();
      const bubble = event.currentTarget instanceof HTMLElement ? event.currentTarget : null;
      if (bubble) {
        toggleMessageTimestamp(bubble);
      }
    }
  };

  /**
   * @param {Record<string, unknown>} message
   * @returns {void}
   */
  const appendMessage = (message) => {
    const list = ensureThreadList();
    const state = getState();
    const matchId = toId(message.match_id);
    const activeMatchId = getActiveMatchId();
    const isOwnMessage = toId(message.sender_id) === getCurrentUserId();
    const isPending = textValue(message.status) === 'sending' || textValue(message.status) === 'pending';
    const bubble = createMessageBubble(message, isOwnMessage, isPending);
    const existing = list.querySelector(`[data-message-id="${CSS.escape(toId(message.id) ?? '')}"]`);

    if (existing instanceof HTMLElement) {
      existing.replaceWith(bubble);
    } else {
      list.appendChild(bubble);
    }

    if (matchId && matchId === activeMatchId) {
      state.activeVisible = true;
    }
  };

  /**
   * @param {Record<string, unknown>[]} messages
   * @returns {void}
   */
  const renderMessages = (messages) => {
    const list = ensureThreadList();
    const grouped = [...messages].sort((left, right) => messageOrderValue(textValue(left.sent_at)) - messageOrderValue(textValue(right.sent_at)));
    const fragment = document.createDocumentFragment();

    for (const message of grouped) {
      fragment.appendChild(createMessageBubble(message, toId(message.sender_id) === getCurrentUserId(), textValue(message.status) === 'sending' || textValue(message.status) === 'pending'));
    }

    list.replaceChildren(ensureTopSentinel(), fragment);
  };

  /**
   * @param {string} matchId
   * @param {number} page
   * @param {string | null} beforeId
   * @param {string | null} afterId
   * @returns {Promise<unknown>}
   */
  const loadMessagesPage = async (matchId, page, beforeId, afterId) => {
    const apiFetch = getApiFetch();
    if (!apiFetch) {
      throw new Error('Messaging services are unavailable.');
    }

    const params = new URLSearchParams();
    params.set('match_id', matchId);
    params.set('limit', String(PAGE_SIZE));
    params.set('page', String(page));
    if (beforeId) {
      params.set('before_id', beforeId);
    }
    if (afterId) {
      params.set('after_id', afterId);
    }

    return apiFetch(`${MESSAGES_ENDPOINT}?${params.toString()}`);
  };

  /**
   * @param {string} matchId
   * @param {boolean} reset
   * @returns {Promise<void>}
   */
  const fetchMessages = async (matchId, reset) => {
    const state = getState();
    const conversation = ensureConversationState(matchId);
    if (!matchId) {
      return;
    }

    if (conversation.isLoadingFresh || conversation.isLoadingOlder) {
      return;
    }

    conversation.isLoadingFresh = true;
    const fetchToken = ++state.pendingFetchToken;

    try {
      const page = reset ? 1 : conversation.oldestPage;
      const beforeId = !reset ? conversation.messages[0] ? toId(conversation.messages[0].id) : null : null;
      const afterId = reset ? null : conversation.newestMessageId;
      const response = await loadMessagesPage(matchId, page, beforeId, afterId);
      if (fetchToken !== state.pendingFetchToken) {
        return;
      }

      conversation.blocked = extractBlocked(response);

      const incomingMessages = normaliseMessagesResponse(response);
      const existingIds = new Set(conversation.messages.map((message) => toId(message.id)).filter(Boolean));
      const uniqueMessages = incomingMessages.filter((message) => !existingIds.has(toId(message.id) ?? ''));

      if (reset) {
        conversation.messages = uniqueMessages;
      } else if (conversation.messages.length === 0) {
        conversation.messages = uniqueMessages;
      } else {
        const firstLoaded = conversation.messages[0];
        const isOlderLoad = beforeId !== null && toId(firstLoaded.id) === beforeId;
        if (isOlderLoad) {
          conversation.messages = [...uniqueMessages, ...conversation.messages];
        } else {
          conversation.messages = [...conversation.messages, ...uniqueMessages];
        }
      }

      conversation.messages.sort((left, right) => messageOrderValue(textValue(left.sent_at)) - messageOrderValue(textValue(right.sent_at)));
      conversation.newestMessageId = conversation.messages.length > 0 ? toId(conversation.messages[conversation.messages.length - 1].id) : null;
      conversation.oldestPage = page + 1;
      conversation.hasMoreOlder = extractHasMore(response) || incomingMessages.length === PAGE_SIZE;
      conversation.lastFetchedAt = Date.now();

      renderConversation(matchId);
      syncReadState();
    } catch (error) {
      getToast()(error instanceof Error ? error.message : 'Could not load messages.', 'error');
    } finally {
      conversation.isLoadingFresh = false;
    }
  };

  /**
   * @returns {void}
   */
  const renderConversation = (matchId) => {
    const conversation = ensureConversationState(matchId);
    const thread = ensureThread();
    const blockedNotice = ensureBlockedNotice();
    const emptyState = ensureEmptyState();
    const composer = ensureComposer();
    const input = ensureMessageInput();
    const sendButton = ensureSendButton();
    const status = ensureStatus();
    const list = ensureThreadList();

    status.textContent = matchId ? `Conversation with ${getMatchById(matchId)?.display_name ?? 'match'}` : 'Select a conversation';

    if (!matchId) {
      blockedNotice.hidden = true;
      emptyState.hidden = false;
      emptyState.textContent = 'Select a match to start chatting.';
      list.replaceChildren(ensureTopSentinel());
      composer.hidden = true;
      input.disabled = true;
      sendButton.disabled = true;
      return;
    }

    composer.hidden = false;
    input.disabled = conversation.blocked;
    sendButton.disabled = conversation.blocked;

    if (conversation.blocked) {
      blockedNotice.textContent = 'You have blocked this user';
      blockedNotice.hidden = false;
      emptyState.hidden = true;
    } else {
      blockedNotice.hidden = true;
      emptyState.hidden = conversation.messages.length > 0;
      if (conversation.messages.length === 0) {
        emptyState.textContent = 'No messages yet. Say hello!';
      }
    }
    renderMessages(conversation.messages);
  };

  /**
   * @returns {Record<string, unknown> | null}
   */
  const getMatchById = (matchId) => {
    const state = getState();
    return state.matches.find((match) => toId(match.id) === matchId) ?? null;
  };

  /**
   * @returns {void}
   */
  const scrollThreadToBottom = () => {
    const thread = ensureThread();
    thread.scrollTop = thread.scrollHeight;
  };

  /**
   * @param {HTMLElement} element
   * @returns {boolean}
   */
  const isElementInView = (element) => {
    const rect = element.getBoundingClientRect();
    return rect.top < window.innerHeight && rect.bottom > 0;
  };

  /**
   * @returns {void}
   */
  const syncReadState = () => {
    const state = getState();
    const matchId = getActiveMatchId();
    const conversation = matchId ? ensureConversationState(matchId) : null;
    const thread = ensureThread();
    const match = matchId ? getMatchById(matchId) : null;

    if (!matchId || !conversation || conversation.blocked) {
      return;
    }

    if (match && Number(match.unread_count) <= 0) {
      return;
    }

    if (!state.activeVisible) {
      return;
    }

    if (!isElementInView(thread)) {
      return;
    }

    if (state.pendingRead === matchId) {
      return;
    }

    state.pendingRead = matchId;
    void markConversationRead(matchId);
  };

  /**
   * @param {string} matchId
   * @returns {Promise<void>}
   */
  const markConversationRead = async (matchId) => {
    const apiFetch = getApiFetch();
    if (!apiFetch) {
      return;
    }

    try {
      await apiFetch(READ_ENDPOINT, {
        method: 'POST',
        body: {
          match_id: matchId
        }
      });

      const match = getMatchById(matchId);
      if (match) {
        match.unread_count = 0;
      }

      renderMatches();
    } catch (error) {
      getToast()(error instanceof Error ? error.message : 'Could not mark messages as read.', 'error');
    } finally {
      const state = getState();
      state.pendingRead = null;
    }
  };

  /**
   * @param {string | null} matchId
   * @returns {Promise<void>}
   */
  const selectMatch = async (matchId) => {
    if (!matchId) {
      return;
    }

    setActiveMatchId(matchId);
    renderMatches();
    renderConversation(matchId);
    await fetchMessages(matchId, true);
    scrollThreadToBottom();
    schedulePolling();
  };

  /**
   * @returns {void}
   */
  const schedulePolling = () => {
    const state = getState();
    if (state.matchesPollTimer !== null) {
      window.clearInterval(state.matchesPollTimer);
    }
    if (state.threadPollTimer !== null) {
      window.clearInterval(state.threadPollTimer);
    }

    state.matchesPollTimer = window.setInterval(() => {
      void refreshMatches();
    }, MATCHES_POLL_MS);

    state.threadPollTimer = window.setInterval(() => {
      const matchId = getActiveMatchId();
      if (matchId) {
        void refreshActiveConversation(matchId);
      }
    }, THREAD_POLL_MS);
  };

  /**
   * @returns {Promise<void>}
   */
  const refreshMatches = async () => {
    const state = getState();
    const apiFetch = getApiFetch();
    if (!apiFetch || state.isLoadingMatches) {
      return;
    }

    state.isLoadingMatches = true;
    try {
      const response = await apiFetch(MATCHES_ENDPOINT);
      const matches = normaliseMatchesResponse(response);
      const previousActive = getActiveMatchId();
      state.matches = matches;
      renderMatches();

      if (previousActive && !state.matches.some((match) => toId(match.id) === previousActive)) {
        setActiveMatchId(null);
      }
    } catch (error) {
      getToast()(error instanceof Error ? error.message : 'Could not refresh matches.', 'error');
    } finally {
      state.isLoadingMatches = false;
    }
  };

  /**
   * @param {string} matchId
   * @returns {Promise<void>}
   */
  const refreshActiveConversation = async (matchId) => {
    const conversation = ensureConversationState(matchId);
    if (!matchId || conversation.blocked) {
      return;
    }

    if (conversation.isLoadingFresh) {
      return;
    }

    try {
      await fetchMessages(matchId, false);
    } catch (error) {
      getToast()(error instanceof Error ? error.message : 'Could not refresh conversation.', 'error');
    }
  };

  /**
   * @param {HTMLFormElement} form
   * @returns {{ matchId: string, body: string } | null}
   */
  const readComposerValues = (form) => {
    const matchId = getActiveMatchId();
    const input = ensureMessageInput();
    if (!matchId || !input) {
      return null;
    }

    const body = input.value.trim();
    if (!body) {
      return null;
    }

    return {
      matchId,
      body
    };
  };

  /**
   * @param {string} matchId
   * @param {string} body
   * @returns {Record<string, unknown>}
   */
  const createOptimisticMessage = (matchId, body) => ({
    id: `temp-${Date.now()}-${Math.random().toString(16).slice(2)}`,
    match_id: matchId,
    sender_id: getNamespace().currentUserId ?? '',
    body,
    sent_at: new Date().toISOString(),
    is_read: false,
    status: 'sending'
  });

  /**
   * @param {HTMLFormElement} form
   * @returns {Promise<void>}
   */
  const handleComposerSubmit = async (form) => {
    const values = readComposerValues(form);
    const input = ensureMessageInput();
    if (!values) {
      return;
    }

    const { matchId, body } = values;
    if (body.length > MAX_MESSAGE_LENGTH) {
      getToast()('Your message is too long.', 'error');
      return;
    }

    const conversation = ensureConversationState(matchId);
    if (conversation.blocked) {
      return;
    }

    const apiFetch = getApiFetch();
    if (!apiFetch) {
      getToast()('Messaging services are unavailable.', 'error');
      return;
    }

    const optimistic = createOptimisticMessage(matchId, body);
    conversation.messages.push(optimistic);
    conversation.messages.sort((left, right) => messageOrderValue(textValue(left.sent_at)) - messageOrderValue(textValue(right.sent_at)));
    appendMessage(optimistic);
    input.value = '';
    scrollThreadToBottom();

    try {
      const response = await apiFetch(MESSAGES_ENDPOINT, {
        method: 'POST',
        body: {
          match_id: matchId,
          body
        }
      });

      const sentMessages = normaliseMessagesResponse(response);
      const sentMessage = sentMessages[0] ?? {
        ...optimistic,
        status: 'sent'
      };
      upsertMessageResult(matchId, optimistic.id, sentMessage);
      renderConversation(matchId);
      renderMatches();
      scrollThreadToBottom();
    } catch (error) {
      markMessageFailed(matchId, optimistic.id);
      getToast()(error instanceof Error ? error.message : 'Message failed to send.', 'error');
    }
  };

  /**
   * @param {string} matchId
   * @param {string} tempId
   * @param {Record<string, unknown>} result
   * @returns {void}
   */
  const upsertMessageResult = (matchId, tempId, result) => {
    const conversation = ensureConversationState(matchId);
    const index = conversation.messages.findIndex((message) => toId(message.id) === tempId);
    const nextMessage = {
      ...result,
      match_id: matchId,
      status: 'sent'
    };

    if (index >= 0) {
      conversation.messages[index] = nextMessage;
    } else {
      conversation.messages.push(nextMessage);
    }

    conversation.messages.sort((left, right) => messageOrderValue(textValue(left.sent_at)) - messageOrderValue(textValue(right.sent_at)));
    conversation.newestMessageId = toId(nextMessage.id);
  };

  /**
   * @param {string} matchId
   * @param {string} tempId
   * @returns {void}
   */
  const markMessageFailed = (matchId, tempId) => {
    const conversation = ensureConversationState(matchId);
    const message = conversation.messages.find((item) => toId(item.id) === tempId);
    if (message) {
      message.status = 'failed';
    }
    renderConversation(matchId);
  };

  /**
   * @param {HTMLElement} bubble
   * @returns {void}
   */
  const retryMessageSend = async (bubble) => {
    const matchId = toId(bubble.dataset.matchId);
    const messageId = toId(bubble.dataset.messageId);
    if (!matchId || !messageId) {
      return;
    }

    const conversation = ensureConversationState(matchId);
    const message = conversation.messages.find((item) => toId(item.id) === messageId);
    if (!message) {
      return;
    }

    message.status = 'sending';
    renderConversation(matchId);

    try {
      const apiFetch = getApiFetch();
      if (!apiFetch) {
        throw new Error('Messaging services are unavailable.');
      }

      const response = await apiFetch(MESSAGES_ENDPOINT, {
        method: 'POST',
        body: {
          match_id: matchId,
          body: textValue(message.body)
        }
      });

      const sentMessages = normaliseMessagesResponse(response);
      const sentMessage = sentMessages[0] ?? { ...message, status: 'sent' };
      upsertMessageResult(matchId, messageId, sentMessage);
      renderConversation(matchId);
    } catch (error) {
      message.status = 'failed';
      renderConversation(matchId);
      getToast()(error instanceof Error ? error.message : 'Retry failed.', 'error');
    }
  };

  /**
   * @param {HTMLElement} element
   * @returns {void}
   */
  const handleThreadScroll = (element) => {
    const matchId = getActiveMatchId();
    if (!matchId) {
      return;
    }

    const conversation = ensureConversationState(matchId);
    if (element.scrollTop < 120 && conversation.hasMoreOlder && !conversation.isLoadingOlder) {
      void loadOlderMessages(matchId);
    }

    syncReadState();
  };

  /**
   * @param {string} matchId
   * @returns {Promise<void>}
   */
  const loadOlderMessages = async (matchId) => {
    const conversation = ensureConversationState(matchId);
    if (conversation.isLoadingOlder || !conversation.hasMoreOlder) {
      return;
    }

    conversation.isLoadingOlder = true;
    const currentHeight = ensureThread().scrollHeight;
    const beforeId = conversation.messages.length > 0 ? toId(conversation.messages[0].id) : null;

    try {
      const response = await loadMessagesPage(matchId, conversation.oldestPage, beforeId, null);
      const olderMessages = normaliseMessagesResponse(response);
      const existingIds = new Set(conversation.messages.map((message) => toId(message.id)).filter(Boolean));
      const uniqueOlder = olderMessages.filter((message) => !existingIds.has(toId(message.id) ?? ''));
      conversation.blocked = extractBlocked(response);
      conversation.messages = [...uniqueOlder, ...conversation.messages].sort((left, right) => messageOrderValue(textValue(left.sent_at)) - messageOrderValue(textValue(right.sent_at)));
      conversation.hasMoreOlder = extractHasMore(response) || olderMessages.length === PAGE_SIZE;
      conversation.oldestPage += 1;
      renderConversation(matchId);
      ensureThread().scrollTop = Math.max(0, ensureThread().scrollHeight - currentHeight);
    } catch (error) {
      getToast()(error instanceof Error ? error.message : 'Could not load older messages.', 'error');
    } finally {
      conversation.isLoadingOlder = false;
    }
  };

  /**
   * @returns {void}
   */
  const initialiseThreadObserver = () => {
    const thread = ensureThread();
    const state = getState();

    if ('IntersectionObserver' in window) {
      state.readObserver = new IntersectionObserver((entries) => {
        for (const entry of entries) {
          if (entry.isIntersecting) {
            state.activeVisible = true;
            syncReadState();
          } else {
            state.activeVisible = false;
          }
        }
      }, {
        threshold: 0.2
      });

      state.readObserver.observe(thread);

      state.topObserver = new IntersectionObserver((entries) => {
        for (const entry of entries) {
          if (!entry.isIntersecting) {
            continue;
          }

          const matchId = getActiveMatchId();
          if (matchId) {
            void loadOlderMessages(matchId);
          }
        }
      }, {
        root: thread,
        threshold: 1
      });

      state.topObserver.observe(ensureTopSentinel());
    }

    thread.addEventListener('scroll', () => {
      handleThreadScroll(thread);
    });
  };

  /**
   * @returns {void}
   */
  const initialiseComposer = () => {
    const form = ensureComposer();
    const input = ensureMessageInput();
    const sendButton = ensureSendButton();

    form.addEventListener('submit', (event) => {
      event.preventDefault();
      void handleComposerSubmit(form);
    });

    input.addEventListener('keydown', (event) => {
      if (event.key === 'Enter' && !event.shiftKey) {
        event.preventDefault();
        void handleComposerSubmit(form);
      }
    });

    sendButton.addEventListener('click', () => {
      void handleComposerSubmit(form);
    });
  };

  /**
   * @returns {void}
   */
  const bindSidebarSelection = () => {
    const list = ensureMatchList();
    list.addEventListener('click', (event) => {
      const target = event.target instanceof HTMLElement ? event.target.closest('[data-match-id]') : null;
      if (!(target instanceof HTMLElement)) {
        return;
      }

      void selectMatch(toId(target.dataset.matchId));
    });
  };

  /**
   * @returns {Promise<void>}
   */
  const bootstrap = async () => {
    ensureChatApp();
    ensureMatchList();
    ensureThread();
    ensureComposer();
    ensureBlockedNotice();
    ensureStatus();
    ensureActiveMatchIdField();
    ensureEmptyState();
    ensureThreadList();
    ensureTopSentinel();
    initialiseComposer();
    initialiseThreadObserver();
    bindSidebarSelection();

    await refreshMatches();
    renderMatches();
    schedulePolling();

    const initialMatchId = new URLSearchParams(window.location.search).get('match_id');
    if (initialMatchId) {
      await selectMatch(initialMatchId);
      return;
    }

    const firstMatch = getState().matches[0];
    if (firstMatch) {
      await selectMatch(toId(firstMatch.id));
    } else {
      renderConversation(null);
    }
  };

  /**
   * @returns {void}
   */
  const init = () => {
    void bootstrap();
  };

  window[NAMESPACE_NAME] = window[NAMESPACE_NAME] ?? {};
  window[NAMESPACE_NAME].initChat = init;

  document.addEventListener('DOMContentLoaded', init);
})();
