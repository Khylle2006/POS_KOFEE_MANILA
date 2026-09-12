// =============================
// Employee Dashboard — camera clock in/out + leave request
// =============================

let cameraStream    = null;
let cameraMode       = null;   // 'clock_in' | 'clock_out'
let capturedDataUrl  = null;

// ── Toast (matches project pattern) ────────────
let toastTimer;
function showToast(msg, type = 'success') {
  const t = document.getElementById('toast');
  t.textContent = msg;
  t.className   = 'toast toast-' + type + ' show';
  t.style.display = 'flex';
  clearTimeout(toastTimer);
  toastTimer = setTimeout(() => { t.classList.remove('show'); }, 3200);
}

// ── Camera modal ────────────────────────────────
async function openCamera(mode) {
  if (!window.HAS_EMPLOYEE) {
    showToast('⚠️ Your account has no linked employee profile.', 'error');
    return;
  }
  cameraMode = mode;
  capturedDataUrl = null;

  document.getElementById('camera-title').textContent = mode === 'clock_in' ? '📸 Clock In' : '📸 Clock Out';
  document.getElementById('camera-error').style.display = 'none';
  document.getElementById('camera-hint').style.display = '';
  document.getElementById('camera-preview').style.display = 'none';
  document.getElementById('camera-video').style.display = '';
  document.getElementById('camera-capture-btn').style.display = '';
  document.getElementById('camera-confirm-btn').style.display = 'none';
  document.getElementById('camera-retake-btn').style.display = 'none';

  document.getElementById('camera-modal').classList.add('open');

  try {
    cameraStream = await navigator.mediaDevices.getUserMedia({ video: { facingMode: 'user' }, audio: false });
    document.getElementById('camera-video').srcObject = cameraStream;
  } catch (err) {
    document.getElementById('camera-error').textContent = '⚠️ Could not access camera: ' + err.message;
    document.getElementById('camera-error').style.display = 'block';
  }
}

function stopCameraStream() {
  if (cameraStream) {
    cameraStream.getTracks().forEach(t => t.stop());
    cameraStream = null;
  }
}

function closeCamera() {
  stopCameraStream();
  document.getElementById('camera-modal').classList.remove('open');
}

function capturePhoto() {
  const video  = document.getElementById('camera-video');
  const canvas = document.getElementById('camera-canvas');
  if (!video.videoWidth) return;

  canvas.width  = video.videoWidth;
  canvas.height = video.videoHeight;
  const ctx = canvas.getContext('2d');

  // Mirror the draw so the still photo matches the mirrored live preview
  ctx.translate(canvas.width, 0);
  ctx.scale(-1, 1);
  ctx.drawImage(video, 0, 0, canvas.width, canvas.height);
  ctx.setTransform(1, 0, 0, 1, 0, 0); // reset transform before drawing text

  // ── Timestamp watermark burned into the image ──
  const now = new Date();
  const dateStr = now.toLocaleDateString('en-PH', { month: 'short', day: 'numeric', year: 'numeric' });
  const timeStr = now.toLocaleTimeString('en-PH', { hour: 'numeric', minute: '2-digit', second: '2-digit', hour12: true });
  const label   = (cameraMode === 'clock_in' ? 'CLOCK IN' : 'CLOCK OUT') + ' — ' + (window.EMPLOYEE_NAME || 'Employee');

  const barH = Math.max(50, canvas.height * 0.09);
  ctx.fillStyle = 'rgba(24,17,32,0.72)';
  ctx.fillRect(0, canvas.height - barH, canvas.width, barH);

  const fs1 = Math.max(14, canvas.width * 0.032);
  const fs2 = Math.max(12, canvas.width * 0.026);
  ctx.textBaseline = 'middle';
  ctx.fillStyle = '#FBF3E9';
  ctx.font = `bold ${fs1}px Arial, sans-serif`;
  ctx.fillText(label, 16, canvas.height - barH * 0.62);
  ctx.fillStyle = '#E6A25C';
  ctx.font = `${fs2}px Arial, sans-serif`;
  ctx.fillText(dateStr + '  ·  ' + timeStr, 16, canvas.height - barH * 0.28);

  capturedDataUrl = canvas.toDataURL('image/jpeg', 0.85);

  // Swap preview
  document.getElementById('camera-preview').src = capturedDataUrl;
  document.getElementById('camera-preview').style.display = '';
  document.getElementById('camera-video').style.display = 'none';
  document.getElementById('camera-hint').style.display = 'none';
  document.getElementById('camera-capture-btn').style.display = 'none';
  document.getElementById('camera-confirm-btn').style.display = '';
  document.getElementById('camera-retake-btn').style.display = '';
}

