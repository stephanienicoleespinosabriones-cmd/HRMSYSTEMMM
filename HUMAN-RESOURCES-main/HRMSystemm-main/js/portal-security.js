(function () {
  'use strict';

  const role = document.documentElement.dataset.portalRole;
  let checking = false;

  async function verifySession() {
    if (!role || checking) return;
    checking = true;
    try {
      const response = await fetch('../php/session_status.php?role=' + encodeURIComponent(role), {
        credentials: 'same-origin',
        cache: 'no-store',
        headers: { 'X-Requested-With': 'XMLHttpRequest' }
      });
      if (!response.ok) {
        window.location.replace('Login.html?session=expired');
        return;
      }
    } catch (error) {
      window.location.replace('Login.html?session=check_failed');
    } finally {
      checking = false;
    }
  }

  window.securePortalLogout = async function () {
    try {
      await fetch('../php/logout.php', {
        method: 'POST',
        credentials: 'same-origin',
        cache: 'no-store',
        headers: { 'X-Requested-With': 'XMLHttpRequest' }
      });
    } finally {
      window.location.replace('Login.html?logged_out=1');
    }
  };

  window.addEventListener('pageshow', verifySession);
  window.addEventListener('focus', verifySession);
  document.addEventListener('visibilitychange', function () {
    if (document.visibilityState === 'visible') verifySession();
  });
})();
