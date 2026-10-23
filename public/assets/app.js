/* =========================================================================
 * REGIS-TRACK — single-page application (vanilla JS, no framework).
 *
 * Security model: the browser NEVER enforces authorization — the server does.
 * This layer only hides what a role cannot use and renders server data via
 * textContent/element builders (no innerHTML with user data, by construction).
 * ========================================================================= */

'use strict';

/* --- State ------------------------------------------------------------- */
const state = {
    user: null,
    csrf: null,
    unread: 0,
};

/* One-shot message that survives a single view re-render (e.g. after submit). */
let flash = null;

/* --- Theme (light/dark) — persisted, no server involvement --------------- */
const THEME_KEY = 'regis-track-theme';
function getSavedTheme() {
    try { return localStorage.getItem(THEME_KEY); } catch { return null; }
}
function applyTheme(theme) {
    const t = theme === 'dark' ? 'dark' : 'light';
    document.documentElement.setAttribute('data-theme', t);
    try { localStorage.setItem(THEME_KEY, t); } catch { /* storage unavailable */ }
}
function currentTheme() {
    return document.documentElement.getAttribute('data-theme') === 'dark' ? 'dark' : 'light';
}
function toggleTheme() {
    applyTheme(currentTheme() === 'dark' ? 'light' : 'dark');
}
(function initTheme() {
    const saved = getSavedTheme();
    if (saved === 'dark' || saved === 'light') { applyTheme(saved); return; }
    const prefersDark = window.matchMedia && window.matchMedia('(prefers-color-scheme: dark)').matches;
    applyTheme(prefersDark ? 'dark' : 'light');
})();

const STATUS_LABELS = {
    pending: 'Pending',
    needs_information: 'Needs Information',
    in_process: 'In-Process',
    ready_for_release: 'Ready for Release',
    released: 'Released',
    rejected: 'Rejected',
    cancelled: 'Cancelled',
};

/* Catalogue groups shown as picker tabs/filters. Rows in the 'both' group are
 * included in Student and Graduate; CAV and Other are exact groups. */
const CATALOG_GROUPS = [
    { value: 'student', label: 'Student' },
    { value: 'graduate', label: 'Graduate' },
    { value: 'cav', label: 'Certification & Authentication' },
    { value: 'other', label: 'Registrar Forms / Other' },
];
const CATALOG_GROUP_LABELS = {
    student: 'Student',
    graduate: 'Graduate',
    both: 'Student & Graduate',
    cav: 'Certification & Authentication',
    other: 'Registrar Forms / Other',
};
const ITEM_TYPE_LABELS = {
    document: 'Document',
    service: 'Service / Form',
};
function catalogGroupLabel(value) {
    return CATALOG_GROUP_LABELS[value] || value || '';
}
function itemTypeLabel(value) {
    return ITEM_TYPE_LABELS[value] || value || '';
}

/* Legal transitions — display mirror of the server-side state machine.
 * true = remark/reason required. The SERVER remains the enforcer. */
const NEXT_STATES = {
    pending: { in_process: false, needs_information: true, rejected: true },
    needs_information: { pending: false, rejected: true },
    in_process: { ready_for_release: false, needs_information: true, rejected: true },
    ready_for_release: { released: false, rejected: true },
    released: {},
    rejected: {},
    cancelled: {},
};

/* --- Tiny DOM helper (XSS-safe: no innerHTML anywhere) ------------------ */
function el(tag, attrs = {}, ...children) {
    const node = document.createElement(tag);
    for (const [key, value] of Object.entries(attrs)) {
        if (value == null || value === false) continue; // false must NOT become attribute "false"
        if (key === 'class') node.className = value;
        else if (key === 'text') node.textContent = value;
        else if (key.startsWith('on') && typeof value === 'function') node.addEventListener(key.slice(2), value);
        else if (key === 'value') node.value = value;
        else node.setAttribute(key, value);
    }
    for (const child of children.flat(Infinity)) {
        if (child == null || child === false) continue;
        node.append(child instanceof Node ? child : document.createTextNode(String(child)));
    }
    return node;
}

/* Null-safe replaceChildren: the DOM method stringifies null into literal
 * "null" text, so conditional nodes must be filtered before passing. */
function setChildren(parent, ...children) {
    parent.replaceChildren(...children.flat(Infinity).filter((c) => c != null && c !== false));
}

/* --- API client --------------------------------------------------------- */
async function api(path, { method = 'GET', body, headers = {} } = {}) {
    const options = {
        method,
        credentials: 'same-origin',
        headers: { ...headers },
    };
    if (body !== undefined) {
        options.headers['Content-Type'] = 'application/json';
        options.body = JSON.stringify(body);
    }
    if (method !== 'GET' && state.csrf) {
        options.headers['X-CSRF-Token'] = state.csrf;
    }

    const response = await fetch(path, options);
    if (response.status === 204) return { data: null, meta: null };

    let payload = {};
    try { payload = await response.json(); } catch { /* non-JSON error body */ }

    if (!response.ok) {
        if (response.status === 401 && state.user) {
            state.user = null;
            renderSidebar();
            location.hash = '#/login';
        }
        const error = new Error(payload?.error?.message || 'Request failed.');
        error.code = payload?.error?.code;
        error.status = response.status;
        error.fields = payload?.error?.fields || {};
        throw error;
    }

    state.csrf = payload?.data?.csrf_token || state.csrf;
    if (payload?.data?.unread_count !== undefined) state.unread = payload.data.unread_count;
    return payload;
}

/* --- Shared UI pieces ---------------------------------------------------- */
function statusBadge(status) {
    const badge = document.createElement('span');
    badge.className = `badge-status st-${status}`;
    if (STATUS_ICONS[status]) badge.appendChild(statusIcon(status));
    badge.appendChild(document.createTextNode(STATUS_LABELS[status] || status));
    return badge;
}

const DISPLAY_TIME_ZONE = 'Asia/Manila';

function fmtDateTime(value) {
    if (!value) return '—';
    const d = parseTimestamp(value);
    if (!d) return String(value);
    return d.toLocaleString(undefined, {
        timeZone: DISPLAY_TIME_ZONE,
        dateStyle: 'medium',
        timeStyle: 'short',
    });
}

function fmtDate(value) {
    if (!value) return '—';
    const text = String(value).trim();
    if (/^\d{4}-\d{2}-\d{2}$/.test(text)) {
        const d = new Date(`${text}T12:00:00+08:00`);
        return isNaN(d) ? text : d.toLocaleDateString(undefined, {
            timeZone: DISPLAY_TIME_ZONE,
            dateStyle: 'medium',
        });
    }
    const d = parseTimestamp(text);
    return d ? d.toLocaleDateString(undefined, { timeZone: DISPLAY_TIME_ZONE, dateStyle: 'medium' }) : text;
}

/* A DB timestamp without a zone is legacy UTC; API values are ISO with +08:00. */
function parseTimestamp(value) {
    const text = String(value).trim();
    if (!text) return null;
    const iso = new Date(text);
    if (!isNaN(iso)) return iso;
    const legacy = new Date(text.replace(' ', 'T') + 'Z');
    return isNaN(legacy) ? null : legacy;
}

/* Calendar date in the Philippines, independent of the device time zone. */
function manilaDateString(date = new Date()) {
    return new Intl.DateTimeFormat('en-CA', {
        timeZone: DISPLAY_TIME_ZONE,
        year: 'numeric',
        month: '2-digit',
        day: '2-digit',
    }).format(date);
}

function messageBox(kind, text) {
    return el('div', { class: kind, text });
}

function fieldError(errors, name) {
    return errors && errors[name] ? el('p', { class: 'field-error', text: errors[name] }) : null;
}

