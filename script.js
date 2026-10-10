/**
 * script.js — client-side behaviour for the Group Travel Expense Tracker.
 *
 * Responsibilities:
 *   1. Validate the "Add Expense" form and lock the button while it saves.
 *   2. Guard destructive/settling forms behind a second click.
 *   3. Build the balances summary and copy it for WhatsApp.
 *
 * Note: none of this is a security boundary. Every check here is repeated
 * server-side, because a user can bypass all of it with devtools or curl.
 */
(() => {
  'use strict';

  /* ---------------------------------------------------------------------
     Tunables
     --------------------------------------------------------------------- */
  const MIN_DESCRIPTION_LENGTH = 3;

  // Mirrors the DECIMAL(10,2) column in the schema: max 99,999,999.99.
  const MAX_AMOUNT = 99999999.99;

  const MESSAGE_TIMEOUT_MS = 6000;    // how long a form error stays on screen
  const RESTORE_DELAY_MS = 10000;     // re-enable the button if navigation never happens
  const CONFIRM_TIMEOUT_MS = 4000;    // how long an armed "Confirm?" button waits
  const TOAST_TIMEOUT_MS = 2600;      // how long the clipboard toast stays up

  // Characters a valid money value never contains. Blocked at the keystroke level.
  const BLOCKED_KEYS = new Set(['-', '+', 'e', 'E']);

  const INDIA_RUPEES = new Intl.NumberFormat('en-IN', {
    style: 'currency',
    currency: 'INR',
    minimumFractionDigits: 2,
    maximumFractionDigits: 2,
  });

  /* =====================================================================
     Clipboard toast
     ===================================================================== */
  const toastEl = document.querySelector('#toast');
  let toastTimer = null;

  /**
   * Show a short confirmation at the bottom of the screen.
   * @param {string} message
   */
  const showToast = (message) => {
    if (!toastEl) {
      return;
    }

    toastEl.textContent = message;
    toastEl.classList.add('is-visible');

    window.clearTimeout(toastTimer);
    toastTimer = window.setTimeout(() => toastEl.classList.remove('is-visible'), TOAST_TIMEOUT_MS);
  };

  /**
   * Copy text to the clipboard.
   *
   * navigator.clipboard needs a secure context, which http://localhost is but
   * a LAN address like http://192.168.1.5 is not — hence the fallback, since
   * this app is meant to be used on a phone over the local network.
   *
   * @param {string} text
   * @returns {Promise<boolean>} whether the copy succeeded
   */
  const copyToClipboard = async (text) => {
    if (navigator.clipboard && window.isSecureContext) {
      try {
        await navigator.clipboard.writeText(text);
        return true;
      } catch (error) {
        // Fall through to the legacy path.
      }
    }

    const area = document.createElement('textarea');
    area.value = text;
    area.setAttribute('readonly', '');
    area.style.position = 'fixed';
    area.style.top = '-1000px';
    area.style.opacity = '0';
    document.body.appendChild(area);

    let copied = false;
    try {
      area.select();
      copied = document.execCommand('copy');
    } catch (error) {
      copied = false;
    }

    document.body.removeChild(area);
    return copied;
  };

  /* =====================================================================
     Share the balances to WhatsApp
     ===================================================================== */

  /**
   * Turn the rendered balance rows into a plain-text message.
   *
   * The figures come from data-* attributes written by PHP, so the on-screen
   * numbers and the shared numbers can never drift apart.
   *
   * @param {HTMLElement} card
   * @returns {string}
   */
  const buildBalanceSummary = (card) => {
    const tripName = card.dataset.tripName || 'Trip';
    const total = Number(card.dataset.totalCents || 0) / 100;
    const perPerson = Number(card.dataset.perPersonCents || 0) / 100;

    const lines = [
      // WhatsApp renders *asterisks* as bold.
      `*${tripName}* — trip balances`,
      `Total: ${INDIA_RUPEES.format(total)}`,
      `Per person: ${INDIA_RUPEES.format(perPerson)}`,
      '',
    ];

    card.querySelectorAll('.balance-item').forEach((row) => {
      const name = row.dataset.travelerName || 'Unknown';
      const cents = Number(row.dataset.balanceCents || 0);
      const amount = INDIA_RUPEES.format(Math.abs(cents) / 100);

      let verdict;
      if (cents > 0) {
        verdict = `is owed ${amount}`;
      } else if (cents < 0) {
        verdict = `owes ${amount}`;
      } else {
        verdict = 'is settled up';
      }

      lines.push(`• ${name} ${verdict}`);
    });

    lines.push('');
    lines.push(`Settle up here: ${window.location.origin + window.location.pathname}`);

    return lines.join('\n');
  };

  const shareButton = document.querySelector('#share-balances');

  if (shareButton) {
    shareButton.addEventListener('click', async () => {
      const selector = shareButton.dataset.shareTarget || '#balances-card';
      const card = document.querySelector(selector);

      if (!card) {
        return;
      }

      const copied = await copyToClipboard(buildBalanceSummary(card));

      if (copied) {
        showToast('Copied for WhatsApp!');
      } else {
        showToast('Could not copy automatically — select the balances and copy manually.');
      }
    });
  }

  /* =====================================================================
     Two-step confirmation for settling up and deleting
     ===================================================================== */

  /**
   * Ask for a second click before a form that moves money or deletes a row.
   *
   * A native confirm() dialog would be simpler but blocks the page and cannot
   * be styled; arming the button keeps the action reversible for four seconds.
   */
  const initConfirmForms = () => {
    document.querySelectorAll('form.js-confirm').forEach((form) => {
      const button = form.querySelector('button[type="submit"]');

      if (!button) {
        return;
      }

      const originalLabel = button.textContent.trim();
      const confirmLabel = button.dataset.confirmLabel || 'Confirm?';
      let armed = false;
      let timer = null;

      const disarm = () => {
        armed = false;
        window.clearTimeout(timer);
        button.textContent = originalLabel;
        button.classList.remove('is-armed');
      };

      form.addEventListener('submit', (event) => {
        // Second click: the form is armed, so let the submission through.
        if (armed) {
          return;
        }

        event.preventDefault();
        armed = true;
        button.textContent = confirmLabel;
        button.classList.add('is-armed');

        window.clearTimeout(timer);
        timer = window.setTimeout(disarm, CONFIRM_TIMEOUT_MS);
      });

      // Clicking away cancels an armed button.
      form.addEventListener('focusout', () => {
        if (armed) {
          disarm();
        }
      });
    });
  };

  /* =====================================================================
     Add Expense form — validation, amount hardening, submit lock
     ===================================================================== */

  /** Strip everything a money value cannot contain, keeping one decimal point. */
  const sanitizeAmount = (value) => {
    const digitsAndDots = String(value).replace(/[^0-9.]/g, '');
    const [whole, ...rest] = digitsAndDots.split('.');

    return rest.length > 0 ? `${whole}.${rest.join('')}` : whole;
  };

  const initExpenseForm = () => {
    const form = document.querySelector('.expense-form');

    // Pages without the form (or without JS at all) simply skip this section.
    if (!form) {
      return;
    }

    const descriptionInput = form.querySelector('#description');
    const amountInput = form.querySelector('#amount');
    const payerSelect = form.querySelector('#payer_id');
    const participantsGrid = form.querySelector('#participants-grid');
    const submitButton = form.querySelector('button[type="submit"]');

    const statusEl = form.querySelector('.form-status') ?? (() => {
      const el = document.createElement('p');
      el.className = 'form-status';
      el.setAttribute('role', 'status');
      el.setAttribute('aria-live', 'polite');
      form.appendChild(el);
      return el;
    })();

    let messageTimer = null;
    let restoreTimer = null;
    let isSubmitting = false;

    /* --- Feedback helpers ---------------------------------------------- */

    const clearMessage = () => {
      window.clearTimeout(messageTimer);
      statusEl.textContent = '';
      statusEl.classList.remove('is-error', 'is-success');
    };

    const showMessage = (text, type = 'error') => {
      window.clearTimeout(messageTimer);

      statusEl.textContent = text;
      statusEl.classList.toggle('is-error', type === 'error');
      statusEl.classList.toggle('is-success', type === 'success');

      messageTimer = window.setTimeout(clearMessage, MESSAGE_TIMEOUT_MS);
    };

    const markInvalid = (input) => {
      if (input) {
        input.setAttribute('aria-invalid', 'true');
      }
    };

    const clearInvalid = (input) => input?.removeAttribute('aria-invalid');

    const clearAllInvalid = () => {
      form.querySelectorAll('[aria-invalid="true"]').forEach((el) => {
        el.removeAttribute('aria-invalid');
      });
    };

    /* --- Validators ---------------------------------------------------- */

    const validateDescription = () => {
      const value = descriptionInput.value.trim();

      if (value === '') {
        return 'Please enter a description, e.g. "Dinner" or "Train Tickets".';
      }
      if (value.length < MIN_DESCRIPTION_LENGTH) {
        return `Description must be at least ${MIN_DESCRIPTION_LENGTH} characters.`;
      }
      return null;
    };

    const validateAmount = () => {
      const value = amountInput.value.trim();

      if (value === '') {
        return 'Please enter the amount paid.';
      }

      const parsed = Number.parseFloat(value);

      if (!Number.isFinite(parsed)) {
        return 'Amount must be a valid number.';
      }
      if (parsed <= 0) {
        return 'Amount must be greater than 0.';
      }
      if (parsed > MAX_AMOUNT) {
        return `Amount cannot exceed ${INDIA_RUPEES.format(MAX_AMOUNT)}.`;
      }
      return null;
    };

    const validatePayer = () => {
      if (!payerSelect) {
        return null;
      }
      return payerSelect.value === '' ? 'Please choose who paid for this expense.' : null;
    };

    /** At least one member must share the expense, or nobody owes anything. */
    const validateParticipants = () => {
      if (!participantsGrid) {
        return null;
      }

      const selected = participantsGrid.querySelectorAll('[data-participant]:checked').length;

      return selected === 0 ? 'Tick at least one member to share this expense.' : null;
    };

    /* --- Amount hardening ---------------------------------------------- */

    amountInput?.addEventListener('keydown', (event) => {
      // A number input happily accepts "1e5" and "-20"; neither is an amount.
      if (BLOCKED_KEYS.has(event.key)) {
        event.preventDefault();
        return;
      }

      // The spinner arrows are hidden by CSS, but the arrow keys still change
      // the value — block them so a stray keypress cannot alter an amount.
      if (event.key === 'ArrowUp' || event.key === 'ArrowDown') {
        event.preventDefault();
      }
    });

    amountInput?.addEventListener('paste', (event) => {
      const clipboard = event.clipboardData ?? window.clipboardData;

      if (!clipboard) {
        return;
      }

      const pasted = clipboard.getData('text');

      // Scientific notation is far more likely to be a mistake than an intent:
      // strip the "e" and "1e3" would silently become ₹13, so reject it.
      if (/[eE]/.test(pasted)) {
        event.preventDefault();
        return;
      }

      const cleaned = sanitizeAmount(pasted);

      if (cleaned !== pasted) {
        event.preventDefault();
        amountInput.value = cleaned;
      }
    });

    // Final guard for autofill, dictation, and drag-and-drop text.
    amountInput?.addEventListener('input', () => {
      const raw = amountInput.value;
      const cleaned = sanitizeAmount(raw);

      if (cleaned !== raw) {
        amountInput.value = cleaned;
      }
    });

    // Tidy the display value on blur: "1200" becomes "1200.00".
    amountInput?.addEventListener('blur', () => {
      const parsed = Number.parseFloat(amountInput.value);

      if (Number.isFinite(parsed) && parsed > 0) {
        amountInput.value = parsed.toFixed(2);
      }
    });

    /* --- Clear stale errors as the user fixes them ---------------------- */
    [descriptionInput, amountInput, payerSelect].forEach((field) => {
      if (!field) {
        return;
      }

      const resetFieldState = () => {
        clearInvalid(field);
        clearMessage();
      };

      field.addEventListener('input', resetFieldState);
      field.addEventListener('change', resetFieldState);
    });

    /* --- Submit -------------------------------------------------------- */
    form.addEventListener('submit', (event) => {
      if (isSubmitting) {
        event.preventDefault();
        return;
      }

      const failures = [
        [descriptionInput, validateDescription()],
        [amountInput, validateAmount()],
        [payerSelect, validatePayer()],
        [participantsGrid, validateParticipants()],
      ].filter(([, message]) => message !== null);

      if (failures.length > 0) {
        event.preventDefault();

        clearAllInvalid();
        failures.forEach(([field]) => markInvalid(field));

        const [firstField, firstMessage] = failures[0];
        showMessage(firstMessage, 'error');

        if (firstField) {
          // A fieldset is not focusable, so aim at its first checkbox instead.
          const target = firstField.querySelector?.('[data-participant]') ?? firstField;
          target.focus?.();
          target.select?.();
        }
        return;
      }

      // --- Valid: let the POST to add_expense.php proceed ----------------
      clearAllInvalid();
      clearMessage();

      isSubmitting = true;

      if (submitButton) {
        submitButton.dataset.originalLabel = submitButton.textContent.trim();
        submitButton.textContent = 'Saving…';
        submitButton.disabled = true;
        submitButton.setAttribute('aria-busy', 'true');
      } else {
        form.querySelectorAll('button, input[type="submit"]').forEach((btn) => {
          btn.disabled = true;
        });
      }

      // Safety net: if the browser never navigates (offline, blocked request),
      // the button must not stay disabled forever.
      restoreTimer = window.setTimeout(() => {
        if (!isSubmitting) {
          return;
        }

        isSubmitting = false;

        if (submitButton) {
          submitButton.textContent = submitButton.dataset.originalLabel ?? 'Add Expense';
          submitButton.disabled = false;
          submitButton.removeAttribute('aria-busy');
        }

        showMessage('The expense was not saved. Please check your connection and try again.', 'error');
      }, RESTORE_DELAY_MS);
    });

    // Restored from the back/forward cache after a submission: reset the button
    // instead of leaving a permanently disabled form on screen.
    window.addEventListener('pageshow', (event) => {
      if (!event.persisted || !isSubmitting) {
        return;
      }

      window.clearTimeout(restoreTimer);
      isSubmitting = false;

      if (submitButton) {
        submitButton.textContent = submitButton.dataset.originalLabel ?? 'Add Expense';
        submitButton.disabled = false;
        submitButton.removeAttribute('aria-busy');
      }
    });
  };

  /* =====================================================================
     Participant picker — select all / none, and a live per-head figure
     ===================================================================== */
  const initParticipants = () => {
    const grid = document.querySelector('#participants-grid');
    const hint = document.querySelector('#split-hint');
    const amountInput = document.querySelector('#amount');

    if (!grid) {
      return;
    }

    const checkedBoxes = () => [...grid.querySelectorAll('[data-participant]:checked')];

    const updateHint = () => {
      if (!hint) {
        return;
      }

      const checked = checkedBoxes();
      const count = checked.length;

      if (count === 0) {
        hint.textContent = 'nobody selected';
        return;
      }

      const cents = Math.round(Number.parseFloat(amountInput?.value ?? '') * 100);

      if (!Number.isFinite(cents) || cents <= 0) {
        hint.textContent = `${count} member${count === 1 ? '' : 's'}`;
        return;
      }

      // Mirror the server's integer-paise division so the figure shown here is
      // the figure that gets stored, not a floating-point approximation.
      const base = Math.floor(cents / count);
      const remainder = cents % count;

      hint.textContent =
        `${remainder === 0 ? '' : '\u2248 '}${INDIA_RUPEES.format(base / 100)} each (${count})`;
    };

    grid.addEventListener('change', updateHint);
    amountInput?.addEventListener('input', updateHint);

    document.querySelectorAll('[data-participants-action]').forEach((button) => {
      button.addEventListener('click', () => {
        const selectAll = button.dataset.participantsAction === 'all';

        grid.querySelectorAll('[data-participant]').forEach((box) => {
          box.checked = selectAll;
        });

        updateHint();
      });
    });

    updateHint();
  };

  /* =====================================================================
     Trip switcher — the dashboard is the endpoint: switching is a GET
     navigation to ?trip_id=N, no separate state-changing request.
     ===================================================================== */
  const initTripSwitcher = () => {
    const select = document.querySelector('[data-trip-switcher]');

    if (!select) {
      return;
    }

    // The submit button is the no-JavaScript fallback for this same
    // navigation; hide it where the instant behaviour works.
    document.querySelectorAll('[data-trip-switcher-submit]').forEach((button) => {
      button.hidden = true;
    });

    select.addEventListener('change', () => {
      const url = new URL(window.location.href);

      url.searchParams.set('trip_id', select.value);

      // Do not carry the previous action's flash message over to the trip
      // being switched TO.
      url.searchParams.delete('status');
      url.searchParams.delete('reason');

      window.location.href = url.toString();
    });
  };

  /* =====================================================================
     New-trip modal — dynamic member rows
     ===================================================================== */
  const initTripDialog = () => {
    const dialog = document.querySelector('#trip-dialog');
    const openButton = document.querySelector('#trip-dialog-open');
    const cancelButton = document.querySelector('#trip-dialog-cancel');
    const rowsContainer = document.querySelector('#member-rows');
    const template = document.querySelector('#member-row-template');
    const addRowButton = document.querySelector('#add-member-row');
    const nameInput = document.querySelector('#trip_name');

    if (!dialog || !rowsContainer || !template) {
      return;
    }

    const INITIAL_ROWS = 3;

    const addRow = () => {
      const row = template.content.firstElementChild.cloneNode(true);
      rowsContainer.appendChild(row);
      return row;
    };

    // Removing the last row would leave nothing to type into, so keep one.
    const removeRow = (row) => {
      if (rowsContainer.children.length > 1) {
        row.remove();
      } else {
        const input = row.querySelector('input');
        if (input) {
          input.value = '';
        }
      }
    };

    rowsContainer.addEventListener('click', (event) => {
      const removeButton = event.target.closest('.js-remove-member');

      if (removeButton) {
        removeRow(removeButton.closest('.member-row'));
      }
    });

    addRowButton?.addEventListener('click', () => {
      const row = addRow();
      row.querySelector('input')?.focus();
    });

    const resetRows = () => {
      rowsContainer.replaceChildren();

      for (let i = 0; i < INITIAL_ROWS; i += 1) {
        addRow();
      }
    };

    openButton?.addEventListener('click', () => {
      // Start from a clean slate every time the modal is opened.
      resetRows();

      if (nameInput) {
        nameInput.value = '';
      }

      // showModal() is what gives Esc-to-close and the ::backdrop for free.
      if (typeof dialog.showModal === 'function') {
        dialog.showModal();
      } else {
        dialog.setAttribute('open', '');
      }

      nameInput?.focus();
    });

    cancelButton?.addEventListener('click', () => dialog.close());

    // Do not submit an empty member list; the server refuses it anyway, but
    // catching it here saves a round trip and keeps what was typed intact.
    dialog.addEventListener('submit', (event) => {
      const filled = [...rowsContainer.querySelectorAll('input')]
        .filter((input) => input.value.trim() !== '');

      if (filled.length === 0) {
        event.preventDefault();
        showToast('Add at least one member name.');
        rowsContainer.querySelector('input')?.focus();
      }
    });

    // Backdrop click closes the modal (clicks land on the dialog element itself).
    dialog.addEventListener('click', (event) => {
      if (event.target === dialog) {
        dialog.close();
      }
    });
  };

  /* =====================================================================
     Boot
     ===================================================================== */
  document.addEventListener('DOMContentLoaded', () => {
    initExpenseForm();
    initConfirmForms();
    initParticipants();
    initTripSwitcher();
    initTripDialog();

    console.log('[travel_tracker] script.js loaded.');
  });
})();
