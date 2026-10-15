'use strict';

/* Bushisa Find Match: one optional follow-up on screen at a time.
   All inputs are the original server-rendered inputs; find-match.js continues
   to handle validation, CSRF and saving. Nothing is cloned or submitted here. */
(() => {
  const root = document.querySelector('[data-find-match]');
  if (!root) return;

  const steps = Array.from(root.querySelectorAll('.fm-step[data-step]'));
  const actions = root.querySelector('[data-match-actions]');
  const next = root.querySelector('[data-match-next]');
  const error = root.querySelector('[data-match-error]');
  if (!steps.length || !actions || !next) return;

  const sections = Array.from(root.querySelectorAll('details.fm-followups[data-conditional]'))
    .filter(section => !section.parentElement?.closest('details.fm-followups'));
  const stages = new WeakMap();
  const indexes = new WeakMap();
  const completed = new WeakSet();
  const changeButtons = new Map();
  let previousStep = null;
  let active = null;
  let advanceTimer = null;

  // Keep each relevant follow-up directly under the core answer that caused it.
  steps.forEach(step => {
    Array.from(step.querySelectorAll(':scope > details.fm-followups[data-conditional]'))
      .forEach(section => {
        const key = section.dataset.whenKey;
        const origin = Array.from(step.querySelectorAll(':scope > fieldset.fm-choices'))
          .find(fieldset => fieldset.querySelector('input[data-answer-key="' + key + '"]'));
        if (origin) origin.after(section);
      });
  });

  // Only collapse MAIN single-select answers. The active optional question
  // must show all of its possible answers, without extra "Change" links.
  const mainRadios = steps.flatMap(step =>
    Array.from(step.querySelectorAll(':scope > fieldset.fm-choices')).filter(fieldset => {
      const inputs = Array.from(fieldset.querySelectorAll('input[data-answer-key]'));
      return inputs.length && inputs.every(input => input.type === 'radio' && !input.hasAttribute('data-optional-answer'));
    })
  );
  mainRadios.forEach(fieldset => {
    const button = document.createElement('button');
    button.type = 'button';
    button.className = 'fm-change-answer';
    button.textContent = 'Change answer';
    button.setAttribute('aria-label', 'Change ' + (fieldset.getAttribute('aria-label') || 'selection'));
    button.hidden = true;
    fieldset.after(button);
    changeButtons.set(fieldset, button);
    button.addEventListener('click', () => {
      fieldset.dataset.showChoices = 'true';
      updateMainChoices();
      (Array.from(fieldset.querySelectorAll('input[type="radio"]')).find(input => !input.checked)
        || fieldset.querySelector('input[type="radio"]'))?.focus();
    });
  });

  function updateMainChoices() {
    mainRadios.forEach(fieldset => {
      const checked = fieldset.querySelector('input[type="radio"]:checked');
      const condensed = !!checked && fieldset.dataset.showChoices !== 'true';
      fieldset.classList.toggle('fm-choice-condensed', condensed);
      fieldset.querySelectorAll(':scope > label.fm-option').forEach(label => {
        label.hidden = condensed && !label.querySelector('input:checked');
      });
      changeButtons.get(fieldset).hidden = !condensed;
    });
  }

  // A follow-up can be a single fieldset (music), or a fieldset plus mini
  // questions (pace + first meet-up; sports + playing + football team).
  // Mark the original elements as screens instead of moving their inputs.
  sections.forEach(section => {
    const body = section.querySelector(':scope > .fm-followup-body');
    if (!body) return;
    const firstScreen = [];
    const screens = [];
    for (const child of Array.from(body.children)) {
      if (child.classList.contains('fm-clear-extra')) {
        child.classList.add('fm-focus-clear');
        continue;
      }
      if (child.classList.contains('fm-mini-question')) {
        if (firstScreen.length) {
          screens.push(firstScreen);
          firstScreen.length = 0;
        }
        screens.push([child]);
      } else {
        firstScreen.push(child);
      }
    }
    if (firstScreen.length) screens.unshift(firstScreen);
    screens.forEach((screen, index) => screen.forEach(element => {
      element.classList.add('fm-focus-fragment');
      element.dataset.fmScreen = String(index);
    }));
    stages.set(section, screens);
    indexes.set(section, 0);

    const heading = document.createElement('div');
    heading.className = 'fm-focus-heading';
    heading.innerHTML = '<span class="fm-focus-pill">✧ TELL US A LITTLE MORE</span><small>Optional · One question at a time</small>';
    body.prepend(heading);

    const controls = document.createElement('div');
    controls.className = 'fm-focus-controls';
    const previous = document.createElement('button');
    previous.type = 'button';
    previous.className = 'fm-focus-previous';
    previous.textContent = '← Previous';
    const skip = document.createElement('button');
    skip.type = 'button';
    skip.className = 'fm-focus-skip';
    skip.textContent = 'Skip →';
    const continueButton = document.createElement('button');
    continueButton.type = 'button';
    continueButton.className = 'fm-focus-continue';
    continueButton.textContent = 'Continue →';
    controls.append(previous, skip, continueButton);
    body.appendChild(controls);
    section._fmControls = {previous, skip, continueButton};

    previous.addEventListener('click', () => {
      clearTimeout(advanceTimer);
      const available = eligibleScreens(section);
      const at = available.indexOf(currentScreen(section));
      if (at > 0) {
        indexes.set(section, stages.get(section).indexOf(available[at - 1]));
        render();
      }
    });
    skip.addEventListener('click', () => advance(section));
    continueButton.addEventListener('click', () => advance(section));
  });

  function activeStep() {
    return steps.find(step => !step.hidden) || null;
  }

  function visibleSections(step) {
    if (!step) return [];
    return sections.filter(section =>
      step.contains(section) && !section.hidden &&
      !section.parentElement?.closest('details.fm-followups[hidden]')
    );
  }

  function eligibleScreens(section) {
    return (stages.get(section) || []).filter(screen =>
      screen.some(element => !element.hidden && !element.closest('[data-conditional][hidden]'))
    );
  }

  function currentScreen(section) {
    const screens = stages.get(section) || [];
    const available = eligibleScreens(section);
    return available.includes(screens[indexes.get(section) || 0])
      ? screens[indexes.get(section) || 0]
      : available[0] || null;
  }

  function requiredAnswered(step) {
    const required = new Map();
    for (const input of step.querySelectorAll('input[data-answer-key]:not([data-optional-answer])')) {
      const key = input.dataset.answerKey;
      if (!required.has(key)) required.set(key, false);
      if (input.checked) required.set(key, true);
    }
    return required.size > 0 && Array.from(required.values()).every(Boolean);
  }

  function focusNextRequired(step) {
    const first = Array.from(step.querySelectorAll('fieldset.fm-choices')).find(fieldset => {
      const required = Array.from(fieldset.querySelectorAll('input[data-answer-key]:not([data-optional-answer])'));
      return required.length && !required.some(input => input.checked);
    });
    if (first) {
      first.scrollIntoView({block:'center', behavior:'smooth'});
      first.querySelector('input')?.focus({preventScroll:true});
    }
  }

  // Switch the visible optional screen without leaving the user's scroll
  // position or opening a floating dialog. A radio answer moves ahead
  // automatically except on the final optional question.
  function render() {
    const step = activeStep();
    updateMainChoices();
    if (!step) return;
    if (previousStep !== step) {
      previousStep = step;
      clearTimeout(advanceTimer);
      // Reopening a previous step allows revising all optional answers.
      visibleSections(step).forEach(section => {
        completed.delete(section);
        indexes.set(section, 0);
      });
    }

    if (actions.parentElement !== step) {
      if (error) step.appendChild(error);
      step.appendChild(actions);
    }

    const available = visibleSections(step);
    active = available.find(section => !completed.has(section) && eligibleScreens(section).length) || null;
    step.classList.toggle('fm-extra-in-progress', !!active);

    sections.forEach(section => {
      const isActive = section === active;
      section.classList.toggle('fm-focus-hidden', !isActive);
      section.classList.toggle('fm-focus-active', isActive);
      if (isActive) {
        section.open = true;
        const screens = stages.get(section) || [];
        const showing = currentScreen(section);
        screens.forEach(screen => screen.forEach(element => {
          element.classList.toggle('fm-hidden-stage', screen !== showing);
        }));
        const eligible = eligibleScreens(section);
        const location = eligible.indexOf(showing);
        const controls = section._fmControls;
        controls.previous.hidden = location < 1;
        controls.continueButton.textContent = location === eligible.length - 1
          ? 'Continue →' : 'Next question →';
        const intent = steps[0]?.querySelector('input[data-answer-key="intent"]:checked')?.value || '';
        if (section.dataset.whenKey === 'intent') {
          const shortTitles = {
            'Casual dating': 'A little more about casual dating',
            'Something serious': 'A little more about what matters to you',
            'Friendship first': 'A little more about friendship',
            'Still exploring': 'A little more about your pace'
          };
          const label = section.querySelector('.fm-focus-pill');
          if (label) label.textContent = '✧ ' + (shortTitles[intent] || 'Tell us a little more');
        }
      } else {
        section.open = false;
      }
    });
  }

  function advance(section) {
    if (active !== section || next.disabled) return;
    clearTimeout(advanceTimer);
    const available = eligibleScreens(section);
    const current = currentScreen(section);
    const at = available.indexOf(current);
    if (at >= 0 && at < available.length - 1) {
      indexes.set(section, stages.get(section).indexOf(available[at + 1]));
      render();
      return;
    }
    completed.add(section);
    const step = activeStep();
    render();
    if (!active && step) {
      if (requiredAnswered(step)) {
        next.click(); // Save through the original validated CSRF endpoint.
      } else {
        focusNextRequired(step);
      }
    }
  }

  root.addEventListener('change', event => {
    const input = event.target;
    if (!(input instanceof HTMLInputElement)) return;
    const fieldset = input.closest('fieldset.fm-choices');
    if (fieldset && input.type === 'radio' && mainRadios.includes(fieldset)) {
      delete fieldset.dataset.showChoices;
    }
    const isCore = !input.hasAttribute('data-optional-answer');
    if (isCore) {
      visibleSections(activeStep()).forEach(section => {
        if (section.dataset.whenKey === input.dataset.answerKey) {
          completed.delete(section);
          indexes.set(section, 0);
        }
      });
    }
    render();

    if (active && input.hasAttribute('data-optional-answer') && input.type === 'radio' &&
        active.contains(input) && currentScreen(active)?.some(element => element.contains(input))) {
      const section = active;
      const available = eligibleScreens(section);
      if (available.indexOf(currentScreen(section)) < available.length - 1) {
        clearTimeout(advanceTimer);
        advanceTimer = setTimeout(() => {
          if (active === section) advance(section);
        }, 260);
      }
    }
  });

  const observer = new MutationObserver(() => render());
  steps.forEach(step => observer.observe(step, {attributes:true, attributeFilter:['hidden']}));
  root.classList.add('fm-inline-enhanced', 'fm-focus-enhanced');
  render();
})();
