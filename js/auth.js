'use strict';
// js/auth.js

(function () {
  const NAMESPACE_NAME = 'Bushisa';
  const REQUEST_TIMEOUT_MS = 15000;
  const MIN_PASSWORD_LENGTH = 8;
  const MAX_PHOTO_SIZE_BYTES = 5 * 1024 * 1024;
  const NUST_EMAIL_SUFFIX = '@students.nust.ac.zw';
  const FACULTIES = [
    'Applied Sciences',
    'Built Environment',
    'Agriculture Science and Technology',
    'Business and Economic Sciences',
    'Comm and Information Science',
    'Engineering',
    'Environmental Science',
    'Medicine',
    'Science and Technology Education'
  ];
  const LOGIN_FORM_SELECTORS = [
    'form[data-auth-form="login"]',
    'form[data-form="login"]',
    '#loginForm',
    'form[action*="login.php"]',
    'form[action*="login"]'
  ];
  const REGISTER_FORM_SELECTORS = [
    'form[data-auth-form="register"]',
    'form[data-form="register"]',
    '#registerForm',
    'form[action*="register.php"]',
    'form[action*="register"]'
  ];
  const FORM_FIELD_SELECTORS = {
    email: ['[name="email"]', '#email', '[name="loginEmail"]', '#loginEmail'],
    password: ['[name="password"]', '#password', '[name="loginPassword"]', '#loginPassword'],
    confirmPassword: ['[name="confirm_password"]', '#confirmPassword', '[name="passwordConfirmation"]', '#passwordConfirmation'],
    displayName: ['[name="display_name"]', '#displayName', '[name="name"]', '#name'],
    dob: ['[name="date_of_birth"]', '#dateOfBirth', '[name="dob"]', '#dob'],
    gender: ['[name="gender"]', '#gender'],
    faculty: ['[name="faculty"]', '#faculty'],
    photo: ['[name="profile_photo"]', '#profilePhoto', '[name="profilePhoto"]', '#profilePhotoInput'],
    terms: ['[name="terms"]', '#terms', '[name="accept_terms"]', '#acceptTerms']
  };

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
   * @returns {(endpoint: string, options?: RequestInit) => Promise<unknown>}
   */
  const getApiFetch = () => {
    const namespace = getNamespace();
    return typeof namespace.apiFetch === 'function' ? namespace.apiFetch : null;
  };

  /**
   * @param {string[]} selectors
   * @returns {HTMLFormElement | null}
   */
  const findForm = (selectors) => {
    for (const selector of selectors) {
      const form = document.querySelector(selector);
      if (form instanceof HTMLFormElement) {
        return form;
      }
    }

    return null;
  };

  /**
   * @param {HTMLFormElement} form
   * @param {string[]} selectors
   * @returns {HTMLElement | null}
   */
  const findField = (form, selectors) => {
    for (const selector of selectors) {
      const field = form.querySelector(selector);
      if (field instanceof HTMLElement) {
        return field;
      }
    }

    return null;
  };

  /**
   * @param {HTMLElement} field
   * @returns {HTMLElement}
   */
  const getFieldContainer = (field) => field.closest('.form-group, .field, .input-group, label, .control, .form-control-wrapper') ?? field.parentElement ?? field;

  /**
   * @param {HTMLElement} field
   * @returns {string}
   */
  const getFieldKey = (field) => field.getAttribute('name') ?? field.id ?? 'field';

  /**
   * @param {HTMLElement} field
   * @returns {HTMLParagraphElement}
   */
  const ensureFieldErrorElement = (field) => {
    const errorId = `${getFieldKey(field)}-error`;
    const existing = document.getElementById(errorId);
    if (existing instanceof HTMLParagraphElement) {
      return existing;
    }

    const error = document.createElement('p');
    error.id = errorId;
    error.className = 'field-error';
    error.setAttribute('role', 'alert');
    error.hidden = true;
    return error;
  };

  /**
   * @param {HTMLElement} field
   * @returns {void}
   */
  const clearFieldError = (field) => {
    field.removeAttribute('aria-invalid');

    const describedBy = (field.getAttribute('aria-describedby') ?? '')
      .split(/\s+/)
      .filter((id) => id && !id.endsWith('-error'));

    if (describedBy.length > 0) {
      field.setAttribute('aria-describedby', describedBy.join(' '));
    } else {
      field.removeAttribute('aria-describedby');
    }

    const errorElement = document.getElementById(`${getFieldKey(field)}-error`);
    if (errorElement instanceof HTMLElement) {
      errorElement.textContent = '';
      errorElement.hidden = true;
    }
  };

  /**
   * @param {HTMLElement} field
   * @param {string} message
   * @returns {void}
   */
  const setFieldError = (field, message) => {
    const errorElement = ensureFieldErrorElement(field);
    const container = getFieldContainer(field);

    if (!errorElement.isConnected) {
      container.appendChild(errorElement);
    }

    errorElement.textContent = message;
    errorElement.hidden = false;
    field.setAttribute('aria-invalid', 'true');

    const errorId = errorElement.id;
    const describedBy = (field.getAttribute('aria-describedby') ?? '').split(/\s+/).filter(Boolean);
    if (!describedBy.includes(errorId)) {
      describedBy.push(errorId);
    }

    field.setAttribute('aria-describedby', describedBy.join(' '));
  };

  /**
   * @param {HTMLFormElement} form
   * @returns {void}
   */
  const clearFormErrors = (form) => {
    form.querySelectorAll('[aria-invalid="true"]').forEach((field) => {
      if (field instanceof HTMLElement) {
        clearFieldError(field);
      }
    });

    form.querySelectorAll('.field-error').forEach((error) => {
      if (error instanceof HTMLElement) {
        error.textContent = '';
        error.hidden = true;
      }
    });
  };

  /**
   * @param {HTMLFormElement} form
   * @returns {HTMLElement | null}
   */
  const getFirstInvalidField = (form) => form.querySelector('[aria-invalid="true"]');

  /**
   * @param {HTMLFormElement} form
   * @returns {HTMLButtonElement | HTMLInputElement | null}
   */
  const getSubmitControl = (form) => {
    const submit = form.querySelector('[type="submit"]');
    return submit instanceof HTMLButtonElement || submit instanceof HTMLInputElement ? submit : null;
  };

  /**
   * @param {HTMLButtonElement | HTMLInputElement | null} control
   * @param {boolean} isBusy
   * @returns {void}
   */
  const setControlBusy = (control, isBusy) => {
    if (!control) {
      return;
    }

    if (isBusy) {
      if (!control.dataset.originalText) {
        control.dataset.originalText = control.textContent ?? '';
      }

      control.disabled = true;
      control.textContent = 'Please wait...';
      return;
    }

    control.disabled = false;
    if (control.dataset.originalText !== undefined) {
      control.textContent = control.dataset.originalText;
      delete control.dataset.originalText;
    }
  };

  /**
   * @param {string} email
   * @returns {boolean}
   */
  const isValidEmail = (email) => /^[^\s@]+@[^\s@]+\.[^\s@]+$/.test(email.trim());

  /**
   * @param {string} email
   * @returns {boolean}
   */
  const isNustEmail = (email) => email.trim().toLowerCase().endsWith(NUST_EMAIL_SUFFIX);

  /**
   * @param {string} password
   * @returns {boolean}
   */
  const isStrongPassword = (password) => {
    const hasUppercase = /[A-Z]/.test(password);
    const hasDigit = /\d/.test(password);
    const hasSpecial = /[^A-Za-z0-9]/.test(password);
    return password.length >= MIN_PASSWORD_LENGTH && hasUppercase && hasDigit && hasSpecial;
  };

  /**
   * @param {string} value
   * @returns {boolean}
   */
  const isDisplayNameValid = (value) => /^[A-Za-z0-9 ]{2,30}$/.test(value.trim());

  /**
   * @param {string} value
   * @returns {boolean}
   */
  const isAdult = (value) => {
    const birthDate = new Date(value);
    if (Number.isNaN(birthDate.getTime())) {
      return false;
    }

    const today = new Date();
    let age = today.getFullYear() - birthDate.getFullYear();
    const monthDifference = today.getMonth() - birthDate.getMonth();

    if (monthDifference < 0 || (monthDifference === 0 && today.getDate() < birthDate.getDate())) {
      age -= 1;
    }

    return age >= 18;
  };

  /**
   * @param {HTMLFormElement} form
   * @returns {HTMLInputElement | null}
   */
  const getPasswordField = (form) => {
    const passwordField = findField(form, FORM_FIELD_SELECTORS.password);
    return passwordField instanceof HTMLInputElement ? passwordField : null;
  };

  /**
   * @param {HTMLFormElement} form
   * @returns {HTMLInputElement[]}
   */
  const getPasswordInputs = (form) => Array.from(form.querySelectorAll('input[type="password"]')).filter((field) => field instanceof HTMLInputElement);

  /**
   * @param {HTMLInputElement} input
   * @returns {void}
   */
  const togglePasswordVisibility = (input) => {
    const button = document.createElement('button');
    button.type = 'button';
    button.className = 'password-toggle';
    button.setAttribute('aria-pressed', 'false');
    button.setAttribute('aria-label', 'Show password');
    button.textContent = '👁';

    /**
     * @returns {void}
     */
    const updateState = () => {
      const isVisible = input.type === 'text';
      input.type = isVisible ? 'password' : 'text';
      button.setAttribute('aria-pressed', String(!isVisible));
      button.setAttribute('aria-label', isVisible ? 'Show password' : 'Hide password');
      button.textContent = isVisible ? '👁' : '🙈';
    };

    button.addEventListener('click', updateState);
    input.insertAdjacentElement('afterend', button);
  };

  /**
   * @param {HTMLFormElement} form
   * @returns {void}
   */
  const initialisePasswordVisibility = (form) => {
    getPasswordInputs(form).forEach((input) => {
      const nextElement = input.nextElementSibling;
      if (nextElement instanceof HTMLButtonElement && nextElement.classList.contains('password-toggle')) {
        return;
      }

      togglePasswordVisibility(input);
    });
  };

  /**
   * @param {HTMLSelectElement} select
   * @returns {void}
   */
  const populateFacultySelect = (select) => {
    const existingOptions = Array.from(select.options).filter((option) => option.value.trim().length > 0);
    if (existingOptions.length > 0) {
      return;
    }

    const placeholder = select.querySelector('option[value=""]');
    select.innerHTML = '';

    if (placeholder instanceof HTMLOptionElement) {
      select.appendChild(placeholder);
    } else {
      const defaultOption = document.createElement('option');
      defaultOption.value = '';
      defaultOption.textContent = 'Select faculty';
      select.appendChild(defaultOption);
    }

    for (const faculty of FACULTIES) {
      const option = document.createElement('option');
      option.value = faculty;
      option.textContent = faculty;
      select.appendChild(option);
    }
  };

  /**
   * @param {HTMLFormElement} form
   * @returns {void}
   */
  const initialiseFacultyField = (form) => {
    const facultyField = findField(form, FORM_FIELD_SELECTORS.faculty);
    if (facultyField instanceof HTMLSelectElement) {
      populateFacultySelect(facultyField);
    }
  };

  /**
   * @param {HTMLFormElement} form
   * @param {string} fieldName
   * @returns {HTMLElement | null}
   */
  const getFormField = (form, fieldName) => {
    const selectorList = FORM_FIELD_SELECTORS[fieldName] ?? [];
    return findField(form, selectorList);
  };

  /**
   * @param {HTMLFormElement} form
   * @returns {void}
   */
  const attachInputClearing = (form) => {
    const selector = 'input, select, textarea';
    form.querySelectorAll(selector).forEach((field) => {
      if (!(field instanceof HTMLElement)) {
        return;
      }

      const clearOnChange = () => clearFieldError(field);
      field.addEventListener('input', clearOnChange);
      field.addEventListener('change', clearOnChange);
    });
  };

  /**
   * @param {FormData | Record<string, string>} payload
   * @returns {Promise<unknown>}
   */
  const submitAuthRequest = async (payload) => {
    const apiFetch = getApiFetch();
    if (!apiFetch) {
      throw new Error('Authentication services are unavailable.');
    }

    const controller = new AbortController();
    const timeoutId = window.setTimeout(() => controller.abort(), REQUEST_TIMEOUT_MS);

    try {
      return await apiFetch('php/auth.php', {
        method: 'POST',
        body: payload,
        signal: controller.signal
      });
    } finally {
      window.clearTimeout(timeoutId);
    }
  };

  /**
   * @param {HTMLFormElement} form
   * @param {Record<string, string>} values
   * @returns {boolean}
   */
  const validateLoginValues = (form, values) => {
    let isValid = true;

    const emailField = getFormField(form, 'email');
    if (emailField instanceof HTMLElement) {
      clearFieldError(emailField);
      if (!values.email || !isValidEmail(values.email)) {
        setFieldError(emailField, 'Enter a valid email address.');
        isValid = false;
      }
    }

    const passwordField = getPasswordField(form);
    if (passwordField) {
      clearFieldError(passwordField);
      if (!values.password) {
        setFieldError(passwordField, 'Password is required.');
        isValid = false;
      }
    }

    return isValid;
  };

  /**
   * @param {HTMLFormElement} form
   * @param {Record<string, string>} values
   * @returns {boolean}
   */
  const validateRegisterValues = (form, values) => {
    let isValid = true;

    const emailField = getFormField(form, 'email');
    if (emailField instanceof HTMLElement) {
      clearFieldError(emailField);
      if (!values.email || !isValidEmail(values.email)) {
        setFieldError(emailField, 'Enter a valid email address.');
        isValid = false;
      } else if (!isNustEmail(values.email)) {
        setFieldError(emailField, 'Use your @students.nust.ac.zw email address.');
        isValid = false;
      }
    }

    const passwordField = getPasswordField(form);
    if (passwordField) {
      clearFieldError(passwordField);
      if (!values.password) {
        setFieldError(passwordField, 'Password is required.');
        isValid = false;
      } else if (!isStrongPassword(values.password)) {
        setFieldError(passwordField, 'Use 8+ chars with 1 uppercase letter, 1 digit, and 1 special character.');
        isValid = false;
      }
    }

    const confirmPasswordField = getFormField(form, 'confirmPassword');
    if (confirmPasswordField instanceof HTMLElement) {
      clearFieldError(confirmPasswordField);
      if (!values.confirmPassword) {
        setFieldError(confirmPasswordField, 'Please confirm your password.');
        isValid = false;
      } else if (values.confirmPassword !== values.password) {
        setFieldError(confirmPasswordField, 'Passwords do not match.');
        isValid = false;
      }
    }

    const displayNameField = getFormField(form, 'displayName');
    if (displayNameField instanceof HTMLElement) {
      clearFieldError(displayNameField);
      if (!values.displayName) {
        setFieldError(displayNameField, 'Display name is required.');
        isValid = false;
      } else if (!isDisplayNameValid(values.displayName)) {
        setFieldError(displayNameField, 'Use 2–30 characters: letters, numbers, and spaces only.');
        isValid = false;
      }
    }

    const dobField = getFormField(form, 'dob');
    if (dobField instanceof HTMLElement) {
      clearFieldError(dobField);
      if (!values.dob) {
        setFieldError(dobField, 'Date of birth is required.');
        isValid = false;
      } else if (!isAdult(values.dob)) {
        setFieldError(dobField, 'You must be at least 18 years old.');
        isValid = false;
      }
    }

    const genderField = getFormField(form, 'gender');
    if (genderField instanceof HTMLElement) {
      clearFieldError(genderField);
      if (!values.gender) {
        setFieldError(genderField, 'Select your gender.');
        isValid = false;
      }
    }

    const facultyField = getFormField(form, 'faculty');
    if (facultyField instanceof HTMLElement) {
      clearFieldError(facultyField);
      if (!values.faculty) {
        setFieldError(facultyField, 'Select your faculty.');
        isValid = false;
      }
    }

    const photoField = getFormField(form, 'photo');
    if (photoField instanceof HTMLInputElement && photoField.files && photoField.files.length > 0) {
      clearFieldError(photoField);
      const photoFile = photoField.files[0];
      const validMimeTypes = ['image/jpeg', 'image/png', 'image/webp'];
      if (!validMimeTypes.includes(photoFile.type)) {
        setFieldError(photoField, 'Upload a JPEG, PNG, or WebP image.');
        isValid = false;
      } else if (photoFile.size > MAX_PHOTO_SIZE_BYTES) {
        setFieldError(photoField, 'Profile photo must be 5 MB or smaller.');
        isValid = false;
      }
    }

    const termsField = getFormField(form, 'terms');
    if (termsField instanceof HTMLInputElement) {
      clearFieldError(termsField);
      if (!termsField.checked) {
        setFieldError(termsField, 'You must accept the Terms of Service.');
        isValid = false;
      }
    }

    return isValid;
  };

  /**
   * @param {HTMLFormElement} form
   * @returns {{ email: string, password: string }}
   */
  const readLoginValues = (form) => ({
    email: (getFormField(form, 'email') instanceof HTMLInputElement ? getFormField(form, 'email') : null)?.value.trim() ?? '',
    password: (getPasswordField(form)?.value ?? '').trim()
  });

  /**
   * @param {HTMLFormElement} form
   * @returns {{ [key: string]: string }}
   */
  const readRegisterValues = (form) => ({
    email: (getFormField(form, 'email') instanceof HTMLInputElement ? getFormField(form, 'email') : null)?.value.trim() ?? '',
    password: (getPasswordField(form)?.value ?? ''),
    confirmPassword: (getFormField(form, 'confirmPassword') instanceof HTMLInputElement ? getFormField(form, 'confirmPassword') : null)?.value ?? '',
    displayName: (getFormField(form, 'displayName') instanceof HTMLInputElement ? getFormField(form, 'displayName') : null)?.value.trim() ?? '',
    dob: (getFormField(form, 'dob') instanceof HTMLInputElement ? getFormField(form, 'dob') : null)?.value ?? '',
    gender: (getFormField(form, 'gender') instanceof HTMLSelectElement ? getFormField(form, 'gender') : null)?.value ?? '',
    faculty: (getFormField(form, 'faculty') instanceof HTMLSelectElement ? getFormField(form, 'faculty') : null)?.value ?? ''
  });

  /**
   * @param {HTMLFormElement} form
   * @returns {FormData}
   */
  const buildRegisterFormData = (form) => {
    const formData = new FormData();
    const values = readRegisterValues(form);

    formData.append('action', 'register');
    formData.append('email', values.email);
    formData.append('password', values.password);
    formData.append('confirmPassword', values.confirmPassword);
    formData.append('confirm_password', values.confirmPassword);
    formData.append('display_name', values.displayName);
    formData.append('displayName', values.displayName);
    formData.append('date_of_birth', values.dob);
    formData.append('dob', values.dob);
    formData.append('gender', values.gender);
    formData.append('faculty', values.faculty);

    const photoField = getFormField(form, 'photo');
    if (photoField instanceof HTMLInputElement && photoField.files && photoField.files[0]) {
      formData.append('profile_photo', photoField.files[0]);
    }

    const termsField = getFormField(form, 'terms');
    if (termsField instanceof HTMLInputElement) {
      formData.append('terms', termsField.checked ? '1' : '0');
    }

    return formData;
  };

  /**
   * @param {HTMLFormElement} form
   * @returns {Promise<void>}
   */
  const handleLoginSubmit = async (form) => {
    const values = readLoginValues(form);
    const submitControl = getSubmitControl(form);
    const toast = getToast();

    clearFormErrors(form);

    if (!validateLoginValues(form, values)) {
      const firstInvalidField = getFirstInvalidField(form);
      if (firstInvalidField instanceof HTMLElement) {
        firstInvalidField.focus();
      }
      return;
    }

    setControlBusy(submitControl, true);

    try {
      await submitAuthRequest({ action: 'login', ...values });
      window.location.href = 'discover.php';
    } catch (error) {
      const message = error instanceof Error ? error.message : 'Unable to sign in right now.';
      if (!(error instanceof Error && error.handled) && message && !message.toLowerCase().includes('session expired')) {
        toast(message, 'error');
      }
    } finally {
      setControlBusy(submitControl, false);
    }
  };

  /**
   * @param {HTMLFormElement} form
   * @returns {Promise<void>}
   */
  const handleRegisterSubmit = async (form) => {
    const values = readRegisterValues(form);
    const submitControl = getSubmitControl(form);
    const toast = getToast();
    const sanitiseHTML = getSanitiser();

    clearFormErrors(form);

    if (!validateRegisterValues(form, values)) {
      const firstInvalidField = getFirstInvalidField(form);
      if (firstInvalidField instanceof HTMLElement) {
        firstInvalidField.focus();
      }
      return;
    }

    setControlBusy(submitControl, true);

    try {
      const responseData = await submitAuthRequest(buildRegisterFormData(form));
      const message = responseData?.message ? sanitiseHTML(String(responseData.message)) : 'Registration successful.';
      toast(message, 'success');
      window.location.href = responseData?.redirectTo ?? 'login.php';
    } catch (error) {
      const message = error instanceof Error ? error.message : 'Unable to register right now.';
      if (!(error instanceof Error && error.handled) && message && !message.toLowerCase().includes('session expired')) {
        toast(message, 'error');
      }
    } finally {
      setControlBusy(submitControl, false);
    }
  };

  /**
   * @param {HTMLFormElement} form
   * @returns {void}
   */
  const initialiseLoginForm = (form) => {
    initialisePasswordVisibility(form);
    attachInputClearing(form);

    form.addEventListener('submit', (event) => {
      event.preventDefault();
      void handleLoginSubmit(form);
    });
  };

  /**
   * @param {HTMLFormElement} form
   * @returns {void}
   */
  const initialiseRegisterForm = (form) => {
    initialisePasswordVisibility(form);
    initialiseFacultyField(form);
    attachInputClearing(form);

    form.addEventListener('submit', (event) => {
      event.preventDefault();
      void handleRegisterSubmit(form);
    });
  };

  /**
   * @returns {void}
   */
  const init = () => {
    const loginForm = findForm(LOGIN_FORM_SELECTORS);
    const registerForm = findForm(REGISTER_FORM_SELECTORS);

    if (loginForm) {
      initialiseLoginForm(loginForm);
    }

    if (registerForm) {
      initialiseRegisterForm(registerForm);
    }
  };

  document.addEventListener('DOMContentLoaded', init);
})();
