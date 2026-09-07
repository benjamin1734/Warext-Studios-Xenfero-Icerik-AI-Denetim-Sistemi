(() => {
  'use strict';
  if (window.__warextAiTracker) return;
  window.__warextAiTracker = true;

  const states = new WeakMap();
  const startedAt = Date.now();

  function isEditor(el) {
    return el instanceof Element && (
      el.matches('textarea[name="message"], textarea[data-original-name="message"], .fr-element[contenteditable="true"]')
    );
  }

  function stateFor(el) {
    if (!states.has(el)) states.set(el, { typedChars:0, pastedChars:0, deletedChars:0, pasteEvents:0, inputEvents:0, startedAt:Date.now() });
    return states.get(el);
  }

  document.addEventListener('beforeinput', event => {
    const el = event.target;
    if (!isEditor(el)) return;
    const state = stateFor(el);
    state.inputEvents++;
    const type = String(event.inputType || '');
    const dataLength = String(event.data || '').length;
    if (type.startsWith('delete')) state.deletedChars += Math.max(1, dataLength);
    else if (!type.includes('Paste') && !type.includes('Drop')) state.typedChars += Math.max(1, dataLength);
  }, true);

  document.addEventListener('paste', event => {
    const el = event.target;
    if (!isEditor(el)) return;
    const state = stateFor(el);
    const length = String(event.clipboardData?.getData('text/plain') || '').length;
    state.pastedChars += length;
    state.pasteEvents++;
  }, true);

  function aggregate(form) {
    let typedChars = 0, pastedChars = 0, deletedChars = 0, pasteEvents = 0, inputEvents = 0;
    let firstStart = startedAt;
    form.querySelectorAll('textarea[name="message"], textarea[data-original-name="message"], .fr-element[contenteditable="true"]').forEach(el => {
      const state = states.get(el);
      if (!state) return;
      typedChars += state.typedChars;
      pastedChars += state.pastedChars;
      deletedChars += state.deletedChars;
      pasteEvents += state.pasteEvents;
      inputEvents += state.inputEvents;
      firstStart = Math.min(firstStart, state.startedAt);
    });
    return {
      observed: true,
      typedChars,
      pastedChars,
      deletedChars,
      pasteEvents,
      inputEvents,
      durationSeconds: Math.max(0, Math.round((Date.now() - firstStart) / 1000))
    };
  }

  document.addEventListener('submit', event => {
    const form = event.target;
    if (!(form instanceof HTMLFormElement)) return;
    if (!form.querySelector('textarea[name="message"], textarea[data-original-name="message"], .fr-element[contenteditable="true"]')) return;

    let hidden = form.querySelector('input[name="warext_ai_behavior"]');
    if (!hidden) {
      hidden = document.createElement('input');
      hidden.type = 'hidden';
      hidden.name = 'warext_ai_behavior';
      form.appendChild(hidden);
    }

    const bridge = window.WarextWritingIntegration;
    const writing = bridge?.available && typeof bridge.getSummary === 'function'
      ? { available:true, ...bridge.getSummary() }
      : { available:false };

    hidden.value = JSON.stringify({ behavior: aggregate(form), writingChecker: writing });
  }, true);
})();
