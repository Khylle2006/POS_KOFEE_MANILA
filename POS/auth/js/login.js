/**
 * auth/js/login.js
 * Captures device telemetry and GPS coordinates for HR Workplace Authorization.
 * Supports multi-stage fallback (High Accuracy -> Low Accuracy) and permissions API.
 */
document.addEventListener('DOMContentLoaded', () => {
  const form = document.getElementById('loginForm') || document.querySelector('form');
  const latInput = document.getElementById('geo_latitude');
  const lonInput = document.getElementById('geo_longitude');
  const accInput = document.getElementById('geo_accuracy');
  const statusInput = document.getElementById('geo_status');
  const devInput = document.getElementById('geo_device_info');
  const badge = document.getElementById('geo_badge');
  const badgeIcon = document.getElementById('geo_icon');
  const badgeLabel = document.getElementById('geo_label');
  const submitBtn = document.getElementById('submitBtn') || (form ? form.querySelector('button[type="submit"]') : null);
  const btnText = document.getElementById('btnText') || (submitBtn ? submitBtn : null);

  // 1. Gather rich device telemetry
  try {
    const tz = Intl.DateTimeFormat().resolvedOptions().timeZone || 'UTC';
    const scr = `${window.screen.width}x${window.screen.height}`;
    const pf = navigator.userAgentData?.platform || navigator.platform || 'Device';
    if (devInput) {
      devInput.value = `${pf} (${scr}, ${tz})`;
    }
  } catch (e) {
    console.warn('Device telemetry collection:', e);
  }

  let geoResolved = false;
  let isSubmitting = false;

  function updateBadge(state, message) {
    if (!badge || !badgeLabel) return;
    if (state === 'capturing') {
      badge.className = 'flex items-center justify-between gap-2 px-2.5 py-1.5 rounded-lg text-[11px] bg-amber-50 text-amber-900 border border-amber-300 transition-all';
      if (badgeIcon) {
        badgeIcon.textContent = '📡';
        badgeIcon.className = 'animate-pulse';
      }
      badgeLabel.textContent = message || 'Acquiring workplace location…';
    } else if (state === 'prompt') {
      badge.className = 'flex items-center justify-between gap-2 px-2.5 py-1.5 rounded-lg text-[11px] bg-amber-100 text-amber-900 border border-amber-400 font-semibold transition-all';
      if (badgeIcon) {
        badgeIcon.textContent = '👆';
        badgeIcon.className = 'animate-bounce';
      }
      badgeLabel.textContent = message || 'Click "Allow" on the browser location popup';
    } else if (state === 'success') {
      badge.className = 'flex items-center justify-between gap-2 px-2.5 py-1.5 rounded-lg text-[11px] bg-emerald-50 text-emerald-800 border border-emerald-300 transition-all';
      if (badgeIcon) {
        badgeIcon.textContent = '📍';
        badgeIcon.className = '';
      }
      badgeLabel.textContent = message || 'Workplace GPS locked';
    } else if (state === 'denied') {
      badge.className = 'flex items-center justify-between gap-2 px-2.5 py-1.5 rounded-lg text-[11px] bg-rose-50 text-rose-800 border border-rose-300 transition-all';
      if (badgeIcon) {
        badgeIcon.textContent = '⚠️';
        badgeIcon.className = '';
      }
      badgeLabel.textContent = message || 'Location blocked in browser (HR review required)';
    } else {
      badge.className = 'flex items-center justify-between gap-2 px-2.5 py-1.5 rounded-lg text-[11px] bg-stone-100 text-stone-600 border border-stone-200 transition-all';
      if (badgeIcon) {
        badgeIcon.textContent = '📍';
        badgeIcon.className = '';
      }
      badgeLabel.textContent = message || 'Location check standby';
    }
  }

  function applyLocationSuccess(pos) {
    geoResolved = true;
    const lat = pos.coords.latitude;
    const lon = pos.coords.longitude;
    const acc = Math.round(pos.coords.accuracy);

    if (latInput) latInput.value = lat.toFixed(7);
    if (lonInput) lonInput.value = lon.toFixed(7);
    if (accInput) accInput.value = acc;
    if (statusInput) statusInput.value = 'success';

    updateBadge('success', `Location locked (±${acc}m accuracy)`);

    if (isSubmitting && form) {
      form.submit();
    }
  }

  function applyLocationFailure(err) {
    geoResolved = true;
    let st = 'error';
    let msg = 'Location unavailable';

    if (err.code === 1) { // PERMISSION_DENIED
      st = 'denied';
      msg = 'Location blocked (Check browser lock icon 🔒)';
    } else if (err.code === 2) { // POSITION_UNAVAILABLE
      st = 'unavailable';
      msg = 'GPS signal unavailable (Windows location off)';
    } else if (err.code === 3) { // TIMEOUT
      st = 'timeout';
      msg = 'GPS timed out (HR manual review)';
    }

    if (statusInput) statusInput.value = st;
    updateBadge(st === 'denied' ? 'denied' : 'error', msg);

    if (isSubmitting && form) {
      form.submit();
    }
  }

  function requestLocation(isRetry = false) {
    if (!('geolocation' in navigator)) {
      if (statusInput) statusInput.value = 'unsupported';
      geoResolved = true;
      updateBadge('error', 'Location not supported by browser');
      return;
    }

    // Check permission state if supported
    if (navigator.permissions && navigator.permissions.query) {
      navigator.permissions.query({ name: 'geolocation' }).then((perm) => {
        if (perm.state === 'prompt') {
          updateBadge('prompt', 'Please click "Allow" on the location prompt');
        } else if (perm.state === 'denied') {
          updateBadge('denied', 'Location blocked (Click lock icon 🔒 in address bar)');
          geoResolved = true;
          if (statusInput) statusInput.value = 'denied';
          return;
        } else {
          updateBadge('capturing', 'Acquiring workplace location…');
        }
      }).catch(() => {
        updateBadge('capturing', 'Acquiring workplace location…');
      });
    } else {
      updateBadge('capturing', 'Acquiring workplace location…');
    }

    // Stage 1: Try high accuracy with 4.5s timeout and 1-minute cache
    navigator.geolocation.getCurrentPosition(
      applyLocationSuccess,
      (err1) => {
        // If denied, don't bother retrying
        if (err1.code === 1) {
          applyLocationFailure(err1);
          return;
        }

        console.warn('High accuracy location failed, retrying with low accuracy network triangulation...', err1);
        updateBadge('capturing', 'Acquiring network location…');

        // Stage 2: Fallback to low accuracy (Wi-Fi / ISP / network cell) with 6s timeout & 5-min cache
        navigator.geolocation.getCurrentPosition(
          applyLocationSuccess,
          (err2) => {
            applyLocationFailure(err2);
          },
          {
            enableHighAccuracy: false,
            timeout: 6000,
            maximumAge: 300000,
          }
        );
      },
      {
        enableHighAccuracy: true,
        timeout: 4500,
        maximumAge: 60000,
      }
    );
  }

  // Global retry hook
  window.retryLocation = () => {
    geoResolved = false;
    requestLocation(true);
  };

  // Initiate location request immediately on load
  requestLocation();

  // Retry when user focuses on username/password fields
  const inputs = form ? form.querySelectorAll('input[type="text"], input[type="password"]') : [];
  inputs.forEach((inp) => {
    inp.addEventListener('focus', () => {
      if (!geoResolved && (!statusInput || statusInput.value === 'unrequested')) {
        requestLocation();
      }
    }, { once: true });
  });

  // Handle form submission: give enough time if user just clicked submit while prompt was showing
  if (form) {
    form.addEventListener('submit', (e) => {
      if (geoResolved) {
        return; // Coordinates or definitive error already resolved
      }

      e.preventDefault();
      isSubmitting = true;

      if (submitBtn) {
        submitBtn.disabled = true;
        submitBtn.style.opacity = '0.75';
        if (btnText) {
          btnText.textContent = 'Verifying Location (Please Allow)…';
        }
      }

      updateBadge('prompt', 'Please click "Allow" on the browser prompt!');

      // Give user up to 6.5 seconds to click "Allow" on the prompt before proceeding with timeout fallback
      const timer = setTimeout(() => {
        if (!geoResolved) {
          if (statusInput && !statusInput.value) {
            statusInput.value = 'timeout';
          }
          geoResolved = true;
          form.submit();
        }
      }, 6500);
    });
  }
});
