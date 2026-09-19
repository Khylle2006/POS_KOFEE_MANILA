/**
 * KofeeValidator — Universal Form Validation & UI/UX Module
 * Modern web validation following :user-invalid, :user-valid, ARIA accessibility,
 * real-time interaction feedback, and double-submit prevention.
 */
const KofeeValidator = (() => {

  /**
   * Helper to find or create an inline error element for an input
   */
  function getOrCreateErrorElement(input) {
    const parent = input.closest('.field-group') || input.closest('.mfield') || input.parentElement;
    let err = parent.querySelector(`.field-error[data-for="${input.id || input.name}"]`) ||
              parent.querySelector('.field-error');

    if (!err) {
      err = document.createElement('div');
      err.className = 'field-error';
      if (input.id || input.name) {
        err.dataset.for = input.id || input.name;
        err.id = (input.id || input.name) + '-error';
      }
      // Insert after the input or its wrapping container (e.g. .pw-wrap)
      const targetWrap = input.closest('.pw-wrap') || input;
      if (targetWrap.nextSibling) {
        parent.insertBefore(err, targetWrap.nextSibling);
      } else {
        parent.appendChild(err);
      }
    }
    return err;
  }

  /**
   * Display inline error for an input element
   */
  function showError(input, message) {
    if (!input) return;
    input.classList.add('is-invalid');
    input.classList.remove('is-valid');
    input.setAttribute('aria-invalid', 'true');

    const err = getOrCreateErrorElement(input);
    err.innerHTML = `<span aria-hidden="true" style="display:inline-flex;align-items:center"><svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M10.29 3.86L1.82 18a2 2 0 0 0 1.71 3h16.94a2 2 0 0 0 1.71-3L13.71 3.86a2 2 0 0 0-3.42 0z"/><line x1="12" y1="9" x2="12" y2="13"/><line x1="12" y1="17" x2="12.01" y2="17"/></svg></span> <span>${message}</span>`;
    err.style.display = 'flex';
    input.setAttribute('aria-describedby', err.id || '');

    const group = input.closest('.field-group') || input.closest('.mfield');
    if (group) group.classList.add('has-error');
  }

  /**
   * Clear error state from an input element
   */
  function clearError(input) {
    if (!input) return;
    input.classList.remove('is-invalid');
    input.removeAttribute('aria-invalid');

    const parent = input.closest('.field-group') || input.closest('.mfield') || input.parentElement;
    if (parent) {
      const err = parent.querySelector(`.field-error[data-for="${input.id || input.name}"]`) ||
                  parent.querySelector('.field-error');
      if (err) {
        err.style.display = 'none';
        err.textContent = '';
      }
      parent.classList.remove('has-error');
    }

    if (input.value && input.value.trim().length > 0) {
      input.classList.add('is-valid');
    } else {
      input.classList.remove('is-valid');
    }
  }

  /**
   * Validate a single input field against HTML5 and custom constraints
   */
  function validateField(input) {
    if (!input || input.disabled || input.type === 'hidden' || input.type === 'submit' || input.type === 'button') {
      return true;
    }

    const val = input.value !== undefined ? input.value.trim() : '';
    const label = input.dataset.label ||
                  (input.closest('.field-group') || input.closest('.mfield'))?.querySelector('.field-label, label')?.textContent?.replace(/[*]/g, '').trim() ||
                  input.placeholder ||
                  input.name ||
                  'This field';

    // 1. Required Check
    if (input.required || input.dataset.required === 'true') {
      if (input.type === 'checkbox') {
        if (!input.checked) {
          showError(input, `${label} is required.`);
          return false;
        }
      } else if (val === '') {
        showError(input, `${label} is required.`);
        return false;
      }
    }

    // Skip format checks if empty and not required
    if (val === '') {
      clearError(input);
      return true;
    }

    // 2. Minimum Length
    if (input.minLength && input.minLength > 0 && val.length < input.minLength) {
      showError(input, `${label} must be at least ${input.minLength} characters.`);
      return false;
    }

    // 3. Email Format Check
    if (input.type === 'email') {
      const emailRegex = /^[^\s@]+@[^\s@]+\.[^\s@]+$/;
      if (!emailRegex.test(val)) {
        showError(input, `Please enter a valid email address.`);
        return false;
      }
    }

    // 4. Number Min / Max / Positive Check
    if (input.type === 'number') {
      const num = parseFloat(val);
      if (isNaN(num)) {
        showError(input, `${label} must be a valid number.`);
        return false;
      }
      if (input.min !== '' && num < parseFloat(input.min)) {
        showError(input, `${label} cannot be less than ${input.min}.`);
        return false;
      }
      if (input.max !== '' && num > parseFloat(input.max)) {
        showError(input, `${label} cannot exceed ${input.max}.`);
        return false;
      }
    }

    // 5. Pattern Check
    if (input.pattern) {
      const reg = new RegExp(`^${input.pattern}$`);
      if (!reg.test(val)) {
        showError(input, input.title || `Format does not match requested format.`);
        return false;
      }
    }

    clearError(input);
    return true;
  }

  /**
   * Set button to loading state
   */
  function setLoading(button, loadingText = 'Processing…') {
    if (!button) return;
    button.dataset.origHtml = button.innerHTML;
    button.disabled = true;
    button.classList.add('is-loading');
    button.innerHTML = `<span class="btn-spinner"></span> <span>${loadingText}</span>`;
  }

  /**
   * Restore button from loading state
   */
  function resetLoading(button, originalText = null) {
    if (!button) return;
    button.disabled = false;
    button.classList.remove('is-loading');
    if (originalText !== null) {
      button.innerHTML = originalText;
    } else if (button.dataset.origHtml) {
      button.innerHTML = button.dataset.origHtml;
    }
  }

  /**
   * Bind validation to a form or container
   */
  function attach(formOrSelector, options = {}) {
    const form = typeof formOrSelector === 'string'
      ? document.querySelector(formOrSelector)
      : formOrSelector;

    if (!form) return null;

    // Track user blur to prevent preemptive errors on pristine fields
    const inputs = form.querySelectorAll('input, select, textarea');
    inputs.forEach(input => {
      input.addEventListener('blur', () => {
        validateField(input);
      });

      input.addEventListener('input', () => {
        if (input.classList.contains('is-invalid')) {
          validateField(input);
        }
      });

      input.addEventListener('change', () => {
        if (input.classList.contains('is-invalid')) {
          validateField(input);
        }
      });
    });

    // Form submit handler
    form.addEventListener('submit', function (e) {
      let firstInvalid = null;
      let isValid = true;

      const currentInputs = form.querySelectorAll('input, select, textarea');
      currentInputs.forEach(input => {
        const valid = validateField(input);
        if (!valid && !firstInvalid) {
          firstInvalid = input;
          isValid = false;
        }
      });

      // Custom form-level validation (e.g. cross-field)
      if (isValid && typeof options.customValidate === 'function') {
        const customRes = options.customValidate(form);
        if (customRes !== true) {
          isValid = false;
          if (customRes && customRes.field) {
            showError(customRes.field, customRes.message || 'Please fix this field.');
            firstInvalid = customRes.field;
          } else if (customRes && customRes.message) {
            alert(customRes.message);
          }
        }
      }

      if (!isValid) {
        e.preventDefault();
        e.stopImmediatePropagation();
        if (firstInvalid) {
          firstInvalid.scrollIntoView({ behavior: 'smooth', block: 'center' });
          firstInvalid.focus();
        }
        return false;
      }

      const submitBtn = form.querySelector('button[type="submit"], .btn-save, .btn-msave');
      if (submitBtn && !options.skipLoading) {
        setLoading(submitBtn, options.loadingText || 'Saving…');
      }

      if (typeof options.onSubmit === 'function') {
        e.preventDefault();
        options.onSubmit(form, e);
      }
    });

    return {
      validate: () => {
        let isValid = true;
        form.querySelectorAll('input, select, textarea').forEach(inp => {
          if (!validateField(inp)) isValid = false;
        });
        return isValid;
      },
      reset: () => {
        form.querySelectorAll('input, select, textarea').forEach(inp => clearError(inp));
      }
    };
  }

  return {
    attach,
    validateField,
    showError,
    clearError,
    setLoading,
    resetLoading
  };
})();

window.KofeeValidator = KofeeValidator;