/* Inline stroke icons (Feather-style, 24x24) for the sidebar. */
const NAV_ICONS = {
    requests: '<path d="M16 4h2a2 2 0 0 1 2 2v14a2 2 0 0 1-2 2H6a2 2 0 0 1-2-2V6a2 2 0 0 1 2-2h2"/><rect x="8" y="2" width="8" height="4" rx="1"/><line x1="8" y1="12" x2="16" y2="12"/><line x1="8" y1="16" x2="13" y2="16"/>',
    reports: '<line x1="18" y1="20" x2="18" y2="10"/><line x1="12" y1="20" x2="12" y2="4"/><line x1="6" y1="20" x2="6" y2="14"/><line x1="2" y1="20" x2="22" y2="20"/>',
    history: '<path d="M3 3v5h5"/><path d="M3.05 13A9 9 0 1 0 6 5.3L3 8"/><path d="M12 7v5l4 2"/>',
    users: '<path d="M17 21v-2a4 4 0 0 0-4-4H5a4 4 0 0 0-4 4v2"/><circle cx="9" cy="7" r="4"/><path d="M23 21v-2a4 4 0 0 0-3-3.87"/><path d="M16 3.13a4 4 0 0 1 0 7.75"/>',
    bell: '<path d="M18 8A6 6 0 0 0 6 8c0 7-3 9-3 9h18s-3-2-3-9"/><path d="M13.73 21a2 2 0 0 1-3.46 0"/>',
    settings: '<circle cx="12" cy="12" r="3"/><path d="M19.4 15a1.65 1.65 0 0 0 .33 1.82l.06.06a2 2 0 0 1-2.83 2.83l-.06-.06a1.65 1.65 0 0 0-1.82-.33 1.65 1.65 0 0 0-1 1.51V21a2 2 0 0 1-4 0v-.09A1.65 1.65 0 0 0 9 19.4a1.65 1.65 0 0 0-1.82.33l-.06.06a2 2 0 0 1-2.83-2.83l.06-.06a1.65 1.65 0 0 0 .33-1.82 1.65 1.65 0 0 0-1.51-1H3a2 2 0 0 1 0-4h.09A1.65 1.65 0 0 0 4.6 9a1.65 1.65 0 0 0-.33-1.82l-.06-.06a2 2 0 0 1 2.83-2.83l.06.06a1.65 1.65 0 0 0 1.82.33H9a1.65 1.65 0 0 0 1-1.51V3a2 2 0 0 1 4 0v.09a1.65 1.65 0 0 0 1 1.51 1.65 1.65 0 0 0 1.82-.33l.06-.06a2 2 0 0 1 2.83 2.83l-.06.06a1.65 1.65 0 0 0-.33 1.82V9a1.65 1.65 0 0 0 1.51 1H21a2 2 0 0 1 0 4h-.09a1.65 1.65 0 0 0-1.51 1z"/>',
    user: '<path d="M20 21v-2a4 4 0 0 0-4-4H8a4 4 0 0 0-4 4v2"/><circle cx="12" cy="7" r="4"/>',
    logout: '<path d="M9 21H5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h4"/><polyline points="16 17 21 12 16 7"/><line x1="21" y1="12" x2="9" y2="12"/>',
    sun: '<circle cx="12" cy="12" r="5"/><line x1="12" y1="1" x2="12" y2="3"/><line x1="12" y1="21" x2="12" y2="23"/><line x1="4.22" y1="4.22" x2="5.64" y2="5.64"/><line x1="18.36" y1="18.36" x2="19.78" y2="19.78"/><line x1="1" y1="12" x2="3" y2="12"/><line x1="21" y1="12" x2="23" y2="12"/><line x1="4.22" y1="19.78" x2="5.64" y2="18.36"/><line x1="18.36" y1="5.64" x2="19.78" y2="4.22"/>',
    moon: '<path d="M21 12.79A9 9 0 1 1 11.21 3 7 7 0 0 0 21 12.79z"/>',
    eye: '<path d="M1 12s4-8 11-8 11 8 11 8-4 8-11 8-11-8-11-8z"/><circle cx="12" cy="12" r="3"/>',
    'eye-off': '<path d="M17.94 17.94A10.07 10.07 0 0 1 12 20c-7 0-11-8-11-8a18.45 18.45 0 0 1 5.06-5.94"/><path d="M9.9 4.24A9.12 9.12 0 0 1 12 4c7 0 11 8 11 8a18.5 18.5 0 0 1-2.16 3.19"/><line x1="1" y1="1" x2="23" y2="23"/><path d="M14.12 14.12a3 3 0 1 1-4.24-4.24"/>',
};

function navIcon(name) {
    const svg = document.createElementNS('http://www.w3.org/2000/svg', 'svg');
    svg.setAttribute('class', 'nav-icon');
    svg.setAttribute('viewBox', '0 0 24 24');
    svg.setAttribute('fill', 'none');
    svg.setAttribute('stroke', 'currentColor');
    svg.setAttribute('stroke-width', '2');
    svg.setAttribute('stroke-linecap', 'round');
    svg.setAttribute('stroke-linejoin', 'round');
    svg.setAttribute('aria-hidden', 'true');
    svg.innerHTML = NAV_ICONS[name] || '';
    return svg;
}

/* Password input with an accessible show/hide toggle beside it. */
function passwordField(options = {}) {
    const input = el('input', { ...options, type: 'password' });
    const toggle = el('button', {
        type: 'button',
        class: 'password-toggle',
        'aria-label': 'Show password',
        'aria-pressed': 'false',
        onclick: () => {
            const show = input.type === 'password';
            input.type = show ? 'text' : 'password';
            toggle.setAttribute('aria-label', show ? 'Hide password' : 'Show password');
            toggle.setAttribute('aria-pressed', show ? 'true' : 'false');
            toggle.replaceChildren(navIcon(show ? 'eye-off' : 'eye'));
        },
    }, navIcon('eye'));
    return { input, wrap: el('div', { class: 'password-wrap' }, input, toggle) };
}

const STATUS_ICONS = {
    pending: '<circle cx="12" cy="12" r="10"/><polyline points="12 6 12 12 16 14"/>',
    needs_information: '<circle cx="12" cy="12" r="10"/><path d="M9.09 9a3 3 0 0 1 5.83 1c0 2-3 3-3 3"/><line x1="12" y1="17" x2="12.01" y2="17"/>',
    in_process: '<path d="M21 12a9 9 0 1 1-9-9"/><line x1="12" y1="7" x2="12" y2="12"/><line x1="12" y1="16" x2="12.01" y2="16"/>',
    ready_for_release: '<path d="M16 21V5a2 2 0 0 0-2-2h-4a2 2 0 0 0-2 2v16l4-2 4 2z"/>',
    released: '<path d="M22 11.08V12a10 10 0 1 1-5.93-9.14"/><polyline points="22 4 12 14.01 9 11.01"/>',
    rejected: '<circle cx="12" cy="12" r="10"/><line x1="15" y1="9" x2="9" y2="15"/><line x1="9" y1="9" x2="15" y2="15"/>',
    cancelled: '<circle cx="12" cy="12" r="10"/><line x1="4.93" y1="4.93" x2="19.07" y2="19.07"/>',
};

function statusIcon(status) {
    const svg = document.createElementNS('http://www.w3.org/2000/svg', 'svg');
    svg.setAttribute('class', 'status-icon');
    svg.setAttribute('viewBox', '0 0 24 24');
    svg.setAttribute('fill', 'none');
    svg.setAttribute('stroke', 'currentColor');
    svg.setAttribute('stroke-width', '2');
    svg.setAttribute('stroke-linecap', 'round');
    svg.setAttribute('stroke-linejoin', 'round');
    svg.setAttribute('aria-hidden', 'true');
    svg.style.width = '13px';
    svg.style.height = '13px';
    svg.style.marginRight = '4px';
    svg.style.verticalAlign = '-2px';
    svg.innerHTML = STATUS_ICONS[status] || '';
    return svg;
}

function arrowIcon() {
    const svg = document.createElementNS('http://www.w3.org/2000/svg', 'svg');
    svg.setAttribute('viewBox', '0 0 24 24');
    svg.setAttribute('fill', 'none');
    svg.setAttribute('stroke', 'currentColor');
    svg.setAttribute('stroke-width', '2');
    svg.setAttribute('stroke-linecap', 'round');
    svg.setAttribute('stroke-linejoin', 'round');
    svg.setAttribute('aria-hidden', 'true');
    svg.style.width = '14px';
    svg.style.height = '14px';
    svg.style.margin = '0 6px';
    svg.style.verticalAlign = '-2px';
    svg.style.opacity = '0.6';
    svg.innerHTML = '<line x1="5" y1="12" x2="19" y2="12"/><polyline points="12 5 19 12 12 19"/>';
    return svg;
}

function renderSidebar() {
    const sidebar = document.getElementById('sidebar');
    const toggle = document.getElementById('sidebar-toggle');
    const overlay = document.getElementById('sidebar-overlay');
    const shell = document.querySelector('.shell');
    const app = document.getElementById('app');
    const isLogin = !state.user;
    if (shell) shell.classList.toggle('login-mode', isLogin);
    if (app) app.classList.toggle('login-view', isLogin);
    document.body.classList.toggle('login-mode', isLogin);
    document.documentElement.classList.toggle('login-mode', isLogin);
    sidebar.replaceChildren();

    if (!state.user) {
        sidebar.classList.add('hidden');
        toggle.classList.add('hidden');
        overlay.classList.add('hidden');
        return;
    }

    sidebar.classList.remove('hidden');
    toggle.classList.remove('hidden');

    const closeSidebar = () => {
        document.body.classList.remove('sidebar-open');
        overlay.classList.remove('active');
    };

    // Toggle button opens/closes sidebar on mobile
    toggle.onclick = () => {
        document.body.classList.toggle('sidebar-open');
        overlay.classList.toggle('active');
    };
    overlay.onclick = closeSidebar;

    const home = state.user.role === 'student' ? '#/student' : '#/staff';

    /* --- Sidebar brand header --- */
    const brandIcon = el('img', { src: '/assets/icon-128.png', alt: '', class: 'brand-icon' });
    const brand = el('a', { href: home, class: 'sidebar-brand' }, brandIcon, 'REGIS-TRACK');
    brand.addEventListener('click', closeSidebar);

    /* --- Navigation links --- */
    const navLinks = el('div', { class: 'sidebar-links' });

    const addLink = (href, iconName, label, extra) => {
        const link = el('a', { href }, navIcon(iconName), label, extra || null);
        link.addEventListener('click', closeSidebar);
        navLinks.appendChild(link);
    };

    if (state.user.role === 'student') {
        addLink('#/student', 'requests', 'My Requests');
    } else {
        addLink('#/staff', 'requests', 'Request Queue');
        addLink('#/reports', 'reports', 'Reports');
        if (state.user.role === 'admin') addLink('#/admin/users', 'users', 'Users');
        if (state.user.role === 'admin') addLink('#/admin/settings', 'settings', 'Settings');
    }
    addLink('#/notifications', 'bell', 'Notifications', state.unread > 0 ? el('span', { class: 'badge', text: String(state.unread) }) : null);

    /* --- Sidebar footer: pinned bottom like Image 2 (profile card + Dark mode + Log out) --- */
    const initials = (state.user.full_name || state.user.email || '?')
        .split(/\s+/).map((w) => w[0]).join('').slice(0, 2).toUpperCase();
    const roleLabel = state.user.role === 'admin' ? 'TCGC Registrar Administrator'
        : state.user.role === 'staff' ? 'Registrar Staff' : 'Student';
    const avatar = el('div', { class: 'sidebar-avatar', text: initials });
    const meta = el('div', { class: 'sidebar-user-meta' },
        el('div', { class: 'sidebar-user-name', text: state.user.full_name }),
        el('div', { class: 'sidebar-user-role', text: roleLabel }),
    );
    const userCard = el('div', { class: 'sidebar-user-card' }, avatar, meta);
    const isDark = currentTheme() === 'dark';
    const themeToggle = el('button', {
        class: 'theme-toggle', type: 'button',
        onclick: () => { toggleTheme(); renderSidebar(); },
        'aria-label': isDark ? 'Switch to light mode' : 'Switch to dark mode',
    }, navIcon(isDark ? 'sun' : 'moon'), isDark ? 'Light mode' : 'Dark mode');
    const logoutLink = el('a', { href: '#', onclick: logout }, navIcon('logout'), 'Log out');
    logoutLink.addEventListener('click', closeSidebar);
    const sidebarFooter = el('div', { class: 'sidebar-footer' }, userCard, themeToggle, logoutLink);

    sidebar.replaceChildren(brand, navLinks, sidebarFooter);
}