function retakePhoto() {
  capturedDataUrl = null;
  document.getElementById('camera-preview').style.display = 'none';
  document.getElementById('camera-video').style.display = '';
  document.getElementById('camera-hint').style.display = '';
  document.getElementById('camera-capture-btn').style.display = '';
  document.getElementById('camera-confirm-btn').style.display = 'none';
  document.getElementById('camera-retake-btn').style.display = 'none';
}

async function confirmCapture() {
  if (!capturedDataUrl) return;
  const btn = document.getElementById('camera-confirm-btn');
  btn.disabled = true;
  btn.textContent = 'Submitting…';

  try {
    const res = await fetch('../api/mark_attendance.php', {
      method: 'POST',
      headers: { 'Content-Type': 'application/json' },
      body: JSON.stringify({ action: cameraMode, photo: capturedDataUrl })
    });
    const data = await res.json();

    if (!data.ok) {
      document.getElementById('camera-error').textContent = '⚠️ ' + data.error;
      document.getElementById('camera-error').style.display = 'block';
      btn.disabled = false;
      btn.textContent = '✔ Confirm & Submit';
      return;
    }

    showToast(cameraMode === 'clock_in' ? '✅ Clocked in!' : '✅ Clocked out!');
    stopCameraStream();
    document.getElementById('camera-modal').classList.remove('open');
    location.reload(); // simplest way to refresh stats/table/button states
  } catch (err) {
    document.getElementById('camera-error').textContent = '⚠️ Network error: ' + err.message;
    document.getElementById('camera-error').style.display = 'block';
    btn.disabled = false;
    btn.textContent = '✔ Confirm & Submit';
  }
}

// ── Photo preview modal ─────────────────────────
function openPhotoPreview(url) {
  document.getElementById('photo-preview-img').src = url;
  document.getElementById('photo-preview-modal').classList.add('open');
}
function closePhotoPreview() {
  document.getElementById('photo-preview-modal').classList.remove('open');
}

// ── Leave request modal ─────────────────────────
function openLeaveModal() {
  if (!window.HAS_EMPLOYEE) {
    showToast('⚠️ Your account has no linked employee profile.', 'error');
    return;
  }
  document.getElementById('lv-type').value   = 'Vacation';
  document.getElementById('lv-start').value  = '';
  document.getElementById('lv-end').value    = '';
  document.getElementById('lv-reason').value = '';
  document.getElementById('leave-error').style.display = 'none';
  document.getElementById('leave-modal').classList.add('open');
}
function closeLeaveModal() {
  document.getElementById('leave-modal').classList.remove('open');
}

async function submitLeave() {
  const type   = document.getElementById('lv-type').value;
  const start  = document.getElementById('lv-start').value;
  const end    = document.getElementById('lv-end').value;
  const reason = document.getElementById('lv-reason').value.trim();
  const errEl  = document.getElementById('leave-error');

  if (!start || !end) {
    errEl.textContent = '⚠️ Start date and end date are required.';
    errEl.style.display = 'block';
    return;
  }
  if (new Date(end) < new Date(start)) {
    errEl.textContent = '⚠️ End date cannot be before start date.';
    errEl.style.display = 'block';
    return;
  }
  errEl.style.display = 'none';

  const btn = document.getElementById('leave-submit-btn');
  btn.disabled = true;
  btn.textContent = 'Submitting…';

  try {
    const res = await fetch('../api/submit_leave.php', {
      method: 'POST',
      headers: { 'Content-Type': 'application/json' },
      body: JSON.stringify({ leave_type: type, start_date: start, end_date: end, reason })
    });
    const data = await res.json();

    if (!data.ok) {
      errEl.textContent = '⚠️ ' + data.error;
      errEl.style.display = 'block';
      btn.disabled = false;
      btn.textContent = '✔ Submit Request';
      return;
    }

    showToast('✅ Leave request submitted!');
    closeLeaveModal();
    location.reload();
  } catch (err) {
    errEl.textContent = '⚠️ Network error: ' + err.message;
    errEl.style.display = 'block';
    btn.disabled = false;
    btn.textContent = '✔ Submit Request';
  }
}

// ── Modal dismiss (backdrop / Escape) ───────────
document.querySelectorAll('.modal-overlay').forEach(el => {
  el.addEventListener('click', e => {
    if (e.target === el) {
      closeCamera();
      closePhotoPreview();
      closeLeaveModal();
    }
  });
});
document.addEventListener('keydown', e => {
  if (e.key === 'Escape') {
    closeCamera();
    closePhotoPreview();
    closeLeaveModal();
  }
});
window.addEventListener('beforeunload', stopCameraStream);