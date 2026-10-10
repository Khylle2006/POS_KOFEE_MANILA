(() => {
  'use strict';

  const form = document.getElementById('geofence-settings-form');
  if (!form) return;
  const latitude = document.getElementById('setting_lat');
  const longitude = document.getElementById('setting_lon');
  const radius = document.getElementById('setting_radius');
  const button = document.getElementById('detect-store-pin');
  const status = document.getElementById('store-pin-status');
  let map = null;
  let marker = null;
  let circle = null;
  let locating = false;
  let pinRevision = 0;
  const rolesDialog = document.getElementById('bypass-roles-modal');

  if (rolesDialog) {
    const chooseRoles = document.getElementById('choose-bypass-roles');
    const summary = document.getElementById('bypass-roles-summary');
    const empty = document.getElementById('bypass-roles-empty');
    const checkboxes = Array.from(rolesDialog.querySelectorAll('input[name="exempt_roles[]"]'));
    let selectedRoles = checkboxes.filter(input => input.checked).map(input => input.value);

    function restoreSelection() {
      for (const input of checkboxes) input.checked = selectedRoles.includes(input.value);
    }

    chooseRoles.addEventListener('click', () => {
      restoreSelection();
      rolesDialog.showModal();
    });
    document.getElementById('cancel-bypass-roles').addEventListener('click', () => rolesDialog.close());
    rolesDialog.addEventListener('close', () => {
      restoreSelection();
      chooseRoles.focus();
    });
    document.getElementById('save-bypass-roles').addEventListener('click', () => {
      const selected = checkboxes.filter(input => input.checked);
      selectedRoles = selected.map(input => input.value);
      const labels = selected.map(input => {
        const item = document.createElement('li');
        item.textContent = input.dataset.roleLabel;
        return item;
      });
      summary.replaceChildren(...labels);
      empty.hidden = selected.length > 0;
      rolesDialog.close();
    });
  }

  function message(text, error = false) {
    status.textContent = text;
    status.classList.toggle('is-error', error);
  }

  function coordinates() {
    if (latitude.value.trim() === '' || longitude.value.trim() === '') return null;
    const lat = Number(latitude.value);
    const lng = Number(longitude.value);
    return Number.isFinite(lat) && Math.abs(lat) <= 90 && Number.isFinite(lng) && Math.abs(lng) <= 180 ? [lat, lng] : null;
  }

  function updatePreview() {
    const point = coordinates();
    if (!map || !point) return;
    marker.setLatLng(point);
    circle.setLatLng(point);
    const meters = Number(radius.value);
    if (Number.isFinite(meters) && meters >= 20 && meters <= 10000) circle.setRadius(meters);
    map.panTo(point);
  }

  function setPin(lat, lng) {
    if (!Number.isFinite(lat) || Math.abs(lat) > 90 || !Number.isFinite(lng) || Math.abs(lng) > 180) return false;
    latitude.value = lat.toFixed(7);
    longitude.value = lng.toFixed(7);
    pinRevision++;
    updatePreview();
    return true;
  }

  function mapPin(point) {
    // Leaflet can display repeated worlds; store the equivalent canonical longitude.
    const lng = ((point.lng + 180) % 360 + 360) % 360 - 180;
    if (setPin(point.lat, lng)) message('New store pin selected. Save Geofence Settings to apply it.');
  }

  function showMap() {
    if (!window.L) {
      message('The map could not load. You can still use your current location or enter coordinates.', true);
      return;
    }
    if (!map) {
      const point = coordinates();
      if (!point) return;
      map = window.L.map('storePinMap').setView(point, 16);
      window.L.tileLayer('https://{s}.tile.openstreetmap.org/{z}/{x}/{y}.png', {
        attribution: '&copy; OpenStreetMap contributors', maxZoom: 19,
      }).addTo(map);
      marker = window.L.marker(point, { draggable: true, title: 'Drag to move the store pin' }).addTo(map);
      circle = window.L.circle(point, {
        radius: Number(radius.value), color: '#C97B3D', fillOpacity: 0.15,
      }).addTo(map);
      map.on('click', event => mapPin(event.latlng));
      marker.on('dragend', () => mapPin(marker.getLatLng()));
    }
    map.invalidateSize();
    updatePreview();
  }

  function finishLocation() {
    locating = false;
    button.disabled = false;
    button.textContent = 'Use My Current Location';
  }

  function detectLocation() {
    if (locating) return;
    if (!window.isSecureContext) {
      message('Location needs HTTPS or localhost. Open this page on localhost, or enter the store coordinates manually.', true);
      return;
    }
    if (!navigator.geolocation) {
      message('This browser cannot access location. Click the map or enter the store coordinates.', true);
      return;
    }
    locating = true;
    const requestedRevision = pinRevision;
    button.disabled = true;
    button.textContent = 'Finding your location…';
    message('Allow location access when your browser asks. Finding your current location…');

    function request(highAccuracy) {
      try {
        navigator.geolocation.getCurrentPosition(position => {
          finishLocation();
          if (requestedRevision !== pinRevision) {
            message('Your manually selected pin was kept. Save Geofence Settings to apply it.');
            return;
          }
          if (!setPin(position.coords.latitude, position.coords.longitude)) {
            message('The browser returned invalid coordinates. Choose a pin on the map or enter coordinates.', true);
            return;
          }
          const accuracy = Number(position.coords.accuracy);
          const detail = Number.isFinite(accuracy) ? ` (approximately ${Math.round(accuracy)} m accuracy)` : '';
          message(`Current location selected${detail}. Adjust the pin if needed, then save Geofence Settings.`);
        }, error => {
          if (highAccuracy && (error.code === 2 || error.code === 3)) {
            message('Precise location is unavailable. Trying the browser’s approximate location…');
            request(false);
            return;
          }
          finishLocation();
          const messages = {
            1: 'Location access was denied. Allow Location in your browser’s site settings and device location settings, then try again. You can also click the map or enter coordinates.',
            2: 'Your device could not determine its location. Check device location services, or choose the store pin on the map.',
            3: 'Location detection timed out. Try again, or choose the store pin on the map.',
          };
          message(messages[error.code] || 'Location could not be detected. Choose the pin on the map or enter coordinates.', true);
        }, { enableHighAccuracy: highAccuracy, timeout: highAccuracy ? 20000 : 15000, maximumAge: 0 });
      } catch (error) {
        finishLocation();
        message('The browser blocked location access. Check site location permissions, or choose the pin manually.', true);
      }
    }
    request(true);
  }

  for (const input of [latitude, longitude]) {
    input.addEventListener('input', () => {
      pinRevision++;
      updatePreview();
      message(coordinates() ? 'New store coordinates entered. Save Geofence Settings to apply them.' : 'Enter latitude from -90 to 90 and longitude from -180 to 180.', !coordinates());
    });
  }
  radius.addEventListener('input', updatePreview);
  button.addEventListener('click', detectLocation);
  form.addEventListener('submit', event => {
    // Enter must not submit an unconfirmed checklist selection to the server.
    if (rolesDialog?.open) {
      event.preventDefault();
      return;
    }
    if (locating) {
      event.preventDefault();
      message('Please wait for location detection to finish before saving.', true);
    }
  });
  window.storePinSettings = { showMap };
})();
