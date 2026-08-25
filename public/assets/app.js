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

const STATUS_LABELS = {
    pending: 'Pending',
    needs_information: 'Needs Information',
    in_process: 'In-Process',
    ready_for_release: 'Ready for Release',
    released: 'Released',
    rejected: 'Rejected',
    cancelled: 'Cancelled',
};

/* Legal transitions — display mirror of the server-side state machine.
 * true = remark/reason required. The SERVER remains the enforcer. */
const NEXT_STATES = {
    pending: { in_process: false, needs_information: true, rejected: true },
    needs_information: { pending: false, rejected: true },
    in_process: { ready_for_release: false, needs_information: true },
    ready_for_release: { released: false },
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
            renderNavbar();
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
    return el('span', { class: `badge-status st-${status}`, text: STATUS_LABELS[status] || status });
}

function fmtDateTime(value) {
    if (!value) return '—';
    const d = new Date(String(value).replace(' ', 'T') + 'Z');
    return isNaN(d) ? String(value) : d.toLocaleString();
}

function fmtDate(value) {
    if (!value) return '—';
    const d = new Date(String(value));
    return isNaN(d) ? String(value) : d.toLocaleDateString();
}

function messageBox(kind, text) {
    return el('div', { class: kind, text });
}

function fieldError(errors, name) {
    return errors && errors[name] ? el('p', { class: 'field-error', text: errors[name] }) : null;
}