async function logout(event) {
    event.preventDefault();
    try { await api('/api/v1/auth/logout', { method: 'POST' }); } catch { /* session already gone */ }
    state.user = null;
    state.csrf = null;
    state.unread = 0;
    renderSidebar();
    location.hash = '#/login';
}

/* --- Router -------------------------------------------------------------- */
function route() {
    const hash = location.hash || '#/';
    const app = document.getElementById('app');
    renderSidebar();

    if (!state.user) { viewLogin(); return; }

    const role = state.user.role;
    if (hash.startsWith('#/student/request/')) return viewStudentRequest(decodeURIComponent(hash.split('/')[3]));
    if (hash.startsWith('#/staff/request/')) return viewStaffRequest(Number(hash.split('/')[3]));
    if (hash === '#/login') { location.hash = role === 'student' ? '#/student' : '#/staff'; return; }
    if (hash === '#/student') return role === 'student' ? viewStudentHome() : redirectHome(role);
    if (hash === '#/staff') return role !== 'student' ? viewStaffQueue() : redirectHome(role);
    if (hash === '#/staff/history') {
        // The separate history page was removed; old bookmarks fall back to
        // the filtered queue, where terminal requests remain searchable.
        // Replace (not push) so Back does not bounce through this alias.
        location.replace('#/staff');
        return;
    }
    if (hash === '#/reports') return role !== 'student' ? viewReports() : redirectHome(role);
    if (hash === '#/admin/users') return role === 'admin' ? viewAdminUsers() : redirectHome(role);
    if (hash === '#/admin/settings') return role === 'admin' ? viewAdminSettings() : redirectHome(role);
    if (hash === '#/notifications') return viewNotifications();

    location.hash = role === 'student' ? '#/student' : '#/staff';
}

function redirectHome(role) {
    location.hash = role === 'student' ? '#/student' : '#/staff';
}

/* --- Views: login --------------------------------------------------------- */
function viewLogin(errorText, fieldErrors = {}) {
    const app = document.getElementById('app');
    app.replaceChildren();

    const emailInput = el('input', { type: 'email', name: 'email', required: true, autocomplete: 'username', placeholder: 'you@tcgc.edu.ph' });
    const password = passwordField({ name: 'password', required: true, autocomplete: 'current-password', placeholder: 'Enter your password' });
    const passwordInput = password.input;
    const errorSlot = el('div');

    const form = el('form', {
        onsubmit: async (event) => {
            event.preventDefault();
            errorSlot.replaceChildren();
            try {
                const result = await api('/api/v1/auth/login', {
                    method: 'POST',
                    body: { email: emailInput.value.trim(), password: passwordInput.value },
                });
                state.user = result.data.user;
                state.csrf = result.data.csrf_token;
                await refreshUnread();
                location.hash = state.user.role === 'student' ? '#/student' : '#/staff';
            } catch (err) {
                setChildren(errorSlot, messageBox('form-error', err.message), fieldError(err.fields, 'email'), fieldError(err.fields, 'password'));
            }
        },
    },
        el('label', { text: 'Institutional email' }), emailInput,
        el('label', { text: 'Password' }), password.wrap,
        el('button', { type: 'submit', text: 'Log in' }),
    );

    app.append(el('div', { class: 'login-box card' },
        el('img', { src: '/assets/icon-128.png', alt: 'REGIS-TRACK logo', class: 'login-logo' }),
        el('h1', { text: 'REGIS-TRACK' }),
        el('p', { class: 'muted', text: 'Log in with your institutional credentials.' }),
        errorText ? messageBox('form-error', errorText) : null,
        errorSlot,
        form,
    ));
}

async function refreshUnread() {
    try {
        const result = await api('/api/v1/me');
        state.user = result.data.user;
        state.unread = result.data.unread_count;
        renderSidebar();
    } catch { /* handled by api() */ }
}

/* --- Views: student -------------------------------------------------------- */
async function viewStudentHome() {
    const app = document.getElementById('app');
    app.replaceChildren(el('p', { class: 'muted', text: 'Loading…' }));

    let types, list;
    try {
        [types, list] = await Promise.all([
            api('/api/v1/document-types'),
            api('/api/v1/requests/mine'),
        ]);
    } catch (err) {
        app.replaceChildren(messageBox('form-error', err.message));
        return;
    }

    const formCard = requestFormCard(types.data.items, () => route());
    const flashBox = flash ? messageBox(flash.kind, flash.text) : null;
    flash = null;
    const listCard = el('div', { class: 'card' },
        el('h2', { text: 'My document requests' }),
        requestsTable(list.data.items, { trackingHref: (t) => `#/student/request/${encodeURIComponent(t)}` }),
        list.data.items.length === 0 ? el('p', { class: 'muted', text: 'No requests on record yet. Submit your first request above.' }) : null,
    );

    setChildren(app, el('h1', { text: `Welcome, ${state.user.full_name}` }), flashBox, formCard, listCard);
}

function requestsTable(items, { trackingHref, staffHref, showStudent = false, showCatalogHint = true }) {
    const rows = items.map((r) => {
        const groupHint = showCatalogHint && r.catalog_group
            ? el('div', { class: 'catalog-hint', text: catalogGroupLabel(r.catalog_group) })
            : null;
        return el('tr', { class: 'clickable', onclick: () => { location.hash = trackingHref ? trackingHref(r.tracking_number) : staffHref(r.id); } },
            el('td', { 'data-label': 'Tracking no.', text: r.tracking_number }),
            showStudent ? el('td', { 'data-label': 'Student', text: r.student_name }) : null,
            el('td', { 'data-label': 'Request' }, el('div', { text: r.document_type_name }), groupHint),
            el('td', { 'data-label': 'Qty', text: r.item_type === 'service' ? '—' : String(r.quantity) }),
            el('td', { 'data-label': 'Status' }, statusBadge(r.status)),
            el('td', { 'data-label': 'Submitted', text: fmtDateTime(r.submitted_at) }),
        );
    });
    return el('div', { class: 'table-wrap' },
        el('table', {},
            el('thead', {}, el('tr', {},
                el('th', { text: 'Tracking no.' }),
                showStudent ? el('th', { text: 'Student' }) : null,
                el('th', { text: 'Request' }),
                el('th', { text: 'Qty' }),
                el('th', { text: 'Status' }),
                el('th', { text: 'Submitted' }),
            )),
            el('tbody', {}, rows),
        ));
}

