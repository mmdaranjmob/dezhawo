/* Runs before styles so the saved/system appearance is applied before first paint. */
(() => {
  'use strict';
  const storageKey = 'dezhawo-theme';
  const modes = ['system', 'light', 'dark'];
  const labels = {system: 'سیستم', light: 'روشن', dark: 'تاریک'};
  const descriptions = {system: 'مطابق تنظیم دستگاه', light: 'همیشه روشن', dark: 'همیشه تاریک'};
  const paths = {
    system: '<rect x="3" y="4" width="18" height="13" rx="2"/><path d="M8 21h8m-4-4v4"/>',
    light: '<circle cx="12" cy="12" r="4"/><path d="M12 2v2m0 16v2M2 12h2m16 0h2M5 5l1.5 1.5m11 11L19 19M5 19l1.5-1.5m11-11L19 5"/>',
    dark: '<path d="M20.7 13.1A8.8 8.8 0 0 1 10.9 3.3a9 9 0 1 0 9.8 9.8Z"/>',
  };
  const media = typeof window.matchMedia === 'function' ? window.matchMedia('(prefers-color-scheme: dark)') : null;
  let mode = 'system';
  // Labels and consumers report the rendered theme; only apply() samples the OS.
  let activeTheme = 'light';
  let menu = null;
  let activeTrigger = null;
  try {
    const saved = localStorage.getItem(storageKey);
    if (modes.includes(saved)) mode = saved;
  } catch (_) { /* A blocked storage area must not prevent rendering or switching. */ }

  function resolved() {
    return mode === 'system' ? (media?.matches ? 'dark' : 'light') : mode;
  }
  function svg(value) {
    return `<svg class="theme-icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.6" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">${paths[value]}</svg>`;
  }
  function buttonLabel() {
    return `ظاهر سایت: ${labels[mode]}${mode === 'system' ? ` (${labels[activeTheme]})` : ''}؛ تغییر ظاهر`;
  }
  function buttonHtml() {
    return `<div class="theme-control"><button type="button" class="theme-trigger" data-theme-trigger aria-label="${buttonLabel()}" title="${buttonLabel()}" aria-haspopup="menu" aria-expanded="false">${svg(mode)}</button></div>`;
  }
  function syncControls() {
    document.querySelectorAll('[data-theme-trigger]').forEach(button => {
      button.setAttribute('aria-label', buttonLabel());
      button.title = buttonLabel();
      button.innerHTML = svg(mode);
    });
    menu?.querySelectorAll('[data-theme-choice]').forEach(button => {
      button.setAttribute('aria-checked', String(button.dataset.themeChoice === mode));
    });
    if (activeTrigger && !activeTrigger.isConnected) closeMenu();
  }
  function apply() {
    const theme = resolved();
    activeTheme = theme;
    document.documentElement.dataset.theme = theme;
    document.documentElement.dataset.themeMode = mode;
    document.documentElement.style.colorScheme = theme;
    const meta = document.querySelector('meta[name="theme-color"]');
    if (meta) meta.content = theme === 'dark' ? '#141713' : '#f5f4ef';
    syncControls();
    window.dispatchEvent(new CustomEvent('dezhawo:theme-change', {detail: {mode, theme}}));
  }
  function setMode(value) {
    if (!modes.includes(value)) return false;
    mode = value;
    try {
      if (value === 'system') localStorage.removeItem(storageKey);
      else localStorage.setItem(storageKey, value);
    } catch (_) { /* The selected appearance still works for the current page. */ }
    apply();
    return true;
  }
  function closeMenu(restoreFocus = false) {
    if (menu) menu.hidden = true;
    const trigger = activeTrigger;
    activeTrigger = null;
    if (trigger?.isConnected) {
      trigger.setAttribute('aria-expanded', 'false');
      if (restoreFocus) trigger.focus({preventScroll: true});
    }
  }
  function positionMenu() {
    if (!menu || menu.hidden || !activeTrigger) return;
    if (!activeTrigger.isConnected) { closeMenu(); return; }
    const rect = activeTrigger.getBoundingClientRect();
    if (rect.bottom <= 0 || rect.top >= window.innerHeight || rect.right <= 0 || rect.left >= window.innerWidth) {
      closeMenu();
      return;
    }
    const width = menu.offsetWidth;
    const height = menu.offsetHeight;
    menu.style.left = `${Math.max(12, Math.min(rect.right - width, window.innerWidth - width - 12))}px`;
    menu.style.top = `${Math.max(12, rect.bottom + height + 10 <= window.innerHeight ? rect.bottom + 8 : rect.top - height - 8)}px`;
  }
  function openMenu(trigger) {
    if (activeTrigger === trigger && menu && !menu.hidden) {
      closeMenu(true);
      return;
    }
    closeMenu();
    if (!menu) {
      menu = document.createElement('div');
      menu.id = 'appearanceMenu';
      menu.className = 'theme-menu';
      menu.setAttribute('role', 'menu');
      menu.setAttribute('aria-label', 'انتخاب ظاهر سایت');
      menu.innerHTML = `<p class="theme-menu-title">ظاهر سایت</p>${modes.map(value => `<button type="button" role="menuitemradio" data-theme-choice="${value}" aria-checked="${value === mode}">${svg(value)}<span><strong>${labels[value]}</strong><small>${descriptions[value]}</small></span><svg class="theme-check" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true"><path d="m5 12 4 4L19 6"/></svg></button>`).join('')}`;
      document.body.append(menu);
    }
    activeTrigger = trigger;
    trigger.setAttribute('aria-controls', menu.id);
    trigger.setAttribute('aria-expanded', 'true');
    syncControls();
    menu.hidden = false;
    positionMenu();
    menu.querySelector('[aria-checked="true"]')?.focus({preventScroll: true});
  }

  // The same controls are used by the static header and dynamically rendered admin.
  window.DezhawoTheme = Object.freeze({setMode, getMode: () => mode, getTheme: () => activeTheme, buttonHtml});
  apply();
  document.addEventListener('DOMContentLoaded', syncControls);
  document.addEventListener('click', event => {
    const trigger = event.target.closest('[data-theme-trigger]');
    if (trigger) { openMenu(trigger); return; }
    const choice = event.target.closest('[data-theme-choice]');
    if (choice && menu?.contains(choice)) {
      setMode(choice.dataset.themeChoice);
      closeMenu(true);
      return;
    }
    if (menu && !menu.hidden && !menu.contains(event.target)) closeMenu();
  });
  document.addEventListener('keydown', event => {
    const trigger = event.target.closest('[data-theme-trigger]');
    if (trigger && ['ArrowDown', 'ArrowUp'].includes(event.key)) {
      event.preventDefault();
      openMenu(trigger);
      return;
    }
    if (!menu || menu.hidden) return;
    if (event.key === 'Escape') { event.preventDefault(); closeMenu(true); return; }
    if (event.key === 'Tab') { closeMenu(true); return; }
    if (!menu.contains(event.target)) return;
    const buttons = [...menu.querySelectorAll('[data-theme-choice]')];
    const index = buttons.indexOf(document.activeElement);
    const next = ({ArrowDown: (index + 1) % buttons.length, ArrowUp: (index - 1 + buttons.length) % buttons.length, Home: 0, End: buttons.length - 1})[event.key];
    if (next !== undefined) { event.preventDefault(); buttons[next].focus(); }
  });
  window.addEventListener('resize', () => closeMenu());
  window.addEventListener('scroll', positionMenu, {passive: true, capture: true});
  window.addEventListener('storage', event => {
    if (event.key !== storageKey && event.key !== null) return;
    mode = modes.includes(event.newValue) ? event.newValue : 'system';
    apply();
  });
  const onSystemChange = () => { if (mode === 'system') apply(); };
  if (typeof media?.addEventListener === 'function') media.addEventListener('change', onSystemChange);
  else if (typeof media?.addListener === 'function') media.addListener(onSystemChange);
})();
