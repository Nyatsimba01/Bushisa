'use strict';

/* Inline optional questions for the four-step Find Match questionnaire.
   Reuse the REAL answer inputs (and the existing save/CSRF path in find-match.js).
   No bottom-floating prompt, detached dialog, copied answers or new API calls. */
(() => {
  const root = document.querySelector('[data-find-match]');
  if (!root) return;

  const steps = Array.from(root.querySelectorAll('.fm-step[data-step]'));
  const next = root.querySelector('[data-match-next]');
  const actions = root.querySelector('[data-match-actions]');
  const error = root.querySelector('[data-match-error]');
  if (!steps.length || !next || !actions) return;

  const radioGroups = steps.flatMap(step =>
    Array.from(step.querySelectorAll('fieldset.fm-choices')).filter(fieldset => {
      const inputs = Array.from(fieldset.querySelectorAll('input[data-answer-key]'));
      return inputs.length > 0 && inputs.every(input => input.type === 'radio');
    })
  );
  const changeButtons = new Map();

  // The optional section belongs immediately after the answer that revealed it.
  // Step 1 previously placed both extras after all required questions.
  steps.forEach(step => {
    for (const section of Array.from(step.querySelectorAll(':scope > details.fm-followups[data-conditional]'))) {
      const key = section.dataset.whenKey;
      if (!['intent', 'social_activity', 'communication_style', 'availability'].includes(key)) continue;
      const fieldset = Array.from(step.querySelectorAll(':scope > fieldset.fm-choices'))
        .find(field => field.querySelector('input[data-answer-key="' + key + '"]'));
      if (fieldset) fieldset.after(section);
    }
  });

  function activeStep() {
    return steps.find(group => !group.hidden) || null;
  }

  function hasCoreAnswers(step) {
    const required = new Map();
    for (const input of step.querySelectorAll('input[data-answer-key]:not([data-optional-answer])')) {
      const key = input.dataset.answerKey;
      if (!required.has(key)) required.set(key, false);
      if (input.checked) required.set(key, true);
    }
    return required.size > 0 && Array.from(required.values()).every(Boolean);
  }

  // Keep the selected card, hide only its siblings, and provide an easy way
  // to change the choice. Never collapse checkbox/multi-select interests.
  radioGroups.forEach(fieldset => {
    const change = document.createElement('button');
    change.type = 'button';
    change.className = 'fm-change-answer';
    change.textContent = 'Change answer';
    change.setAttribute('aria-label', 'Change ' + (fieldset.getAttribute('aria-label') || 'selection'));
    change.hidden = true;
    fieldset.after(change);
    changeButtons.set(fieldset, change);
    change.addEventListener('click', () => {
      fieldset.dataset.showChoices = 'true';
      refreshRadioGroup(fieldset);
      const alternative = Array.from(fieldset.querySelectorAll('input[type="radio"]'))
        .find(input => !input.checked);
      (alternative || fieldset.querySelector('input[type="radio"]'))?.focus();
    });
  });

  function refreshRadioGroup(fieldset) {
    const chosen = fieldset.querySelector('input[type="radio"]:checked');
    const collapsed = !!chosen && fieldset.dataset.showChoices !== 'true';
    fieldset.classList.toggle('fm-choice-condensed', collapsed);
    for (const label of fieldset.querySelectorAll(':scope > label.fm-option')) {
      const input = label.querySelector('input[type="radio"]');
      label.hidden = collapsed && !!input && !input.checked;
    }
    const change = changeButtons.get(fieldset);
    if (change) change.hidden = !collapsed;
  }

  const skip = document.createElement('button');
  skip.type = 'button';
  skip.className = 'fm-inline-skip';
  skip.textContent = 'Skip optional questions →';
  skip.hidden = true;
  const actionsRight = actions.querySelector('.fm-actions-right');
  if (actionsRight) actionsRight.prepend(skip);

  // Give each inline optional card a shortcut at its top, not at the very
  // bottom of the mobile screen. Skip simply advances once core answers exist.
  for (const section of root.querySelectorAll('details.fm-followups[data-conditional]')) {
    const body = section.querySelector('.fm-followup-body');
    if (!body) continue;
    const prompt = document.createElement('div');
    prompt.className = 'fm-inline-prompt';
    prompt.innerHTML = '<span>✨ A little more about you <small>Optional — answer one, both, or neither.</small></span>';
    const earlySkip = document.createElement('button');
    earlySkip.type = 'button';
    earlySkip.className = 'fm-inline-early-skip';
    earlySkip.textContent = 'Skip →';
    earlySkip.addEventListener('click', () => skipExtras(section));
    prompt.appendChild(earlySkip);
    body.prepend(prompt);
  }

  function skipExtras(section) {
    const step = activeStep();
    if (!step) return;
    if (hasCoreAnswers(step)) {
      next.click(); // Existing validation and authenticated persistence.
      return;
    }
    section.open = false;
    section.dataset.skipped = 'true';
    const required = radioGroups.find(fieldset =>
      step.contains(fieldset) &&
      !!fieldset.querySelector('input[data-answer-key]:not([data-optional-answer])') &&
      !fieldset.querySelector('input:checked')
    );
    if (required) {
      required.scrollIntoView({block:'center', behavior:'smooth'});
      required.querySelector('input')?.focus({preventScroll:true});
    }
  }

  skip.addEventListener('click', () => next.click());

  // The same existing Back / Continue buttons now sit at the end of the
  // visible step rather than being stranded after every hidden step.
  let previousStep = null;
  function sync(changedInput = null) {
    const step = activeStep();
    for (const fieldset of radioGroups) refreshRadioGroup(fieldset);
    if (!step) return;
    const isNewStep = previousStep !== step;
    previousStep = step;

    if (actions.parentNode !== step) {
      if (error) step.appendChild(error);
      step.appendChild(actions);
    }

    const visibleSections = Array.from(step.querySelectorAll('details.fm-followups[data-conditional]'))
      .filter(section => !section.hidden && !section.closest('details.fm-followups[hidden]'));
    skip.hidden = visibleSections.length === 0;

    if (changedInput) {
      const changedKey = changedInput.dataset.answerKey;
      // A changed primary answer invites the new relevant question in-place.
      visibleSections.forEach(section => {
        if (section.dataset.whenKey === changedKey ||
            (changedKey === 'sports' && section.dataset.whenKey === 'sports')) {
          section.open = true;
          delete section.dataset.skipped;
        }
      });
    } else if (isNewStep) {
      // Prefilled answers: show the relevant extras inline when revisiting.
      // Multiple interests stay individually expandable to avoid scroll fatigue.
      if (step.dataset.step === '2') {
        const first = visibleSections.find(section => !section.dataset.skipped);
        if (first) first.open = true;
      } else {
        visibleSections.forEach(section => {
          if (!section.dataset.skipped) section.open = true;
        });
      }
    }
  }

  root.addEventListener('change', event => {
    const input = event.target;
    if (!(input instanceof HTMLInputElement)) return;
    const fieldset = input.closest('fieldset.fm-choices');
    if (fieldset && input.type === 'radio') delete fieldset.dataset.showChoices;
    // find-match.js's group listener has already applied conditional visibility.
    sync(input);
  });

  const watcher = new MutationObserver(() => sync());
  steps.forEach(step => watcher.observe(step, {attributes:true, attributeFilter:['hidden']}));
  root.classList.add('fm-inline-enhanced');
  sync();
})();