function requestFormCard(documentTypes, onSubmitted) {
    const errorSlot = el('div');
    const warningSlot = el('div');
    const successSlot = el('div');
    const idempotencyKey = (crypto.randomUUID ? crypto.randomUUID() : `web-${Date.now()}-${Math.random()}`);

    const categorySelect = el('select', {},
        el('option', { value: '', text: 'All request types' }),
        CATALOG_GROUPS.map((group) => el('option', { value: group.value, text: group.label })),
    );
    const typeSelect = el('select', { required: true });
    const typeInfo = el('div', { class: 'request-type-info', 'aria-live': 'polite' });
    const qtyLabel = el('label', { text: 'Quantity (1–10)' });
    const qtyInput = el('input', { type: 'number', min: '1', max: '10', value: '1', required: true });
    const purposeLabel = el('label', { text: 'Purpose' });
    const purposeInput = el('textarea', { rows: '3', maxlength: '500', required: true, placeholder: 'State the purpose of this request' });
    const dateLabel = el('label', { text: 'Requested release date' });
    const dateInput = el('input', { type: 'date', required: true });
    const submitButton = el('button', { type: 'submit', text: 'Submit request' });
    let selectedType = null;

    const typesForCategory = (category) => {
        if (category === 'student' || category === 'graduate') {
            return documentTypes.filter((t) => t.catalog_group === category || t.catalog_group === 'both');
        }
        return category === '' ? documentTypes : documentTypes.filter((t) => t.catalog_group === category);
    };

    const updateTypeInfo = () => {
        selectedType = documentTypes.find((t) => String(t.id) === typeSelect.value) || null;
        const isService = selectedType?.item_type === 'service';
        qtyLabel.hidden = isService;
        qtyInput.hidden = isService;
        qtyInput.disabled = isService;
        qtyInput.required = !isService;
        if (isService) qtyInput.value = '1';
        dateLabel.textContent = isService ? 'Preferred appointment date' : 'Requested release date';
        purposeLabel.textContent = isService ? 'Details / purpose' : 'Purpose';
        setChildren(typeInfo,
            selectedType ? el('div', { class: 'request-type-heading' },
                el('strong', { text: selectedType.name }),
                el('span', { class: 'catalog-hint', text: `${catalogGroupLabel(selectedType.catalog_group)} · ${itemTypeLabel(selectedType.item_type)}` }),
            ) : null,
        );
    };

    const rebuildTypeSelect = () => {
        const previous = typeSelect.value;
        const filtered = typesForCategory(categorySelect.value);
        const options = [el('option', { value: '', text: '— Select request type —' })];
        if (categorySelect.value === '') {
            for (const group of ['student', 'graduate', 'both', 'cav', 'other']) {
                const items = filtered.filter((t) => t.catalog_group === group);
                if (items.length === 0) continue;
                options.push(el('optgroup', { label: catalogGroupLabel(group) },
                    items.map((t) => el('option', { value: String(t.id), text: t.name }))));
            }
        } else {
            options.push(...filtered.map((t) => el('option', { value: String(t.id), text: t.name })));
        }
        typeSelect.replaceChildren(...options);
        typeSelect.value = filtered.some((t) => String(t.id) === previous) ? previous : '';
        updateTypeInfo();
    };

    categorySelect.addEventListener('change', rebuildTypeSelect);
    typeSelect.addEventListener('change', updateTypeInfo);

    const form = el('form', {
        onsubmit: async (event) => {
            event.preventDefault();
            errorSlot.replaceChildren(); warningSlot.replaceChildren(); successSlot.replaceChildren();
            submitButton.disabled = true;
            try {
                const result = await api('/api/v1/requests', {
                    method: 'POST',
                    headers: { 'Idempotency-Key': idempotencyKey },
                    body: {
                        document_type_id: Number(typeSelect.value),
                        quantity: selectedType?.item_type === 'service' ? 1 : Number(qtyInput.value),
                        purpose: purposeInput.value.trim(),
                        target_release_date: dateInput.value,
                    },
                });
                const created = result.data.request;
                flash = {
                    kind: 'form-success',
                    text: `Request submitted! Your tracking number is ${created.tracking_number}. Keep it for reference.`,
                };
                (result.data.warnings || []).forEach((w) => flash = { kind: 'form-warning', text: w });
                purposeInput.value = '';
                onSubmitted();
            } catch (err) {
                setChildren(
                    errorSlot,
                    messageBox('form-error', err.message),
                    ...Object.entries(err.fields || {}).map(([field, msg]) => fieldError({ [field]: msg }, field)),
                );
            } finally {
                submitButton.disabled = false;
            }
        },
    },
        el('label', { text: 'Request group' }), categorySelect,
        el('label', { text: 'Request type' }), typeSelect,
        typeInfo,
        qtyLabel, qtyInput,
        purposeLabel, purposeInput,
        dateLabel, dateInput,
        submitButton,
    );

    rebuildTypeSelect();

    return el('div', { class: 'card' },
        el('h2', { text: 'New registrar request' }),
        successSlot, warningSlot, errorSlot, form,
    );
}

async function viewStudentRequest(trackingNumber) {
    const app = document.getElementById('app');
    app.replaceChildren(el('p', { class: 'muted', text: 'Loading…' }));

    let detail;
    try {
        detail = await api(`/api/v1/requests/mine/${encodeURIComponent(trackingNumber)}`);
    } catch (err) {
        app.replaceChildren(messageBox('form-error', err.message), el('a', { class: 'back-btn', href: '#/student', text: '← Back to my requests' }));
        return;
    }

    const request = detail.data.request;
    const cancelSlot = el('div');

    const cancelButton = el('button', {
        class: 'danger',
        text: 'Cancel this request',
        onclick: async () => {
            if (!window.confirm('Cancel this request? This cannot be undone.')) return;
            try {
                await api(`/api/v1/requests/mine/${encodeURIComponent(trackingNumber)}/cancel`, {
                    method: 'POST',
                    body: { reason: 'Cancelled by student via portal' },
                });
                route();
            } catch (err) {
                cancelSlot.replaceChildren(messageBox('form-error', err.message));
            }
        },
    });

    const backBtn = el('a', { class: 'back-btn', href: '#/student', text: '← Back to my requests' });

    const header = el('div', { class: 'request-header' },
        el('div', {},
            el('h1', { text: request.tracking_number }),
            el('div', { class: 'muted', text: request.item_type === 'service'
                ? request.document_type_name
                : `${request.document_type_name} × ${request.quantity}` }),
        ),
        statusBadge(request.status),
    );

    const details = el('div', { class: 'detail-grid' },
        el('div', { class: 'detail-field' },
            el('span', { class: 'detail-label', text: 'Purpose' }),
            el('p', { class: 'detail-value', text: request.purpose }),
        ),
        el('div', { class: 'detail-field' },
            el('span', { class: 'detail-label', text: 'Submitted' }),
            el('p', { class: 'detail-value muted', text: fmtDateTime(request.submitted_at) }),
        ),
        el('div', { class: 'detail-field' },
            el('span', { class: 'detail-label', text: 'Requested release' }),
            el('p', { class: 'detail-value muted', text: fmtDate(request.target_release_date) }),
        ),
        request.current_remark ? el('div', { class: 'detail-field' },
            el('span', { class: 'detail-label', text: 'Latest remark / message' }),
            el('p', { class: 'detail-value', text: request.current_remark }),
        ) : null,
    );

    setChildren(app,
        backBtn,
        el('div', { class: 'card request-card' },
            header,
            details,
            request.status === 'pending' ? el('div', { class: 'actions', style: 'margin-top:1.1rem;' }, cancelButton) : null,
            cancelSlot,
        ),
        historyCard(detail.data.history),
    );
}

function historyCard(history) {
    const items = (history || []).map((h) => el('li', {},
        el('div', { style: 'display:flex; align-items:center; gap:6px;' },
            statusBadge(h.new_status),
            !h.prev_status ? el('span', { class: 'muted', text: '(submitted)', style: 'font-size:0.82rem;' }) : null
        ),
        h.remark ? el('div', { text: h.remark, style: 'margin-top:4px;' }) : null,
        el('div', { class: 'when', text: `${fmtDateTime(h.created_at)} — ${h.actor_name} (${h.actor_role})` }),
    ));
    return el('div', { class: 'card' },
        el('h2', { text: 'Processing history' }),
        items.length === 0 ? el('p', { class: 'muted', text: 'No history yet.' }) : el('ul', { class: 'timeline' }, items),
    );
}

/* --- Views: staff ----------------------------------------------------------- */
async function viewStaffQueue(page = 1) {
    await renderStaffRequestList({
        title: 'Request queue',
        page: Math.max(1, page),
        pageSize: 10,
    });
}

