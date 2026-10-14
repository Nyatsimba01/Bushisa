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
      '<span><strong>Make it yours</strong><small data-offer-copy>A few optional details about what you enjoy.</small></span>' +
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

  function refreshOffer() {
    const group = currentStep();
    const themes = eligibleThemes();
    const show = !!group && themes.length > 0 && group !== dismissedStep && !dialog.open;
    offer.hidden = !show;
    if (!show) return;
    const names = themes.slice(0, 2).map(themeName);
    promptText.textContent = names.join(' + ') +
      (themes.length > 2 ? ' and more' : '') +
      ' · Add a few fun details, or skip.';
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
