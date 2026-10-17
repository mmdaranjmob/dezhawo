/* The upgrade notice belongs to the signed-in store owner only. */
(() => {
  'use strict';
  const DZ = window.DZ;
  if (!DZ) return;

  let banner;
  let message;
  let button;
  let busy = false;
  let success = false;
  let error = '';
  let ownerId = null;
  let operation = 0;
  let hideTimer = null;

  function clearTimer() {
    if (hideTimer !== null) clearTimeout(hideTimer);
    hideTimer = null;
  }

  function currentOwner() {
    const user = DZ.state.currentUser;
    return user?.roleId === 'owner' ? user : null;
  }

  function render(fromState = false) {
    if (!banner) return;
    const owner = currentOwner();
    if (!owner || owner.id !== ownerId) {
      clearTimer();
      operation++;
      busy = false;
      success = false;
      error = '';
      ownerId = owner?.id || null;
    }
    if (fromState && !busy && success) {
      clearTimer();
      success = false;
    }
    const status = DZ.state.databaseUpgrade;
    const required = status?.required === true;
    banner.hidden = !owner || (!required && !success && !busy);
    if (banner.hidden) {
      clearTimer();
      return;
    }

    banner.dataset.state = busy ? 'busy' : success ? 'success' : error ? 'error' : 'required';
    banner.setAttribute('aria-busy', busy ? 'true' : 'false');
    message.textContent = busy
      ? 'ارتقای پایگاه داده در حال انجام است. لطفاً منتظر بمان.'
      : success
        ? 'پایگاه داده با موفقیت ارتقا یافت.'
        : error || 'برای استفاده از امکانات جدید، پایگاه دادهٔ فروشگاه را ارتقا بده.';
    button.hidden = success;
    button.disabled = busy;
    button.textContent = busy ? 'در حال ارتقا…' : 'ارتقای دیتابیس';
  }

  async function upgrade() {
    if (busy || !currentOwner() || DZ.state.databaseUpgrade?.required !== true) return;
    clearTimer();
    busy = true;
    success = false;
    error = '';
    const activeOperation = ++operation;
    render();
    try {
      await DZ.upgradeDatabase();
      if (operation !== activeOperation || !currentOwner()) return;
      if (DZ.state.databaseUpgrade?.required !== false) {
        throw new Error('تکمیل ارتقای پایگاه داده تأیید نشد. دوباره تلاش کن.');
      }
      success = true;
    } catch (err) {
      if (operation !== activeOperation || !currentOwner()) return;
      // A refreshed bootstrap can verify completion even if the earlier response was interrupted.
      if (DZ.state.databaseUpgrade?.required === false) success = true;
      else error = err?.message || 'ارتقای پایگاه داده انجام نشد. دوباره تلاش کن.';
    } finally {
      if (operation === activeOperation) {
        busy = false;
        render();
        if (success && !banner.hidden) {
          hideTimer = setTimeout(() => {
            hideTimer = null;
            success = false;
            render();
          }, 6000);
        }
      }
    }
  }

  function init() {
    banner = document.getElementById('databaseUpgradeBanner');
    if (!banner) return;
    banner.classList.add('database-upgrade');
    banner.hidden = true;
    banner.setAttribute('aria-label', 'ارتقای پایگاه داده');
    banner.innerHTML = '<div class="database-upgrade-copy"><strong class="database-upgrade-title">پایگاه داده نیاز به ارتقا دارد</strong><p class="database-upgrade-message" data-database-upgrade-error role="status" aria-live="polite" aria-atomic="true"></p></div><button class="database-upgrade-button" data-database-upgrade type="button">ارتقای دیتابیس</button>';
    message = banner.querySelector('.database-upgrade-message');
    button = banner.querySelector('.database-upgrade-button');
    button.addEventListener('click', upgrade);
    DZ.subscribe(() => render(true));
    render();
    Promise.resolve(DZ.ready).then(() => render(true));
  }

  if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', init, {once:true});
  else init();
})();
