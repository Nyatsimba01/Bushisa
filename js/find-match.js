'use strict';

/* Four-step Figma Find Match questionnaire.
   Save each step to the existing authenticated PHP endpoint. This does not
   fetch or expose matches until verification and eligibility are implemented. */
(() => {
  const root = document.querySelector('[data-find-match]');
  if (!root) return;

  const groups = Array.from(root.querySelectorAll('[data-step]'));
  const progress = Array.from(root.querySelectorAll('[data-progress]'));
  const progressLabel = root.querySelector('[data-progress-label]');
  const back = root.querySelector('[data-match-back]');
  const next = root.querySelector('[data-match-next]');
  const selected = root.querySelector('[data-selected]');
  const error = root.querySelector('[data-match-error]');
  const complete = root.querySelector('[data-match-complete]');
  const actions = root.querySelector('[data-match-actions]');
  const labels = ['Your intentions', 'Your vibe', 'Shared interests', 'Preferences'];
  const token = document.querySelector('meta[name="csrf-token"]')?.getAttribute('content') || '';
  let step = 0;
  let saving = false;

  function activeInputs() {
    return Array.from(groups[step]?.querySelectorAll('input[data-answer-key]') || []);
  }

  function readStep() {
    const answers = {};
    const entries = new Map();
    for (const input of activeInputs()) {
      if (!entries.has(input.dataset.answerKey)) entries.set(input.dataset.answerKey, []);
      if (input.checked) entries.get(input.dataset.answerKey).push(input.value);
    }
    for (const [key, values] of entries) {
      if (!values.length) return null;
      answers[key] = values.join(', ');
    }
    return answers;
  }

  function updateSelected() {
    if (!selected) return;
    const input = groups[step]?.querySelector('input[data-answer-key="intent"]:checked');
    selected.textContent = step === 0 && input ? 'Selected: ' + input.value : '';
  }

  function display() {
    groups.forEach((group, i) => { group.hidden = i !== step; });
    if (complete) complete.hidden = true;
    if (actions) actions.hidden = false;
    if (progressLabel) progressLabel.textContent = 'Step ' + (step + 1) + ' of 4 · ' + labels[step];
    progress.forEach((item, i) => {
      item.classList.toggle('is-current', i === step);
      item.classList.toggle('is-done', i < step);
      if (i === step) item.setAttribute('aria-current', 'step');
      else item.removeAttribute('aria-current');
    });
    if (back) back.hidden = step === 0;
    if (next) next.textContent = step === 3 ? 'Save my answers  →' : 'Continue  →';
    if (error) error.textContent = '';
    updateSelected();
  }

  function showComplete() {
    groups.forEach(group => { group.hidden = true; });
    if (complete) complete.hidden = false;
    if (actions) actions.hidden = true;
    if (progressLabel) progressLabel.textContent = 'Your answers are saved';
    progress.forEach(item => { item.classList.add('is-done'); item.classList.remove('is-current'); item.removeAttribute('aria-current'); });
    document.querySelector('[data-match-flow-title]')?.scrollIntoView({ behavior: 'auto', block: 'start' });
  }

  groups.forEach(group => group.addEventListener('change', updateSelected));
  back?.addEventListener('click', () => {
    if (saving || step === 0) return;
    step -= 1;
    display();
  });
  next?.addEventListener('click', async () => {
    if (saving) return;
    const answers = readStep();
    if (!answers) {
      if (error) error.textContent = step === 2
        ? 'Choose at least one interest to continue.'
        : 'Choose an answer for each question to continue.';
      return;
    }
    const tooLong = Object.values(answers).some(value => value.length > 120);
    if (tooLong) {
      if (error) error.textContent = 'Please choose fewer interests (120 characters maximum).';
      return;
    }
    saving = true;
    if (next) { next.disabled = true; next.textContent = 'Saving…'; }
    if (error) error.textContent = '';
    try {
      const response = await fetch('api/find_match.php', {
        method: 'POST',
        credentials: 'same-origin',
        headers: { 'Content-Type': 'application/json', 'X-CSRF-Token': token },
        body: JSON.stringify({ answers })
      });
      const payload = await response.json();
      if (!response.ok || payload.success !== true) {
        throw new Error(typeof payload.error === 'string' ? payload.error : 'Unable to save your answers.');
      }
      if (step === groups.length - 1) showComplete();
      else {
        step += 1;
        display();
        document.querySelector('[data-match-flow-title]')?.scrollIntoView({ behavior: 'smooth', block: 'start' });
      }
    } catch (exception) {
      if (error) error.textContent = exception instanceof Error ? exception.message : 'Unable to save your answers right now.';
    } finally {
      saving = false;
      if (next) next.disabled = false;
      if (complete?.hidden !== false) displayActionText();
    }
  });
  function displayActionText() {
    if (next) next.textContent = step === 3 ? 'Save my answers  →' : 'Continue  →';
  }

  root.querySelector('[data-match-edit]')?.addEventListener('click', () => {
    step = 0;
    display();
  });

  display();
})();
