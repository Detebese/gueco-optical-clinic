// ============================================================
// GUECO OPTICAL — Main JavaScript
// Theme Toggle, Sidebar, Toasts, Utilities
// ============================================================

document.addEventListener('DOMContentLoaded', function () {

  // ── Theme ──────────────────────────────────────────────
  const THEME_KEY = 'gueco_theme';
  const html = document.documentElement;

  function applyTheme(theme) {
    html.setAttribute('data-theme', theme);
    localStorage.setItem(THEME_KEY, theme);
    const icon = document.getElementById('themeIcon');
    if (icon) {
      icon.className = theme === 'dark' ? 'fas fa-sun' : 'fas fa-moon';
    }
  }

  // Load saved theme
  const savedTheme = localStorage.getItem(THEME_KEY) || 'light';
  applyTheme(savedTheme);

  // Toggle button
  const themeToggle = document.getElementById('themeToggle');
  if (themeToggle) {
    themeToggle.addEventListener('click', () => {
      const current = html.getAttribute('data-theme') || 'light';
      applyTheme(current === 'dark' ? 'light' : 'dark');
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

  // Show PHP-passed flash messages as toasts
  const flashMsg = document.getElementById('flashMsg');
  if (flashMsg) {
    const msg = flashMsg.dataset.msg;
    const type = flashMsg.dataset.type || 'info';
    if (msg) showToast(msg, type);
  }

  // ── Modal Helpers ──────────────────────────────────────
  window.openModal = function (id) {
    const m = document.getElementById(id);
    if (m) m.classList.add('active');
  };

  window.closeModal = function (id) {
    const m = document.getElementById(id);
    if (m) m.classList.remove('active');
  };

  // Close modal on overlay click
  document.querySelectorAll('.modal-overlay').forEach(overlay => {
    overlay.addEventListener('click', (e) => {
      if (e.target === overlay) overlay.classList.remove('active');
    });
  });

  // ── Confirm Delete ─────────────────────────────────────
  document.querySelectorAll('[data-confirm]').forEach(btn => {
    btn.addEventListener('click', (e) => {
      const msg = btn.dataset.confirm || 'Are you sure you want to delete this?';
      if (!confirm(msg)) e.preventDefault();
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
    // Basic formatting from HH:MM:SS to HH:MM AM/PM
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
      const isDev = window.location.hostname === 'localhost' || window.location.hostname === '127.0.0.1';
      const apiPath = window.location.pathname.includes('gueco-optical') 
                      ? '/gueco-optical/api/check_appointments.php' 
                      : '/api/check_appointments.php';

      const res = await fetch(apiPath + '?t=' + new Date().getTime(), { cache: 'no-store' });
      const data = await res.json();
      
      if (data && typeof data.count !== 'undefined') {
        const count = data.count;
        const appts = data.appointments || [];
        
        console.log('[Appt Poll] fetched data:', data);

        // Find new appointments
        if (knownApptIds !== null) {
          appts.forEach(appt => {
            const currentId = String(appt.id);
            if (!knownApptIds.has(currentId)) {
              console.log('[Appt Poll] NEW APPOINTMENT DETECTED:', appt);
              // It's a new appointment! Show toast
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
          // Rebuild HTML
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
    setInterval(checkAppointments, 10000); // 10 seconds for more real-time feel
  }

});
