'use strict';

/* Optional detail experience. Presentation only: original answer inputs remain
   inside the active questionnaire step and still save through find-match.js. */
(() => {
  const root = document.querySelector('[data-find-match]');
  if (!root || !window.HTMLDialogElement || !HTMLDialogElement.prototype.showModal) return;

  const steps = Array.from(root.querySelectorAll('.fm-step[data-step]'));
  const offer = document.createElement('aside');
  offer.className = 'fm-detail-offer';
  offer.hidden = true;
  offer.setAttribute('aria-label', 'Optional personalized questions');
  offer.innerHTML =
    '<div class="fm-detail-offer-copy">' +
      '<span class="fm-detail-offer-sparkle" aria-hidden="true">✧</span>' +
      '<span><strong data-offer-title>Make it yours</strong><small data-offer-copy>A few optional details about what you enjoy.</small></span>' +
    '</div>' +
    '<div class="fm-detail-offer-actions">' +
      '<button type="button" class="fm-offer-skip" data-offer-skip>Not now</button>' +
      '<button type="button" class="fm-offer-open" data-offer-open>Explore ✨</button>' +
    '</div>';
  root.appendChild(offer);

  const dialog = document.createElement('dialog');
  dialog.className = 'fm-detail-dialog';
  dialog.setAttribute('aria-labelledby', 'fm-detail-title');
  dialog.innerHTML =
    '<div class="fm-detail-dialog-shell">' +
      '<header class="fm-detail-dialog-head">' +
        '<div><span class="fm-detail-kicker">✧ MAKE IT YOURS · OPTIONAL</span>' +
          '<h2 id="fm-detail-title">A little more you.</h2>' +
          '<p>Choose what feels like you. Every extra answer is optional.</p></div>' +
        '<button type="button" class="fm-detail-close" data-detail-close aria-label="Close extra questions">×</button>' +
      '</header>' +
      '<div class="fm-detail-progress" data-detail-progress aria-live="polite"></div>' +
      '<div class="fm-detail-slot" data-detail-slot></div>' +
      '<footer class="fm-detail-dialog-actions">' +
        '<button type="button" class="fm-detail-text-button" data-detail-skip>Skip extras</button>' +
        '<button type="button" class="fm-detail-next" data-detail-next>Next theme →</button>' +
        '<button type="button" class="fm-detail-done" data-detail-done>Done ✓</button>' +
      '</footer>' +
    '</div>';

  const slot = dialog.querySelector('[data-detail-slot]');
  const heading = dialog.querySelector('#fm-detail-title');
  const themeProgress = dialog.querySelector('[data-detail-progress]');
  const nextTheme = dialog.querySelector('[data-detail-next]');
  const promptTitle = offer.querySelector('[data-offer-title]');
  const promptText = offer.querySelector('[data-offer-copy]');
  let activeDetail = null;
  let anchor = null;
  let activeIndex = 0;
  let returnFocus = null;
  let dismissedStep = null;

  function currentStep() {
    return steps.find(group => !group.hidden) || null;
  }

  function eligibleThemes() {
    const group = currentStep();
    if (!group) return [];
    return Array.from(group.querySelectorAll('details.fm-followups[data-conditional]'))
      .filter(section => !section.hidden);
  }

  function themeName(section) {
    const summary = section.querySelector('summary');
    return (summary ? summary.textContent : 'Your interests')
      .replace(/\s*Optional\s*/i, '').trim();
  }

  // Personalize the small invitation from choices on the active step.
  // This is display text only; the original answers and saving API are unchanged.
  function selectedValues(group, key) {
    return Array.from(group.querySelectorAll('input[data-answer-key]'))
      .filter(input => input.dataset.answerKey === key && input.checked &&
        !input.closest('[data-conditional]'))
      .map(input => input.value);
  }

  function invitationFor(group, themes) {
    const step = Number(group.dataset.step);
    const first = key => selectedValues(group, key)[0] || '';
    if (step === 0) {
      const intent = first('intent');
      const messages = {
        'Something serious': ['Your pace, your way', 'You chose something serious. What pace feels comfortable? One little extra, or skip.'],
        'Casual dating': ['Your pace, your call', 'You chose casual dating. How do you prefer getting to know someone? Totally optional.'],
        'Friendship first': ['Start with a hello', 'Friendship first feels right to you. Share your preferred pace, or keep going.'],
        'Still exploring': ['Room to explore', 'Still figuring it out? Share what feels comfortable, or skip the extra.']
      };
      if (messages[intent]) return { title: messages[intent][0], copy: messages[intent][1] };
    }
    if (step === 1) {
      const social = first('social_activity');
      const messages = {
        'Quiet moments': ['Your quieter side?', 'Quiet moments are your vibe. What does a good hangout or first hello look like?'],
        'Somewhere in the middle': ['A little of both?', 'A balance sounds like you. Tell us what spending time together feels like, or skip.'],
        'Always up for an activity': ['Always up for something?', 'You chose an active vibe. What kind of time together sounds most like you?']
      };
      if (messages[social]) return { title: messages[social][0], copy: messages[social][1] };
      const communication = first('communication_style');
      if (communication) return {
        title: 'Your way of connecting',
        copy: communication + ' feels right to you. Want to add what makes a good first hello?'
      };
    }
    if (step === 2) {
      const interests = selectedValues(group, 'interests');
      if (interests.length > 1) {
        const firstTwo = interests.slice(0, 2);
        return {
          title: 'More on your interests?',
          copy: firstTwo.join(' + ') + (interests.length > 2 ? ' and more' : '') +
            ' made your list. Add a little detail about any of them, or skip.'
        };
      }
      const messages = {
        'Sport': ['Your sporty side?', 'You chose sport. Which sports do you enjoy? You can even share a favourite team.'],
        'Music': ['More on your music?', 'You chose music. What kind of sounds are on repeat for you?'],
        'Reading': ['Your next great read?', 'You chose reading. What kinds of stories do you enjoy?'],
        'Photography': ['Behind your lens?', 'You chose photography. What do you most enjoy creating or capturing?'],
        'Art': ['Your creative side?', 'You chose art. Tell us a little more about what you like making or enjoying.'],
        'Gaming': ['Your gaming side?', 'You chose gaming. What kinds of games do you come back to?'],
        'Campus events': ['Your campus side?', 'Campus events caught your eye. What would you enjoy going to?'],
        'Travel': ['Places and possibilities?', 'You chose travel. What kind of outing sounds like you?'],
        'Food & cooking': ['What’s on your menu?', 'You chose food and cooking. What do you enjoy trying or making?'],
        'Films & series': ['On your watchlist?', 'You chose films and series. What genres do you enjoy?'],
        'Technology': ['Your curious side?', 'You chose technology. What kinds of ideas excite you?'],
        'Community': ['Your community side?', 'You chose community. How do you enjoy getting involved?']
      };
      if (messages[interests[0]]) return { title: messages[interests[0]][0], copy: messages[interests[0]][1] };
    }
    if (step === 3) {
      const availability = first('availability');
      if (availability) return {
        title: 'Plans that feel like you',
        copy: availability + ' works for you. Do you prefer planning ahead or keeping it flexible?'
      };
    }
    const names = themes.slice(0, 2).map(themeName);
    return {
      title: 'Make it yours',
      copy: names.join(' + ') + (themes.length > 2 ? ' and more' : '') +
        ' · Add a little detail, or skip.'
    };
  }

  function refreshOffer() {
    const group = currentStep();
    const themes = eligibleThemes();
    const show = !!group && themes.length > 0 && group !== dismissedStep && !dialog.open;
    offer.hidden = !show;
    if (!show) return;
    const invitation = invitationFor(group, themes);
    promptTitle.textContent = invitation.title;
    promptText.textContent = invitation.copy;
  }

  function restoreDetail() {
    if (activeDetail && anchor) {
      activeDetail.open = false;
      anchor.replaceWith(activeDetail);
    }
    activeDetail = null;
    anchor = null;
  }

  function showTheme(index) {
    restoreDetail();
    const themes = eligibleThemes();
    if (!themes.length) {
      dialog.close();
      return;
    }
    activeIndex = Math.max(0, Math.min(index, themes.length - 1));
    activeDetail = themes[activeIndex];
    anchor = document.createComment('optional theme original position');
    activeDetail.parentNode.insertBefore(anchor, activeDetail);
    slot.appendChild(activeDetail);
    activeDetail.open = true;
    heading.textContent = 'Your ' + themeName(activeDetail).toLowerCase() + ' side.';
    themeProgress.textContent = 'Theme ' + (activeIndex + 1) + ' of ' + themes.length +
      ' · You can skip any of these.';
    nextTheme.hidden = activeIndex >= themes.length - 1;
    dialog.querySelector('[data-detail-done]').textContent =
      activeIndex >= themes.length - 1 ? 'All done ✓' : 'Done for now ✓';
  }

  function openThemes() {
    const group = currentStep();
    if (!group || !eligibleThemes().length) return;
    returnFocus = document.activeElement;
    group.appendChild(dialog); // Keep the original inputs inside the active step for saving.
    dialog.showModal();
    showTheme(0);
    offer.hidden = true;
  }

  function closeThemes(dismiss) {
    if (dismiss) dismissedStep = currentStep();
    if (dialog.open) dialog.close();
    else refreshOffer();
  }

  offer.querySelector('[data-offer-open]').addEventListener('click', openThemes);
  offer.querySelector('[data-offer-skip]').addEventListener('click', () => {
    dismissedStep = currentStep();
    refreshOffer();
  });
  dialog.querySelector('[data-detail-close]').addEventListener('click', () => closeThemes(false));
  dialog.querySelector('[data-detail-done]').addEventListener('click', () => closeThemes(true));
  dialog.querySelector('[data-detail-skip]').addEventListener('click', () => closeThemes(true));
  nextTheme.addEventListener('click', () => showTheme(activeIndex + 1));
  dialog.addEventListener('click', event => {
    if (event.target === dialog) closeThemes(false);
  });
  dialog.addEventListener('close', () => {
    restoreDetail();
    if (returnFocus && typeof returnFocus.focus === 'function') returnFocus.focus();
    returnFocus = null;
    refreshOffer();
  });

  // Keep the invitation in sync with the existing branch logic and step navigation.
  root.addEventListener('change', () => window.setTimeout(refreshOffer, 0));
  const observer = new MutationObserver(() => {
    if (!dialog.open) dismissedStep = null;
    refreshOffer();
  });
  steps.forEach(group => observer.observe(group, { attributes: true, attributeFilter: ['hidden'] }));
  root.classList.add('fm-enhanced');
  refreshOffer();
})();