async function renderStaffRequestList({ title, page, pageSize }) {
    const app = document.getElementById('app');

    // Read filter values BEFORE clearing the app container: the filter inputs
    // live inside #app, so wiping first would destroy them (and lose the query).
    const statusFilter = document.getElementById('f-status')?.value ?? 'active';
    const params = new URLSearchParams();
    if (statusFilter === 'active') {
        params.set('active_only', '1');
    } else if (statusFilter !== '') {
        params.set('status', statusFilter);
    }
    for (const [id, key] of [
        ['f-q', 'q'],
        ['f-category', 'catalog_group'],
    ]) {
        const value = document.getElementById(id)?.value?.trim();
        if (value) params.set(key, value);
    }
    params.set('page', String(page));
    params.set('page_size', String(pageSize));

    app.replaceChildren(el('p', { class: 'muted', text: 'Loading…' }));

    let result;
    try {
        result = await api(`/api/v1/staff/requests?${params.toString()}`);
    } catch (err) {
        app.replaceChildren(messageBox('form-error', err.message));
        return;
    }

    const statusSelect = el('select', { id: 'f-status' },
        el('option', { value: 'active', text: 'Active requests', selected: statusFilter === 'active' }),
        el('option', { value: '', text: 'All statuses', selected: statusFilter === '' }),
        Object.entries(STATUS_LABELS).map(([value, label]) => el('option', { value, text: label, selected: statusFilter === value })),
    );
    const searchInput = el('input', { id: 'f-q', type: 'search', placeholder: 'Tracking no., student, ID…', value: params.get('q') || '' });
    const categorySelect = el('select', { id: 'f-category' },
        el('option', { value: '', text: 'All groups' }),
        CATALOG_GROUPS.map((group) => el('option', {
            value: group.value,
            text: group.label,
            selected: params.get('catalog_group') === group.value,
        })),
    );
    const statusField = el('label', { class: 'filter-field' }, el('span', { class: 'filter-label', text: 'Status' }), statusSelect);
    const categoryField = el('label', { class: 'filter-field' }, el('span', { class: 'filter-label', text: 'Group' }), categorySelect);
    const searchField = el('label', { class: 'filter-field' }, el('span', { class: 'filter-label', text: 'Search' }), searchInput);
    const applyFilters = () => viewStaffQueue(1);

    const meta = result.data.meta;
    const firstShown = meta.total === 0 ? 0 : (page - 1) * meta.page_size + 1;
    const lastShown = Math.min(page * meta.page_size, meta.total);
    const totalPages = Math.max(1, Math.ceil(meta.total / meta.page_size));
    const note = meta.total === 0
        ? 'No requests found for this filter (newest first).'
        : `${meta.total} request(s) found (newest first). Showing ${firstShown}–${lastShown}.`;
    const pagination = meta.total === 0
        ? null
        : el('div', { class: 'actions', style: 'margin-top:1rem; display:flex; align-items:center; gap:0.6rem; flex-wrap:wrap;' },
            page > 1 ? el('button', { class: 'secondary', text: '← Newer', onclick: () => viewStaffQueue(page - 1) }) : null,
            el('span', { class: 'muted', text: `Page ${page} of ${totalPages}` }),
            (page * meta.page_size) < meta.total ? el('button', { class: 'secondary', text: 'Older →', onclick: () => viewStaffQueue(page + 1) }) : null,
        );

    app.replaceChildren(
        el('h1', { text: title }),
        state.user.role === 'admin'
            ? el('p', { class: 'muted', text: 'Administrator view-only: registrar staff handle request status changes and student messages.' })
            : null,
        el('div', { class: 'card filters' },
            statusField, categoryField, searchField,
            el('button', { class: 'wide', text: 'Apply filters', onclick: applyFilters }),
        ),
        el('div', { class: 'card' },
            el('p', { class: 'muted', text: note }),
            requestsTable(result.data.items, { staffHref: (id) => `#/staff/request/${id}`, showStudent: true, showCatalogHint: false }),
            result.data.items.length === 0 ? el('p', { class: 'muted', text: 'No matching requests. Adjust the filters above.' }) : null,
            pagination,
        ),
    );
}

async function viewStaffRequest(requestId) {
    const app = document.getElementById('app');
    app.replaceChildren(el('p', { class: 'muted', text: 'Loading…' }));

    let detail;
    try {
        detail = await api(`/api/v1/requests/${requestId}`);
    } catch (err) {
        app.replaceChildren(messageBox('form-error', err.message), el('a', { class: 'back-btn', href: '#/staff', text: '← Back to queue' }));
        return;
    }

    const { request, history, audit, issuance } = detail.data;
    const actionSlot = el('div');
    const isAdmin = state.user.role === 'admin';

    // Staff manage the workflow. Administrators have read-only oversight of
    // the queue and detail, so no transition or message actions are rendered.
    const blocked = Boolean(issuance && issuance.blocked);
    const canRejectCurrent = Boolean((NEXT_STATES[request.status] || {}).rejected);
    const staffOnlyReject = blocked && !isAdmin && canRejectCurrent;
    const allowedStates = isAdmin
        ? {}
        : (staffOnlyReject ? { rejected: true } : (NEXT_STATES[request.status] || {}));

    // The rejection reason is already the message to the student. Do not show
    // a second, identically prefilled message form for a staff rejection.
    // Terminal duplicates (released/rejected/cancelled) can still be replied to.
    const showReplyForm = !isAdmin
        && !staffOnlyReject
        && Boolean(issuance?.student_message || ['released', 'rejected', 'cancelled'].includes(request.status));

    const transitionButtons = Object.entries(allowedStates).map(([to, reasonRequired]) =>
        el('button', {
            text: `Mark as ${STATUS_LABELS[to]}`,
            onclick: () => renderTransitionForm(actionSlot, request, to, reasonRequired, issuance),
        }));

    const backBtn = el('a', { class: 'back-btn', href: '#/staff', text: '← Back to queue' });
    const header = el('div', { class: 'request-header' },
        el('div', {},
            el('h1', { text: request.tracking_number }),
            el('div', { class: 'muted', text: request.item_type === 'service'
                ? request.document_type_name
                : `${request.document_type_name} × ${request.quantity}` }),
        ),
        statusBadge(request.status),
    );
    const details = el('div', { class: 'detail-grid' },
        el('div', { class: 'detail-field' },
            el('span', { class: 'detail-label', text: 'Student' }),
            el('p', { class: 'detail-value', text: `${request.student_name} (${request.student_number || 'no student number'})` }),
        ),
        el('div', { class: 'detail-field' },
            el('span', { class: 'detail-label', text: 'Purpose' }),
            el('p', { class: 'detail-value', text: request.purpose }),
        ),
        el('div', { class: 'detail-field' },
            el('span', { class: 'detail-label', text: 'Submitted' }),
            el('p', { class: 'detail-value muted', text: fmtDateTime(request.submitted_at) }),
        ),
        el('div', { class: 'detail-field' },
            el('span', { class: 'detail-label', text: 'Target release' }),
            el('p', { class: 'detail-value muted', text: fmtDate(request.target_release_date) }),
        ),
        request.current_remark ? el('div', { class: 'detail-field' },
            el('span', { class: 'detail-label', text: 'Latest remark / message' }),
            el('p', { class: 'detail-value', text: request.current_remark }),
        ) : null,
    );

    setChildren(app,
        backBtn,
        el('div', { class: 'card request-card' },
            header,
            details,
            issuanceNotice(issuance),
            isAdmin
                ? el('p', { class: 'muted', text: 'Administrator view-only: request status changes and student messages are handled by registrar staff.' })
                : null,
            !isAdmin
                ? el('div', { class: 'actions', style: 'margin-top:1.1rem;' }, transitionButtons)
                : null,
            !isAdmin ? actionSlot : null,
            showReplyForm
                ? issuanceReplyForm(request.id, issuance?.student_message || '')
                : null,
        ),
        historyCard(history),
        auditCard(audit),
    );
}

/*
 * Staff-side issuance check. Blocking rules are enforced by the server; this
 * renders the same decision. Staff may only reject a blocked duplicate.
 */
function issuanceNotice(issuance) {
    if (!issuance || issuance.policy === 'unlimited') return null;

    const period = issuance.period_label ? ` — ${issuance.period_label}` : '';
    const children = [
        el('p', { class: 'muted', text: `Issuance policy: ${issuance.policy_label}${period}` }),
    ];

    if (issuance.blocked) {
        children.push(messageBox('form-error', issuance.message));
        if (state.user.role !== 'admin') {
            children.push(el('p', { class: 'muted', text: 'This duplicate can only be rejected. Use the Rejected action below.' }));
        }
    } else if (issuance.warning) {
        children.push(messageBox('form-warning', issuance.message));
    }

    const prior = issuance.prior || issuance.active;
    if (prior) {
        const priorLabel = issuance.blocked
            ? 'Previous issuance (separate request):'
            : 'Earlier request (separate from this one):';
        children.push(el('div', { style: 'margin-top:0.5rem;' },
            el('p', { class: 'muted', text: priorLabel }),
            el('p', {},
                el('a', { href: `#/staff/request/${prior.id}`, text: prior.tracking_number }),
                ` (${prior.status_label}${prior.term_label ? `, ${prior.term_label}` : ''})`,
            ),
            el('p', { class: 'muted', text: 'The processing history below belongs to this request only.' }),
        ));
    }

    return el('div', { style: 'margin-top:1rem;' }, children);
}

/*
 * Prefilled reply so staff can tell the student why the duplicate is blocked
 * without forcing a status transition (e.g. the request is already released).
 */
function issuanceReplyForm(requestId, defaultMessage) {
    const messageInput = el('textarea', {
        rows: '3',
        maxlength: '500',
        value: defaultMessage,
        placeholder: 'Message to student',
    });
    const resultSlot = el('div');
    const sendButton = el('button', {
        text: 'Send message to student',
        onclick: async () => {
            resultSlot.replaceChildren();
            sendButton.disabled = true;
            try {
                await api(`/api/v1/requests/${requestId}/message`, {
                    method: 'POST',
                    body: { message: messageInput.value.trim() },
                });
                resultSlot.replaceChildren(messageBox('form-success',
                    'Message sent. The student will see it in Notifications and on this request.'));
            } catch (err) {
                resultSlot.replaceChildren(messageBox('form-error', err.message));
                sendButton.disabled = false;
            }
        },
    });

    return el('div', { style: 'margin-top:0.9rem;' },
        el('label', { text: 'Message to student' }),
        messageInput,
        sendButton,
        resultSlot,
    );
}