/* Inline stroke icons (Feather-style, 24x24) for the navbar. */
const NAV_ICONS = {
    requests: '<path d="M16 4h2a2 2 0 0 1 2 2v14a2 2 0 0 1-2 2H6a2 2 0 0 1-2-2V6a2 2 0 0 1 2-2h2"/><rect x="8" y="2" width="8" height="4" rx="1"/><line x1="8" y1="12" x2="16" y2="12"/><line x1="8" y1="16" x2="13" y2="16"/>',
    reports: '<line x1="18" y1="20" x2="18" y2="10"/><line x1="12" y1="20" x2="12" y2="4"/><line x1="6" y1="20" x2="6" y2="14"/><line x1="2" y1="20" x2="22" y2="20"/>',
    users: '<path d="M17 21v-2a4 4 0 0 0-4-4H5a4 4 0 0 0-4 4v2"/><circle cx="9" cy="7" r="4"/><path d="M23 21v-2a4 4 0 0 0-3-3.87"/><path d="M16 3.13a4 4 0 0 1 0 7.75"/>',
    bell: '<path d="M18 8A6 6 0 0 0 6 8c0 7-3 9-3 9h18s-3-2-3-9"/><path d="M13.73 21a2 2 0 0 1-3.46 0"/>',
    user: '<path d="M20 21v-2a4 4 0 0 0-4-4H8a4 4 0 0 0-4 4v2"/><circle cx="12" cy="7" r="4"/>',
    logout: '<path d="M9 21H5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h4"/><polyline points="16 17 21 12 16 7"/><line x1="21" y1="12" x2="9" y2="12"/>',
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

function renderNavbar() {
    const navbar = document.getElementById('navbar');
    navbar.replaceChildren();
    if (!state.user) { navbar.classList.add('hidden'); return; }
    navbar.classList.remove('hidden');

    const home = state.user.role === 'student' ? '#/student' : '#/staff';
    const brandIcon = el('img', { src: '/assets/icon-128.png', alt: '', class: 'brand-icon' });
    const links = [el('a', { href: home, class: 'brand' }, brandIcon, 'REGIS-TRACK')];

    if (state.user.role === 'student') {
        links.push(el('a', { href: '#/student' }, navIcon('requests'), 'My Requests'));
    } else {
        links.push(el('a', { href: '#/staff' }, navIcon('requests'), 'Request Queue'));
        links.push(el('a', { href: '#/reports' }, navIcon('reports'), 'Reports'));
        if (state.user.role === 'admin') links.push(el('a', { href: '#/admin/users' }, navIcon('users'), 'Users'));
    }
    links.push(el('a', { href: '#/notifications' }, navIcon('bell'), 'Notifications', state.unread > 0 ? el('span', { class: 'badge', text: String(state.unread) }) : null));
    links.push(el('span', { class: 'spacer' }));
    links.push(el('span', { class: 'user-chip' }, navIcon('user'), state.user.full_name));
    links.push(el('a', { href: '#', onclick: logout }, navIcon('logout'), 'Log out'));

    navbar.replaceChildren(...links);
}

async function logout(event) {
    event.preventDefault();
    try { await api('/api/v1/auth/logout', { method: 'POST' }); } catch { /* session already gone */ }
    state.user = null;
    state.csrf = null;
    state.unread = 0;
    renderNavbar();
    location.hash = '#/login';
}

/* --- Router -------------------------------------------------------------- */
function route() {
    const hash = location.hash || '#/';
    const app = document.getElementById('app');
    renderNavbar();

    if (!state.user) { viewLogin(); return; }

    const role = state.user.role;
    if (hash.startsWith('#/student/request/')) return viewStudentRequest(decodeURIComponent(hash.split('/')[3]));
    if (hash.startsWith('#/staff/request/')) return viewStaffRequest(Number(hash.split('/')[3]));
    if (hash === '#/login') { location.hash = role === 'student' ? '#/student' : '#/staff'; return; }
    if (hash === '#/student') return role === 'student' ? viewStudentHome() : redirectHome(role);
    if (hash === '#/staff') return role !== 'student' ? viewStaffQueue() : redirectHome(role);
    if (hash === '#/reports') return role !== 'student' ? viewReports() : redirectHome(role);
    if (hash === '#/admin/users') return role === 'admin' ? viewAdminUsers() : redirectHome(role);
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

    const emailInput = el('input', { type: 'email', name: 'email', required: true, autocomplete: 'username', placeholder: 'you@tcg.edu.ph' });
    const passwordInput = el('input', { type: 'password', name: 'password', required: true, autocomplete: 'current-password' });
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
        el('label', { text: 'Password' }), passwordInput,
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
        renderNavbar();
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

function requestsTable(items, { trackingHref, staffHref, showStudent = false }) {
    const rows = items.map((r) => el('tr', { class: 'clickable', onclick: () => { location.hash = trackingHref ? trackingHref(r.tracking_number) : staffHref(r.id); } },
        el('td', { 'data-label': 'Tracking no.', text: r.tracking_number }),
        showStudent ? el('td', { 'data-label': 'Student', text: r.student_name }) : null,
        el('td', { 'data-label': 'Document', text: r.document_type_name }),
        el('td', { 'data-label': 'Qty', text: String(r.quantity) }),
        el('td', { 'data-label': 'Status' }, statusBadge(r.status)),
        el('td', { 'data-label': 'Submitted', text: fmtDateTime(r.submitted_at) }),
    ));
    return el('div', { class: 'table-wrap' },
        el('table', {},
            el('thead', {}, el('tr', {},
                el('th', { text: 'Tracking no.' }),
                showStudent ? el('th', { text: 'Student' }) : null,
                el('th', { text: 'Document' }),
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

    const typeSelect = el('select', { required: true },
        el('option', { value: '', text: '— Select document type —' }),
        documentTypes.map((t) => el('option', { value: String(t.id), text: t.name })),
    );
    const qtyInput = el('input', { type: 'number', min: '1', max: '10', value: '1', required: true });
    const purposeInput = el('textarea', { rows: '3', maxlength: '500', required: true, placeholder: 'State the purpose of this request' });
    const dateInput = el('input', { type: 'date', required: true });
    const submitButton = el('button', { type: 'submit', text: 'Submit request' });

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
                        quantity: Number(qtyInput.value),
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
        el('label', { text: 'Document type' }), typeSelect,
        el('label', { text: 'Quantity (1–10)' }), qtyInput,
        el('label', { text: 'Purpose' }), purposeInput,
        el('label', { text: 'Requested release date' }), dateInput,
        submitButton,
    );

    return el('div', { class: 'card' },
        el('h2', { text: 'New document request' }),
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
        app.replaceChildren(messageBox('form-error', err.message), el('p', {}, el('a', { href: '#/student', text: '← Back to my requests' })));
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

    setChildren(app,
        el('p', {}, el('a', { href: '#/student', text: '← Back to my requests' })),
        el('div', { class: 'card' },
            el('h1', { text: request.tracking_number }, ' ', statusBadge(request.status)),
            el('p', {}, el('strong', { text: request.document_type_name }), ` × ${request.quantity}`),
            el('p', { text: `Purpose: ${request.purpose}` }),
            el('p', { class: 'muted', text: `Submitted ${fmtDateTime(request.submitted_at)} · Requested release ${fmtDate(request.target_release_date)}` }),
            request.current_remark ? el('p', { text: `Latest remark: ${request.current_remark}` }) : null,
            request.status === 'pending' ? el('div', { class: 'actions' }, cancelButton) : null,
            cancelSlot,
        ),
        historyCard(detail.data.history),
    );
}

function historyCard(history) {
    const items = (history || []).map((h) => el('li', {},
        el('div', {}, statusBadge(h.new_status), ` ${h.prev_status ? `(${STATUS_LABELS[h.prev_status] || h.prev_status} →)` : '(submitted)'}`),
        h.remark ? el('div', { text: h.remark }) : null,
        el('div', { class: 'when', text: `${fmtDateTime(h.created_at)} — ${h.actor_name} (${h.actor_role})` }),
    ));
    return el('div', { class: 'card' },
        el('h2', { text: 'Processing history' }),
        items.length === 0 ? el('p', { class: 'muted', text: 'No history yet.' }) : el('ul', { class: 'timeline' }, items),
    );
}

/* --- Views: staff ----------------------------------------------------------- */
async function viewStaffQueue() {
    const app = document.getElementById('app');
    app.replaceChildren(el('p', { class: 'muted', text: 'Loading…' }));

    const params = new URLSearchParams();
    for (const [id, key] of [['f-status', 'status'], ['f-q', 'q'], ['f-type', 'document_type_id'], ['f-from', 'date_from'], ['f-to', 'date_to']]) {
        const value = document.getElementById(id)?.value?.trim();
        if (value) params.set(key, value);
    }
    params.set('page', '1');
    params.set('page_size', '20');

    let result;
    try {
        result = await api(`/api/v1/staff/requests?${params.toString()}`);
    } catch (err) {
        app.replaceChildren(messageBox('form-error', err.message));
        return;
    }

    const statusSelect = el('select', { id: 'f-status' },
        el('option', { value: '', text: 'All statuses' }),
        Object.entries(STATUS_LABELS).map(([value, label]) => el('option', { value, text: label, selected: params.get('status') === value })),
    );
    const searchInput = el('input', { id: 'f-q', type: 'search', placeholder: 'Tracking no., student, ID…', value: params.get('q') || '' });
    const typeInput = el('input', { id: 'f-type', type: 'number', min: '1', placeholder: 'Type ID', value: params.get('document_type_id') || '' });
    const fromInput = el('input', { id: 'f-from', type: 'date', value: params.get('date_from') || '' });
    const toInput = el('input', { id: 'f-to', type: 'date', value: params.get('date_to') || '' });

    app.replaceChildren(
        el('h1', { text: 'Request queue' }),
        el('div', { class: 'card filters' },
            statusSelect, searchInput, typeInput, fromInput,
            el('button', { class: 'wide', text: 'Apply filters', onclick: viewStaffQueue }),
        ),
        el('div', { class: 'card' },
            el('p', { class: 'muted', text: `${result.data.meta.total} request(s) found (oldest first).` }),
            requestsTable(result.data.items, { staffHref: (id) => `#/staff/request/${id}`, showStudent: true }),
            result.data.items.length === 0 ? el('p', { class: 'muted', text: 'No matching requests. Adjust the filters above.' }) : null,
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
        app.replaceChildren(messageBox('form-error', err.message), el('p', {}, el('a', { href: '#/staff', text: '← Back to queue' })));
        return;
    }

    const { request, history, audit } = detail.data;
    const actionSlot = el('div');

    const transitionButtons = Object.entries(NEXT_STATES[request.status] || {}).map(([to, reasonRequired]) =>
        el('button', {
            text: `Mark as ${STATUS_LABELS[to]}`,
            onclick: () => renderTransitionForm(actionSlot, request, to, reasonRequired),
        }));

    setChildren(app,
        el('p', {}, el('a', { href: '#/staff', text: '← Back to queue' })),
        el('div', { class: 'card' },
            el('h1', { text: request.tracking_number }, ' ', statusBadge(request.status)),
            el('p', {}, el('strong', { text: request.student_name }), ` (${request.student_number || 'no student number'})`),
            el('p', {}, el('strong', { text: request.document_type_name }), ` × ${request.quantity}`),
            el('p', { text: `Purpose: ${request.purpose}` }),
            el('p', { class: 'muted', text: `Submitted ${fmtDateTime(request.submitted_at)} · Target release ${fmtDate(request.target_release_date)}` }),
            request.current_remark ? el('p', { text: `Latest remark: ${request.current_remark}` }) : null,
            el('div', { class: 'actions' }, transitionButtons),
            actionSlot,
        ),
        historyCard(history),
        auditCard(audit),
    );
}

function renderTransitionForm(slot, request, toStatus, reasonRequired) {
    const remarkInput = el('textarea', { rows: '2', maxlength: '500', placeholder: reasonRequired ? 'Reason (required)' : 'Remark (optional)' });
    const errorSlot = el('div');
    const submit = el('button', {
        text: `Confirm: ${STATUS_LABELS[toStatus]}`,
        onclick: async () => {
            errorSlot.replaceChildren();
            submit.disabled = true;
            try {
                await api(`/api/v1/requests/${request.id}/transition`, {
                    method: 'POST',
                    body: { to: toStatus, remark: remarkInput.value.trim(), version: request.version },
                });
                route();
            } catch (err) {
                errorSlot.replaceChildren(messageBox('form-error', err.message));
                submit.disabled = false;
            }
        },
    });

    slot.replaceChildren(el('div', { class: 'card' },
        el('h2', { text: `Transition to ${STATUS_LABELS[toStatus]}` }),
        errorSlot,
        el('form', { onsubmit: (e) => { e.preventDefault(); submit.click(); } },
            el('label', { text: reasonRequired ? 'Reason (required by workflow rules)' : 'Remark (optional)' }), remarkInput, submit,
        ),
    ));
}

function auditCard(audit) {
    const rows = (audit || []).map((a) => el('tr', {},
        el('td', { 'data-label': 'When', text: fmtDateTime(a.created_at) }),
        el('td', { 'data-label': 'Actor', text: `${a.actor_name} (${a.actor_role})` }),
        el('td', { 'data-label': 'Action', text: a.action }),
        el('td', { 'data-label': 'Change', text: a.before_value && a.after_value
            ? `${shortJson(a.before_value)} → ${shortJson(a.after_value)}`
            : (shortJson(a.after_value) || '—') }),
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

    const items = result.data.items.map((n) => el('li', { class: 'timeline' },
        el('div', {}, el('strong', { text: n.subject }), n.read_at ? null : el('span', { class: 'badge', text: 'new' })),
        el('div', { text: n.body }),
        el('div', { class: 'when', text: fmtDateTime(n.created_at) }),
        n.read_at ? null : el('button', {
            class: 'secondary',
            text: 'Mark as read',
            onclick: async () => {
                try { await api(`/api/v1/notifications/${n.id}/read`, { method: 'POST' }); await refreshUnread(); route(); }
                catch (err) { app.prepend(messageBox('form-error', err.message)); }
            },
        }),
    ));

    app.replaceChildren(
        el('h1', { text: 'Notifications' }),
        el('div', { class: 'card' },
            el('p', { class: 'muted', text: `${result.data.meta.unread} unread.` }),
            items.length === 0 ? el('p', { class: 'muted', text: 'No notifications yet.' }) : el('ul', { class: 'timeline' }, items),
        ),
    );
}

/* --- Views: admin users ----------------------------------------------------------- */
async function viewAdminUsers(formState = {}) {
    const app = document.getElementById('app');
    app.replaceChildren(el('p', { class: 'muted', text: 'Loading…' }));

    let result;
    try {
        result = await api('/api/v1/admin/users?page=1&page_size=50');
    } catch (err) {
        app.replaceChildren(messageBox('form-error', err.message));
        return;
    }

    const rows = result.data.items.map((u) => el('tr', {},
        el('td', { 'data-label': 'Name', text: u.full_name }),
        el('td', { 'data-label': 'Email', text: u.email }),
        el('td', { 'data-label': 'Role', text: u.role }),
        el('td', { 'data-label': 'Status' }, statusBadge(u.status === 'active' ? 'released' : 'cancelled'), ` ${u.status}`),
        el('td', { 'data-label': 'Student no.', text: u.student_number || '—' }),
    ));

    const errorSlot = el('div');
    const emailInput = el('input', { type: 'email', required: true, placeholder: 'new.user@tcg.edu.ph', value: formState.email || '' });
    const nameInput = el('input', { type: 'text', required: true, placeholder: 'Full name', value: formState.fullName || '' });
    const roleSelect = el('select', {},
        el('option', { value: 'student', text: 'Student' }),
        el('option', { value: 'staff', text: 'Registrar Staff' }),
        el('option', { value: 'admin', text: 'Administrator' }),
    );
    const studentNoInput = el('input', { type: 'text', placeholder: 'Student number (students only)' });
    const passwordInput = el('input', { type: 'password', required: true, placeholder: 'Initial password (min 8 chars)' });
    roleSelect.addEventListener('change', () => {
        studentNoInput.required = roleSelect.value === 'student';
    });

    const createForm = el('form', {
        onsubmit: async (event) => {
            event.preventDefault();
            errorSlot.replaceChildren();
            try {
                await api('/api/v1/admin/users', {
                    method: 'POST',
                    body: {
                        email: emailInput.value.trim(),
                        full_name: nameInput.value.trim(),
                        role: roleSelect.value,
                        student_number: studentNoInput.value.trim() || undefined,
                        password: passwordInput.value,
                    },
                });
                viewAdminUsers();
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
        el('label', { text: 'Student number' }), studentNoInput,
        el('label', { text: 'Initial password' }), passwordInput,
        el('button', { type: 'submit', text: 'Create account' }),
    );

    app.replaceChildren(
        el('h1', { text: 'User management' }),
        el('div', { class: 'card' }, el('h2', { text: 'Create account' }), errorSlot, createForm),
        el('div', { class: 'card' },
            el('h2', { text: `Accounts (${result.data.meta.total})` }),
            el('div', { class: 'table-wrap' }, el('table', {},
                el('thead', {}, el('tr', {},
                    el('th', { text: 'Name' }), el('th', { text: 'Email' }), el('th', { text: 'Role' }),
                    el('th', { text: 'Status' }), el('th', { text: 'Student no.' }))),
                el('tbody', {}, rows)),
            ),
        ),
    );
}

/* --- Views: reports ------------------------------------------------------------------ */
async function viewReports() {
    const app = document.getElementById('app');
    const today = new Date().toISOString().slice(0, 10);
    const monthAgo = new Date(Date.now() - 29 * 86400000).toISOString().slice(0, 10);

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

    app.replaceChildren(
        el('h1', { text: 'Reports & analytics' }),
        el('div', { class: 'card filters' },
            el('div', {}, el('label', { text: 'From' }), fromInput),
            el('div', {}, el('label', { text: 'To' }), toInput),
            el('div', {}, el('label', { text: 'Status' }), statusSelect),
            el('div', { class: 'actions' },
                el('button', { text: 'Generate', onclick: load }),
                el('button', { class: 'secondary', text: 'Print', onclick: () => window.print() }),
                el('a', { class: 'btn', text: 'Download CSV', onclick: (e) => { e.preventDefault(); downloadCsv(); } }),
            ),
        ),
        output,
    );
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

    const statusRows = summary.by_status.map((row) => el('tr', {},
        el('td', { 'data-label': 'Status' }, statusBadge(row.status)),
        el('td', { 'data-label': 'Count', text: String(row.count) }),
    ));

    const typeRows = summary.by_document_type.map((row) => el('tr', {},
        el('td', { 'data-label': 'Type', text: row.document_type_name }),
        el('td', { 'data-label': 'Total', text: String(row.total) }),
        el('td', { 'data-label': 'Released', text: String(row.released) }),
        el('td', { 'data-label': 'Pending', text: String(row.pending + row.needs_information + row.in_process + row.ready_for_release) }),
        el('td', { 'data-label': 'Rejected', text: String(row.rejected) }),
    ));

    return el('div', {},
        el('div', { class: 'card' },
            el('h2', { text: 'Summary' }),
            el('p', { class: 'muted', text: `${summary.filters.from} → ${summary.filters.to}` }),
            el('p', {}, 'Total submitted: ', el('span', { class: 'stat', text: String(summary.totals.submitted) })),
            el('div', { class: 'table-wrap' }, el('table', {},
                el('thead', {}, el('tr', {}, el('th', { text: 'Status' }), el('th', { text: 'Count' }))),
                el('tbody', {}, statusRows)),
            ),
        ),
        el('div', { class: 'card' },
            el('h2', { text: 'By document type' }),
            el('div', { class: 'table-wrap' }, el('table', {},
                el('thead', {}, el('tr', {},
                    el('th', { text: 'Type' }), el('th', { text: 'Total' }), el('th', { text: 'Released' }),
                    el('th', { text: 'In progress' }), el('th', { text: 'Rejected' }))),
                el('tbody', {}, typeRows)),
            ),
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
