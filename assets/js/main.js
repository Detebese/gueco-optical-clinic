// ============================================================
// GUECO OPTICAL — Main JavaScript
// Theme Toggle, Sidebar, Toasts, Modals, Utilities
// ============================================================

document.addEventListener('DOMContentLoaded', function () {

  // ── Theme ──────────────────────────────────────────────
  const html = document.documentElement;

  function applyTheme(theme) {
    if (theme !== 'light' && theme !== 'dark') theme = 'dark';
    html.setAttribute('data-theme', theme);
    try {
      localStorage.setItem('gueco_theme', theme);
      localStorage.setItem('gueco-theme', theme);
      localStorage.setItem('guecoTheme', theme);
      localStorage.setItem('theme', theme);
      document.cookie = "gueco_theme=" + theme + "; path=/; max-age=31536000; SameSite=Lax";
      document.cookie = "theme=" + theme + "; path=/; max-age=31536000; SameSite=Lax";
    } catch(e) {}
    const icon = document.getElementById('themeIcon');
    if (icon) {
      icon.className = theme === 'dark' ? 'fas fa-sun' : 'fas fa-moon';
    }
    window.dispatchEvent(new CustomEvent('themeChanged', { detail: { theme: theme } }));
  }

  // Sync icon and state with already initialized attribute
  const activeTheme = html.getAttribute('data-theme') || localStorage.getItem('gueco_theme') || localStorage.getItem('gueco-theme') || localStorage.getItem('theme') || localStorage.getItem('guecoTheme') || 'dark';
  applyTheme(activeTheme);

  // Toggle button
  const themeToggle = document.getElementById('themeToggle');
  if (themeToggle) {
    themeToggle.addEventListener('click', (e) => {
      e.preventDefault();
      const current = html.getAttribute('data-theme') || 'dark';
      const next = current === 'dark' ? 'light' : 'dark';
      applyTheme(next);
    });
  }

  // ── Sidebar Mobile Toggle ──────────────────────────────
  const sidebarToggle = document.getElementById('sidebarToggle');
  const sidebar = document.getElementById('sidebar');

  if (sidebarToggle && sidebar) {
    sidebarToggle.addEventListener('click', () => {
      sidebar.classList.toggle('open');
    });

    // Close sidebar when clicking outside on mobile
    document.addEventListener('click', (e) => {
      if (window.innerWidth <= 992 &&
          !sidebar.contains(e.target) &&
          !sidebarToggle.contains(e.target)) {
        sidebar.classList.remove('open');
      }
    });
  }

  // ── Active Nav Link ────────────────────────────────────
  const currentPath = window.location.pathname.split('/').pop();
  document.querySelectorAll('.sidebar-nav .nav-link').forEach(link => {
    const href = link.getAttribute('href')?.split('/').pop();
    if (href && href === currentPath) {
      link.classList.add('active');
    }
  });

  // ── Toast Notifications ────────────────────────────────
  window.showToast = function (message, type = 'info', duration = 3500) {
    let container = document.querySelector('.toast-container');
    if (!container) {
      container = document.createElement('div');
      container.className = 'toast-container';
      document.body.appendChild(container);
    }

    const icons = { success: 'check-circle', error: 'times-circle', info: 'info-circle', warning: 'exclamation-triangle' };
    const icon = icons[type] || icons.info;

    const toast = document.createElement('div');
    toast.className = `toast ${type}`;
    toast.innerHTML = `
      <i class="fas fa-${icon} toast-icon" style="font-size:1.1rem;flex-shrink:0"></i>
      <span>${message}</span>
      <button onclick="this.parentElement.remove()" style="margin-left:auto;background:none;border:none;cursor:pointer;color:var(--text-muted);font-size:.9rem;padding:0 4px;">
        <i class="fas fa-times"></i>
      </button>`;

    container.appendChild(toast);
    setTimeout(() => {
      toast.style.animation = 'none';
      toast.style.opacity = '0';
      toast.style.transform = 'translateX(40px)';
      toast.style.transition = 'all .3s ease';
      setTimeout(() => toast.remove(), 300);
    }, duration);
  };

  // Show PHP-passed flash messages as SweetAlert Modal Popup
  const flashMsg = document.getElementById('flashMsg');
  if (flashMsg) {
    const msg = flashMsg.dataset.msg;
    const type = flashMsg.dataset.type || 'info';
    if (msg) {
      if (typeof Swal !== 'undefined') {
        const iconType = type === 'success' ? 'success' : (type === 'danger' || type === 'error' ? 'error' : (type === 'warning' ? 'warning' : 'info'));
        const titleText = type === 'success' ? 'Success!' : (type === 'danger' || type === 'error' ? 'Error' : 'Notice');
        Swal.fire({
          title: titleText,
          text: msg,
          icon: iconType,
          confirmButtonColor: 'var(--clr-primary)',
          background: 'var(--bg-card)',
          color: 'var(--text-primary)',
          timer: 3000,
          timerProgressBar: true
        });
      } else {
        showToast(msg, type);
      }
    }
  }

  // ── Modal Helpers & Unsaved Changes Confirmation ───────
  function getModalFormState(form) {
    if (!form) return {};
    const state = {};
    const elements = form.querySelectorAll('input, select, textarea');
    elements.forEach((el, idx) => {
      // Exclude hidden tokens/actions
      if (el.type === 'hidden' && (el.name === 'csrf_token' || el.name === 'action')) return;
      const key = el.name || el.id || `field_${idx}`;
      if (el.type === 'checkbox' || el.type === 'radio') {
        state[key] = el.checked;
      } else {
        state[key] = (el.value || '').trim();
      }
    });
    return state;
  }

  function snapshotModalForms(m) {
    if (!m) return;
    m.querySelectorAll('form').forEach(form => {
      form._initialFormState = getModalFormState(form);
    });
  }

  function isModalDirty(m) {
    if (!m) return false;
    const forms = m.querySelectorAll('form');
    for (const form of forms) {
      if (form._isSubmitting) continue;
      const initial = form._initialFormState || {};
      const current = getModalFormState(form);
      for (const key in current) {
        const initVal = initial[key] !== undefined ? initial[key] : (typeof current[key] === 'boolean' ? false : '');
        if (current[key] !== initVal) {
          return true;
        }
      }
    }
    return false;
  }

  function resetModalForms(m) {
    if (!m) return;
    m.querySelectorAll('form').forEach(form => {
      form.reset();
      delete form._initialFormState;
    });
  }

  window.openModal = function (id) {
    const m = document.getElementById(id);
    if (m) {
      m.classList.add('active');
      m.classList.add('open');
      document.body.style.overflow = 'hidden';
      // Snapshot immediately and after brief timeout for dynamically populated edit modals
      snapshotModalForms(m);
      setTimeout(() => snapshotModalForms(m), 50);

      // Track submit to bypass discard prompt
      m.querySelectorAll('form').forEach(form => {
        if (!form._hasSubmitListener) {
          form.addEventListener('submit', () => { form._isSubmitting = true; });
          form._hasSubmitListener = true;
        }
      });
    }
  };

  window.closeModal = function (id, force = false) {
    const m = document.getElementById(id);
    if (!m) return;

    if (!force && isModalDirty(m)) {
      const modalHeader = (m.querySelector('.modal-header')?.textContent || '').toLowerCase();
      const formAction = (m.querySelector('input[name="action"]')?.value || '').toLowerCase();
      const isAdd = formAction === 'add' || modalHeader.includes('add') || modalHeader.includes('new') || modalHeader.includes('create') || modalHeader.includes('write');

      const titleText = isAdd ? 'Cancel Adding?' : 'Discard Changes?';
      const promptText = isAdd 
        ? 'Are you sure you want to cancel? The information you entered will not be saved.'
        : 'Are you sure you want to close without saving your changes?';
      const confirmBtnText = isAdd ? 'Yes, cancel' : 'Yes, discard';
      const cancelBtnText = isAdd ? 'Continue editing' : 'Keep editing';

      if (typeof Swal !== 'undefined') {
        Swal.fire({
          title: titleText,
          text: promptText,
          icon: 'warning',
          showCancelButton: true,
          confirmButtonColor: 'var(--clr-danger)',
          cancelButtonColor: 'var(--clr-primary)',
          confirmButtonText: confirmBtnText,
          cancelButtonText: cancelBtnText,
          background: 'var(--bg-card)',
          color: 'var(--text-primary)'
        }).then((result) => {
          if (result.isConfirmed) {
            resetModalForms(m);
            window.closeModal(id, true);
          }
        });
        return;
      } else if (confirm(promptText)) {
        resetModalForms(m);
      } else {
        return;
      }
    }

    m.classList.remove('active');
    m.classList.remove('open');
    if (force) resetModalForms(m);
    if (!document.querySelector('.modal-overlay.active, .modal-overlay.open')) {
      document.body.style.overflow = '';
    }
  };

  // Close modal on overlay click (with dirty check)
  document.querySelectorAll('.modal-overlay').forEach(overlay => {
    overlay.addEventListener('click', (e) => {
      if (e.target === overlay) {
        window.closeModal(overlay.id);
      }
    });
  });

  // Close modals on Escape key (with dirty check)
  document.addEventListener('keydown', (e) => {
    if (e.key === 'Escape') {
      const activeModal = document.querySelector('.modal-overlay.active, .modal-overlay.open');
      if (activeModal) {
        window.closeModal(activeModal.id);
      }
    }
  });

  // ── Confirm Action / Delete ────────────────────────────
  document.querySelectorAll('[data-confirm]').forEach(btn => {
    btn.addEventListener('click', (e) => {
      e.preventDefault();
      const msg = btn.dataset.confirm || 'Are you sure you want to proceed?';
      
      if (typeof Swal !== 'undefined') {
        Swal.fire({
          title: 'Confirm Action',
          text: msg,
          icon: 'warning',
          showCancelButton: true,
          confirmButtonColor: 'var(--clr-primary)',
          cancelButtonColor: 'var(--clr-danger)',
          confirmButtonText: 'Yes, proceed',
          cancelButtonText: 'Cancel',
          background: 'var(--bg-card)',
          color: 'var(--text-primary)'
        }).then((result) => {
          if (result.isConfirmed) {
            const form = btn.closest('form');
            if (form) {
              form.submit();
            } else if (btn.tagName === 'A' && btn.href) {
              window.location.href = btn.href;
            }
          }
        });
      } else {
        if (confirm(msg)) {
          const form = btn.closest('form');
          if (form) form.submit();
          else if (btn.tagName === 'A' && btn.href) window.location.href = btn.href;
        }
      }
    });
  });

  // ── Role Detection on Login ────────────────────────────
  const emailInput = document.getElementById('loginEmail');
  const roleDetected = document.getElementById('roleDetected');
  const roleLabel = document.getElementById('roleLabel');

  if (emailInput && roleDetected) {
    let debounceTimer;
    emailInput.addEventListener('input', () => {
      clearTimeout(debounceTimer);
      const val = emailInput.value.trim();
      if (val.length < 4) {
        roleDetected.classList.remove('visible');
        return;
      }
      debounceTimer = setTimeout(async () => {
        try {
          const res = await fetch('check_role.php?email=' + encodeURIComponent(val));
          const data = await res.json();
          if (data.role) {
            roleLabel.textContent = data.role_label;
            roleDetected.classList.add('visible');
          } else {
            roleDetected.classList.remove('visible');
          }
        } catch { /* silent */ }
      }, 400);
    });
  }

  // ── Password Toggle ────────────────────────────────────
  document.querySelectorAll('[data-toggle-pass]').forEach(btn => {
    btn.addEventListener('click', () => {
      const target = document.getElementById(btn.dataset.togglePass);
      if (!target) return;
      const isPass = target.type === 'password';
      target.type = isPass ? 'text' : 'password';
      btn.querySelector('i').className = isPass ? 'fas fa-eye-slash' : 'fas fa-eye';
    });
  });

  // ── Auto-dismiss alerts ────────────────────────────────
  document.querySelectorAll('.alert[data-auto-dismiss]').forEach(alert => {
    const delay = parseInt(alert.dataset.autoDismiss) || 4000;
    setTimeout(() => {
      alert.style.transition = 'opacity .5s ease';
      alert.style.opacity = '0';
      setTimeout(() => alert.remove(), 500);
    }, delay);
  });

  // ── Table Row Click ────────────────────────────────────
  document.querySelectorAll('tr[data-href]').forEach(row => {
    row.style.cursor = 'pointer';
    row.addEventListener('click', () => {
      window.location.href = row.dataset.href;
    });
  });

  // ── Number Formatting ──────────────────────────────────
  window.formatCurrency = (num) =>
    '₱' + parseFloat(num || 0).toLocaleString('en-PH', { minimumFractionDigits: 2, maximumFractionDigits: 2 });

  // ── Print Page ─────────────────────────────────────────
  window.printPage = function () { window.print(); };

  // ── Input Filter (numbers only) ────────────────────────
  document.querySelectorAll('[data-numeric]').forEach(input => {
    input.addEventListener('input', () => {
      input.value = input.value.replace(/[^0-9.]/g, '');
    });
  });

  // ── Appointment Notification Polling ─────────────────────
  let knownApptIds = null;
  const notifBtn = document.querySelector('.notif-btn');

  function formatTime(timeStr) {
    const [h, m] = timeStr.split(':');
    let hours = parseInt(h);
    const ampm = hours >= 12 ? 'PM' : 'AM';
    hours = hours % 12 || 12;
    return `${hours}:${m} ${ampm}`;
  }

  function formatDate(dateStr) {
    const d = new Date(dateStr);
    return d.toLocaleDateString('en-US', { month: 'short', day: 'numeric', year: 'numeric' });
  }

  async function checkAppointments() {
    try {
      const apiPath = window.location.pathname.includes('gueco-optical') 
                      ? '/gueco-optical/api/check_appointments.php' 
                      : '/api/check_appointments.php';

      const res = await fetch(apiPath + '?t=' + new Date().getTime(), { cache: 'no-store' });
      const data = await res.json();
      
      if (data && typeof data.count !== 'undefined') {
        const count = data.count;
        const appts = data.appointments || [];

        // Find new appointments
        if (knownApptIds !== null) {
          appts.forEach(appt => {
            const currentId = String(appt.id);
            if (!knownApptIds.has(currentId)) {
              const msg = `<strong>${appt.patient_name}</strong> booked an appointment on <strong>${formatDate(appt.appointment_date)}</strong> at <strong>${formatTime(appt.appointment_time)}</strong> for <em>${appt.purpose}</em>.`;
              showToast(msg, 'info', 7000); 
            }
          });
        }
        
        // Update known IDs
        knownApptIds = new Set(appts.map(a => String(a.id)));
        
        // Update badge
        let notifBadge = document.querySelector('.notif-badge');
        if (count > 0) {
          if (notifBadge) {
            notifBadge.textContent = count > 99 ? 99 : count;
          } else if (notifBtn) {
            const badge = document.createElement('span');
            badge.className = 'notif-badge';
            badge.textContent = count > 99 ? 99 : count;
            notifBtn.appendChild(badge);
          }
        } else if (notifBadge) {
          notifBadge.remove();
        }
        
        // Dynamically update dropdown list if present
        const dropdownMenu = document.querySelector('#notifDropdownWrap .dropdown-menu');
        if (dropdownMenu && knownApptIds !== null) {
          let html = `<li><h6 class="dropdown-header">Notifications</h6></li>`;
          if (appts.length === 0) {
            html += `<li><span class="dropdown-item text-muted">No new notifications</span></li>`;
          } else {
            appts.slice(0, 5).forEach(appt => {
              html += `
                <li>
                  <a class="dropdown-item py-2" href="appointments.php">
                    <div class="fw-bold text-truncate" style="max-width: 260px;">
                      ${appt.patient_name}
                    </div>
                    <small class="text-muted">
                      Requested for ${formatDate(appt.appointment_date)} at ${formatTime(appt.appointment_time)}
                    </small>
                  </a>
                </li>
              `;
            });
          }
          html += `<li><hr class="dropdown-divider"></li>
                   <li><a class="dropdown-item text-center text-primary fw-semibold" href="appointments.php">View All Appointments</a></li>`;
          dropdownMenu.innerHTML = html;
        }
      }
    } catch (e) {
      console.error('[Appt Poll] Error:', e);
    }
  }

  // Check immediately if we have a notif btn, then every 10 seconds
  if (notifBtn) {
    checkAppointments();
    setInterval(checkAppointments, 10000);
  }

});