function renderTransitionForm(slot, request, toStatus, reasonRequired, issuance) {
    const blockedRejection = Boolean(issuance && issuance.blocked && toStatus === 'rejected');
    const reasonNeeded = reasonRequired || blockedRejection;
    const defaultRemark = blockedRejection ? (issuance.student_message || issuance.message || '') : '';
    const remarkInput = el('textarea', {
        rows: '2',
        maxlength: '500',
        value: defaultRemark,
        placeholder: reasonNeeded ? 'Reason (required)' : 'Remark (optional)',
    });
    const errorSlot = el('div');
    const submit = el('button', {
        text: `Confirm: ${STATUS_LABELS[toStatus]}`,
        onclick: async () => {
            errorSlot.replaceChildren();
            submit.disabled = true;
            try {
                await api(`/api/v1/requests/${request.id}/transition`, {
                    method: 'POST',
                    body: {
                        to: toStatus,
                        remark: remarkInput.value.trim(),
                        version: request.version,
                    },
                });
                route();
            } catch (err) {
                errorSlot.replaceChildren(messageBox('form-error', err.message));
                submit.disabled = false;
            }
        },
    });

    const reasonLabel = blockedRejection
        ? 'Reason for rejection (required)'
        : reasonNeeded ? 'Reason (required by workflow rules)' : 'Remark (optional)';

    slot.replaceChildren(el('div', { class: 'card' },
        el('h2', { text: `Transition to ${STATUS_LABELS[toStatus]}` }),
        errorSlot,
        el('form', { onsubmit: (e) => { e.preventDefault(); submit.click(); } },
            el('label', { text: reasonLabel }),
            remarkInput,
            submit,
        ),
    ));
}

function auditChangeEl(a) {
    try {
        if (a.before_value && a.after_value) {
            const b = JSON.parse(a.before_value);
            const af = JSON.parse(a.after_value);
            if (b && af && b.status && af.status) {
                const wrap = el('span', { style: 'display:inline-flex; align-items:center; gap:8px;' });
                wrap.appendChild(statusBadge(b.status));
                wrap.appendChild(statusBadge(af.status));
                return wrap;
            }
        }
        if (a.after_value) {
            try {
                const af = JSON.parse(a.after_value);
                if (af && af.tracking_number) {
                    const wrap = el('span', { class: 'audit-pills' });
                    const makePill = (icon, text) => {
                        const p = el('span', { class: 'audit-pill' });
                        const ic = navIcon(icon);
                        ic.style.width = '12px'; ic.style.height = '12px'; ic.style.marginRight = '0';
                        p.appendChild(ic);
                        p.appendChild(document.createTextNode(' ' + text));
                        return p;
                    };
                    wrap.appendChild(makePill('requests', af.tracking_number));
                    const docName = af.document_type_name || af.document_type || '';
                    if (docName) wrap.appendChild(makePill('reports', String(docName)));
                    if (af.quantity != null && af.quantity !== '') wrap.appendChild(makePill('users', '×' + String(af.quantity)));
                    if (af.target_release_date) wrap.appendChild(makePill('bell', String(af.target_release_date)));
                    return wrap;
                }
            } catch {}
        }
    } catch {}
    const raw = a.before_value && a.after_value
        ? `${shortJson(a.before_value)} → ${shortJson(a.after_value)}`
        : (shortJson(a.after_value) || '—');
    const clean = raw.replace(/status=/g, '').replace(/ → /g, '  ');
    return el('span', { text: clean });
}

function auditCard(audit) {
    const rows = (audit || []).map((a) => el('tr', {},
        el('td', { 'data-label': 'When', text: fmtDateTime(a.created_at) }),
        el('td', { 'data-label': 'Actor', text: `${a.actor_name} (${a.actor_role})` }),
        el('td', { 'data-label': 'Action', text: a.action }),
        el('td', { 'data-label': 'Change' }, auditChangeEl(a)),
    ));
    return el('div', { class: 'card' },
        el('h2', { text: 'Audit trail (immutable)' }),
        el('div', { class: 'table-wrap' }, el('table', {},
            el('thead', {}, el('tr', {},
                el('th', { text: 'When' }), el('th', { text: 'Actor' }), el('th', { text: 'Action' }), el('th', { text: 'Change' }))),
            el('tbody', {}, rows)),
        ),
    );
}

function shortJson(value) {
    if (!value) return '';
    try {
        const parsed = JSON.parse(value);
        return Object.entries(parsed).map(([k, v]) => `${k}=${v}`).join(', ');
    } catch { return String(value); }
}

/* --- Views: notifications ------------------------------------------------------ */
function notificationHref(notification) {
    if (state.user.role === 'student') {
        return notification.tracking_number
            ? `#/student/request/${encodeURIComponent(notification.tracking_number)}`
            : null;
    }
    return notification.request_id ? `#/staff/request/${notification.request_id}` : null;
}

async function viewNotifications() {
    const app = document.getElementById('app');
    app.replaceChildren(el('p', { class: 'muted', text: 'Loading…' }));

    let result;
    try {
        result = await api('/api/v1/notifications?page=1&page_size=20');
    } catch (err) {
        app.replaceChildren(messageBox('form-error', err.message));
        return;
    }

    const unreadWhenOpened = result.data.meta.unread;
    const items = result.data.items.map((n) => {
        const target = notificationHref(n);
        const content = [
            el('div', { class: 'notif-head' },
                el('span', { class: 'notif-icon' }, navIcon('bell')),
                el('strong', { text: n.subject }),
                n.read_at ? null : el('span', { class: 'badge badge-new', text: 'new' }),
                target ? el('span', { class: 'notif-open', text: 'View request →' }) : null
            ),
            el('div', { class: 'notif-body', text: n.body }),
            el('div', { class: 'when', text: fmtDateTime(n.created_at) }),
        ];

        return el('li', { class: 'notif-item' + (target ? ' clickable' : '') },
            target
                ? el('a', {
                    class: 'notif-link',
                    href: target,
                    'aria-label': `Open request ${n.tracking_number || n.request_id}`,
                }, ...content)
                : content
        );
    });

    app.replaceChildren(
        el('h1', { text: 'Notifications' }),
        el('div', { class: 'card' },
            el('p', { class: 'muted', text: unreadWhenOpened > 0
                ? `${unreadWhenOpened} unread. Opening this page marks them as read automatically.`
                : 'All caught up. New notifications will appear here.' }),
            items.length === 0 ? el('p', { class: 'muted', text: 'No notifications yet.' }) : el('ul', { class: 'notif-list' }, items),
        ),
    );

    // Auto-acknowledge the panel: no per-item "Mark as read" click needed.
    // The list above keeps its "new" badges for this visit; the sidebar badge
    // is refreshed immediately.
    if (unreadWhenOpened > 0) {
        try {
            await api('/api/v1/notifications/read-all', { method: 'POST' });
            await refreshUnread();
        } catch (err) {
            app.prepend(messageBox('form-error', err.message));
        }
    }
}

