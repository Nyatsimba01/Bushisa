'use strict';
// js/app.js

(function () {
  const BUSHISA_NAMESPACE = 'Bushisa';
  const TOAST_CONTAINER_ID = 'bushisa-toast-container';
  const DEFAULT_TOAST_HIDE_MS = 4000;
  const NAV_TOGGLE_SELECTORS = [
    '[data-nav-toggle]',
    '.nav-toggle',
    '.menu-toggle',
    '#navToggle',
    '#menuToggle'
  ];

  /**
   * @returns {Window['Bushisa']}
   */
  const getBushisaNamespace = () => {
    window[BUSHISA_NAMESPACE] = window[BUSHISA_NAMESPACE] ?? {};
    return window[BUSHISA_NAMESPACE];
  };

  /**
   * @returns {HTMLMetaElement | null}
   */
  const getCsrfMeta = () => document.querySelector('meta[name="csrf-token"]');

  /**
   * @returns {string}
   */
  const readCsrfToken = () => getCsrfMeta()?.content?.trim() ?? '';

  /**
   * @param {unknown} value
   * @returns {string}
   */
  const toSafeString = (value) => (value ?? '').toString();

  /**
   * @param {unknown} value
   * @returns {boolean}
   */
  const isJsonSerializableObject = (value) => {
    if (value === null || typeof value !== 'object') {
      return false;
    }

    return !(
      value instanceof FormData ||
      value instanceof Blob ||
      value instanceof ArrayBuffer ||
      value instanceof URLSearchParams
    );
  };

  /**
   * @param {string} message
   * @param {'success' | 'error' | 'info'} type
   * @returns {void}
   */
  const showToast = (message, type = 'info') => {
    const container = ensureToastContainer();
    const toast = document.createElement('div');
    toast.className = `bushisa-toast bushisa-toast--${type}`;
    toast.setAttribute('role', 'status');
    toast.setAttribute('aria-live', type === 'error' ? 'assertive' : 'polite');

    const text = document.createElement('div');
    text.className = 'bushisa-toast__message';
    text.textContent = message;

    const closeButton = document.createElement('button');
    closeButton.type = 'button';
    closeButton.className = 'bushisa-toast__close';
    closeButton.setAttribute('aria-label', 'Dismiss notification');
    closeButton.textContent = '×';

    let hideTimerId = window.setTimeout(() => hideToast(toast), DEFAULT_TOAST_HIDE_MS);

    /**
     * @returns {void}
     */
    const dismissToast = () => {
      window.clearTimeout(hideTimerId);
      hideToast(toast);
    };

    closeButton.addEventListener('click', dismissToast);

    toast.append(text, closeButton);
    container.appendChild(toast);

    requestAnimationFrame(() => {
      toast.classList.add('is-visible');
    });
  };

  /**
   * @returns {HTMLElement}
   */
  const ensureToastContainer = () => {
    const existingContainer = document.getElementById(TOAST_CONTAINER_ID);
    if (existingContainer) {
      return existingContainer;
    }

    const container = document.createElement('div');
    container.id = TOAST_CONTAINER_ID;
    container.className = 'bushisa-toast-container';
    container.setAttribute('aria-live', 'polite');
    container.setAttribute('aria-atomic', 'true');
    (document.body ?? document.documentElement).appendChild(container);
    return container;
  };

  /**
   * @param {HTMLElement} toast
   * @returns {void}
   */
  const hideToast = (toast) => {
    toast.classList.remove('is-visible');
    toast.classList.add('is-hiding');

    window.setTimeout(() => {
      toast.remove();
    }, 220);
  };

  /**
   * @param {Function} fn
   * @param {number} ms
   * @returns {Function}
   */
  const debounce = (fn, ms) => {
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
   * @param {string} value
   * @returns {string}
   */
  const sanitiseHTML = (value) => {
    const wrapper = document.createElement('div');
    wrapper.textContent = toSafeString(value);
    return wrapper.textContent ?? '';
  };

  /**
   * @param {string} isoDate
   * @returns {string}
   */
  const formatRelativeTime = (isoDate) => {
    const inputDate = new Date(isoDate);
    if (Number.isNaN(inputDate.getTime())) {
      return '';
    }

    const now = new Date();
    const diffMs = now.getTime() - inputDate.getTime();
    const diffMinutes = Math.floor(diffMs / 60000);
    const diffHours = Math.floor(diffMs / 3600000);
    const diffDays = Math.floor(diffMs / 86400000);

    if (diffMinutes < 1) {
      return 'Just now';
    }

    if (diffMinutes < 60) {
      return `${diffMinutes}m ago`;
    }

    if (diffHours < 24) {
      return `${diffHours}h ago`;
    }

    if (diffDays === 1) {
      return 'Yesterday';
    }

    if (diffDays < 7) {
      return `${diffDays}d ago`;
    }

    return inputDate.toLocaleDateString(undefined, {
      month: 'short',
      day: 'numeric',
      year: inputDate.getFullYear() !== now.getFullYear() ? 'numeric' : undefined
    });
  };

  /**
   * @param {string} endpoint
   * @param {RequestInit} [options={}]
   * @returns {Promise<unknown>}
   */
  const apiFetch = async (endpoint, options = {}) => {
    const url = new URL(endpoint, window.location.origin.endsWith('/') ? window.location.origin : `${window.location.origin}/`);
    const headers = new Headers(options.headers ?? {});
    const csrfToken = window.Bushisa?.csrfToken ?? readCsrfToken();
    const requestOptions = { ...options };

    if (isJsonSerializableObject(requestOptions.body)) {
      requestOptions.body = JSON.stringify(requestOptions.body);
      headers.set('Content-Type', 'application/json');
    }

    if (csrfToken) {
      headers.set('X-CSRF-Token', csrfToken);
    }

    try {
      const response = await window.fetch(url.toString(), {
        ...requestOptions,
        headers
      });

      if (response.status === 401) {
        window.location.href = 'login.php';
        throw new Error('Session expired. Please sign in again.');
      }

      const contentType = response.headers.get('content-type') ?? '';
      const payload = contentType.includes('application/json') ? await response.json() : null;

      if (!response.ok) {
        const message = payload?.error ?? 'Something went wrong. Please try again.';
        showToast(message, 'error');
        const responseError = new Error(message);
        responseError.handled = true;
        throw responseError;
      }

      return payload?.data ?? null;
    } catch (error) {
      const message = error instanceof Error ? error.message : 'Network error. Please try again.';

      if (!(error instanceof Error && error.handled) && !message.toLowerCase().includes('session expired')) {
        showToast(message, 'error');
      }

      throw error instanceof Error ? error : new Error(message);
    }
  };

  /**
   * @param {HTMLButtonElement | null} toggleButton
   * @returns {void}
   */
  const initialiseMobileNav = (toggleButton) => {
    if (!toggleButton) {
      return;
    }

    const controlledId = toggleButton.getAttribute('data-nav-target');
    const controlledMenu = controlledId ? document.getElementById(controlledId) : document.querySelector('[data-mobile-nav]');

    const syncState = () => {
      const isExpanded = toggleButton.getAttribute('aria-expanded') === 'true';
      toggleButton.setAttribute('aria-expanded', String(!isExpanded));
      if (controlledMenu) {
        controlledMenu.hidden = isExpanded;
        controlledMenu.classList.toggle('is-open', !isExpanded);
      }
    };

    toggleButton.setAttribute('aria-expanded', toggleButton.getAttribute('aria-expanded') ?? 'false');
    toggleButton.addEventListener('click', syncState);
  };

  /**
   * @returns {HTMLButtonElement | null}
   */
  const findNavToggleButton = () => {
    for (const selector of NAV_TOGGLE_SELECTORS) {
      const candidate = document.querySelector(selector);
      if (candidate instanceof HTMLButtonElement) {
        return candidate;
      }
    }

    return null;
  };

  /**
   * @returns {void}
   */
  const installGlobalFetchGuard = () => {
    const nativeFetch = window.fetch.bind(window);

    /**
     * @param {RequestInfo | URL} input
     * @param {RequestInit} [init]
     * @returns {Promise<Response>}
     */
    window.fetch = async (input, init) => {
      const response = await nativeFetch(input, init);
      if (response.status === 401) {
        window.location.href = 'login.php';
      }
      return response;
    };
  };

  /**
   * @returns {void}
   */
  const init = () => {
    const namespace = getBushisaNamespace();
    const csrfToken = readCsrfToken();

    namespace.apiFetch = apiFetch;
    namespace.showToast = showToast;
    namespace.debounce = debounce;
    namespace.sanitiseHTML = sanitiseHTML;
    namespace.formatRelativeTime = formatRelativeTime;
    namespace.csrfToken = csrfToken;
    namespace.CSRF_TOKEN = csrfToken;

    initialiseMobileNav(findNavToggleButton());
    installGlobalFetchGuard();
  };

  document.addEventListener('DOMContentLoaded', init);
})();