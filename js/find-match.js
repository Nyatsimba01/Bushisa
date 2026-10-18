'use strict';

/* Four phases, one question on screen. Keep the existing inputs, answer keys,
   authenticated save endpoint and completed-questionnaire destination. */
(() => {
  const root = document.querySelector('[data-find-match]');
  if (!root) return;
  const groups = Array.from(root.querySelectorAll('.fm-step[data-step]'));
  const questionnaire = root.querySelector('.fm-questionnaire');
  const next = root.querySelector('[data-match-next]');
  const back = root.querySelector('[data-match-back]');
  const actions = root.querySelector('[data-match-actions]');
  if (!groups.length || !questionnaire || !next || !back || !actions) return;

  // Load only the new flow styles; retain the page's existing design styles.
  const stylesheet = document.createElement('link');
  stylesheet.rel = 'stylesheet';
  const scriptUrl = document.currentScript?.src;
  const styleUrl = scriptUrl ? new URL('../css/find-match-flow.css', scriptUrl) : new URL('css/find-match-flow.css', document.baseURI);
  if (scriptUrl) styleUrl.search = new URL(scriptUrl).search;
  stylesheet.href = styleUrl.href;
  document.head.appendChild(stylesheet);

  const allInputs = Array.from(root.querySelectorAll('input[data-answer-key]'));
  const conditional = Array.from(root.querySelectorAll('[data-conditional]'));
  const progress = Array.from(root.querySelectorAll('[data-progress]'));
  const progressLabel = root.querySelector('[data-progress-label]');
  const error = root.querySelector('[data-match-error]');
  const token = document.querySelector('meta[name="csrf-token"]')?.content || '';
  const labels = ['Your intentions', 'Your vibe', 'Shared interests', 'Preferences'];
  const conditions = new Map();
  const ancestry = new Map();
  // Remember semantic branch ancestry before moving a field into its own screen.
  // Presentation-hidden questions still contribute their answers when saving.
  for (const input of allInputs) {
    const parents = [];
    for (let node = input.parentElement; node && node !== root; node = node.parentElement) {
      if (node.hasAttribute('data-conditional')) parents.unshift(node);
    }
    ancestry.set(input, parents);
  }

  let sequence = 0;
  const questions = [];
  const trail = [];
  let current = null;
  let saving = false;

  function makeQuestion(group, key, inputs, heading, field, optional, theme) {
    const wrapper = document.createElement('div');
    wrapper.className = 'fm-flow-question' + (optional ? ' fm-flow-question--optional' : '');
    wrapper.dataset.flowQuestion = key;
    wrapper.hidden = true;
    const h2 = heading.querySelector('h2');
    if (!h2.id) h2.id = 'fm-flow-title-' + (++sequence);
    h2.tabIndex = -1;
    wrapper.setAttribute('aria-labelledby', h2.id);
    if (optional) {
      const kicker = document.createElement('p');
      kicker.className = 'fm-flow-kicker';
      kicker.textContent = theme + ' · Optional';
      wrapper.appendChild(kicker);
    }
    wrapper.append(heading, field);
    group.appendChild(wrapper);
    const question = { group, step: groups.indexOf(group), key, inputs, wrapper, h2, optional };
    questions.push(question);
    return question;
  }

  // Take snapshots first: optional fields include nested sports/team questions.
  const extras = [];
  for (const group of groups) {
    const keys = new Set();
    for (const input of group.querySelectorAll('input[data-optional-answer]')) {
      const key = input.dataset.answerKey;
      if (keys.has(key)) continue;
      keys.add(key);
      const inputs = allInputs.filter(item => item.dataset.answerKey === key && group.contains(item));
      const field = input.closest('fieldset') || input;
      const title = input.type === 'text'
        ? (input.labels?.[0]?.textContent || 'Anything else you would like to share?')
        : (field.previousElementSibling?.textContent || field.getAttribute('aria-label') || 'A little more about you');
      const theme = input.closest('details')?.querySelector('summary')?.textContent.replace(/\s*Optional\s*/i, '').trim() || labels[groups.indexOf(group)];
      extras.push({ group, key, inputs, field, title, theme });
    }
  }
  for (const group of groups) {
    const fields = Array.from(group.children).filter(child => child.matches('fieldset.fm-choices'));
    for (const field of fields) {
      const heading = field.previousElementSibling;
      if (!heading?.matches('.fm-question')) continue;
      const inputs = Array.from(field.querySelectorAll('input[data-answer-key]'));
      makeQuestion(group, inputs[0].dataset.answerKey, inputs, heading, field, false, '');
    }
  }
  for (const { group, key, inputs, field, title, theme } of extras) {
    const heading = document.createElement('div');
    heading.className = 'fm-question';
    const h2 = document.createElement('h2');
    h2.textContent = title.trim();
    const help = document.createElement('p');
    help.textContent = inputs[0].type === 'checkbox' ? 'Choose any that fit, or skip this question.'
      : inputs[0].type === 'text' ? 'Local or international. You can leave this blank.'
        : 'Choose what feels right, or skip this question.';
    heading.append(h2, help);
    makeQuestion(group, key, inputs, heading, field, true, theme);
    if (field === inputs[0]) field.setAttribute('aria-labelledby', h2.id);
  }
  // Preserve original question order inside each phase: core questions, then extras.
  questions.sort((left, right) => left.step - right.step || Number(left.optional) - Number(right.optional));
  conditional.forEach(section => { section.hidden = true; });
  groups.forEach(group => {
    Array.from(group.children).filter(child => child.matches('.fm-optional-help')).forEach(child => { child.hidden = true; });
  });

  const detailProgress = document.createElement('p');
  detailProgress.className = 'fm-flow-position';
  detailProgress.setAttribute('aria-live', 'polite');
  progressLabel?.after(detailProgress);
  const skip = document.createElement('button');
  skip.type = 'button';
  skip.className = 'fm-back fm-flow-skip';
  skip.textContent = 'Skip question';
  skip.dataset.matchSkip = '';
  next.before(skip);
  const skipRest = document.createElement('button');
  skipRest.type = 'button';
  skipRest.className = 'fm-flow-skip-rest';
  skipRest.textContent = 'Skip remaining extras';
  skipRest.dataset.matchSkipRest = '';
  questionnaire.insertBefore(skipRest, actions.nextSibling);
  const saveNote = document.createElement('p');
  saveNote.className = 'fm-flow-save-note';
  saveNote.textContent = 'Answers save when you finish each phase. You can review them later.';
  skipRest.after(saveNote);
  const selected = root.querySelector('[data-selected]');
  if (selected) { selected.textContent = ''; selected.hidden = true; }
  root.classList.add('fm-question-flow');

  function relevant(input) {
    return ancestry.get(input).every(section => conditions.get(section) === true);
  }
  function updateConditions() {
    for (const section of conditional) {
      const values = (section.dataset.whenValues || '').split('|');
      conditions.set(section, allInputs.some(input => input.dataset.answerKey === section.dataset.whenKey && input.checked && values.includes(input.value) && relevant(input)));
    }
  }
  function eligible(question) {
    return !question.optional || question.inputs.some(relevant);
  }
  function phaseQuestions(step) {
    return questions.filter(question => question.step === step && eligible(question));
  }
  function values(question) {
    return question.inputs.filter(input => relevant(input) && ((input.type === 'radio' || input.type === 'checkbox') ? input.checked : input.value.trim() !== ''))
      .map(input => input.type === 'radio' || input.type === 'checkbox' ? input.value : input.value.trim()).join(', ');
  }
  function message(text) {
    if (error) error.textContent = text;
  }
  function validate(question) {
    const value = values(question);
    if (!question.optional && !value) {
      message(question.key === 'interests' ? 'Choose at least one interest to continue.' : 'Choose an answer to continue.');
      return false;
    }
    if (Array.from(value).length > 120) {
      message('Please choose fewer options (120 characters maximum).');
      return false;
    }
    return true;
  }
  function focusQuestion() {
    current.h2.focus({ preventScroll: true });
    const position = questionnaire.getBoundingClientRect().top;
    // Keep the questionnaire in view; never jump back to the page introduction.
    if (position < 12 || position > window.innerHeight / 3) questionnaire.scrollIntoView({ block: 'start', behavior: 'auto' });
  }
  function display(focus = false) {
    updateConditions();
    const queue = phaseQuestions(current.step);
    const phase = labels[current.step];
    const core = queue.filter(question => !question.optional);
    const extras = queue.filter(question => question.optional);
    groups.forEach(group => { group.hidden = group !== current.group; });
    questions.forEach(question => { question.wrapper.hidden = question !== current; });
    questionnaire.setAttribute('aria-labelledby', current.h2.id);
    root.querySelector('[data-match-complete]')?.setAttribute('hidden', '');
    if (progressLabel) progressLabel.textContent = 'Step ' + (current.step + 1) + ' of 4 · ' + phase;
    detailProgress.textContent = current.optional
      ? 'Optional question ' + (extras.indexOf(current) + 1) + ' of ' + extras.length + ' · Skip any extra'
      : core.length > 1 ? 'Question ' + (core.indexOf(current) + 1) + ' of ' + core.length : '';
    detailProgress.hidden = detailProgress.textContent === '';
    progress.forEach((item, index) => {
      item.classList.toggle('is-current', index === current.step);
      item.classList.toggle('is-done', index < current.step);
      if (index === current.step) item.setAttribute('aria-current', 'step');
      else item.removeAttribute('aria-current');
    });
    back.hidden = trail.length === 0;
    skip.hidden = !current.optional;
    skipRest.hidden = !current.optional || extras.indexOf(current) === extras.length - 1;
    const last = queue.indexOf(current) === queue.length - 1;
    next.textContent = saving ? 'Saving…' : !current.optional ? 'Continue →' : last
      ? current.step === groups.length - 1 ? 'Save my answers →' : 'Next: ' + labels[current.step + 1] + ' →'
      : 'Next question →';
    if (focus) focusQuestion();
  }
  function visit(question) {
    if (current) trail.push(current);
    current = question;
    message('');
    display(true);
  }
  function clear(question) {
    question.inputs.forEach(input => {
      if (input.type === 'radio' || input.type === 'checkbox') input.checked = false;
      else input.value = '';
    });
  }
  function setSaving(value) {
    saving = value;
    root.setAttribute('aria-busy', String(value));
    allInputs.forEach(input => { input.disabled = value; });
    [next, back, skip, skipRest].forEach(button => { button.disabled = value; });
  }
  async function advance(finishPhase = false) {
    updateConditions();
    const queue = phaseQuestions(current.step);
    const index = queue.indexOf(current);
    if (!finishPhase && index < queue.length - 1) { visit(queue[index + 1]); return; }
    // Validate the complete phase even after a branch or an earlier answer changed.
    if (queue.some(question => !validate(question))) return;
    const answers = {};
    for (const question of questions.filter(item => item.step === current.step)) answers[question.key] = eligible(question) ? values(question) : '';
    setSaving(true);
    display();
    message('');
    try {
      const response = await fetch('api/find_match.php', {
        method: 'POST', credentials: 'same-origin',
        headers: { 'Content-Type': 'application/json', 'X-CSRF-Token': token },
        body: JSON.stringify({ answers })
      });
      const payload = await response.json();
      if (!response.ok || payload.success !== true) throw new Error(typeof payload.error === 'string' ? payload.error : 'Unable to save your answers.');
      if (current.step === groups.length - 1) window.location.assign('matches.php');
      else visit(phaseQuestions(current.step + 1)[0]);
    } catch (exception) {
      message(exception instanceof Error ? exception.message : 'Unable to save your answers right now. Please try again.');
    } finally {
      setSaving(false);
      display();
    }
  }
  root.addEventListener('change', event => {
    if (saving || !event.target.matches('input[data-answer-key]')) return;
    updateConditions();
    message('');
    display();
  });
  back.addEventListener('click', () => {
    if (saving) return;
    updateConditions();
    let previous;
    while (trail.length && !previous) {
      const candidate = trail.pop();
      if (eligible(candidate)) previous = candidate;
    }
    if (previous) { current = previous; message(''); display(true); }
  });
  next.addEventListener('click', () => {
    if (!saving && validate(current)) advance();
  });
  skip.addEventListener('click', () => {
    if (saving || !current.optional) return;
    clear(current);
    message('');
    advance();
  });
  skipRest.addEventListener('click', () => {
    if (saving || !current.optional) return;
    const queue = phaseQuestions(current.step);
    const remaining = queue.slice(queue.indexOf(current));
    remaining.forEach(clear);
    advance(true);
  });
  current = questions[0];
  display();
})();