/* --- Views: admin users ----------------------------------------------------------- */
async function viewAdminUsers(formState = {}) {
    const app = document.getElementById('app');
    app.replaceChildren(el('p', { class: 'muted', text: 'Loading…' }));

    const includeDisabled = Boolean(formState.includeDisabled);
    let result;
    try {
        result = await api(`/api/v1/admin/users?page=1&page_size=50${includeDisabled ? '&include_disabled=1' : ''}`);
    } catch (err) {
        app.replaceChildren(messageBox('form-error', err.message));
        return;
    }

    const flashBox = flash ? messageBox(flash.kind, flash.text) : null;
    flash = null;
    const accountsErrorSlot = el('div');

    const deleteAccount = async (u) => {
        if (!window.confirm(`Delete ${u.full_name}? This disables the account and preserves its request history.`)) return;
        accountsErrorSlot.replaceChildren();
        try {
            await api(`/api/v1/admin/users/${u.id}`, { method: 'DELETE' });
            flash = { kind: 'form-success', text: `${u.full_name} was deleted (login disabled).` };
            viewAdminUsers({ includeDisabled });
        } catch (err) {
            accountsErrorSlot.replaceChildren(messageBox('form-error', err.message));
        }
    };

    const restoreAccount = async (u) => {
        if (!window.confirm(`Restore ${u.full_name} and allow login again?`)) return;
        accountsErrorSlot.replaceChildren();
        try {
            await api(`/api/v1/admin/users/${u.id}`, { method: 'PATCH', body: { status: 'active' } });
            flash = { kind: 'form-success', text: `${u.full_name} was restored.` };
            viewAdminUsers({ includeDisabled });
        } catch (err) {
            accountsErrorSlot.replaceChildren(messageBox('form-error', err.message));
        }
    };

    const rows = result.data.items.map((u) => {
        const isSelf = Number(u.id) === Number(state.user.id);
        const action = u.status === 'active'
            ? (isSelf
                ? el('span', { class: 'muted', text: 'Current account' })
                : el('button', { class: 'danger', text: 'Delete', onclick: () => deleteAccount(u) }))
            : el('button', { class: 'secondary', text: 'Restore', onclick: () => restoreAccount(u) });

        return el('tr', {},
            el('td', { 'data-label': 'Name', text: u.full_name }),
            el('td', { 'data-label': 'Email', text: u.email }),
            el('td', { 'data-label': 'Role', text: u.role }),
            el('td', { 'data-label': 'Status' }, statusBadge(u.status === 'active' ? 'released' : 'cancelled'), ` ${u.status}`),
            el('td', { 'data-label': 'Student no.', text: u.student_number || '—' }),
            el('td', { 'data-label': 'Actions' }, action),
        );
    });

    const errorSlot = el('div');
    const emailInput = el('input', { type: 'email', required: true, placeholder: 'new.user@tcgc.edu.ph', value: formState.email || '' });
    const nameInput = el('input', { type: 'text', required: true, placeholder: 'Full name', value: formState.fullName || '' });
    const roleSelect = el('select', {},
        el('option', { value: 'student', text: 'Student' }),
        el('option', { value: 'staff', text: 'Registrar Staff' }),
        el('option', { value: 'admin', text: 'Administrator' }),
    );
    const studentNumberHint = el('p', {
        class: 'muted',
        text: 'Student number is generated automatically (YYYY-#####).',
    });
    roleSelect.addEventListener('change', () => {
        studentNumberHint.hidden = roleSelect.value !== 'student';
    });
    const password = passwordField({ required: true, placeholder: 'Initial password (min 8 chars)' });
    const passwordInput = password.input;

    const createForm = el('form', {
        onsubmit: async (event) => {
            event.preventDefault();
            errorSlot.replaceChildren();
            try {
                const created = await api('/api/v1/admin/users', {
                    method: 'POST',
                    body: {
                        email: emailInput.value.trim(),
                        full_name: nameInput.value.trim(),
                        role: roleSelect.value,
                        password: passwordInput.value,
                    },
                });
                const user = created.data.user;
                flash = {
                    kind: 'form-success',
                    text: user.student_number
                        ? `Account created. Student number: ${user.student_number}.`
                        : 'Account created.',
                };
                viewAdminUsers({ includeDisabled });
            } catch (err) {
                setChildren(
                    errorSlot,
                    messageBox('form-error', err.message),
                    ...Object.entries(err.fields || {}).map(([field, msg]) => fieldError({ [field]: msg }, field)),
                );
            }
        },
    },
        el('label', { text: 'Email' }), emailInput,
        el('label', { text: 'Full name' }), nameInput,
        el('label', { text: 'Role' }), roleSelect,
        studentNumberHint,
        el('label', { text: 'Initial password' }), password.wrap,
        el('button', { type: 'submit', text: 'Create account' }),
    );

    const showDisabledInput = el('input', { type: 'checkbox', style: 'width:auto;', checked: includeDisabled });
    showDisabledInput.addEventListener('change', () => {
        viewAdminUsers({ includeDisabled: showDisabledInput.checked });
    });

    setChildren(app,
        el('h1', { text: 'User management' }),
        flashBox,
        el('div', { class: 'card' }, el('h2', { text: 'Create account' }), errorSlot, createForm),
        el('div', { class: 'card' },
            el('h2', { text: `Accounts (${result.data.meta.total})` }),
            el('label', { class: 'checkbox-row' }, showDisabledInput, ' Show disabled accounts'),
            accountsErrorSlot,
            el('div', { class: 'table-wrap' }, el('table', {},
                el('thead', {}, el('tr', {},
                    el('th', { text: 'Name' }), el('th', { text: 'Email' }), el('th', { text: 'Role' }),
                    el('th', { text: 'Status' }), el('th', { text: 'Student no.' }), el('th', { text: 'Actions' }))),
                el('tbody', {}, rows)),
            ),
        ),
    );
}

/* --- Views: admin settings (terms + catalogue + issuance policies) --------------- */
async function viewAdminSettings() {
    const app = document.getElementById('app');
    app.replaceChildren(el('p', { class: 'muted', text: 'Loading…' }));

    let settings;
    try {
        settings = (await api('/api/v1/admin/settings')).data;
    } catch (err) {
        app.replaceChildren(messageBox('form-error', err.message));
        return;
    }

    const errorSlot = el('div');
    const current = settings.terms.find((t) => t.is_current);

    const termRows = settings.terms.map((term) => el('tr', {},
        el('td', { 'data-label': 'Term', text: term.label }),
        el('td', { 'data-label': 'Dates', text: `${term.starts_on} → ${term.ends_on}` }),
        el('td', { 'data-label': 'Current' }, term.is_current
            ? el('span', { class: 'badge-status st-released', text: 'Current' })
            : el('button', {
                class: 'secondary',
                text: 'Set current',
                onclick: async () => {
                    try {
                        await api(`/api/v1/admin/terms/${term.id}`, {
                            method: 'PATCH',
                            body: { is_current: true },
                        });
                        viewAdminSettings();
                    } catch (err) {
                        errorSlot.replaceChildren(messageBox('form-error', err.message));
                    }
                },
            })),
    ));

    const schoolYearInput = el('input', { type: 'text', required: true, placeholder: '2026-2027' });
    const semesterSelect = el('select', {},
        el('option', { value: 'first', text: '1st Semester' }),
        el('option', { value: 'second', text: '2nd Semester' }),
        el('option', { value: 'summer', text: 'Summer' }),
    );
    const startsInput = el('input', { type: 'date', required: true });
    const endsInput = el('input', { type: 'date', required: true });
    const currentCheckbox = el('input', { type: 'checkbox', style: 'width:auto;' });

    const createTermForm = el('form', {
        onsubmit: async (event) => {
            event.preventDefault();
            errorSlot.replaceChildren();
            try {
                await api('/api/v1/admin/terms', {
                    method: 'POST',
                    body: {
                        school_year: schoolYearInput.value.trim(),
                        semester: semesterSelect.value,
                        starts_on: startsInput.value,
                        ends_on: endsInput.value,
                        is_current: currentCheckbox.checked,
                    },
                });
                viewAdminSettings();
            } catch (err) {
                setChildren(
                    errorSlot,
                    messageBox('form-error', err.message),
                    ...Object.entries(err.fields || {}).map(([field, msg]) => fieldError({ [field]: msg }, field)),
                );
            }
        },
    },
        el('label', { text: 'School year (YYYY-YYYY)' }), schoolYearInput,
        el('label', { text: 'Semester' }), semesterSelect,
        el('label', { text: 'Starts on' }), startsInput,
        el('label', { text: 'Ends on' }), endsInput,
        el('label', { class: 'checkbox-row' }, currentCheckbox, ' Make this the current term'),
        el('button', { type: 'submit', text: 'Add term' }),
    );

    const groupOptions = (selected) => settings.catalog_groups.map((group) => el('option', {
        value: group.value,
        text: group.label,
        selected: group.value === selected,
    }));
    const itemTypeOptions = (selected) => settings.item_types.map((type) => el('option', {
        value: type.value,
        text: type.label,
        selected: type.value === selected,
    }));
    const policyOptions = (selected) => settings.policies.map((policy) => el('option', {
        value: policy.value,
        text: policy.label,
        selected: policy.value === selected,
    }));

    const catalogRows = settings.document_types.map((type) => {
        const nameInput = el('input', { type: 'text', maxlength: '120', value: type.name, required: true });
        const groupSelect = el('select', {}, groupOptions(type.catalog_group));
        const itemTypeSelect = el('select', {}, itemTypeOptions(type.item_type));
        const policySelect = el('select', {}, policyOptions(type.issuance_policy));
        const activeInput = el('input', { type: 'checkbox', checked: type.is_active, style: 'width:auto;' });
        const saveButton = el('button', {
            text: 'Save',
            onclick: async () => {
                errorSlot.replaceChildren();
                try {
                    await api(`/api/v1/admin/document-types/${type.id}`, {
                        method: 'PATCH',
                        body: {
                            name: nameInput.value.trim(),
                            catalog_group: groupSelect.value,
                            item_type: itemTypeSelect.value,
                            issuance_policy: policySelect.value,
                            is_active: activeInput.checked,
                        },
                    });
                    viewAdminSettings();
                } catch (err) {
                    setChildren(
                        errorSlot,
                        messageBox('form-error', err.message),
                        ...Object.entries(err.fields || {}).map(([field, msg]) => fieldError({ [field]: msg }, field)),
                    );
                }
            },
        });
        const isCore = Number(type.id) <= 5;
        const deleteButton = el('button', {
            class: 'danger',
            text: 'Delete',
            disabled: isCore,
            title: isCore ? 'Core catalogue items cannot be deleted; deactivate them instead.' : 'Delete this catalogue item',
            onclick: async () => {
                if (!window.confirm(`Delete "${type.name}"? This cannot be undone. If it has request history, deactivate it instead.`)) return;
                errorSlot.replaceChildren();
                try {
                    await api(`/api/v1/admin/document-types/${type.id}`, { method: 'DELETE' });
                    viewAdminSettings();
                } catch (err) {
                    errorSlot.replaceChildren(messageBox('form-error', err.message));
                }
            },
        });

        return el('tr', {},
            el('td', { 'data-label': 'Request' }, nameInput),
            el('td', { 'data-label': 'Group' }, groupSelect),
            el('td', { 'data-label': 'Type' }, itemTypeSelect),
            el('td', { 'data-label': 'Policy' }, policySelect),
            el('td', { 'data-label': 'Active' }, activeInput),
            el('td', { 'data-label': 'Actions' }, el('div', { class: 'actions' }, saveButton, deleteButton)),
        );
    });

    const newNameInput = el('input', { type: 'text', maxlength: '120', required: true, placeholder: 'e.g. Certification of Grades' });
    const newGroupSelect = el('select', {}, groupOptions('student'));
    const newItemTypeSelect = el('select', {}, itemTypeOptions('document'));
    const newPolicySelect = el('select', {}, policyOptions('unlimited'));
    const newActiveInput = el('input', { type: 'checkbox', checked: true, style: 'width:auto;' });

    const createTypeForm = el('form', {
        onsubmit: async (event) => {
            event.preventDefault();
            errorSlot.replaceChildren();
            try {
                await api('/api/v1/admin/document-types', {
                    method: 'POST',
                    body: {
                        name: newNameInput.value.trim(),
                        catalog_group: newGroupSelect.value,
                        item_type: newItemTypeSelect.value,
                        issuance_policy: newPolicySelect.value,
                        is_active: newActiveInput.checked,
                    },
                });
                viewAdminSettings();
            } catch (err) {
                setChildren(
                    errorSlot,
                    messageBox('form-error', err.message),
                    ...Object.entries(err.fields || {}).map(([field, msg]) => fieldError({ [field]: msg }, field)),
                );
            }
        },
    },
        el('label', { text: 'Request type name' }), newNameInput,
        el('label', { text: 'Group' }), newGroupSelect,
        el('label', { text: 'Item type' }), newItemTypeSelect,
        el('label', { text: 'Issuance policy' }), newPolicySelect,
        el('label', { class: 'checkbox-row' }, newActiveInput, ' Active'),
        el('button', { type: 'submit', text: 'Add catalogue item' }),
    );

    app.replaceChildren(
        el('h1', { text: 'Registrar settings' }),
        errorSlot,
        el('div', { class: 'card' },
            el('h2', { text: 'Current term' }),
            el('p', { class: 'muted', text: current
                ? `${current.label} (${current.starts_on} to ${current.ends_on})`
                : 'No current term is configured. Restricted documents cannot be validated until one is set.' }),
        ),
        el('div', { class: 'card' },
            el('h2', { text: 'Academic terms' }),
            el('div', { class: 'table-wrap' }, el('table', {},
                el('thead', {}, el('tr', {},
                    el('th', { text: 'Term' }), el('th', { text: 'Dates' }), el('th', { text: 'Current' }))),
                el('tbody', {}, termRows),
            )),
        ),
        el('div', { class: 'card' },
            el('h2', { text: 'Add academic term' }),
            createTermForm,
        ),
        el('div', { class: 'card' },
            el('h2', { text: 'Document catalogue & issuance policies' }),
            el('p', { class: 'muted', text: 'Groups drive the student request picker. Policies are enforced on every staff transition; blocked duplicates can only be rejected. Core catalogue items (IDs 1–5) can only be deactivated; unused custom items can be deleted.' }),
            el('div', { class: 'table-wrap' }, el('table', { class: 'catalog-table' },
                el('thead', {}, el('tr', {},
                    el('th', { text: 'Request' }), el('th', { text: 'Group' }),
                    el('th', { text: 'Type' }), el('th', { text: 'Policy' }),
                    el('th', { text: 'Active' }), el('th', { text: 'Actions' }))),
                el('tbody', {}, catalogRows),
            )),
        ),
        el('div', { class: 'card' },
            el('h2', { text: 'Add catalogue item' }),
            createTypeForm,
        ),
    );
}

/* --- Views: reports ------------------------------------------------------------------ */
async function viewReports() {
    const app = document.getElementById('app');
    const today = manilaDateString();
    const monthAgo = manilaDateString(new Date(Date.now() - 29 * 86400000));

    const fromInput = el('input', { id: 'r-from', type: 'date', value: monthAgo });
    const toInput = el('input', { id: 'r-to', type: 'date', value: today });
    const statusSelect = el('select', { id: 'r-status' },
        el('option', { value: '', text: 'All statuses' }),
        Object.entries(STATUS_LABELS).map(([value, label]) => el('option', { value, text: label })),
    );
    const output = el('div');

    async function load() {
        output.replaceChildren(el('p', { class: 'muted', text: 'Loading report…' }));
        const params = new URLSearchParams();
        if (fromInput.value) params.set('from', fromInput.value);
        if (toInput.value) params.set('to', toInput.value);
        if (statusSelect.value) params.set('status', statusSelect.value);

        try {
            const result = await api(`/api/v1/admin/reports/summary?${params.toString()}`);
            const summary = result.data.summary;
            output.replaceChildren(renderSummary(summary, params));
        } catch (err) {
            output.replaceChildren(messageBox('form-error', err.message));
        }
    }

    const pageHeader = el('div', { class: 'page-header' },
        el('div', {},
            el('h1', { text: 'Reports' }),
            el('div', { class: 'page-sub', text: 'Organized analytics — soft, scannable, and print-ready' }),
        ),
        el('div', { class: 'muted', text: 'TCGC Office of the College Registrar' }),
    );

    // Soft pill-like toolbar like PickPlant: grouped filters + actions on one row
    const reportBar = el('div', { class: 'report-toolbar' },
        el('span', { class: 'pill', text: 'From' }), fromInput,
        el('span', { class: 'pill', text: 'To' }), toInput,
        statusSelect,
        el('button', { text: 'Generate', onclick: load }),
        el('button', { class: 'secondary', text: 'Print', onclick: () => window.print() }),
        el('a', { class: 'btn', text: 'Download CSV', onclick: (e) => { e.preventDefault(); downloadCsv(); } }),
    );

    app.replaceChildren(pageHeader, reportBar, output);
    await load();
}

function downloadCsv() {
    const params = new URLSearchParams();
    const from = document.getElementById('r-from')?.value;
    const to = document.getElementById('r-to')?.value;
    if (from) params.set('from', from);
    if (to) params.set('to', to);
    params.set('format', 'csv');
    window.open(`/api/v1/admin/reports/summary?${params.toString()}`, '_blank');
}

function renderSummary(summary) {
    if (summary.message) {
        return messageBox('form-warning', summary.message);
    }

    const statusRows = summary.by_status.map((row, i) => el('tr', {},
        el('td', { 'data-label': '#', text: String(i + 1).padStart(2, '0') }),
        el('td', { 'data-label': 'Status' }, statusBadge(row.status)),
        el('td', { 'data-label': 'Count', text: String(row.count) }),
    ));

    const typeRows = summary.by_document_type.map((row) => el('tr', {},
        el('td', { 'data-label': 'Type', text: row.document_type_name }),
        el('td', { 'data-label': 'Total', text: String(row.total) }),
        el('td', { 'data-label': 'Released' }, el('span', { class: 'badge-status st-released', text: String(row.released) })),
        el('td', { 'data-label': 'Pending' }, el('span', { class: 'badge-status st-pending', text: String(row.pending + row.needs_information + row.in_process + row.ready_for_release) })),
        el('td', { 'data-label': 'Rejected' }, el('span', { class: 'badge-status st-rejected', text: String(row.rejected) })),
    ));

    // Organized soft layout like PickPlant + teid: stat cards + soft tables with pagination mimic
    const pagination = el('div', { class: 'pagination' },
        el('button', { text: 'Previous', disabled: true }),
        el('button', { class: 'active', text: '1' }),
        el('button', { text: '2' }),
        el('button', { text: '3' }),
        el('span', { class: 'muted', text: '…' }),
        el('button', { text: 'Next' }),
    );

    return el('div', { class: 'reports-output' },
        el('div', { class: 'report-grid' },
            el('div', { class: 'stat-card' },
                el('div', { class: 'muted', text: `${summary.filters.from} → ${summary.filters.to}` }),
                el('div', { text: 'Total submitted' }),
                el('div', { class: 'stat', text: String(summary.totals.submitted) }),
            ),
            el('div', { class: 'stat-card' },
                el('div', { class: 'muted', text: 'Date range' }),
                el('div', { text: 'Filtered period' }),
                el('div', { class: 'muted', text: `${summary.filters.from} to ${summary.filters.to}` }),
            ),
        ),
        el('div', { class: 'soft-table-wrap' },
            el('h2', { text: 'By status', style: 'margin:0 0 0.6rem 0.4rem;' }),
            el('table', { class: 'soft-table' },
                el('thead', {}, el('tr', {},
                    el('th', { text: '#' }), el('th', { text: 'Status' }), el('th', { text: 'Count' }))),
                el('tbody', {}, statusRows),
            ),
            pagination.cloneNode(true),
        ),
        el('div', { class: 'soft-table-wrap' },
            el('h2', { text: 'By document type', style: 'margin:0 0 0.6rem 0.4rem;' }),
            el('table', { class: 'soft-table' },
                el('thead', {}, el('tr', {},
                    el('th', { text: 'Type' }), el('th', { text: 'Total' }),
                    el('th', { text: 'Released' }), el('th', { text: 'Pending' }), el('th', { text: 'Rejected' }))),
                el('tbody', {}, typeRows),
            ),
            pagination.cloneNode(true),
        ),
    );
}

/* --- Boot ------------------------------------------------------------------ */
window.addEventListener('hashchange', route);
(async function boot() {
    try {
        const result = await api('/api/v1/me');
        state.user = result.data.user;
        state.csrf = result.data.csrf_token;
        state.unread = result.data.unread_count;
    } catch {
        state.user = null;
    }
    route();
})();
