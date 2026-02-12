let device;
let activeCall;
let activeCallSid = '';
let callTimer = null;
let callSeconds = 0;
let isMuted = false;
let isRecording = false;
let audioCtx = null;

let state = {
  assignedFilter: 'all',
  query: '',
  conversations: [],
  activeConversationId: null,
  activeConversation: null,
  myNumbers: [],
  users: [],
  adminUsers: [],
  contacts: [],
  selectedContactIds: {},
  selectedCallIds: {},
  selectedVoicemailIds: {},
  contactsTagsByContact: {},
  contactsGroupsByContact: {},
  contactsFieldValues: {},
  crmTags: [],
  crmGroups: [],
  calls: [],
  voicemails: [],
  permissions: [],
  permissionSet: {},
  rbacPermissions: [],
  rbacRoles: [],
  rbacUsers: [],
  notifRules: [],
  adminNumbers: [],
  adminNumberMappings: [],
  twilioAccounts: [],
  contactFields: [],
  templates: [],
  polling: null
};

function hasPerm(k) {
  if (!k) return true;
  if (state.permissionSet && state.permissionSet[k]) return true;
  return false;
}

async function handleApiForbidden() {
  try {
    await loadMe();
    applyNavPermissions();
    const cur = (String(window.location.hash || '').replace('#', '') || 'analytics');
    await setActiveNav(cur).catch(() => {});
  } catch {
  }
}

async function parseApiError(res) {
  let t = '';
  try { t = await res.text(); } catch {}
  const raw = String(t || '');
  const isHtml = /^\s*</.test(raw) || /<html/i.test(raw);
  try {
    const asJson = JSON.parse(raw);
    if (asJson && asJson.error) {
      return { message: String(asJson.error), isJson: true, raw };
    }
  } catch {
  }
  if (isHtml) {
    return { message: `Request failed (${res.status}). Gateway/proxy returned HTML.`, isJson: false, raw };
  }
  const snippet = raw.length > 240 ? (raw.slice(0, 240) + '...') : raw;
  return { message: snippet || `Request failed (${res.status})`, isJson: false, raw };
}

function storageGetBool(key, def) {
  try {
    const v = localStorage.getItem(String(key));
    if (v === null || v === undefined) return !!def;
    if (v === '1' || v === 'true') return true;
    if (v === '0' || v === 'false') return false;
    return !!def;
  } catch {
    return !!def;
  }
}

function storageSetBool(key, val) {
  try {
    localStorage.setItem(String(key), val ? '1' : '0');
  } catch {
  }
}

function updateInboxUnreadIndicators() {
  const hasUnread = Array.isArray(state.conversations) && state.conversations.some((c) => Number(c && c.is_unread || 0) === 1);
  const dot = el('navInboxDot');
  if (dot) dot.style.display = hasUnread ? '' : 'none';
}

function maybeNotifyNewSms(convs) {
  const next = Array.isArray(convs) ? convs : [];
  const prevSet = state._unreadConvSet && typeof state._unreadConvSet === 'object' ? state._unreadConvSet : {};
  const nextSet = {};
  const newlyUnread = [];
  next.forEach((c) => {
    const id = Number(c && c.conversation_id || 0);
    if (!id) return;
    const u = Number(c && c.is_unread || 0) === 1;
    if (u) {
      nextSet[id] = true;
      if (!prevSet[id]) newlyUnread.push(c);
    }
  });
  state._unreadConvSet = nextSet;

  if (newlyUnread.length === 0) return;

  const playSound = storageGetBool('vcw_sms_sound', true);
  const desktop = storageGetBool('vcw_sms_desktop', false);

  if (playSound) {
    try {
      const AC = window.AudioContext || window.webkitAudioContext;
      if (AC) {
        const ctx = new AC();
        const o = ctx.createOscillator();
        const g = ctx.createGain();
        o.type = 'sine';
        o.frequency.value = 880;
        g.gain.value = 0.0001;
        o.connect(g);
        g.connect(ctx.destination);
        const now = ctx.currentTime;
        g.gain.setValueAtTime(0.0001, now);
        g.gain.exponentialRampToValueAtTime(0.18, now + 0.01);
        g.gain.exponentialRampToValueAtTime(0.0001, now + 0.18);
        o.start(now);
        o.stop(now + 0.20);
        setTimeout(() => { try { ctx.close(); } catch {} }, 350);
      }
    } catch {
    }
  }
  if (desktop && ('Notification' in window)) {
    try {
      if (Notification.permission === 'granted') {
        const c0 = newlyUnread[0];
        const title = 'New SMS';
        const body = String(c0 && (c0.contact_name || c0.contact_phone || c0.last_message_preview) || '');
        const n = new Notification(title, { body: body.slice(0, 120) });
        n.onclick = () => {
          try { window.focus(); } catch {}
          try { window.location.hash = '#inbox'; } catch {}
        };
      }
    } catch {
    }
  }
}

async function loadRbac() {
  const [perms, roles, users] = await Promise.all([
    apiGet('/api/admin/rbac/permissions'),
    apiGet('/api/admin/rbac/roles'),
    apiGet('/api/admin/rbac/users')
  ]);
  state.rbacPermissions = (perms && perms.permissions) ? perms.permissions : [];
  state.rbacRoles = (roles && roles.roles) ? roles.roles : [];
  state.rbacUsers = (users && users.users) ? users.users : [];
}

async function loadNotifRules() {
  const data = await apiGet('/api/admin/notifications/role-rules');
  state.notifRules = (data && data.rules) ? data.rules : [];
}

function renderRbacRoleSelect() {
  const sel = el('rbacRoleSelect');
  if (!sel) return;
  const roles = Array.isArray(state.rbacRoles) ? state.rbacRoles : [];
  const prev = sel.value ? Number(sel.value) : 0;
  sel.innerHTML = roles.map((r) => {
    const id = Number(r.id || 0);
    const name = escapeHtml(String(r.name || ''));
    return `<option value="${id}">${name}</option>`;
  }).join('');

  if (roles.length > 0) {
    const exists = roles.some((r) => Number(r.id || 0) === prev);
    sel.value = exists ? String(prev) : String(Number(roles[0].id || 0));
  }
}

function currentRbacRole() {
  const sel = el('rbacRoleSelect');
  const id = Number(sel ? sel.value : 0);
  const roles = Array.isArray(state.rbacRoles) ? state.rbacRoles : [];
  return roles.find((r) => Number(r.id) === id) || null;
}

function renderRbacRoleEditor() {
  const role = currentRbacRole();
  const name = el('rbacRoleName');
  if (name) name.value = role ? String(role.name || '') : '';

  const locked = !!(role && role.system_locked);
  if (name) name.disabled = locked;
  const delBtn = el('rbacDeleteRole');
  if (delBtn) delBtn.disabled = locked;
  const saveBtn = el('rbacSaveRolePerms');
  if (saveBtn) saveBtn.disabled = locked;

  const host = el('rbacPermissionsList');
  if (!host) return;
  const perms = Array.isArray(state.rbacPermissions) ? state.rbacPermissions : [];
  const keys = role && Array.isArray(role.permission_keys) ? role.permission_keys : [];
  const keySet = {};
  keys.forEach((k) => { keySet[String(k)] = true; });

  host.innerHTML = perms.map((p) => {
    const k = String(p.perm_key || '');
    const lbl = String(p.label || k);
    const checked = keySet[k] ? 'checked' : '';
    const dis = locked ? 'disabled' : '';
    return `<div class="item"><label class="small" style="display:flex;gap:10px;align-items:center"><input type="checkbox" data-rp="1" data-k="${escapeHtml(k)}" ${checked} ${dis}><span><strong>${escapeHtml(k)}</strong><div class="small" style="margin-top:4px">${escapeHtml(lbl)}</div></span></label></div>`;
  }).join('');
}

function openNewRoleModal() {
  const modal = el('rbacNewRoleModal');
  const input = el('rbacNewRoleName');
  if (modal) modal.style.display = 'flex';
  if (input) {
    input.value = '';
    try { input.focus(); } catch {}
  }
}

function closeNewRoleModal() {
  const modal = el('rbacNewRoleModal');
  if (modal) modal.style.display = 'none';
}

async function refreshRbacUi() {
  await loadRbac();
  renderRbacRoleSelect();
  renderRbacRoleEditor();
  renderNotifRoleSelects();
  renderUsersList();
}

function renderNotifRoleSelects() {
  const sel = el('notifRoleSelect');
  if (!sel) return;
  const roles = Array.isArray(state.rbacRoles) ? state.rbacRoles : [];
  sel.innerHTML = roles.map((r) => {
    const id = Number(r.id || 0);
    return `<option value="${id}">${escapeHtml(String(r.name || ''))}</option>`;
  }).join('');
}

function getNotifRule(roleId, eventKey) {
  const rules = Array.isArray(state.notifRules) ? state.notifRules : [];
  return rules.find((r) => Number(r.role_id) === Number(roleId) && String(r.event_key || '') === String(eventKey || '')) || null;
}

function syncNotifRuleEditor() {
  const host = el('notifEventsList');
  if (!host) return;
  const roleId = Number(el('notifRoleSelect') ? el('notifRoleSelect').value : 0);
  const events = [
    { key: 'sms.inbound', label: 'New inbound SMS' },
    { key: 'voice.voicemail', label: 'New voicemail' },
    { key: 'sms.unread_reminder', label: 'Unread message reminder' }
  ];

  host.innerHTML = events.map((ev) => {
    const rule = getNotifRule(roleId, ev.key);
    const enabled = !!(rule && Number(rule.enabled) === 1);
    const rm = rule && rule.reminder_minutes != null ? String(rule.reminder_minutes) : '';
    const isReminder = ev.key === 'sms.unread_reminder';
    const rmHtml = isReminder ? `<div style="margin-top:8px">
        <div class="small">Reminder minutes</div>
        <input class="input" id="notifReminderMinutes" name="notifReminderMinutes" data-nrm="1" value="${escapeHtml(rm)}" placeholder="e.g. 5">
      </div>` : '';
    return `<div class="item" data-nek="${escapeHtml(ev.key)}">
      <label class="small" style="display:flex;gap:10px;align-items:flex-start">
        <input type="checkbox" data-nen="1" ${enabled ? 'checked' : ''}>
        <span style="display:block">
          <strong>${escapeHtml(ev.key)}</strong>
          <div class="small" style="margin-top:4px">${escapeHtml(ev.label)}</div>
        </span>
      </label>
      ${rmHtml}
    </div>`;
  }).join('');
}

async function saveNotifRule() {
  const roleId = Number(el('notifRoleSelect') ? el('notifRoleSelect').value : 0);
  const host = el('notifEventsList');
  if (!roleId || !host) return;
  const items = Array.from(host.querySelectorAll('[data-nek]'));
  const payloads = items.map((node) => {
    const event_key = String(node.getAttribute('data-nek') || '').trim();
    const enabled = !!(node.querySelector('[data-nen="1"]') && node.querySelector('[data-nen="1"]').checked);
    const rmEl = node.querySelector('[data-nrm="1"]');
    const rmRaw = String(rmEl ? rmEl.value : '').trim();
    const reminder_minutes = rmRaw ? Number(rmRaw) : null;
    return { role_id: roleId, event_key, enabled, reminder_minutes };
  }).filter((p) => p.event_key);

  await Promise.all(payloads.map((p) => apiPost('/api/admin/notifications/role-rules', p)));
}

function renderUsersList() {
  const host = el('usersList');
  if (!host) return;

  const users = Array.isArray(state.rbacUsers) ? state.rbacUsers : [];
  const roles = Array.isArray(state.rbacRoles) ? state.rbacRoles : [];

  host.innerHTML = users.map((u) => {
    const id = Number(u.id || 0);
    const email = escapeHtml(String(u.email || ''));
    const active = Number(u.is_active || 0) === 1;
    const roleIds = Array.isArray(u.role_ids) ? u.role_ids.map((x) => Number(x)) : [];
    const roleOptions = roles.map((r) => {
      const rid = Number(r.id || 0);
      const sel = roleIds.includes(rid) ? 'selected' : '';
      return `<option value="${rid}" ${sel}>${escapeHtml(String(r.name || ''))}</option>`;
    }).join('');

    return `<div class="item" data-uid="${id}">
      <div class="row" style="align-items:center;justify-content:space-between;gap:10px;flex-wrap:wrap">
        <div>
          <div><strong>${email}</strong></div>
          <div class="small" style="margin-top:6px">${active ? 'Active' : 'Disabled'}</div>
        </div>
        <div class="row" style="align-items:center;gap:10px;flex-wrap:wrap">
          <select class="input" data-user-roles="1" multiple size="2" style="min-width:240px">${roleOptions}</select>
          <button class="btn" type="button" data-save-user-roles="1">Save roles</button>
        </div>
      </div>
    </div>`;
  }).join('');

  host.querySelectorAll('[data-save-user-roles="1"]').forEach((btn) => {
    btn.addEventListener('click', async () => {
      const item = btn.closest('.item');
      if (!item) return;
      const uid = Number(item.getAttribute('data-uid') || 0);
      const sel = item.querySelector('[data-user-roles="1"]');
      if (!uid || !sel) return;
      const role_ids = Array.from(sel.selectedOptions).map((o) => Number(o.value || 0)).filter((x) => x > 0);
      try {
        btn.disabled = true;
        await apiPost('/api/admin/rbac/users/set-roles', { user_id: uid, role_ids });
        toastSuccess('Saved');
        await refreshRbacUi();
      } catch (e) {
        toastError(e && e.message ? e.message : String(e));
      } finally {
        btn.disabled = false;
      }
    });
  });
}

function wireRbacUi() {
  if (wireRbacUi._wired) return;
  wireRbacUi._wired = true;
  const refresh = el('refreshRbac');
  if (refresh) refresh.addEventListener('click', () => refreshRbacUi().catch((e) => toastError(e && e.message ? e.message : String(e))));

  const roleSel = el('rbacRoleSelect');
  if (roleSel) roleSel.addEventListener('change', () => renderRbacRoleEditor());

  const newRole = el('rbacNewRole');
  if (newRole) {
    newRole.addEventListener('click', () => {
      openNewRoleModal();
    });
  }

  const modalClose = el('rbacNewRoleClose');
  if (modalClose) modalClose.addEventListener('click', closeNewRoleModal);
  const modalCancel = el('rbacNewRoleCancel');
  if (modalCancel) modalCancel.addEventListener('click', closeNewRoleModal);
  const modalCreate = el('rbacNewRoleCreate');
  if (modalCreate) {
    modalCreate.addEventListener('click', async () => {
      const input = el('rbacNewRoleName');
      const name = String(input ? input.value : '').trim();
      if (!name) {
        toastError('Enter a role name');
        return;
      }
      try {
        modalCreate.disabled = true;
        await apiPost('/api/admin/rbac/roles/save', { name });
        closeNewRoleModal();
        await refreshRbacUi();
        toastSuccess('Role created');
      } catch (e) {
        toastError(e && e.message ? e.message : String(e));
      } finally {
        modalCreate.disabled = false;
      }
    });
  }

  const delRole = el('rbacDeleteRole');
  if (delRole) {
    delRole.addEventListener('click', async () => {
      const role = currentRbacRole();
      if (!role) return;
      if (!confirm(`Delete role "${role.name}"?`)) return;
      await apiPost('/api/admin/rbac/roles/delete', { id: Number(role.id) });
      await refreshRbacUi();
      toastSuccess('Deleted');
    });
  }

  const savePerms = el('rbacSaveRolePerms');
  if (savePerms) {
    savePerms.addEventListener('click', async () => {
      const role = currentRbacRole();
      if (!role) return;
      const rid = Number(role.id || 0);
      const name = String(el('rbacRoleName') ? el('rbacRoleName').value : '').trim();
      const keys = [];
      const host = el('rbacPermissionsList');
      if (host) {
        host.querySelectorAll('[data-rp="1"]').forEach((cb) => {
          if (cb.checked) keys.push(String(cb.getAttribute('data-k') || ''));
        });
      }
      try {
        savePerms.disabled = true;
        if (name && name !== String(role.name || '')) {
          await apiPost('/api/admin/rbac/roles/save', { id: rid, name });
        }
        await apiPost('/api/admin/rbac/roles/set-permissions', { role_id: rid, permission_keys: keys });
        toastSuccess('Saved');
        await refreshRbacUi();
      } catch (e) {
        toastError(e && e.message ? e.message : String(e));
      } finally {
        savePerms.disabled = false;
      }
    });
  }
}

function wireNotifRulesUi() {
  if (wireNotifRulesUi._wired) return;
  wireNotifRulesUi._wired = true;
  const refresh = el('refreshNotifRules');
  if (refresh) refresh.addEventListener('click', async () => {
    try {
      refresh.disabled = true;
      await loadNotifRules();
      syncNotifRuleEditor();
      toastSuccess('Refreshed');
    } catch (e) {
      toastError(e && e.message ? e.message : String(e));
    } finally {
      refresh.disabled = false;
    }
  });
  const roleSel = el('notifRoleSelect');
  if (roleSel) roleSel.addEventListener('change', () => syncNotifRuleEditor());

  const save = el('notifSaveRule');
  if (save) {
    save.addEventListener('click', async () => {
      try {
        save.disabled = true;
        await saveNotifRule();
        await loadNotifRules();
        syncNotifRuleEditor();
        toastSuccess('Saved');
      } catch (e) {
        toastError(e && e.message ? e.message : String(e));
      } finally {
        save.disabled = false;
      }
    });
  }
}

async function loadMe() {
  try {
    const data = await apiGet('/api/me');
    const perms = data && Array.isArray(data.permissions) ? data.permissions : [];
    state.permissions = perms;
    state.permissionSet = {};
    perms.forEach((k) => { state.permissionSet[String(k)] = true; });
  } catch {
    state.permissions = [];
    state.permissionSet = {};
  }
}

function applyNavPermissions() {
  const map = {
    navAnalytics: 'analytics.view',
    navInbox: 'inbox.view',
    navDialpad: 'dialpad.use',
    navCalls: 'calls.view',
    navVoicemails: 'voicemails.view',
    navContacts: 'contacts.view',
    navBroadcast: 'broadcast.use',
    navNumbers: 'numbers.view',
    navSettings: 'settings.view',
    navUsers: 'users.manage',
    navRoles: 'users.manage'
  };
  Object.entries(map).forEach(([id, perm]) => {
    const a = el(id);
    if (!a) return;
    a.style.display = hasPerm(perm) ? '' : 'none';
  });
}

let _inflight = {
  crmLists: null,
  contactFields: null,
  templates: null,
  twilioAccounts: null,
  numbersAdmin: null,
  adminUsers: null,
  usersAndNumbers: null
};

function showToast(message, type) {
  const host = el('toastHost');
  if (!host) return;
  const text = String(message || '').trim();
  if (!text) return;
  const t = (type === 'error' || type === 'success' || type === 'info') ? type : 'info';

  const node = document.createElement('div');
  node.className = `toast ${t}`;
  node.textContent = text;
  host.appendChild(node);

  const remove = () => {
    try { node.classList.add('hide'); } catch {}
    setTimeout(() => { try { node.remove(); } catch {} }, 220);
  };
  const timeout = t === 'error' ? 4500 : 2500;
  const id = setTimeout(remove, timeout);
  node.addEventListener('click', () => {
    clearTimeout(id);
    remove();
  });
}

function isGsm7(text) {
  const s = String(text || '');
  return /^[\u0000-\u007F]*$/.test(s);
}

function smsMetrics(text) {
  const s = String(text || '');
  const gsm = isGsm7(s);
  const len = s.length;
  const single = gsm ? 160 : 70;
  const multi = gsm ? 153 : 67;
  const segments = len === 0 ? 0 : (len <= single ? 1 : Math.ceil(len / multi));
  const perSeg = segments <= 1 ? single : multi;
  return { gsm, length: len, segments, perSegment: perSeg };
}

function renderSmsCounter(text, hostId) {
  const host = el(hostId);
  if (!host) return;
  const m = smsMetrics(text);
  if (!m.length) {
    host.textContent = '';
    return;
  }
  host.textContent = `${m.length} characters • ${m.segments} segment${m.segments === 1 ? '' : 's'} • ${m.gsm ? 'GSM-7' : 'Unicode'}`;
}

async function loadAnalyticsQuick(hostId) {
  const host = el(hostId);
  if (!host) return;
  host.innerHTML = '<div class="small">Loading...</div>';
  const data = await apiGet('/api/analytics/quick');
  const rows = [
    ['Contacts', data.contacts_total],
    ['Conversations', data.conversations_total],
    ['Messages today', data.messages_today],
    ['Inbound today', data.inbound_today],
    ['Outbound today', data.outbound_today],
    ['Calls today', data.calls_today],
    ['Voicemails today', data.voicemails_today]
  ];
  host.innerHTML = `<div class="pageGrid">${rows.map(([k, v]) => {
    return `<div class="card"><div class="small">${escapeHtml(k)}</div><div style="margin-top:8px;font-size:22px"><strong>${escapeHtml(String(v ?? 0))}</strong></div></div>`;
  }).join('')}</div>`;
}

function wireAnalytics() {
  const btn = el('refreshAnalytics');
  if (btn) btn.addEventListener('click', () => loadAnalyticsQuick('analyticsQuick').catch((e) => toastError(e && e.message ? e.message : String(e))));
}

function wireDialpadAnalytics() {
  const btn = el('refreshDialpadAnalytics');
  if (btn) btn.addEventListener('click', () => loadAnalyticsQuick('dialpadAnalytics').catch(() => {}));
}

function wireSettingsTabs() {
  const host = el('settingsTabs');
  if (!host) return;
  const buttons = Array.from(host.querySelectorAll('[data-stab]'));
  const sections = Array.from(document.querySelectorAll('.settingsSection[data-stab]'));
  if (buttons.length === 0 || sections.length === 0) return;

  const show = (stab) => {
    sections.forEach((s) => {
      const k = String(s.getAttribute('data-stab') || '');
      s.style.display = (k === stab) ? '' : 'none';
    });
    buttons.forEach((b) => {
      const k = String(b.getAttribute('data-stab') || '');
      if (k === stab) b.classList.add('primary');
      else b.classList.remove('primary');
    });
  };

  buttons.forEach((b) => {
    b.addEventListener('click', () => {
      const stab = String(b.getAttribute('data-stab') || '');
      if (!stab) return;
      show(stab);
    });
  });

  const initial = buttons[0] ? String(buttons[0].getAttribute('data-stab') || 'twilio') : 'twilio';
  show(initial);
}

async function loadOptOutSettings() {
  const cb = el('smsOptOutEnabled');
  if (cb) cb.disabled = true;
  try {
    const data = await apiGet('/api/admin/settings/opt-out');
    if (cb) cb.checked = !!(data && data.sms_opt_out_enabled);
    return !!(data && data.sms_opt_out_enabled);
  } catch (e) {
    toastError(e && e.message ? e.message : String(e));
    throw e;
  } finally {
    if (cb) cb.disabled = false;
  }
}

async function saveOptOutSettings() {
  const cb = el('smsOptOutEnabled');
  const enabled = !!(cb && cb.checked);
  await apiPost('/api/admin/settings/opt-out', { sms_opt_out_enabled: enabled });
}

function wireOptOutSettings() {
  const refresh = el('refreshOptOut');
  if (refresh) refresh.addEventListener('click', () => loadOptOutSettings().catch((e) => toastError(e && e.message ? e.message : String(e))));

  const save = el('saveOptOut');
  if (save) {
    save.addEventListener('click', async () => {
      try {
        save.disabled = true;
        const cb = el('smsOptOutEnabled');
        const intended = !!(cb && cb.checked);
        await saveOptOutSettings();
        const after = await loadOptOutSettings();
        if (after !== intended) {
          toastError('Saved response did not persist. Check server logs / permissions.');
        } else {
          toastSuccess('Saved');
        }
      } catch (e) {
        toastError(e && e.message ? e.message : String(e));
      } finally {
        save.disabled = false;
      }
    });
  }
}

function renderInboxTemplates() {
  const sel = el('inboxTemplate');
  if (!sel) return;
  const tpls = Array.isArray(state.templates) ? state.templates : [];
  sel.innerHTML = '<option value="">(No template)</option>' + tpls.map((t) => {
    const id = Number(t.id);
    const name = escapeHtml(t.name || `Template ${id}`);
    return `<option value="${id}">${name}</option>`;
  }).join('');
}

let statePendingMmsFile = null;
let statePendingMmsUrl = '';

async function uploadPendingMmsIfAny() {
  if (!statePendingMmsFile) return '';
  const fd = new FormData();
  fd.append('file', statePendingMmsFile);
  const res = await fetch('/api/inbox/mms/upload', { method: 'POST', body: fd, headers: { 'Accept': 'application/json' } });
  if (!res.ok) {
    const text = await res.text();
    try {
      const asJson = JSON.parse(text);
      if (asJson && asJson.error) throw new Error(String(asJson.error));
    } catch {
    }
    throw new Error(text || 'Upload failed');
  }
  const data = await res.json();
  statePendingMmsUrl = String((data && data.url) || '');
  return statePendingMmsUrl;
}

function wireInboxTemplatesAndMms() {
  const sel = el('inboxTemplate');
  if (sel) {
    sel.addEventListener('change', () => {
      const id = Number(sel.value || 0);
      const tpls = Array.isArray(state.templates) ? state.templates : [];
      const match = tpls.find((x) => Number(x.id) === id);
      const b = el('messageBody');
      if (b && match) {
        b.value = String(match.body || '');
        renderSmsCounter(b.value, 'inboxSmsCounter');
      }
    });
  }

  const pickBtn = el('mmsPickBtn');
  const input = el('mmsFileInput');
  const label = el('mmsPickedLabel');
  const clearBtn = el('mmsClearBtn');

  const renderPicked = () => {
    if (!label) return;
    if (statePendingMmsFile) {
      label.textContent = statePendingMmsFile.name || 'file';
      label.style.display = '';
    } else {
      label.textContent = '';
      label.style.display = 'none';
    }
    if (clearBtn) clearBtn.style.display = statePendingMmsFile ? '' : 'none';
  };

  if (pickBtn && input) {
    pickBtn.addEventListener('click', () => input.click());
  }
  if (input) {
    input.addEventListener('change', () => {
      const f = input.files && input.files[0] ? input.files[0] : null;
      statePendingMmsFile = f;
      statePendingMmsUrl = '';
      renderPicked();
    });
  }
  if (clearBtn && input) {
    clearBtn.addEventListener('click', () => {
      statePendingMmsFile = null;
      statePendingMmsUrl = '';
      try { input.value = ''; } catch {}
      renderPicked();
    });
  }

  renderPicked();
}

function renderTagsAdmin() {
  const list = el('tagsList');
  if (!list) return;
  const tags = Array.isArray(state.crmTags) ? state.crmTags : [];
  if (tags.length === 0) {
    list.innerHTML = '<div class="item"><div class="small">No tags</div></div>';
    return;
  }
  list.innerHTML = tags.map((t) => {
    const id = Number(t.id);
    const name = escapeHtml(t.name || '');
    const cnt = Number(t.member_count || 0);
    return `<div class="item"><div class="row" style="align-items:center;justify-content:space-between">
      <div><strong>${name}</strong> <span class="small">(${cnt})</span></div>
      <button class="btn danger" type="button" data-tagdel="1" data-id="${id}">Delete</button>
    </div></div>`;
  }).join('');

  list.querySelectorAll('[data-tagdel="1"]').forEach((b) => {
    b.addEventListener('click', async () => {
      const id = Number(b.dataset.id || 0);
      if (!id) return;
      if (!confirm('Delete this tag?')) return;
      try {
        await apiPost('/api/crm/tags/delete', { id });
        await loadCrmLists();
        refreshCrmDropdowns();
        renderTagsAdmin();
      } catch (e) {
        toastError(e && e.message ? e.message : String(e));
      }
    });
  });
}

function renderGroupsAdmin() {
  const list = el('groupsList');
  if (!list) return;
  const groups = Array.isArray(state.crmGroups) ? state.crmGroups : [];
  if (groups.length === 0) {
    list.innerHTML = '<div class="item"><div class="small">No groups</div></div>';
    return;
  }
  list.innerHTML = groups.map((g) => {
    const id = Number(g.id);
    const name = escapeHtml(g.name || '');
    const cnt = Number(g.member_count || 0);
    return `<div class="item"><div class="row" style="align-items:center;justify-content:space-between">
      <div><strong>${name}</strong> <span class="small">(${cnt})</span></div>
      <button class="btn danger" type="button" data-grpdel="1" data-id="${id}">Delete</button>
    </div></div>`;
  }).join('');

  list.querySelectorAll('[data-grpdel="1"]').forEach((b) => {
    b.addEventListener('click', async () => {
      const id = Number(b.dataset.id || 0);
      if (!id) return;
      if (!confirm('Delete this group?')) return;
      try {
        await apiPost('/api/crm/groups/delete', { id });
        await loadCrmLists();
        refreshCrmDropdowns();
        renderGroupsAdmin();
      } catch (e) {
        toastError(e && e.message ? e.message : String(e));
      }
    });
  });
}

function renderContactFieldsAdmin() {
  const card = el('contactFieldsAdminCard');
  const list = el('contactFieldsList');
  if (card) card.style.display = '';
  if (!list) return;
  const fields = Array.isArray(state.contactFields) ? state.contactFields : [];
  if (fields.length === 0) {
    list.innerHTML = '<div class="item"><div class="small">No custom fields</div></div>';
    return;
  }
  list.innerHTML = fields.map((f) => {
    const id = Number(f.id);
    const key = escapeHtml(f.field_key || '');
    const label = escapeHtml(f.label || '');
    return `<div class="item"><div class="row" style="align-items:center;justify-content:space-between">
      <div><strong>${label}</strong><div class="small">{${key}}</div></div>
      <button class="btn danger" type="button" data-cfdel="1" data-id="${id}">Delete</button>
    </div></div>`;
  }).join('');

  list.querySelectorAll('[data-cfdel="1"]').forEach((b) => {
    b.addEventListener('click', async () => {
      const id = Number(b.dataset.id || 0);
      if (!id) return;
      if (!confirm('Delete this custom field? Existing values will be removed.')) return;
      try {
        await apiPost('/api/contacts/fields/delete', { id });
        await loadContactFields();
        renderContactFieldsAdmin();
        renderBroadcastMergeFields();
        toastSuccess('Deleted');
      } catch (e) {
        toastError(e && e.message ? e.message : String(e));
      }
    });
  });
}

async function loadTemplates() {
  if (_inflight.templates) return _inflight.templates;
  _inflight.templates = (async () => {
    const data = await apiGet('/api/templates');
    state.templates = Array.isArray(data.templates) ? data.templates : [];
  })().finally(() => { _inflight.templates = null; });
  return _inflight.templates;
}

function renderBroadcastTemplates() {
  const sel = el('broadcastTemplate');
  if (!sel) return;
  const tpls = Array.isArray(state.templates) ? state.templates : [];
  sel.innerHTML = '<option value="">(No template)</option>' + tpls.map((t) => {
    const id = Number(t.id);
    const name = escapeHtml(t.name || `Template ${id}`);
    return `<option value="${id}">${name}</option>`;
  }).join('');
}

function renderBroadcastTemplatesManagerList() {
  const sel = el('broadcastTemplatesSelect');
  if (!sel) return;
  const tpls = Array.isArray(state.templates) ? state.templates : [];
  sel.innerHTML = '<option value="">Select template</option>' + tpls.map((t) => {
    const id = Number(t.id);
    const name = escapeHtml(t.name || `Template ${id}`);
    return `<option value="${id}">${name}</option>`;
  }).join('');
}

function renderBroadcastTemplatesManagerMergeFields() {
  const sel = el('broadcastTemplateMergeField');
  if (!sel) return;
  const fields = Array.isArray(state.contactFields) ? state.contactFields : [];
  const base = [
    { key: 'first_name', label: 'First name' },
    { key: 'last_name', label: 'Last name' },
    { key: 'name', label: 'Name' },
    { key: 'email', label: 'Email' },
    { key: 'phone_number', label: 'Phone number' }
  ];
  const opts = base.concat(fields.map((f) => ({ key: f.field_key, label: f.label || f.field_key })));
  sel.innerHTML = '<option value="">Insert field...</option>' + opts.map((o) => {
    const k = escapeHtml(String(o.key || ''));
    const l = escapeHtml(String(o.label || o.key || ''));
    return `<option value="${k}">${l} ({${k}})</option>`;
  }).join('');
}

function wireBroadcastTabs() {
  const host = el('broadcastTabs');
  if (!host) return;
  const buttons = Array.from(host.querySelectorAll('[data-btab]'));
  const sections = Array.from(document.querySelectorAll('.broadcastSection[data-btab]'));
  const setTab = (tab) => {
    buttons.forEach((b) => {
      if (String(b.dataset.btab) === tab) b.classList.add('active');
      else b.classList.remove('active');
    });
    sections.forEach((s) => {
      s.style.display = String(s.dataset.btab) === tab ? '' : 'none';
    });
  };
  buttons.forEach((b) => b.addEventListener('click', () => setTab(String(b.dataset.btab || 'campaigns'))));
  setTab('campaigns');
}

function wireBroadcastTemplatesManager() {
  const refreshBtn = el('broadcastTemplatesRefresh');
  const newBtn = el('broadcastTemplatesNew');
  const saveBtn = el('broadcastTemplatesSave');
  const delBtn = el('broadcastTemplatesDelete');
  const sel = el('broadcastTemplatesSelect');
  const nameEl = el('broadcastTemplateName');
  const bodyEl = el('broadcastTemplateBody');
  const counterId = 'broadcastTemplateSmsCounter';
  const mergeSel = el('broadcastTemplateMergeField');

  const updateCounter = () => {
    const text = String(bodyEl ? bodyEl.value : '');
    renderSmsCounter(text, counterId);
  };

  const loadSelectedIntoForm = () => {
    if (!sel) return;
    const id = Number(sel.value || 0);
    const tpls = Array.isArray(state.templates) ? state.templates : [];
    const match = tpls.find((x) => Number(x.id) === id);
    if (nameEl) nameEl.value = match ? String(match.name || '') : '';
    if (bodyEl) bodyEl.value = match ? String(match.body || '') : '';
    updateCounter();
  };

  const refreshAll = async (keepSelection) => {
    const prev = keepSelection && sel ? Number(sel.value || 0) : 0;
    await loadTemplates();
    renderBroadcastTemplates();
    renderInboxTemplates();
    renderBroadcastTemplatesManagerList();
    if (sel && prev) sel.value = String(prev);
    loadSelectedIntoForm();
  };

  if (bodyEl) {
    bodyEl.addEventListener('input', updateCounter);
    updateCounter();
  }

  if (sel) {
    sel.addEventListener('change', loadSelectedIntoForm);
  }

  if (refreshBtn) {
    refreshBtn.addEventListener('click', async () => {
      try {
        refreshBtn.disabled = true;
        await refreshAll(true);
        toastSuccess('Refreshed');
      } catch (e) {
        toastError(e && e.message ? e.message : String(e));
      } finally {
        refreshBtn.disabled = false;
      }
    });
  }

  if (newBtn) {
    newBtn.addEventListener('click', () => {
      if (sel) sel.value = '';
      if (nameEl) nameEl.value = '';
      if (bodyEl) bodyEl.value = '';
      updateCounter();
      if (nameEl) nameEl.focus();
    });
  }

  if (saveBtn) {
    saveBtn.addEventListener('click', async () => {
      const id = sel ? Number(sel.value || 0) : 0;
      const name = String(nameEl ? nameEl.value : '').trim();
      const body = String(bodyEl ? bodyEl.value : '').trim();
      if (!name || !body) return;
      try {
        saveBtn.disabled = true;
        const res = await apiPost('/api/templates/save', { id: id || undefined, name, body });
        await refreshAll(true);
        const nextId = res && res.id ? Number(res.id) : 0;
        if (sel && nextId) sel.value = String(nextId);
        loadSelectedIntoForm();
        toastSuccess('Saved');
      } catch (e) {
        toastError(e && e.message ? e.message : String(e));
      } finally {
        saveBtn.disabled = false;
      }
    });
  }

  if (delBtn) {
    delBtn.addEventListener('click', async () => {
      const id = sel ? Number(sel.value || 0) : 0;
      if (!id) return;
      if (!confirm('Delete this template?')) return;
      try {
        delBtn.disabled = true;
        await apiPost('/api/templates/delete', { id });
        await refreshAll(false);
        if (sel) sel.value = '';
        loadSelectedIntoForm();
        toastSuccess('Deleted');
      } catch (e) {
        toastError(e && e.message ? e.message : String(e));
      } finally {
        delBtn.disabled = false;
      }
    });
  }

  if (mergeSel) {
    mergeSel.addEventListener('change', () => {
      const key = String(mergeSel.value || '').trim();
      if (!key) return;
      const tag = `{${key}}`;
      if (!bodyEl) return;
      const start = bodyEl.selectionStart || 0;
      const end = bodyEl.selectionEnd || 0;
      const cur = String(bodyEl.value || '');
      bodyEl.value = cur.slice(0, start) + tag + cur.slice(end);
      bodyEl.focus();
      try {
        const pos = start + tag.length;
        bodyEl.setSelectionRange(pos, pos);
      } catch {
      }
      mergeSel.value = '';
      updateCounter();
    });
  }

  renderBroadcastTemplatesManagerList();
  renderBroadcastTemplatesManagerMergeFields();
  loadSelectedIntoForm();
}

function renderTemplatesAdmin() {
  const sel = el('templatesAdminSelect');
  if (!sel) return;
  const tpls = Array.isArray(state.templates) ? state.templates : [];
  sel.innerHTML = '<option value="">Select template</option>' + tpls.map((t) => {
    const id = Number(t.id);
    const name = escapeHtml(t.name || `Template ${id}`);
    return `<option value="${id}">${name}</option>`;
  }).join('');
}

function wireTemplatesAdmin() {
  const refreshBtn = el('refreshTemplatesAdmin');
  const newBtn = el('newTemplateAdmin');
  const saveBtn = el('saveTemplateAdmin');
  const delBtn = el('deleteTemplateAdmin');
  const sel = el('templatesAdminSelect');
  const nameEl = el('templateAdminName');
  const bodyEl = el('templateAdminBody');

  const loadSelectedIntoForm = () => {
    if (!sel) return;
    const id = Number(sel.value || 0);
    const tpls = Array.isArray(state.templates) ? state.templates : [];
    const match = tpls.find((x) => Number(x.id) === id);
    if (nameEl) nameEl.value = match ? String(match.name || '') : '';
    if (bodyEl) bodyEl.value = match ? String(match.body || '') : '';
  };

  const refreshAll = async (keepSelection) => {
    const prev = keepSelection && sel ? Number(sel.value || 0) : 0;
    await loadTemplates();
    renderTemplatesAdmin();
    renderInboxTemplates();
    renderBroadcastTemplates();
    if (sel && prev) {
      sel.value = String(prev);
    }
    loadSelectedIntoForm();
  };

  if (refreshBtn) {
    refreshBtn.addEventListener('click', async () => {
      try {
        refreshBtn.disabled = true;
        await refreshAll(true);
        toastSuccess('Refreshed');
      } catch (e) {
        toastError(e && e.message ? e.message : String(e));
      } finally {
        refreshBtn.disabled = false;
      }
    });
  }

  if (sel) {
    sel.addEventListener('change', loadSelectedIntoForm);
  }

  if (newBtn) {
    newBtn.addEventListener('click', () => {
      if (sel) sel.value = '';
      if (nameEl) nameEl.value = '';
      if (bodyEl) bodyEl.value = '';
      if (nameEl) nameEl.focus();
    });
  }

  if (saveBtn) {
    saveBtn.addEventListener('click', async () => {
      const id = sel ? Number(sel.value || 0) : 0;
      const name = String(nameEl ? nameEl.value : '').trim();
      const body = String(bodyEl ? bodyEl.value : '').trim();
      if (!name || !body) return;
      try {
        saveBtn.disabled = true;
        const res = await apiPost('/api/templates/save', { id: id || undefined, name, body });
        await refreshAll(true);
        const nextId = res && res.id ? Number(res.id) : 0;
        if (sel && nextId) sel.value = String(nextId);
        loadSelectedIntoForm();
        toastSuccess('Saved');
      } catch (e) {
        toastError(e && e.message ? e.message : String(e));
      } finally {
        saveBtn.disabled = false;
      }
    });
  }

  if (delBtn) {
    delBtn.addEventListener('click', async () => {
      const id = sel ? Number(sel.value || 0) : 0;
      if (!id) return;
      if (!confirm('Delete this template?')) return;
      try {
        delBtn.disabled = true;
        await apiPost('/api/templates/delete', { id });
        await refreshAll(false);
        if (sel) sel.value = '';
        loadSelectedIntoForm();
        toastSuccess('Deleted');
      } catch (e) {
        toastError(e && e.message ? e.message : String(e));
      } finally {
        delBtn.disabled = false;
      }
    });
  }

  renderTemplatesAdmin();
  loadSelectedIntoForm();
}

function renderBroadcastMergeFields() {
  const sel = el('broadcastMergeField');
  if (!sel) return;
  const fields = Array.isArray(state.contactFields) ? state.contactFields : [];
  const base = [
    { key: 'first_name', label: 'First name' },
    { key: 'last_name', label: 'Last name' },
    { key: 'name', label: 'Name' },
    { key: 'email', label: 'Email' },
    { key: 'phone_number', label: 'Phone number' }
  ];
  const custom = fields.map((f) => ({ key: String(f.field_key || ''), label: String(f.label || f.field_key || '') })).filter((x) => x.key);
  const all = base.concat(custom);
  sel.innerHTML = '<option value="">Insert merge field...</option>' + all.map((f) => {
    return `<option value="${escapeHtml(f.key)}">${escapeHtml(f.label)}</option>`;
  }).join('');
}

function renderBroadcastAudiencePickers() {
  const g = el('broadcastGroupSelect');
  const t = el('broadcastTagSelect');
  if (g) {
    const groups = Array.isArray(state.crmGroups) ? state.crmGroups : [];
    g.innerHTML = '<option value="">Select group</option>' + groups.map((x) => `<option value="${Number(x.id)}">${escapeHtml(x.name || '')}</option>`).join('');
  }
  if (t) {
    const tags = Array.isArray(state.crmTags) ? state.crmTags : [];
    t.innerHTML = '<option value="">Select tag</option>' + tags.map((x) => `<option value="${Number(x.id)}">${escapeHtml(x.name || '')}</option>`).join('');
  }
}

function renderBroadcastFromNumbers() {
  const sel = el('broadcastFromNumber');
  if (!sel) return;
  const nums = Array.isArray(state.myNumbers) ? state.myNumbers : [];
  if (nums.length === 0) {
    sel.innerHTML = '<option value="">No numbers</option>';
    sel.disabled = true;
    return;
  }
  sel.disabled = false;
  sel.innerHTML = '<option value="">Select From number</option>' + nums.map((n) => {
    const id = Number(n.id);
    const pn = escapeHtml(n.phone_number || '');
    return `<option value="${id}">${pn}${Number(n.is_default) === 1 ? ' (default)' : ''}</option>`;
  }).join('');
  const def = nums.find((x) => Number(x.is_default) === 1);
  if (def) sel.value = String(def.id);
}

function wireBroadcast() {
  const body = el('broadcastBody');
  if (body) {
    const update = () => renderSmsCounter(body.value, 'broadcastSmsCounter');
    body.addEventListener('input', update);
    update();
  }

  const mode = el('broadcastAudienceMode');
  const boxSearch = el('broadcastAudienceSearch');
  const boxGroup = el('broadcastAudienceGroup');
  const boxTag = el('broadcastAudienceTag');
  const boxPaste = el('broadcastAudiencePaste');
  const applyMode = () => {
    const v = String(mode ? mode.value : 'all');
    if (boxSearch) boxSearch.style.display = v === 'search' ? '' : 'none';
    if (boxGroup) boxGroup.style.display = v === 'group' ? '' : 'none';
    if (boxTag) boxTag.style.display = v === 'tag' ? '' : 'none';
    if (boxPaste) boxPaste.style.display = v === 'paste' ? '' : 'none';
  };
  if (mode) {
    mode.addEventListener('change', applyMode);
    applyMode();
  }

  const tplSel = el('broadcastTemplate');
  if (tplSel) {
    tplSel.addEventListener('change', () => {
      const id = Number(tplSel.value || 0);
      const tpls = Array.isArray(state.templates) ? state.templates : [];
      const match = tpls.find((x) => Number(x.id) === id);
      const b = el('broadcastBody');
      if (b) {
        b.value = match ? String(match.body || '') : b.value;
        renderSmsCounter(b.value, 'broadcastSmsCounter');
      }
    });
  }

  const merge = el('broadcastMergeField');
  if (merge) {
    merge.addEventListener('change', () => {
      const key = String(merge.value || '').trim();
      if (!key) return;
      const tag = `{${key}}`;
      const b = el('broadcastBody');
      if (!b) return;
      const start = b.selectionStart || 0;
      const end = b.selectionEnd || 0;
      const cur = String(b.value || '');
      b.value = cur.slice(0, start) + tag + cur.slice(end);
      b.focus();
      try {
        const pos = start + tag.length;
        b.setSelectionRange(pos, pos);
      } catch {
      }
      merge.value = '';
      renderSmsCounter(b.value, 'broadcastSmsCounter');
    });
  }

  const saveBtn = el('broadcastTemplateSaveBtn');
  if (saveBtn) {
    saveBtn.addEventListener('click', async () => {
      const name = prompt('Template name');
      if (!name) return;
      const b = el('broadcastBody');
      const bodyText = String(b ? b.value : '').trim();
      if (!bodyText) return;
      try {
        saveBtn.disabled = true;
        await apiPost('/api/templates/save', { name, body: bodyText });
        await loadTemplates();
        renderBroadcastTemplates();
        toastSuccess('Saved');
      } catch (e) {
        toastError(e && e.message ? e.message : String(e));
      } finally {
        saveBtn.disabled = false;
      }
    });
  }

  const delBtn = el('broadcastTemplateDeleteBtn');
  if (delBtn) {
    delBtn.addEventListener('click', async () => {
      const sel = el('broadcastTemplate');
      const id = sel ? Number(sel.value || 0) : 0;
      if (!id) return;
      if (!confirm('Delete this template?')) return;
      try {
        delBtn.disabled = true;
        await apiPost('/api/templates/delete', { id });
        await loadTemplates();
        renderBroadcastTemplates();
        if (sel) sel.value = '';
        toastSuccess('Deleted');
      } catch (e) {
        toastError(e && e.message ? e.message : String(e));
      } finally {
        delBtn.disabled = false;
      }
    });
  }

  const previewBtn = el('broadcastPreviewBtn');
  if (previewBtn) {
    previewBtn.addEventListener('click', async () => {
      const modeV = String(el('broadcastAudienceMode') ? el('broadcastAudienceMode').value : 'all');
      const q = String(el('broadcastQuery') ? el('broadcastQuery').value : '').trim();
      const groupId = Number(el('broadcastGroupSelect') ? el('broadcastGroupSelect').value : 0) || 0;
      const tagId = Number(el('broadcastTagSelect') ? el('broadcastTagSelect').value : 0) || 0;
      const numbers = String(el('broadcastPasteNumbers') ? el('broadcastPasteNumbers').value : '');
      try {
        previewBtn.disabled = true;
        const data = await apiPost('/api/broadcast/preview', { mode: modeV, q, group_id: groupId, tag_id: tagId, numbers });
        const host = el('broadcastPreview');
        if (host) {
          const s = Array.isArray(data.sample) ? data.sample : [];
          host.innerHTML = `<div class="small">Eligible: ${escapeHtml(String(data.count_eligible ?? 0))} (opted out: ${escapeHtml(String(data.count_opted_out ?? 0))})</div>`
            + (s.length ? `<div style="height:10px"></div><div class="small">Sample:</div><div style="height:6px"></div>${s.map((x) => `<div class="small">${escapeHtml(x.phone_number || '')} ${escapeHtml((`${x.first_name || ''} ${x.last_name || ''}`).trim())}</div>`).join('')}` : '');
        }
      } catch (e) {
        toastError(e && e.message ? e.message : String(e));
      } finally {
        previewBtn.disabled = false;
      }
    });
  }

  const sendBtn = el('broadcastSendBtn');
  if (sendBtn) {
    sendBtn.addEventListener('click', async () => {
      const fromNumberId = Number(el('broadcastFromNumber') ? el('broadcastFromNumber').value : 0) || 0;
      const bodyText = String(el('broadcastBody') ? el('broadcastBody').value : '').trim();
      if (!fromNumberId || !bodyText) return;
      if (!confirm('Send broadcast now?')) return;
      const modeV = String(el('broadcastAudienceMode') ? el('broadcastAudienceMode').value : 'all');
      const q = String(el('broadcastQuery') ? el('broadcastQuery').value : '').trim();
      const groupId = Number(el('broadcastGroupSelect') ? el('broadcastGroupSelect').value : 0) || 0;
      const tagId = Number(el('broadcastTagSelect') ? el('broadcastTagSelect').value : 0) || 0;
      const numbers = String(el('broadcastPasteNumbers') ? el('broadcastPasteNumbers').value : '');
      try {
        sendBtn.disabled = true;
        const res = await apiPost('/api/broadcast/send', { mode: modeV, q, group_id: groupId, tag_id: tagId, numbers, body: bodyText, from_number_id: fromNumberId, dry_run: false });
        toastSuccess(`Sent ${res && res.sent_count ? res.sent_count : ''}`.trim() || 'Sent');
      } catch (e) {
        toastError(e && e.message ? e.message : String(e));
      } finally {
        sendBtn.disabled = false;
      }
    });
  }
}

function getSelectedContactIds() {
  if (!state.selectedContactIds || typeof state.selectedContactIds !== 'object') return [];
  return Object.keys(state.selectedContactIds)
    .map((k) => Number(k))
    .filter((id) => id > 0 && state.selectedContactIds[id]);
}

function updateContactsBulkBar() {
  const count = getSelectedContactIds().length;
  const c = el('contactsSelectedCount');
  if (c) c.textContent = `${count} selected`;

  const del = el('contactsBulkDelete');
  if (del) del.disabled = count === 0;
  const addG = el('contactsBulkAddGroup');
  if (addG) addG.disabled = count === 0;
  const addT = el('contactsBulkAddTag');
  if (addT) addT.disabled = count === 0;
}

function getSelectedCallIds() {
  if (!state.selectedCallIds || typeof state.selectedCallIds !== 'object') return [];
  return Object.keys(state.selectedCallIds)
    .map((k) => Number(k))
    .filter((id) => id > 0 && state.selectedCallIds[id]);
}

function updateCallsBulkBar() {
  const count = getSelectedCallIds().length;
  const c = el('callsSelectedCount');
  if (c) c.textContent = `${count} selected`;
  const del = el('callsBulkDelete');
  if (del) del.disabled = count === 0;
}

function getSelectedVoicemailIds() {
  if (!state.selectedVoicemailIds || typeof state.selectedVoicemailIds !== 'object') return [];
  return Object.keys(state.selectedVoicemailIds)
    .map((k) => Number(k))
    .filter((id) => id > 0 && state.selectedVoicemailIds[id]);
}

function updateVoicemailsBulkBar() {
  const count = getSelectedVoicemailIds().length;
  const c = el('voicemailsSelectedCount');
  if (c) c.textContent = `${count} selected`;
  const del = el('voicemailsBulkDelete');
  if (del) del.disabled = count === 0;
}

function refreshCrmDropdowns() {
  const groups = Array.isArray(state.crmGroups) ? state.crmGroups : [];
  const tags = Array.isArray(state.crmTags) ? state.crmTags : [];

  const gFilter = el('contactsFilterGroup');
  if (gFilter) {
    const cur = String(gFilter.value || '');
    gFilter.innerHTML = '<option value="">All groups</option>' + groups
      .map((g) => `<option value="${Number(g.id)}">${escapeHtml(g.name || '')}</option>`)
      .join('');
    gFilter.value = cur;
  }

  const tFilter = el('contactsFilterTag');
  if (tFilter) {
    const cur = String(tFilter.value || '');
    tFilter.innerHTML = '<option value="">All tags</option>' + tags
      .map((t) => `<option value="${Number(t.id)}">${escapeHtml(t.name || '')}</option>`)
      .join('');
    tFilter.value = cur;
  }

  const gBulk = el('contactsBulkGroup');
  if (gBulk) {
    gBulk.innerHTML = '<option value="">Add group...</option>' + groups
      .map((g) => `<option value="${Number(g.id)}">${escapeHtml(g.name || '')}</option>`)
      .join('');
  }
  const tBulk = el('contactsBulkTag');
  if (tBulk) {
    tBulk.innerHTML = '<option value="">Add tag...</option>' + tags
      .map((t) => `<option value="${Number(t.id)}">${escapeHtml(t.name || '')}</option>`)
      .join('');
  }
}

async function loadCrmLists() {
  if (_inflight.crmLists) return _inflight.crmLists;
  _inflight.crmLists = (async () => {
    const [tags, groups] = await Promise.all([
      apiGet('/api/crm/tags'),
      apiGet('/api/crm/groups')
    ]);
    state.crmTags = Array.isArray(tags.tags) ? tags.tags : [];
    state.crmGroups = Array.isArray(groups.groups) ? groups.groups : [];
  })().finally(() => { _inflight.crmLists = null; });
  return _inflight.crmLists;
}

async function loadContactFields() {
  if (_inflight.contactFields) return _inflight.contactFields;
  _inflight.contactFields = (async () => {
    const data = await apiGet('/api/contacts/fields');
    state.contactFields = Array.isArray(data.fields) ? data.fields : [];
  })().finally(() => { _inflight.contactFields = null; });
  return _inflight.contactFields;
}

async function loadInboxContactFieldValues(contactId) {
  const id = Number(contactId || 0);
  if (!id) return {};
  try {
    const data = await apiGet(`/api/contacts/fields/values?contact_id=${encodeURIComponent(id)}`);
    return (data && data.values) ? data.values : {};
  } catch {
    return {};
  }
}

function renderInboxContactFields(values) {
  const host = el('inboxContactFields');
  if (!host) return;
  const fields = Array.isArray(state.contactFields) ? state.contactFields : [];
  if (fields.length === 0) {
    host.innerHTML = '<div class="small">No custom fields yet</div>';
    return;
  }
  const v = values && typeof values === 'object' ? values : {};
  host.innerHTML = fields.map((f) => {
    const key = escapeHtml(f.field_key || '');
    const label = escapeHtml(f.label || f.field_key || '');
    const val = escapeHtml((v[f.field_key] ?? '') || '');
    return `<div style="margin-top:10px">
      <div class="small">${label} <span class="small">({${key}})</span></div>
      <input class="input" data-cfkey="${key}" value="${val}" placeholder="${label}">
    </div>`;
  }).join('');
}

async function loadInboxContactFields() {
  const conv = state.activeConversation;
  const contactId = conv ? Number(conv.contact_id || 0) : 0;
  if (!contactId) return;
  await loadContactFields();
  const values = await loadInboxContactFieldValues(contactId);
  renderInboxContactFields(values);
}

async function loadInboxModalContactNotes(contactId) {
  const id = Number(contactId || 0);
  const list = el('contactNotesListModal');
  if (!id || !list) return;
  list.innerHTML = '<div class="small">Loading...</div>';
  try {
    const data = await apiGet(`/api/contacts/notes?contact_id=${encodeURIComponent(id)}`);
    const notes = Array.isArray(data && data.notes) ? data.notes : [];
    if (notes.length === 0) {
      list.innerHTML = '<div class="small">No notes yet</div>';
      return;
    }
    list.innerHTML = notes.map((n) => {
      const who = escapeHtml(n.user_email || '');
      const when = escapeHtml(fmtWhen(n.created_at || ''));
      const note = escapeHtml(n.note || '');
      return `<div class="noteItem"><div class="small">${who} • ${when}</div><div style="margin-top:6px">${note}</div></div>`;
    }).join('');
  } catch (e) {
    list.innerHTML = `<div class="small">${escapeHtml(e && e.message ? e.message : String(e))}</div>`;
  }
}

function toastSuccess(message) { showToast(message, 'success'); }
function toastError(message) { showToast(message, 'error'); }
function toastInfo(message) { showToast(message, 'info'); }

function el(id) {
  return document.getElementById(id);
}

function playDtmfTone(key) {
  try {
    const map = {
      '1': [697, 1209], '2': [697, 1336], '3': [697, 1477],
      '4': [770, 1209], '5': [770, 1336], '6': [770, 1477],
      '7': [852, 1209], '8': [852, 1336], '9': [852, 1477],
      '*': [941, 1209], '0': [941, 1336], '#': [941, 1477]
    };
    const pair = map[String(key || '').trim()];
    if (!pair) return;

    const AC = window.AudioContext || window.webkitAudioContext;
    if (!AC) return;
    if (!playDtmfTone._ctx) {
      playDtmfTone._ctx = new AC();
    }
    const ctx = playDtmfTone._ctx;
    if (ctx.state === 'suspended') {
      ctx.resume().catch(() => {});
    }

    const now = ctx.currentTime;
    const duration = 0.14;

    const gain = ctx.createGain();
    gain.gain.setValueAtTime(0.0001, now);
    gain.gain.exponentialRampToValueAtTime(0.12, now + 0.01);
    gain.gain.exponentialRampToValueAtTime(0.0001, now + duration);
    gain.connect(ctx.destination);

    const o1 = ctx.createOscillator();
    o1.type = 'sine';
    o1.frequency.setValueAtTime(pair[0], now);
    o1.connect(gain);

    const o2 = ctx.createOscillator();
    o2.type = 'sine';
    o2.frequency.setValueAtTime(pair[1], now);
    o2.connect(gain);

    o1.start(now);
    o2.start(now);
    o1.stop(now + duration);
    o2.stop(now + duration);
  } catch {
  }
}

function wireCallDtmfPad() {
  const pad = el('callDtmfPad');
  if (!pad) return;

  const log = el('callDtmfLog');
  const appendLog = (k) => {
    if (!log) return;
    log.textContent = String((log.textContent || '') + String(k || '')).slice(-64);
  };
  if (log && !log.dataset.wired) {
    log.dataset.wired = '1';
    log.textContent = '';
  }

  if (pad.dataset.wired === '1') return;
  pad.dataset.wired = '1';

  pad.querySelectorAll('[data-dtmf]').forEach((btn) => {
    btn.addEventListener('click', () => {
      const k = String(btn.getAttribute('data-dtmf') || '').trim();
      if (!k) return;
      playDtmfTone(k);
      appendLog(k);
      try {
        if (activeCall && typeof activeCall.sendDigits === 'function') {
          activeCall.sendDigits(k);
        }
      } catch {
      }
    });
  });
}

function renderNewMessageFromNumbers() {
  const sel = el('newMessageFrom');
  if (!sel) return;
  const items = Array.isArray(state.myNumbers) ? state.myNumbers : [];
  if (items.length === 0) {
    sel.innerHTML = '<option value="">No numbers</option>';
    sel.disabled = true;
    return;
  }
  sel.disabled = false;
  sel.innerHTML = items.map((n) => {
    const id = Number(n.id);
    const pn = escapeHtml(n.phone_number);
    return `<option value="${id}">${pn}${Number(n.is_default) === 1 ? ' (default)' : ''}</option>`;
  }).join('');
  const def = items.find((x) => Number(x.is_default) === 1);
  if (def) {
    sel.value = String(def.id);
  }
}
function wireNewMessage() {
  const btn = el('newMessageBtn');
  const panel = el('newMessagePanel');
  const cancel = el('newMessageCancel');
  const start = el('newMessageStart');
  const toEl = el('newMessageTo');
  const fromEl = el('newMessageFrom');
  const msgEl = el('newMessageText');
  const charCountEl = el('newMessageCharCount');
  if (!btn || !panel || !cancel || !start || !toEl || !fromEl || !msgEl || !charCountEl) return;

  const open = () => {
    renderNewMessageFromNumbers();
    panel.style.display = '';
    try { toEl.focus(); } catch {}
  };
  const close = () => {
    panel.style.display = 'none';
    toEl.value = '';
  };

  btn.addEventListener('click', () => {
    if (panel.style.display === 'none' || panel.style.display === '') {
      open();
    } else {
      close();
    }
  });
  cancel.addEventListener('click', close);

  start.addEventListener('click', async () => {
    const to = normalizeE164(String(toEl.value || '').trim());
    const fromId = fromEl.value ? Number(fromEl.value) : 0;
    if (!to) {
      toastError('Enter a valid To number');
      return;
    }
    if (!fromId) {
      toastError('Select a From number');
      return;
    }

    start.disabled = true;
    try {
      const res = await apiPost('/api/inbox/conversations/create', { phone_number: to, default_number_id: fromId });
      close();
      await loadConversations({ silent: true });
      if (res && res.conversation_id) {
        await selectConversation(Number(res.conversation_id));
      }
    } catch (e) {
      toastError(e && e.message ? e.message : String(e));
    } finally {
      start.disabled = false;
    }
  });
}

function wireInboxSmsCounter() {
  const body = el('messageBody');
  if (!body) return;
  const update = () => renderSmsCounter(body.value, 'inboxSmsCounter');
  body.addEventListener('input', update);
  update();
}

function renderTwilioAccounts() {
  const list = el('twilioAccountsList');
  if (!list) return;
  const items = Array.isArray(state.twilioAccounts) ? state.twilioAccounts : [];
  if (items.length === 0) {
    list.innerHTML = '<div class="item"><div class="small">No Twilio accounts saved</div></div>';
    return;
  }

  list.innerHTML = items.map((a) => {
    const id = Number(a.id);
    const name = escapeHtml(a.name || '');
    const sid = escapeHtml(a.account_sid || '');
    const from = escapeHtml(a.default_from_number || '');
    return `<div class="item">
      <div class="row" style="align-items:center;justify-content:space-between">
        <div><strong>${name}</strong></div>
        <button class="btn danger" type="button" data-delta="1" data-id="${id}">Delete</button>
      </div>
      <div class="small" style="margin-top:6px">SID: ${sid}</div>
      ${from ? `<div class="small" style="margin-top:6px">Default From: ${from}</div>` : ''}
    </div>`;
  }).join('');

  list.querySelectorAll('[data-delta="1"]').forEach((btn) => {
    btn.addEventListener('click', async () => {
      const id = Number(btn.dataset.id || 0);
      if (!id) return;
      if (!confirm('Delete this Twilio account profile?')) return;
      try {
        await apiPost('/api/admin/twilio-accounts/delete', { id });
        await loadTwilioAccounts();
        await loadNumbersAdmin();
        toastSuccess('Deleted');
      } catch (e) {
        toastError(e && e.message ? e.message : String(e));
      }
    });
  });
}

async function loadTwilioAccounts() {
  if (_inflight.twilioAccounts) return _inflight.twilioAccounts;
  const list = el('twilioAccountsList');
  if (list) list.innerHTML = '<div class="item"><div class="small">Loading...</div></div>';
  _inflight.twilioAccounts = (async () => {
    try {
      const data = await apiGet('/api/admin/twilio-accounts');
      state.twilioAccounts = data.accounts || [];
    } catch (e) {
      state.twilioAccounts = [];
      if (list) list.innerHTML = `<div class="item"><div class="small">${escapeHtml(e && e.message ? e.message : String(e))}</div></div>`;
      return;
    }
    renderTwilioAccounts();
  })().finally(() => { _inflight.twilioAccounts = null; });
  return _inflight.twilioAccounts;
}

function wireTwilioAccountsSettings() {
  const refresh = el('refreshTwilioAccounts');
  if (refresh) refresh.addEventListener('click', () => loadTwilioAccounts().catch(() => {}));

  const btn = el('addTwilioAccountBtn');
  if (btn) {
    btn.addEventListener('click', async () => {
      const payload = {
        name: String(el('taName') ? el('taName').value : '').trim(),
        account_sid: String(el('taAccountSid') ? el('taAccountSid').value : '').trim(),
        auth_token: String(el('taAuthToken') ? el('taAuthToken').value : '').trim(),
        api_key: String(el('taApiKey') ? el('taApiKey').value : '').trim(),
        api_secret: String(el('taApiSecret') ? el('taApiSecret').value : '').trim(),
        twiml_app_sid: String(el('taTwimlAppSid') ? el('taTwimlAppSid').value : '').trim(),
        default_from_number: String(el('taDefaultFrom') ? el('taDefaultFrom').value : '').trim(),
      };
      try {
        await apiPost('/api/admin/twilio-accounts/add', payload);
        await loadTwilioAccounts();
        await loadNumbersAdmin();
        toastSuccess('Saved');
      } catch (e) {
        toastError(e && e.message ? e.message : String(e));
      }
    });
  }
}

function renderVoicemails() {
  const list = el('voicemailsList');
  const listMain = el('voicemailsListMain');
  if (!list && !listMain) return;
  const items = Array.isArray(state.voicemails) ? state.voicemails : [];
  if (items.length === 0) {
    if (list) list.innerHTML = '<div class="item"><div class="small">No voicemails yet</div></div>';
    if (listMain) listMain.innerHTML = '<div class="item"><div class="small">No voicemails yet</div></div>';
    state._voicemailsVisibleIds = [];
    updateVoicemailsBulkBar();
    return;
  }

  state._voicemailsVisibleIds = items.map((v) => Number(v.id)).filter((x) => x > 0);

  const html = items.map((v) => {
    const id = Number(v.id);
    const when = escapeHtml(fmtWhen(v.created_at || ''));
    const from = escapeHtml(v.from_number || '');
    const to = escapeHtml(v.to_number || '');
    const dur = v.recording_duration ? `${Number(v.recording_duration)}s` : '';
    const url = String(v.recording_url || '').trim();
    let sid = '';
    if (url) {
      const m = url.match(/\/Recordings\/(RE[a-zA-Z0-9]+)/);
      if (m && m[1]) sid = String(m[1]);
    }
    const proxied = String(v.recording_proxy_url || '').trim() || (sid ? `/api/voice/recording?sid=${encodeURIComponent(sid)}` : '');
    const link = proxied ? `<a class="btn" href="${escapeHtml(proxied)}" target="_blank">Open</a>` : '';
    const selected = !!(state.selectedVoicemailIds && state.selectedVoicemailIds[id]);
    return `<div class="item" data-vid="${id}">
      <div class="row" style="align-items:center;justify-content:space-between">
        <div class="row" style="align-items:center;gap:10px">
          <input type="checkbox" data-vsel="1" ${selected ? 'checked' : ''}>
          <div><strong>Voicemail</strong></div>
        </div>
        <div class="small">${when}</div>
      </div>
      <div class="small" style="margin-top:6px">${from} → ${to}</div>
      <div class="row" style="margin-top:10px;align-items:center;justify-content:space-between">
        <div class="small">${escapeHtml(dur)}</div>
        <div class="row">${link}</div>
      </div>
    </div>`;
  }).join('');

  if (list) list.innerHTML = html;
  if (listMain) listMain.innerHTML = html;

  const container = listMain || list;
  if (container) {
    container.querySelectorAll('[data-vsel="1"]').forEach((cb) => {
      cb.addEventListener('change', () => {
        const item = cb.closest('.item');
        if (!item) return;
        const id = Number(item.dataset.vid || 0);
        if (!id) return;
        if (!state.selectedVoicemailIds || typeof state.selectedVoicemailIds !== 'object') state.selectedVoicemailIds = {};
        state.selectedVoicemailIds[id] = !!cb.checked;
        updateVoicemailsBulkBar();
      });
    });
  }

  updateVoicemailsBulkBar();
}

async function loadVoicemails() {
  const list = el('voicemailsList');
  const listMain = el('voicemailsListMain');
  if (list) list.innerHTML = '<div class="item"><div class="small">Loading...</div></div>';
  if (listMain) listMain.innerHTML = '<div class="item"><div class="small">Loading...</div></div>';
  try {
    const data = await apiGet('/api/admin/voicemails?limit=50');
    state.voicemails = data.voicemails || [];
  } catch (e) {
    state.voicemails = [];
    if (list) list.innerHTML = `<div class="item"><div class="small">${escapeHtml(e && e.message ? e.message : String(e))}</div></div>`;
    if (listMain) listMain.innerHTML = `<div class="item"><div class="small">${escapeHtml(e && e.message ? e.message : String(e))}</div></div>`;
    return;
  }
  renderVoicemails();
}

async function loadVoiceRouting() {
  try {
    const data = await apiGet('/api/admin/settings/voice-routing');
    if (el('voiceRingTimeout')) el('voiceRingTimeout').value = String(data.voice_ring_timeout ?? '');
    if (el('voiceForwardNumber')) el('voiceForwardNumber').value = String(data.voice_forward_number ?? '');
    if (el('voiceVoicemailEnabled')) el('voiceVoicemailEnabled').checked = !!data.voice_voicemail_enabled;
    if (el('voiceVoicemailGreeting')) el('voiceVoicemailGreeting').value = String(data.voice_voicemail_greeting ?? '');
    if (el('voiceVoicemailMax')) el('voiceVoicemailMax').value = String(data.voice_voicemail_max_length ?? '');
    if (el('voiceRecordCalls')) el('voiceRecordCalls').checked = !!data.voice_record_calls;
  } catch (e) {
  }
}

async function loadSmtpSettings() {
  try {
    const data = await apiGet('/api/admin/settings/smtp');
    if (el('smtpEnabled')) el('smtpEnabled').checked = !!data.smtp_enabled;
    if (el('smtpHost')) el('smtpHost').value = String(data.smtp_host ?? '');
    if (el('smtpPort')) el('smtpPort').value = String(data.smtp_port ?? '');
    if (el('smtpUsername')) el('smtpUsername').value = String(data.smtp_username ?? '');
    if (el('smtpSecure')) el('smtpSecure').value = String(data.smtp_secure ?? 'tls');
    if (el('smtpFromEmail')) el('smtpFromEmail').value = String(data.smtp_from_email ?? '');
    if (el('smtpFromName')) el('smtpFromName').value = String(data.smtp_from_name ?? '');
    if (el('smtpPassword')) el('smtpPassword').value = '';
  } catch (e) {
  }
}

async function saveSmtpSettings() {
  const payload = {
    smtp_enabled: !!(el('smtpEnabled') && el('smtpEnabled').checked),
    smtp_host: String(el('smtpHost') ? el('smtpHost').value : '').trim(),
    smtp_port: Number(el('smtpPort') ? el('smtpPort').value : 587) || 587,
    smtp_username: String(el('smtpUsername') ? el('smtpUsername').value : '').trim(),
    smtp_password: String(el('smtpPassword') ? el('smtpPassword').value : ''),
    smtp_secure: String(el('smtpSecure') ? el('smtpSecure').value : 'tls').trim(),
    smtp_from_email: String(el('smtpFromEmail') ? el('smtpFromEmail').value : '').trim(),
    smtp_from_name: String(el('smtpFromName') ? el('smtpFromName').value : '').trim(),
  };
  await apiPost('/api/admin/settings/smtp', payload);
  if (el('smtpPassword')) el('smtpPassword').value = '';
}

async function saveVoiceRouting() {
  const payload = {
    voice_ring_timeout: Number(el('voiceRingTimeout') ? el('voiceRingTimeout').value : 20) || 20,
    voice_forward_number: String(el('voiceForwardNumber') ? el('voiceForwardNumber').value : '').trim(),
    voice_voicemail_enabled: !!(el('voiceVoicemailEnabled') && el('voiceVoicemailEnabled').checked),
    voice_voicemail_greeting: String(el('voiceVoicemailGreeting') ? el('voiceVoicemailGreeting').value : '').trim(),
    voice_voicemail_max_length: Number(el('voiceVoicemailMax') ? el('voiceVoicemailMax').value : 60) || 60,
    voice_record_calls: !!(el('voiceRecordCalls') && el('voiceRecordCalls').checked),
  };
  await apiPost('/api/admin/settings/voice-routing', payload);
}

function wireVoiceRoutingSettings() {
  const refresh = el('refreshVoiceRouting');
  if (refresh) refresh.addEventListener('click', () => loadVoiceRouting().catch(() => {}));
  const save = el('saveVoiceRouting');
  if (save) {
    save.addEventListener('click', async () => {
      try {
        save.disabled = true;
        await saveVoiceRouting();
        toastSuccess('Saved');
      } catch (e) {
        toastError(e && e.message ? e.message : String(e));
      } finally {
        save.disabled = false;
      }
    });
  }
}

function wireSmtpSettings() {
  const refresh = el('refreshSmtp');
  if (refresh) refresh.addEventListener('click', () => loadSmtpSettings().catch(() => {}));
  const save = el('saveSmtp');
  if (save) {
    save.addEventListener('click', async () => {
      try {
        save.disabled = true;
        await saveSmtpSettings();
        toastSuccess('Saved');
      } catch (e) {
        toastError(e && e.message ? e.message : String(e));
      } finally {
        save.disabled = false;
      }
    });
  }

  const testBtn = el('sendSmtpTest');
  if (testBtn) {
    testBtn.addEventListener('click', async () => {
      const to_email = String(el('smtpTestTo') ? el('smtpTestTo').value : '').trim();
      if (!to_email) {
        toastError('Enter a test email address');
        return;
      }
      try {
        testBtn.disabled = true;
        await apiPost('/api/admin/settings/smtp/test', { to_email });
        toastSuccess('Test email sent');
      } catch (e) {
        toastError(e && e.message ? e.message : String(e));
      } finally {
        testBtn.disabled = false;
      }
    });
  }
}

function wireVoicemails() {
  const refresh = el('refreshVoicemails');
  if (refresh) refresh.addEventListener('click', () => loadVoicemails().catch(() => {}));

  const refreshMain = el('refreshVoicemailsMain');
  if (refreshMain) refreshMain.addEventListener('click', () => loadVoicemails().catch(() => {}));

  const selAll = el('voicemailsSelectAllVisible');
  if (selAll) {
    selAll.addEventListener('click', () => {
      const ids = Array.isArray(state._voicemailsVisibleIds) ? state._voicemailsVisibleIds : [];
      if (!state.selectedVoicemailIds || typeof state.selectedVoicemailIds !== 'object') state.selectedVoicemailIds = {};
      ids.forEach((id) => { state.selectedVoicemailIds[id] = true; });
      renderVoicemails();
    });
  }
  const clearSel = el('voicemailsClearSelection');
  if (clearSel) {
    clearSel.addEventListener('click', () => {
      state.selectedVoicemailIds = {};
      renderVoicemails();
    });
  }

  const bulkDel = el('voicemailsBulkDelete');
  if (bulkDel) {
    bulkDel.addEventListener('click', async () => {
      const ids = getSelectedVoicemailIds();
      if (ids.length === 0) return;
      if (!confirm(`Delete ${ids.length} voicemails?`)) return;
      try {
        bulkDel.disabled = true;
        await apiPost('/api/admin/voicemails/bulk-delete', { voicemail_ids: ids });
        state.selectedVoicemailIds = {};
        toastSuccess('Deleted');
        await loadVoicemails();
      } catch (e) {
        toastError(e && e.message ? e.message : String(e));
      } finally {
        bulkDel.disabled = false;
      }
    });
  }
}

function escapeHtml(s) {
  return String(s)
    .replaceAll('&', '&amp;')
    .replaceAll('<', '&lt;')
    .replaceAll('>', '&gt;')
    .replaceAll('"', '&quot;')
    .replaceAll("'", '&#039;');
}

function setStatus(text) {
  const node = el('voiceStatus');
  if (node) node.textContent = text;
  const node2 = el('voiceStatus2');
  if (node2) node2.textContent = text;
}

function normalizeE164(value) {
  return String(value || '').trim();
}

function fmtWhen(s) {
  const raw = String(s || '').trim();
  if (!raw) return '';

  const hasTz = /([zZ]|[+-]\d\d:?\d\d)$/.test(raw);
  const isoLike = /^\d{4}-\d{2}-\d{2}[ T]\d{2}:\d{2}/.test(raw);
  const d = new Date(hasTz || !isoLike ? raw : (raw.replace(' ', 'T') + 'Z'));
  if (Number.isNaN(d.getTime())) return raw;

  const tz = String(state.appTimezone || '').trim();
  if (tz) {
    try {
      return new Intl.DateTimeFormat(undefined, {
        timeZone: tz,
        year: 'numeric',
        month: '2-digit',
        day: '2-digit',
        hour: '2-digit',
        minute: '2-digit'
      }).format(d);
    } catch {
    }
  }
  return d.toLocaleString();
}

function playConnectedTone() {
  try {
    if (!audioCtx) {
      const Ctx = window.AudioContext || window.webkitAudioContext;
      if (!Ctx) return;
      audioCtx = new Ctx();
    }

    const now = audioCtx.currentTime;
    const mkBeep = (t, freq, dur) => {
      const osc = audioCtx.createOscillator();
      const gain = audioCtx.createGain();
      osc.type = 'sine';
      osc.frequency.setValueAtTime(freq, t);
      gain.gain.setValueAtTime(0.0001, t);
      gain.gain.exponentialRampToValueAtTime(0.12, t + 0.01);
      gain.gain.exponentialRampToValueAtTime(0.0001, t + dur);
      osc.connect(gain);
      gain.connect(audioCtx.destination);
      osc.start(t);
      osc.stop(t + dur + 0.02);
    };

    mkBeep(now + 0.00, 440, 0.12);
    mkBeep(now + 0.16, 660, 0.12);
  } catch {
  }
}

async function apiGet(url) {
  const res = await fetch(url, { headers: { 'Accept': 'application/json' } });
  if (!res.ok) {
    const parsed = await parseApiError(res);
    if (res.status === 403) {
      toastError('Access changed. Refreshing permissions...');
      await handleApiForbidden();
    }
    throw new Error(parsed.message || 'Request failed');
  }
  return await res.json();
}

async function apiPost(url, payload) {
  const res = await fetch(url, {
    method: 'POST',
    headers: { 'Content-Type': 'application/json', 'Accept': 'application/json' },
    body: JSON.stringify(payload || {})
  });
  if (!res.ok) {
    const parsed = await parseApiError(res);
    if (res.status === 403) {
      toastError('Access changed. Refreshing permissions...');
      await handleApiForbidden();
    }
    throw new Error(parsed.message || 'Request failed');
  }
  return await res.json();
}

function themeApply(theme) {
  const root = document.documentElement;
  if (theme === 'light') {
    root.setAttribute('data-theme', 'light');
  } else {
    root.removeAttribute('data-theme');
  }
}

function themeInit() {
  const saved = localStorage.getItem('vcw_theme');
  themeApply(saved === 'light' ? 'light' : 'dark');
  const btn = el('themeToggle');
  if (btn) {
    btn.addEventListener('click', () => {
      const now = document.documentElement.getAttribute('data-theme') === 'light' ? 'dark' : 'light';
      localStorage.setItem('vcw_theme', now);
      themeApply(now);
    });
  }
}

function rightPanelOpen(open) {
  const panel = el('rightPanel');
  if (!panel) return;
  panel.style.display = open ? 'flex' : 'none';
}

function rightPanelInit() {
  rightPanelOpen(true);
  const t = el('rightToggle');
  if (t) t.addEventListener('click', () => {
    const panel = el('rightPanel');
    if (!panel) return;
    rightPanelOpen(panel.style.display === 'none');
  });
  const c = el('rightClose');
  if (c) c.addEventListener('click', () => rightPanelOpen(false));
}

function renderConversationList() {
  const list = el('conversationList');
  if (!list) return;

  if (!Array.isArray(state.conversations) || state.conversations.length === 0) {
    list.innerHTML = '<div class="conversationItem"><div class="small">No conversations yet</div></div>';
    return;
  }

  list.innerHTML = state.conversations.map((c) => {
    const id = Number(c.conversation_id);
    const name = escapeHtml(c.contact_name || c.contact_phone || 'Unknown');
    const phone = escapeHtml(c.contact_phone || '');
    const inboxNumber = escapeHtml(c.conversation_number || '');
    const preview = escapeHtml(c.last_message_preview || '');
    const when = escapeHtml(c.last_message_at || '');
    const assigned = c.assigned_user_email ? escapeHtml(c.assigned_user_email) : '';
    const unread = Number(c.is_unread || 0) === 1 ? `<span class="badge" style="background:rgba(255,91,107,.18);border-color:rgba(255,91,107,.35)">Unread</span>` : '';
    const badge = assigned ? `<span class="badge">${assigned}</span>` : '';
    const active = state.activeConversationId === id ? ' active' : '';
    return `<div class="conversationItem${active}" data-id="${id}">
      <div class="conversationTop">
        <div class="conversationName">${name}</div>
        ${unread}${badge}
      </div>
      <div class="small" style="margin-top:4px">${phone}</div>
      ${inboxNumber ? `<div class="small" style="margin-top:4px">to ${inboxNumber}</div>` : ''}
      <div class="small" style="margin-top:6px">${preview}</div>
      <div class="small" style="margin-top:6px">${when}</div>
    </div>`;
  }).join('');

  if (!list._convClickWired) {
    list._convClickWired = true;
    list.addEventListener('click', async (ev) => {
      const target = ev && ev.target ? ev.target : null;
      const node = target && target.closest ? target.closest('.conversationItem') : null;
      if (!node) return;
      const id = Number(node.getAttribute('data-id') || 0);
      if (!id) return;
      await selectConversation(id);
    });
  }
}

function wireDialPad() {
  const dialInput = el('dialInput');
  if (!dialInput) return;

  const host = el('viewDialpad') || document;
  if (!host._dialPadDelegationWired) {
    host._dialPadDelegationWired = true;
    host.addEventListener('click', (ev) => {
      const target = ev && ev.target ? ev.target : null;
      if (!target) return;
      const btn = target.closest ? target.closest('.key') : null;
      if (!btn) return;
      const k = btn.getAttribute('data-k') || '';
      if (!k) return;
      dialInput.value += k;
    });
  }

  const clearBtn = el('clearBtn');
  if (clearBtn) clearBtn.addEventListener('click', () => { dialInput.value = ''; });
}

function renderDialFromNumbers() {
  const sel = el('dialFromNumberSelect');
  if (!sel) return;

  const items = Array.isArray(state.myNumbers) ? state.myNumbers : [];
  if (items.length === 0) {
    sel.innerHTML = '<option value="">No numbers</option>';
    sel.disabled = true;
    return;
  }

  sel.disabled = false;
  sel.innerHTML = items.map((n) => {
    const id = Number(n.id);
    const pn = escapeHtml(n.phone_number);
    return `<option value="${id}">${pn}${Number(n.is_default) === 1 ? ' (default)' : ''}</option>`;
  }).join('');

  const def = items.find((x) => Number(x.is_default) === 1);
  if (def) {
    sel.value = String(def.id);
  }
}

async function setActiveNav(view) {
  const needs = {
    analytics: 'analytics.view',
    inbox: 'inbox.view',
    dialpad: 'dialpad.use',
    calls: 'calls.view',
    voicemails: 'voicemails.view',
    contacts: 'contacts.view',
    broadcast: 'broadcast.use',
    numbers: 'numbers.view',
    settings: 'settings.view',
    users: 'users.manage',
    roles: 'users.manage'
  };
  if (needs[view] && !hasPerm(needs[view])) {
    toastError('Forbidden');
    view = 'analytics';
  }
  const views = {
    analytics: el('viewAnalytics'),
    inbox: el('viewInbox'),
    dialpad: el('viewDialpad'),
    calls: el('viewCalls'),
    voicemails: el('viewVoicemails'),
    contacts: el('viewContacts'),
    broadcast: el('viewBroadcast'),
    numbers: el('viewNumbers'),
    settings: el('viewSettings'),
    users: el('viewUsers'),
    roles: el('viewRoles')
  };
  Object.entries(views).forEach(([k, node]) => {
    if (!node) return;
    node.style.display = k === view ? '' : 'none';
  });

  const items = {
    analytics: el('navAnalytics'),
    inbox: el('navInbox'),
    dialpad: el('navDialpad'),
    calls: el('navCalls'),
    voicemails: el('navVoicemails'),
    contacts: el('navContacts'),
    broadcast: el('navBroadcast'),
    numbers: el('navNumbers'),
    settings: el('navSettings'),
    users: el('navUsers'),
    roles: el('navRoles')
  };
  Object.entries(items).forEach(([k, node]) => {
    if (!node) return;
    if (k === view) node.classList.add('active');
    else node.classList.remove('active');
  });

  if (view === 'calls') {
    loadCalls().catch(() => {});
    try { wireCalls(); } catch {}
  }
  if (view === 'inbox') {
    try { loadConversations().catch(() => {}); } catch {}
    try { wireInboxControls(); } catch {}
  }
  if (view === 'analytics') {
    loadAnalyticsQuick('analyticsQuick').catch(() => {});
  }
  if (view === 'dialpad') {
    loadAnalyticsQuick('dialpadAnalytics').catch(() => {});
    if (!device) {
      setStatus('Initializing...');
      initVoice().catch(() => {});
    }
    loadUsersAndNumbers().then(() => {
      renderDialFromNumbers();
    }).catch(() => {});

    try { wireDialPad(); } catch {}
    try { wireCalling(); } catch {}
  }
  if (view === 'numbers') {
    loadNumbersAdmin().catch(() => {});
  }
  if (view === 'contacts') {
    loadCrmLists().then(() => {
      refreshCrmDropdowns();
      loadContacts().catch(() => {});
    }).catch(() => loadContacts().catch(() => {}));
  }
  if (view === 'users') {
    loadAdminUsers().catch(() => {});
  }
  if (view === 'roles') {
    refreshRbacUi().catch(() => {});
    try { wireRbacUi(); } catch {}
  }
  if (view === 'settings') {
    loadTwilioAccounts().catch(() => {});
    loadDefaultTwilioSettings().catch(() => {});
    loadSmtpSettings().catch(() => {});
    loadVoiceRouting().catch(() => {});
    loadOptOutSettings().catch(() => {});
    try { wireSmsNotificationSettings(); } catch {}
    loadNotifRules().then(() => {
      renderNotifRoleSelects();
      syncNotifRuleEditor();
    }).catch(() => {});
    loadTimezoneSettings().catch(() => {});
    loadCrmLists().then(() => {
      refreshCrmDropdowns();
      renderTagsAdmin();
      renderGroupsAdmin();
    }).catch(() => {});
    loadContactFields().then(() => {
      renderContactFieldsAdmin();
    }).catch(() => {});
  }
  if (view === 'broadcast') {
    loadUsersAndNumbers().then(() => {
      renderBroadcastFromNumbers();
    }).catch(() => {});
    loadCrmLists().then(() => {
      renderBroadcastAudiencePickers();
    }).catch(() => {});
    loadContactFields().then(() => {
      renderBroadcastMergeFields();
      renderBroadcastTemplatesManagerMergeFields();
    }).catch(() => {});
    loadTemplates().then(() => {
      renderBroadcastTemplates();
      renderInboxTemplates();
      renderBroadcastTemplatesManagerList();
    }).catch(() => {});
  }
  if (view === 'voicemails') {
    loadVoicemails().catch(() => {});
    try { wireVoicemails(); } catch {}
  }
}

function wireNavigation() {
  const applyFromHash = () => {
    const h = String(window.location.hash || '').replace('#', '');
    if (h === '' || h === 'analytics') return setActiveNav('analytics');
    if (h === 'broadcast') return setActiveNav('broadcast');
    if (h === 'inbox') return setActiveNav('inbox');
    if (h === 'dialpad') return setActiveNav('dialpad');
    if (h === 'calls') return setActiveNav('calls');
    if (h === 'voicemails') return setActiveNav('voicemails');
    if (h === 'contacts') return setActiveNav('contacts');
    if (h === 'numbers') return setActiveNav('numbers');
    if (h === 'settings') return setActiveNav('settings');
    if (h === 'users') return setActiveNav('users');
    if (h === 'roles') return setActiveNav('roles');
    return setActiveNav('analytics');
  };

  window.addEventListener('hashchange', applyFromHash);
  applyFromHash();
}

function renderContacts() {
  const list = el('contactsList');
  if (!list) return;
  const itemsAll = Array.isArray(state.contacts) ? state.contacts : [];
  const fields = Array.isArray(state.contactFields) ? state.contactFields : [];
  const valuesByContact = (state.contactsFieldValues && typeof state.contactsFieldValues === 'object') ? state.contactsFieldValues : {};
  const tagsByContact = (state.contactsTagsByContact && typeof state.contactsTagsByContact === 'object') ? state.contactsTagsByContact : {};
  const groupsByContact = (state.contactsGroupsByContact && typeof state.contactsGroupsByContact === 'object') ? state.contactsGroupsByContact : {};

  const selGroup = Number(el('contactsFilterGroup') ? el('contactsFilterGroup').value : 0) || 0;
  const selTag = Number(el('contactsFilterTag') ? el('contactsFilterTag').value : 0) || 0;

  const items = itemsAll.filter((c) => {
    const id = Number(c.id);
    if (!id) return false;
    if (selGroup) {
      const gs = Array.isArray(groupsByContact[id]) ? groupsByContact[id] : [];
      if (!gs.some((g) => Number(g.id) === selGroup)) return false;
    }
    if (selTag) {
      const ts = Array.isArray(tagsByContact[id]) ? tagsByContact[id] : [];
      if (!ts.some((t) => Number(t.id) === selTag)) return false;
    }
    return true;
  });

  state._contactsVisibleIds = items.map((c) => Number(c.id)).filter((x) => x > 0);

  if (items.length === 0) {
    list.innerHTML = '<div class="item"><div class="small">No contacts</div></div>';
    updateContactsBulkBar();
    return;
  }

  list.innerHTML = items.map((c) => {
    const id = Number(c.id);
    const firstName = escapeHtml(c.first_name || '');
    const lastName = escapeHtml(c.last_name || '');
    const name = escapeHtml(c.name || '');
    const phone = escapeHtml(c.phone_number || '');
    const email = escapeHtml(c.email || '');
    const when = escapeHtml(fmtWhen(c.created_at || ''));
    const v = (valuesByContact && valuesByContact[id] && typeof valuesByContact[id] === 'object') ? valuesByContact[id] : {};
    const selected = !!(state.selectedContactIds && state.selectedContactIds[id]);

    const displayName = escapeHtml((`${c.first_name || ''} ${c.last_name || ''}`).trim() || c.name || '');

    const customFieldsHtml = fields.length ? `<div style="margin-top:10px" class="pageGrid">
        ${fields.map((f) => {
          const key = escapeHtml(f.field_key || '');
          const label = escapeHtml(f.label || f.field_key || '');
          const val = escapeHtml((v[f.field_key] ?? '') || '');
          return `<div>
            <div class="small">${label}</div>
            <input class="input" data-cfkey="${key}" value="${val}" placeholder="${label}" data-init="${val}">
          </div>`;
        }).join('')}
      </div>` : '';

    const tagBadges = (Array.isArray(tagsByContact[id]) ? tagsByContact[id] : []).map((t) => {
      const tid = Number(t.id);
      const tn = escapeHtml(t.name || String(tid));
      return `<span class="badge" style="display:inline-flex;gap:8px;align-items:center">
        <span>${tn}</span>
        <button class="btn danger" type="button" data-ctagunassign="1" data-cid="${id}" data-tid="${tid}" style="padding:2px 8px">x</button>
      </span>`;
    }).join('');
    const groupBadges = (Array.isArray(groupsByContact[id]) ? groupsByContact[id] : []).map((g) => {
      const gid = Number(g.id);
      const gn = escapeHtml(g.name || String(gid));
      return `<span class="badge" style="display:inline-flex;gap:8px;align-items:center">
        <span>${gn}</span>
        <button class="btn danger" type="button" data-cgroupunassign="1" data-cid="${id}" data-gid="${gid}" style="padding:2px 8px">x</button>
      </span>`;
    }).join('');

    const emailLine = email ? `<div class="small" style="margin-top:6px">${email}</div>` : '';

    return `<div class="item" data-id="${id}">
      <div class="row" style="align-items:center;justify-content:space-between;gap:10px">
        <div class="row" style="align-items:center;gap:10px;min-width:0;flex:1">
          <input type="checkbox" data-csel="1" ${selected ? 'checked' : ''}>
          <div style="min-width:0">
            <div><strong>${phone}</strong>${displayName ? ` <span class="small">${displayName}</span>` : ''}</div>
            ${emailLine}
            <div class="small" style="margin-top:6px">Created: ${when}</div>
          </div>
        </div>
        <div class="row">
          <button class="btn" type="button" data-ctoggle="1">Edit</button>
        </div>
      </div>

      <div data-cdetails="1" style="display:none;margin-top:12px">
        <div class="row" style="align-items:flex-start;gap:12px;flex-wrap:wrap">
          <div style="flex:1;min-width:220px">
            <div class="small">Groups</div>
            <div style="margin-top:6px" class="row" style="flex-wrap:wrap">${groupBadges || '<span class="small">None</span>'}</div>
          </div>
          <div style="flex:1;min-width:220px">
            <div class="small">Tags</div>
            <div style="margin-top:6px" class="row" style="flex-wrap:wrap">${tagBadges || '<span class="small">None</span>'}</div>
          </div>
        </div>

        <div style="margin-top:10px" class="row">
          <input class="input" id="contactFirstName_${id}" name="contactFirstName_${id}" data-first="1" value="${firstName}" placeholder="First name" style="flex:1" data-init="${firstName}">
          <input class="input" id="contactLastName_${id}" name="contactLastName_${id}" data-last="1" value="${lastName}" placeholder="Last name" style="flex:1" data-init="${lastName}">
        </div>
        <div style="margin-top:8px" class="row">
          <input class="input" id="contactDisplayName_${id}" name="contactDisplayName_${id}" data-name="1" value="${name}" placeholder="Display name" style="flex:1" data-init="${name}">
          <input class="input" id="contactEmail_${id}" name="contactEmail_${id}" data-email="1" value="${email}" placeholder="Email" style="flex:1" data-init="${email}">
        </div>
        ${customFieldsHtml}
      </div>
    </div>`;
  }).join('');

  list.querySelectorAll('[data-csel="1"]').forEach((cb) => {
    cb.addEventListener('change', () => {
      const item = cb.closest('.item');
      if (!item) return;
      const id = Number(item.dataset.id || 0);
      if (!id) return;
      if (!state.selectedContactIds || typeof state.selectedContactIds !== 'object') state.selectedContactIds = {};
      state.selectedContactIds[id] = !!cb.checked;
      updateContactsBulkBar();
    });
  });

  list.querySelectorAll('[data-ctoggle="1"]').forEach((btn) => {
    btn.addEventListener('click', () => {
      const item = btn.closest('.item');
      if (!item) return;
      const details = item.querySelector('[data-cdetails="1"]');
      if (!details) return;
      const isOpen = details.style.display !== 'none';
      details.style.display = isOpen ? 'none' : '';
      btn.textContent = isOpen ? 'Edit' : 'Close';
    });
  });

  list.querySelectorAll('[data-ctagunassign="1"]').forEach((b) => {
    b.addEventListener('click', async () => {
      const contactId = Number(b.dataset.cid || 0);
      const tagId = Number(b.dataset.tid || 0);
      if (!contactId || !tagId) return;
      try {
        b.disabled = true;
        await apiPost('/api/crm/tags/unassign', { contact_id: contactId, tag_id: tagId });
        await loadContacts();
        toastSuccess('Saved');
      } catch (e) {
        toastError(e && e.message ? e.message : String(e));
      } finally {
        b.disabled = false;
      }
    });
  });

  list.querySelectorAll('[data-cgroupunassign="1"]').forEach((b) => {
    b.addEventListener('click', async () => {
      const contactId = Number(b.dataset.cid || 0);
      const groupId = Number(b.dataset.gid || 0);
      if (!contactId || !groupId) return;
      try {
        b.disabled = true;
        await apiPost('/api/crm/groups/unassign', { contact_id: contactId, group_id: groupId });
        await loadContacts();
        toastSuccess('Saved');
      } catch (e) {
        toastError(e && e.message ? e.message : String(e));
      } finally {
        b.disabled = false;
      }
    });
  });

  updateContactsBulkBar();
}

async function loadContacts() {
  const qEl = el('contactsSearch');
  const q = qEl ? String(qEl.value || '').trim() : '';
  const data = await apiGet(`/api/contacts/full?q=${encodeURIComponent(q)}`);
  state.contacts = data.contacts || [];
  state.contactFields = data.fields || [];
  state.contactsFieldValues = data.values_by_contact || {};
  state.contactsTagsByContact = data.tags_by_contact || {};
  state.contactsGroupsByContact = data.groups_by_contact || {};
  renderContacts();
}

function wireContacts() {
  const qEl = el('contactsSearch');
  if (qEl) {
    let t = null;
    qEl.addEventListener('input', () => {
      if (t) clearTimeout(t);
      t = setTimeout(() => loadContacts().catch(() => {}), 250);
    });
  }

  const gf = el('contactsFilterGroup');
  if (gf) gf.addEventListener('change', () => renderContacts());
  const tf = el('contactsFilterTag');
  if (tf) tf.addEventListener('change', () => renderContacts());

  const selAll = el('contactsSelectAllVisible');
  if (selAll) {
    selAll.addEventListener('click', () => {
      const ids = Array.isArray(state._contactsVisibleIds) ? state._contactsVisibleIds : [];
      if (!state.selectedContactIds || typeof state.selectedContactIds !== 'object') state.selectedContactIds = {};
      ids.forEach((id) => { state.selectedContactIds[id] = true; });
      renderContacts();
    });
  }
  const clearSel = el('contactsClearSelection');
  if (clearSel) {
    clearSel.addEventListener('click', () => {
      state.selectedContactIds = {};
      renderContacts();
    });
  }

  const bulkGroup = el('contactsBulkAddGroup');
  if (bulkGroup) {
    bulkGroup.addEventListener('click', async () => {
      const groupId = Number(el('contactsBulkGroup') ? el('contactsBulkGroup').value : 0) || 0;
      const ids = getSelectedContactIds();
      if (!groupId) return;
      if (ids.length === 0) return;
      try {
        await apiPost('/api/contacts/bulk-assign-group', { group_id: groupId, contact_ids: ids });
        toastSuccess('Saved');
        await loadContacts();
      } catch (e) {
        toastError(e && e.message ? e.message : String(e));
      }
    });
  }

  const bulkTag = el('contactsBulkAddTag');
  if (bulkTag) {
    bulkTag.addEventListener('click', async () => {
      const tagId = Number(el('contactsBulkTag') ? el('contactsBulkTag').value : 0) || 0;
      const ids = getSelectedContactIds();
      if (!tagId) return;
      if (ids.length === 0) return;
      try {
        await apiPost('/api/contacts/bulk-assign-tag', { tag_id: tagId, contact_ids: ids });
        toastSuccess('Saved');
        await loadContacts();
      } catch (e) {
        toastError(e && e.message ? e.message : String(e));
      }
    });
  }

  const bulkDel = el('contactsBulkDelete');
  if (bulkDel) {
    bulkDel.addEventListener('click', async () => {
      const ids = getSelectedContactIds();
      if (ids.length === 0) return;
      if (!confirm(`Delete ${ids.length} contacts?`)) return;
      try {
        await apiPost('/api/contacts/bulk-delete', { contact_ids: ids });
        state.selectedContactIds = {};
        toastSuccess('Deleted');
        await loadContacts();
      } catch (e) {
        toastError(e && e.message ? e.message : String(e));
      }
    });
  }

  const saveAll = el('saveContactsAll');
  if (saveAll) {
    saveAll.addEventListener('click', async () => {
      const list = el('contactsList');
      if (!list) return;
      const contacts = [];
      const fieldValues = {};

      list.querySelectorAll('.item[data-id]').forEach((item) => {
        const id = Number(item.getAttribute('data-id') || 0);
        if (!id) return;
        const firstName = String((item.querySelector('[data-first="1"]') || {}).value || '').trim();
        const lastName = String((item.querySelector('[data-last="1"]') || {}).value || '').trim();
        const name = String((item.querySelector('[data-name="1"]') || {}).value || '').trim();
        const email = String((item.querySelector('[data-email="1"]') || {}).value || '').trim();
        contacts.push({ id, first_name: firstName, last_name: lastName, name, email });

        const v = {};
        item.querySelectorAll('[data-cfkey]').forEach((inp) => {
          const k = String(inp.getAttribute('data-cfkey') || '').trim();
          if (!k) return;
          v[k] = String(inp.value || '').trim();
        });
        if (Object.keys(v).length) {
          fieldValues[String(id)] = v;
        }
      });

      try {
        saveAll.disabled = true;
        await apiPost('/api/contacts/bulk-save', { contacts, field_values: fieldValues });
        toastSuccess('Saved');
        await loadContacts();
      } catch (e) {
        toastError(e && e.message ? e.message : String(e));
      } finally {
        saveAll.disabled = false;
      }
    });
  }

  const exportBtn = el('exportContactsBtn');
  if (exportBtn) {
    exportBtn.addEventListener('click', () => {
      const q = String(el('contactsSearch') ? el('contactsSearch').value : '').trim();
      window.open(`/api/contacts/export?q=${encodeURIComponent(q)}`, '_blank');
    });
  }

  const importBtn = el('importContactsBtn');
  const importFile = el('importContactsFile');
  if (importBtn && importFile) {
    importBtn.addEventListener('click', () => {
      try { importFile.click(); } catch {}
    });
    importFile.addEventListener('change', () => {
      const f = importFile.files && importFile.files[0] ? importFile.files[0] : null;
      if (!f) return;
      const r = new FileReader();
      r.onload = async () => {
        try {
          importBtn.disabled = true;
          const csv = String(r.result || '');
          const res = await apiPost('/api/contacts/import', { csv });
          toastSuccess(`Imported ${Number(res && res.imported ? res.imported : 0)}`);
          await loadContacts();
        } catch (e) {
          toastError(e && e.message ? e.message : String(e));
        } finally {
          importBtn.disabled = false;
          try { importFile.value = ''; } catch {}
        }
      };
      r.readAsText(f);
    });
  }

  const openAdd = el('openAddContactModal');
  const closeAdd = el('closeAddContactModal');
  const modal = el('addContactModal');
  if (openAdd && modal) {
    openAdd.addEventListener('click', () => {
      modal.style.display = 'flex';
      try { (el('newContactPhone') || {}).focus(); } catch {}
    });
  }
  if (closeAdd && modal) {
    closeAdd.addEventListener('click', () => {
      modal.style.display = 'none';
    });
  }

  const addBtn = el('addContactBtn');
  if (addBtn && modal) {
    addBtn.addEventListener('click', async () => {
      const phone = String(el('newContactPhone') ? el('newContactPhone').value : '').trim();
      const firstName = String(el('newContactFirstName') ? el('newContactFirstName').value : '').trim();
      const lastName = String(el('newContactLastName') ? el('newContactLastName').value : '').trim();
      const name = String(el('newContactName') ? el('newContactName').value : '').trim();
      const email = String(el('newContactEmail') ? el('newContactEmail').value : '').trim();
      if (!phone) {
        toastError('Phone number required');
        return;
      }
      try {
        addBtn.disabled = true;
        await apiPost('/api/contacts/add', { phone_number: phone, first_name: firstName, last_name: lastName, name, email });
        modal.style.display = 'none';
        ['newContactPhone','newContactFirstName','newContactLastName','newContactName','newContactEmail'].forEach((id) => {
          const n = el(id);
          if (n) n.value = '';
        });
        toastSuccess('Saved');
        await loadContacts();
      } catch (e) {
        toastError(e && e.message ? e.message : String(e));
      } finally {
        addBtn.disabled = false;
      }
    });
  }
}

function fmtDur(seconds) {
  const s = Number(seconds || 0);
  if (!Number.isFinite(s) || s <= 0) return '';
  const m = Math.floor(s / 60);
  const ss = s % 60;
  return String(m) + ':' + String(ss).padStart(2, '0');
}
function renderCalls() {
  const list = el('callsList');
  if (!list) return;
  const items = Array.isArray(state.calls) ? state.calls : [];
  if (items.length === 0) {
    list.innerHTML = '<div class="item"><div class="small">No calls yet</div></div>';
    state._callsVisibleIds = [];
    updateCallsBulkBar();
    return;
  }

  state._callsVisibleIds = items.map((c) => Number(c.id)).filter((x) => x > 0);

  list.innerHTML = items.map((c) => {
    const id = Number(c.id);
    const dir = escapeHtml(c.direction || '');
    const from = escapeHtml(c.from_number || '');
    const to = escapeHtml(c.to_number || '');
    const st = escapeHtml(c.status || '');
    const when = escapeHtml(fmtWhen(c.created_at || ''));
    const dur = fmtDur(c.duration_seconds);
    const who = escapeHtml(c.user_email || c.client_identity || '');
    const extra = [who ? `By ${who}` : '', dur ? `Dur ${dur}` : '', st ? `Status ${st}` : ''].filter(Boolean).join(' • ');
    const recSid = String(c.recording_sid || '').trim();
    const recUrl = String(c.recording_url || '').trim();
    let sid = recSid;
    if (!sid && recUrl) {
      const m = recUrl.match(/\/Recordings\/(RE[a-zA-Z0-9]+)/);
      if (m && m[1]) sid = String(m[1]);
    }
    const proxied = sid ? `/api/voice/recording?sid=${encodeURIComponent(sid)}` : '';
    const recLink = proxied ? `<a class="btn" href="${escapeHtml(proxied)}" target="_blank">Recording</a>` : '';
    const selected = !!(state.selectedCallIds && state.selectedCallIds[id]);
    return `<div class="item" data-cid="${id}">
      <div class="row" style="align-items:center;justify-content:space-between;gap:10px">
        <div class="row" style="align-items:center;gap:10px;min-width:0;flex:1">
          <input type="checkbox" data-callsel="1" ${selected ? 'checked' : ''}>
          <div><strong>${dir}</strong></div>
        </div>
        <div class="small">${when}</div>
      </div>
      <div class="small" style="margin-top:6px">${from} → ${to}</div>
      <div class="small" style="margin-top:6px">${escapeHtml(extra)}</div>
      ${recLink ? `<div class="row" style="margin-top:10px">${recLink}</div>` : ''}
    </div>`;
  }).join('');

  list.querySelectorAll('[data-callsel="1"]').forEach((cb) => {
    cb.addEventListener('change', () => {
      const item = cb.closest('.item');
      if (!item) return;
      const id = Number(item.dataset.cid || 0);
      if (!id) return;
      if (!state.selectedCallIds || typeof state.selectedCallIds !== 'object') state.selectedCallIds = {};
      state.selectedCallIds[id] = !!cb.checked;
      updateCallsBulkBar();
    });
  });

  updateCallsBulkBar();
}

async function loadCalls() {
  const list = el('callsList');
  if (list) list.innerHTML = '<div class="item"><div class="small">Loading...</div></div>';
  try {
    const params = new URLSearchParams();
    params.set('limit', '50');

    const q = String(el('callsSearch') ? el('callsSearch').value : '').trim();
    const direction = String(el('callsDirection') ? el('callsDirection').value : '').trim();
    const status = String(el('callsStatus') ? el('callsStatus').value : '').trim();
    const userId = String(el('callsUser') ? el('callsUser').value : '').trim();
    const fromDate = String(el('callsFromDate') ? el('callsFromDate').value : '').trim();
    const toDate = String(el('callsToDate') ? el('callsToDate').value : '').trim();
    if (q) params.set('q', q);
    if (direction) params.set('direction', direction);
    if (status) params.set('status', status);
    if (userId) params.set('user_id', userId);
    if (fromDate) params.set('from_date', fromDate);
    if (toDate) params.set('to_date', toDate);

    const data = await apiGet('/api/calls?' + params.toString());
    state.calls = data.calls || [];
    renderCalls();
  } catch (e) {
    state.calls = [];
    if (list) {
      list.innerHTML = `<div class="item"><div class="small">${escapeHtml(e && e.message ? e.message : String(e))}</div></div>`;
    }
  }
}

function wireCalls() {
  const btn = el('refreshCalls');
  if (btn) {
    btn.addEventListener('click', () => loadCalls().catch((e) => toastError(e && e.message ? e.message : String(e))));
  }

  const apply = el('applyCallsFilters');
  if (apply) apply.addEventListener('click', () => loadCalls().catch(() => {}));
  const reset = el('resetCallsFilters');
  if (reset) {
    reset.addEventListener('click', () => {
      ['callsSearch','callsDirection','callsStatus','callsUser','callsFromDate','callsToDate'].forEach((id) => {
        const n = el(id);
        if (!n) return;
        if (n.tagName === 'SELECT') n.value = '';
        else n.value = '';
      });
      loadCalls().catch(() => {});
    });
  }

  const selAll = el('callsSelectAllVisible');
  if (selAll) {
    selAll.addEventListener('click', () => {
      const ids = Array.isArray(state._callsVisibleIds) ? state._callsVisibleIds : [];
      if (!state.selectedCallIds || typeof state.selectedCallIds !== 'object') state.selectedCallIds = {};
      ids.forEach((id) => { state.selectedCallIds[id] = true; });
      renderCalls();
    });
  }
  const clearSel = el('callsClearSelection');
  if (clearSel) {
    clearSel.addEventListener('click', () => {
      state.selectedCallIds = {};
      renderCalls();
    });
  }

  const bulkDel = el('callsBulkDelete');
  if (bulkDel) {
    bulkDel.addEventListener('click', async () => {
      const ids = getSelectedCallIds();
      if (ids.length === 0) return;
      if (!confirm(`Delete ${ids.length} calls?`)) return;
      try {
        bulkDel.disabled = true;
        await apiPost('/api/calls/bulk-delete', { call_ids: ids });
        state.selectedCallIds = {};
        toastSuccess('Deleted');
        await loadCalls();
      } catch (e) {
        toastError(e && e.message ? e.message : String(e));
      } finally {
        bulkDel.disabled = false;
      }
    });
  }
}

function renderNumbersAdmin() {
  const list = el('numbersList');
  if (!list) return;
  const numbers = Array.isArray(state.adminNumbers) ? state.adminNumbers : [];
  const mappings = Array.isArray(state.adminNumberMappings) ? state.adminNumberMappings : [];
  const accounts = Array.isArray(state.twilioAccounts) ? state.twilioAccounts : [];

  if (numbers.length === 0) {
    list.innerHTML = '<div class="item"><div class="small">No numbers yet</div></div>';
    return;
  }

  const users = Array.isArray(state.users) ? state.users : [];
  const accountOptions = '<option value="">(default)</option>' + accounts.map((a) => `<option value="${Number(a.id)}">${escapeHtml(a.name || '')}</option>`).join('');

  const byNumber = new Map();
  mappings.forEach((m) => {
    const nid = Number(m.number_id);
    if (!byNumber.has(nid)) byNumber.set(nid, []);
    byNumber.get(nid).push(m);
  });

  list.innerHTML = numbers.map((n) => {
    const id = Number(n.id);
    const pn = escapeHtml(n.phone_number || '');
    const fn = escapeHtml(n.friendly_name || '');
    const tid = n.twilio_account_id ? Number(n.twilio_account_id) : 0;
    const vfwd = escapeHtml(n.voice_forward_number || '');
    const vrt = n.voice_ring_timeout ? String(Number(n.voice_ring_timeout)) : '';
    const assigned = byNumber.get(id) || [];

    const assignedUserIds = assigned.map((a) => Number(a.user_id)).filter(Boolean);
    const defaultUid = (assigned.find((a) => Number(a.is_default) === 1) || {}).user_id;
    const assignedBadges = assigned.length === 0
      ? '<div class="small" style="margin-top:8px">Not assigned</div>'
      : assigned.map((a) => {
        const uid = Number(a.user_id);
        const email = escapeHtml(a.email || String(uid));
        const isDef = Number(a.is_default) === 1;
        return `<span class="badge">${email}${isDef ? ' (default)' : ''}</span>`;
      }).join(' ');

    const userOptions = users.map((u) => {
      const uid = Number(u.id);
      const isSel = assignedUserIds.includes(uid);
      return `<option value="${uid}" ${isSel ? 'selected' : ''}>${escapeHtml(u.email || '')}</option>`;
    }).join('');

    const defaultUserOptions = '<option value="">(none)</option>' + users
      .filter((u) => assignedUserIds.includes(Number(u.id)))
      .map((u) => {
        const uid = Number(u.id);
        const isSel = defaultUid && Number(defaultUid) === uid;
        return `<option value="${uid}" ${isSel ? 'selected' : ''}>${escapeHtml(u.email || '')}</option>`;
      }).join('');

    return `<div class="item" data-nid="${id}">
      <div class="row" style="align-items:center;justify-content:space-between">
        <div><strong>${pn}</strong></div>
        <div class="small">ID ${id}</div>
      </div>
      <div style="margin-top:10px" class="row">
        <input class="input" id="numberFriendlyName_${id}" name="numberFriendlyName_${id}" data-fn="1" value="${fn}" placeholder="Friendly name" style="flex:1">
      </div>
      <div style="margin-top:10px" class="row">
        <select class="input" id="numberTwilioAccount_${id}" name="numberTwilioAccount_${id}" data-twilioacct="1" style="flex:1">${accountOptions}</select>
      </div>
      <div class="small" style="margin-top:10px">Inbound call routing (this number)</div>
      <div style="margin-top:8px" class="row">
        <input class="input" id="numberVoiceForward_${id}" name="numberVoiceForward_${id}" data-vfwd="1" value="${vfwd}" placeholder="Forward to number (optional)" style="flex:1">
      </div>
      <div style="margin-top:8px" class="row">
        <input class="input" id="numberVoiceRingTimeout_${id}" name="numberVoiceRingTimeout_${id}" data-vrt="1" value="${escapeHtml(vrt)}" placeholder="Ring timeout seconds (optional)" style="flex:1">
      </div>
      <div class="small" style="margin-top:10px">Assigned users</div>
      <div style="margin-top:8px" class="row">
        <select class="input" id="numberUsers_${id}" name="numberUsers_${id}" data-usersmulti="1" multiple size="4" style="flex:1;min-width:260px">${userOptions}</select>
        <div style="flex:1;min-width:220px">
          <div class="small">Default user (for this number)</div>
          <select class="input" id="numberDefaultUser_${id}" name="numberDefaultUser_${id}" data-defaultuid="1" style="margin-top:8px">${defaultUserOptions}</select>
        </div>
      </div>
      <div style="margin-top:10px">${assignedBadges}</div>
      <div style="margin-top:12px" class="row" >
        <button class="btn primary" type="button" data-saveall="1">Save</button>
      </div>
    </div>`;
  }).join('');

  list.querySelectorAll('.item').forEach((item) => {
    const sel = item.querySelector('[data-twilioacct="1"]');
    const nid = Number(item.dataset.nid || 0);
    const found = numbers.find((x) => Number(x.id) === nid);
    if (sel && found) {
      sel.value = found.twilio_account_id ? String(Number(found.twilio_account_id)) : '';
    }
  });

  list.querySelectorAll('[data-saveall="1"]').forEach((btn) => {
    btn.addEventListener('click', async () => {
      const item = btn.closest('.item');
      if (!item) return;

      const id = Number(item.dataset.nid || 0);
      const input = item.querySelector('[data-fn="1"]');
      const friendly_name = String(input ? input.value : '').trim();
      const acctSel = item.querySelector('[data-twilioacct="1"]');
      const twilio_account_id = acctSel && acctSel.value ? Number(acctSel.value) : null;
      const vfwdEl = item.querySelector('[data-vfwd="1"]');
      const voice_forward_number = String(vfwdEl ? vfwdEl.value : '').trim();
      const vrtEl = item.querySelector('[data-vrt="1"]');
      const voice_ring_timeout = vrtEl && String(vrtEl.value || '').trim() !== '' ? Number(vrtEl.value) : null;
      const usersSel = item.querySelector('[data-usersmulti="1"]');
      const selectedUserIds = usersSel ? Array.from(usersSel.selectedOptions).map((o) => Number(o.value)).filter(Boolean) : [];
      const defSel = item.querySelector('[data-defaultuid="1"]');
      const default_user_id = defSel && defSel.value ? Number(defSel.value) : null;

      if (!confirm('Save changes for this number?')) {
        return;
      }

      try {
        await apiPost('/api/admin/numbers/save', {
          id,
          friendly_name,
          twilio_account_id,
          voice_forward_number,
          voice_ring_timeout,
          user_ids: selectedUserIds,
          default_user_id,
        });
        toastSuccess('Saved');
        await loadNumbersAdmin();
      } catch (e) {
        toastError(e && e.message ? e.message : String(e));
      }
    });
  });
}

async function loadNumbersAdmin() {
  if (_inflight.numbersAdmin) return _inflight.numbersAdmin;
  _inflight.numbersAdmin = (async () => {
    try {
      const [_, data] = await Promise.all([
        loadTwilioAccounts().catch(() => {}),
        apiGet('/api/admin/numbers')
      ]);
      state.adminNumbers = data.numbers || [];
      state.adminNumberMappings = data.mappings || [];
    } catch (e) {
      state.adminNumbers = [];
      state.adminNumberMappings = [];
      const list = el('numbersList');
      if (list) list.innerHTML = `<div class="item"><div class="small">${escapeHtml(e && e.message ? e.message : String(e))}</div></div>`;
      return;
    }
    renderNumbersAdmin();
  })().finally(() => { _inflight.numbersAdmin = null; });
  return _inflight.numbersAdmin;
}

async function loadDefaultTwilioSettings() {
  try {
    await loadTwilioAccounts().catch(() => {});
    const data = await apiGet('/api/admin/settings/default-twilio');
    const sel = el('defaultTwilioAccount');
    if (!sel) return;
    const accounts = Array.isArray(state.twilioAccounts) ? state.twilioAccounts : [];
    sel.innerHTML = '<option value="0">(env / none)</option>' + accounts.map((a) => `<option value="${Number(a.id)}">${escapeHtml(a.name || '')}</option>`).join('');
    const v = Number(data.default_twilio_account_id || 0);
    sel.value = String(v > 0 ? v : 0);
  } catch (e) {
  }
}

async function saveDefaultTwilioSettings() {
  const sel = el('defaultTwilioAccount');
  const v = sel ? Number(sel.value || 0) : 0;
  await apiPost('/api/admin/settings/default-twilio', { default_twilio_account_id: v });
}

function wireDefaultTwilioSettings() {
  const r = el('refreshDefaultTwilio');
  if (r) r.addEventListener('click', () => loadDefaultTwilioSettings().catch(() => {}));
  const s = el('saveDefaultTwilio');
  if (s) {
    s.addEventListener('click', async () => {
      if (!confirm('Save default Twilio profile?')) return;
      try {
        await saveDefaultTwilioSettings();
        toastSuccess('Saved');
        await loadDefaultTwilioSettings();
      } catch (e) {
        toastError(e && e.message ? e.message : String(e));
      }
    });
  }
}

function wireWebhooksInfo() {
  const b = el('showWebhooksInfo');
  if (!b) return;
  b.addEventListener('click', () => {
    toastInfo('Use these URLs in Twilio. SMS webhook goes in Messaging settings. Voice webhook goes in your TwiML App Voice URL.');
  });
}

function ensureTimezoneOptions() {
  const sel = el('appTimezone');
  if (!sel) return;
  if (sel.options && sel.options.length > 0) return;
  const zones = (typeof Intl !== 'undefined' && Intl.supportedValuesOf) ? Intl.supportedValuesOf('timeZone') : [];
  const list = Array.isArray(zones) && zones.length ? zones : ['UTC', 'America/New_York', 'America/Chicago', 'America/Denver', 'America/Los_Angeles', 'Europe/London'];
  sel.innerHTML = list.map((z) => `<option value="${escapeHtml(z)}">${escapeHtml(z)}</option>`).join('');
}

function renderTimezoneNow() {
  const out = el('timezoneNow');
  if (!out) return;
  const tz = String(state.appTimezone || (el('appTimezone') ? el('appTimezone').value : '') || 'UTC');
  try {
    const fmt = new Intl.DateTimeFormat(undefined, {
      timeZone: tz,
      year: 'numeric',
      month: '2-digit',
      day: '2-digit',
      hour: '2-digit',
      minute: '2-digit',
      second: '2-digit'
    });
    out.textContent = fmt.format(new Date());
  } catch {
    out.textContent = '';
  }
}

async function loadTimezoneSettings() {
  ensureTimezoneOptions();
  const sel = el('appTimezone');
  if (!sel) return;
  const data = await apiGet('/api/admin/settings/timezone');
  const tz = String((data && data.app_timezone) ? data.app_timezone : 'UTC');
  sel.value = tz;
  state.appTimezone = tz;
  renderTimezoneNow();
}

async function saveTimezoneSettings() {
  const sel = el('appTimezone');
  const tz = String(sel ? sel.value : 'UTC');
  await apiPost('/api/admin/settings/timezone', { app_timezone: tz });
}

function wireTimezoneSettings() {
  const r = el('refreshTimezone');
  if (r) r.addEventListener('click', () => loadTimezoneSettings().catch(() => {}));
  const s = el('saveTimezone');
  if (s) {
    s.addEventListener('click', async () => {
      if (!confirm('Save timezone?')) return;
      try {
        await saveTimezoneSettings();
        toastSuccess('Saved');
        await loadTimezoneSettings();
      } catch (e) {
        toastError(e && e.message ? e.message : String(e));
      }
    });
  }
  ensureTimezoneOptions();
}

function wireNumbersAdmin() {
  const refresh = el('refreshNumbers');
  if (refresh) refresh.addEventListener('click', () => loadNumbersAdmin().catch(() => {}));

  const addBtn = el('addNumberBtn');
  if (addBtn) {
    addBtn.addEventListener('click', async () => {
      const phone_number = String(el('newNumber') ? el('newNumber').value : '').trim();
      const friendly_name = String(el('newNumberName') ? el('newNumberName').value : '').trim();
      if (!phone_number) return;
      try {
        await apiPost('/api/admin/numbers/add', { phone_number, friendly_name });
        if (el('newNumber')) el('newNumber').value = '';
        if (el('newNumberName')) el('newNumberName').value = '';
        await loadNumbersAdmin();
        toastSuccess('Added');
      } catch (e) {
        toastError(e && e.message ? e.message : String(e));
      }
    });
  }
}

function renderAdminUsers() {
  const list = el('usersList');
  if (!list) return;
  const items = Array.isArray(state.adminUsers) ? state.adminUsers : [];
  if (items.length === 0) {
    list.innerHTML = '<div class="item"><div class="small">No users (or not admin)</div></div>';
    return;
  }
  list.innerHTML = items.map((u) => {
    const id = Number(u.id);
    const email = escapeHtml(u.email || '');
    const role = escapeHtml(u.role || '');
    return `<div class="item" data-id="${id}">
      <div class="row" style="align-items:center;justify-content:space-between">
        <div>
          <div>${email}</div>
          <div class="small" style="margin-top:6px">Role: ${role}</div>
        </div>
        <div class="row">
          <select class="input" id="userRole_${id}" name="userRole_${id}" data-role="1" style="max-width:140px">
            <option value="agent" ${role === 'agent' ? 'selected' : ''}>agent</option>
            <option value="admin" ${role === 'admin' ? 'selected' : ''}>admin</option>
          </select>
          <button class="btn" data-setrole="1" type="button">Save</button>
        </div>
      </div>
      <div style="height:10px"></div>
      <div class="row">
        <input class="input" id="userNewPassword_${id}" name="userNewPassword_${id}" data-newpass="1" placeholder="New password (min 8)" type="password" style="flex:1">
        <button class="btn" data-resetpass="1" type="button">Reset</button>
      </div>
    </div>`;
  }).join('');

  list.querySelectorAll('[data-setrole="1"]').forEach((btn) => {
    btn.addEventListener('click', async () => {
      const item = btn.closest('.item');
      if (!item) return;
      const id = Number(item.dataset.id || 0);
      const sel = item.querySelector('[data-role="1"]');
      const role = sel ? sel.value : 'agent';
      try {
        await apiPost('/api/admin/users/set-role', { id, role });
        await loadAdminUsers();
        toastSuccess('Saved');
      } catch (e) {
        toastError(e && e.message ? e.message : String(e));
      }
    });
  });

  list.querySelectorAll('[data-resetpass="1"]').forEach((btn) => {
    btn.addEventListener('click', async () => {
      const item = btn.closest('.item');
      if (!item) return;
      const id = Number(item.dataset.id || 0);
      const input = item.querySelector('[data-newpass="1"]');
      const password = String(input ? input.value : '');
      try {
        await apiPost('/api/admin/users/reset-password', { id, password });
        if (input) input.value = '';
        toastSuccess('Password reset');
      } catch (e) {
        toastError(e && e.message ? e.message : String(e));
      }
    });
  });
}

async function loadAdminUsers() {
  if (_inflight.adminUsers) return _inflight.adminUsers;
  _inflight.adminUsers = (async () => {
    try {
      const data = await apiGet('/api/admin/users');
      state.adminUsers = data.users || [];
    } catch {
      state.adminUsers = [];
    }
    renderAdminUsers();
  })().finally(() => { _inflight.adminUsers = null; });
  return _inflight.adminUsers;
}

function wireUserManager() {
  const btn = el('createUserBtn');
  if (!btn) return;
  btn.addEventListener('click', async () => {
    const email = String(el('newUserEmail') ? el('newUserEmail').value : '').trim();
    const password = String(el('newUserPassword') ? el('newUserPassword').value : '');
    const role = String(el('newUserRole') ? el('newUserRole').value : 'agent');
    try {
      await apiPost('/api/admin/users/create', { email, password, role });
      if (el('newUserEmail')) el('newUserEmail').value = '';
      if (el('newUserPassword')) el('newUserPassword').value = '';
      await loadAdminUsers();
      toastSuccess('User created');
    } catch (e) {
      toastError(e && e.message ? e.message : String(e));
    }
  });
}

async function ensureMicPermission() {
  if (!navigator.mediaDevices || !navigator.mediaDevices.getUserMedia) {
    return;
  }
  try {
    await navigator.mediaDevices.getUserMedia({ audio: true });
  } catch {
  }
}

async function ensureNotificationPermission() {
  if (!('Notification' in window)) return;
  if (Notification.permission === 'default') {
    try {
      await Notification.requestPermission();
    } catch {
    }
  }
}

function wireSmsNotificationSettings() {
  const sound = el('smsNotifySound');
  const desk = el('smsNotifyDesktop');

  if (sound) {
    if (!sound._wired) {
      sound._wired = true;
      sound.addEventListener('change', () => {
        storageSetBool('vcw_sms_sound', !!sound.checked);
        toastSuccess('Saved');
      });
    }
    sound.checked = storageGetBool('vcw_sms_sound', true);
  }

  if (desk) {
    if (!desk._wired) {
      desk._wired = true;
      desk.addEventListener('change', async () => {
        storageSetBool('vcw_sms_desktop', !!desk.checked);
        if (desk.checked) {
          await ensureNotificationPermission();
          if ('Notification' in window && Notification.permission !== 'granted') {
            toastError('Desktop notifications blocked by browser');
          }
        }
        toastSuccess('Saved');
      });
    }
    desk.checked = storageGetBool('vcw_sms_desktop', false);
  }
}

function wireInboxControls() {
  if (wireInboxControls._wired) return;
  wireInboxControls._wired = true;
  const search = el('searchInput');
  if (search) {
    let t = null;
    search.addEventListener('input', () => {
      state.query = String(search.value || '').trim();
      if (t) clearTimeout(t);
      t = setTimeout(() => loadConversations().catch(() => {}), 250);
    });
  }

  const all = el('filterAll');
  if (all) all.addEventListener('click', () => { state.assignedFilter = 'all'; loadConversations().catch(() => {}); });
  const me = el('filterMe');
  if (me) me.addEventListener('click', () => { state.assignedFilter = 'me'; loadConversations().catch(() => {}); });

  const send = el('sendBtn');
  if (send) send.addEventListener('click', () => sendMessage());

  const msgBody = el('messageBody');
  if (msgBody) {
    msgBody.addEventListener('keydown', (ev) => {
      if (ev.key === 'Enter' && !ev.shiftKey) {
        ev.preventDefault();
        sendMessage();
      }
    });
  }
}

function wireRightPanelActions() {
  const editOpen = el('inboxEditOpen');
  const editClose = el('inboxEditClose');
  const modal = el('inboxEditModal');
  const topSave = el('inboxEditSaveTop');

  const openModal = async () => {
    if (!modal) return;
    if (!state.activeConversationId) {
      toastError('Select a conversation');
      return;
    }
    modal.style.display = 'flex';
    await loadInboxContactFields().catch(() => {});
    const conv = state.activeConversation;
    const contactId = conv ? Number(conv.contact_id || 0) : 0;
    if (contactId) {
      await loadInboxModalContactNotes(contactId).catch(() => {});
    }
  };
  const closeModal = () => {
    if (!modal) return;
    modal.style.display = 'none';
  };

  if (editOpen) {
    editOpen.addEventListener('click', () => {
      openModal().catch(() => {});
    });
  }
  if (editClose) editClose.addEventListener('click', closeModal);

  if (topSave) {
    topSave.addEventListener('click', async () => {
      const cid = state.activeConversationId;
      const conv = state.activeConversation;
      const contactId = conv ? Number(conv.contact_id || 0) : 0;
      if (!cid) return;

      topSave.disabled = true;
      try {
        const firstName = String(el('contactFirstName') ? el('contactFirstName').value : '').trim();
        const lastName = String(el('contactLastName') ? el('contactLastName').value : '').trim();
        const name = String(el('contactName') ? el('contactName').value : '').trim();
        const email = String(el('contactEmail') ? el('contactEmail').value : '').trim();

        await apiPost('/api/inbox/contact', { conversation_id: cid, first_name: firstName, last_name: lastName, name, email });

        if (contactId) {
          const host = el('inboxContactFields');
          const values = {};
          if (host) {
            host.querySelectorAll('[data-cfkey]').forEach((inp) => {
              const k = String(inp.getAttribute('data-cfkey') || '').trim();
              if (!k) return;
              values[k] = String(inp.value || '').trim();
            });
          }
          await apiPost('/api/contacts/fields/values', { contact_id: contactId, values });
        }

        await refreshActive();
        toastSuccess('Saved');
      } catch (e) {
        toastError(e && e.message ? e.message : String(e));
      } finally {
        topSave.disabled = false;
      }
    });
  }

  const addChatNote = el('addChatNote');
  if (addChatNote) {
    addChatNote.addEventListener('click', async () => {
      const cid = state.activeConversationId;
      if (!cid) return;
      const noteEl = el('chatNoteBody');
      const note = String(noteEl ? noteEl.value : '').trim();
      if (!note) return;
      try {
        await apiPost('/api/inbox/notes', { conversation_id: cid, note });
        if (noteEl) noteEl.value = '';
        const notes = await apiGet(`/api/inbox/notes?conversation_id=${encodeURIComponent(cid)}`);
        renderNotes(notes.notes);
        toastSuccess('Saved');
      } catch (e) {
        toastError(e && e.message ? e.message : String(e));
      }
    });
  }

  const addContactNoteModal = el('addContactNoteModal');
  if (addContactNoteModal) {
    addContactNoteModal.addEventListener('click', async () => {
      const conv = state.activeConversation;
      const contactId = conv ? Number(conv.contact_id || 0) : 0;
      if (!contactId) return;
      const ta = el('contactNoteBodyModal');
      const note = String(ta ? ta.value : '').trim();
      if (!note) return;
      try {
        await apiPost('/api/contacts/notes', { contact_id: contactId, note });
        if (ta) ta.value = '';
        await loadInboxModalContactNotes(contactId);
        toastSuccess('Saved');
      } catch (e) {
        toastError(e && e.message ? e.message : String(e));
      }
    });
  }

  const assignSel = el('assignedUserSelect');
  if (assignSel) {
    assignSel.addEventListener('change', async () => {
      const cid = state.activeConversationId;
      if (!cid) return;
      const v = assignSel.value;
      try {
        await apiPost('/api/inbox/assign', { conversation_id: cid, assigned_user_id: v || null });
        await refreshActive();
        toastSuccess('Saved');
      } catch (e) {
        toastError(e && e.message ? e.message : String(e));
      }
    });
  }

  const assignMe = el('assignMe');
  if (assignMe) {
    assignMe.addEventListener('click', async () => {
      const cid = state.activeConversationId;
      if (!cid) return;
      try {
        await apiPost('/api/inbox/assign', { conversation_id: cid, assigned_user_id: 'me' });
        await refreshActive();
        toastSuccess('Saved');
      } catch (e) {
        toastError(e && e.message ? e.message : String(e));
      }
    });
  }

  const unassign = el('unassign');
  if (unassign) {
    unassign.addEventListener('click', async () => {
      const cid = state.activeConversationId;
      if (!cid) return;
      try {
        await apiPost('/api/inbox/assign', { conversation_id: cid, assigned_user_id: null });
        await refreshActive();
        toastSuccess('Saved');
      } catch (e) {
        toastError(e && e.message ? e.message : String(e));
      }
    });
  }
}

function showIncoming(from, onAccept, onReject) {
  const box = el('incomingBox');
  if (!box) return;
  box.style.display = 'flex';
  const fromEl = el('incomingFrom');
  if (fromEl) fromEl.textContent = from;

  const a = el('incomingAccept');
  const r = el('incomingReject');

  if (a) a.onclick = () => { hideIncoming(); setStatus('In call'); onAccept(); };
  if (r) r.onclick = () => { hideIncoming(); setStatus('Ready'); onReject(); };

  setStatus('Incoming call');
}

function hideIncoming() {
  const box = el('incomingBox');
  if (!box) return;
  box.style.display = 'none';
}

function showIncomingModal(from, onAccept, onReject) {
  const modal = el('incomingModal');
  if (!modal) return;
  modal.style.display = 'flex';
  const fromEl = el('incomingFromModal');
  if (fromEl) fromEl.textContent = from;

  const a = el('incomingAcceptModal');
  const r = el('incomingRejectModal');
  if (a) a.onclick = () => { hideIncomingModal(); setStatus('In call'); onAccept(); };
  if (r) r.onclick = () => { hideIncomingModal(); setStatus('Ready'); onReject(); };
}

function hideIncomingModal() {
  const modal = el('incomingModal');
  if (!modal) return;
  modal.style.display = 'none';
}

async function getToken() {
  const res = await fetch('/api/voice/token', { headers: { 'Accept': 'application/json' } });
  if (!res.ok) {
    const text = await res.text();
    try {
      const asJson = JSON.parse(text);
      if (asJson && asJson.error) {
        throw new Error(String(asJson.error));
      }
    } catch {
    }
    throw new Error(text || 'Failed to fetch token');
  }
  return await res.json();
}

function renderMessages(messages) {
  const list = el('messageList');
  if (!list) return;

  if (!Array.isArray(messages) || messages.length === 0) {
    list.innerHTML = '<div class="small">No messages yet</div>';
    return;
  }

  const renderMedia = (media, messageId) => {
    if (!Array.isArray(media) || media.length === 0) return '';
    const items = media.map((x, idx) => {
      const url0 = String((x && x.url) || '').trim();
      let url = url0;
      if (!url) return '';
      const ct = String((x && x.content_type) || '').trim().toLowerCase();
      const isProxy = messageId ? true : /\/api\/media\/twilio\b/i.test(url);

      if (messageId) {
        url = `/api/media/twilio?message_id=${encodeURIComponent(String(messageId))}&i=${encodeURIComponent(String(idx))}`;
      }

      const u = escapeHtml(url);
      const isImg = ct.startsWith('image/') || (isProxy && !ct) || (!isProxy && /\.(png|jpg|jpeg|gif|webp)(\?|#|$)/i.test(url));
      const isVid = ct.startsWith('video/') || (!isProxy && /\.(mp4|webm|mov)(\?|#|$)/i.test(url));
      const isAud = ct.startsWith('audio/') || (!isProxy && /\.(mp3|wav|ogg|m4a)(\?|#|$)/i.test(url));
      const isPdf = ct === 'application/pdf' || /\.(pdf)(\?|#|$)/i.test(url);

      if (isImg) {
        return `<a href="${u}" target="_blank" rel="noopener noreferrer" class="mediaBox"><img src="${u}" alt="attachment" loading="lazy"></a>`;
      }
      if (isVid) {
        return `<div class="mediaBox"><video src="${u}" controls></video></div>`;
      }
      if (isAud) {
        return `<div class="mediaBox"><audio src="${u}" controls></audio></div>`;
      }
      if (isPdf) {
        return `<a href="${u}" target="_blank" rel="noopener noreferrer">Open PDF</a>`;
      }
      return `<a href="${u}" target="_blank" rel="noopener noreferrer">Open attachment</a>`;
    }).filter(Boolean);
    if (items.length === 0) return '';
    return `<div class="bubbleMedia">${items.join('')}</div>`;
  };

  list.innerHTML = messages.map((m) => {
    const isOut = String(m.direction || '') === 'outbound';
    const dir = isOut ? 'out' : 'in';
    const body = escapeHtml(m.body || '');
    const toNumber = escapeHtml(m.to_number || '');
    const metaParts = [`${fmtWhen(m.created_at)}`, (m.status || '')].filter(Boolean);
    if (!isOut && toNumber) {
      metaParts.push(`to ${toNumber}`);
    }
    const meta = escapeHtml(metaParts.join(' • '));

    const mediaHtml = renderMedia(m.media, Number(m.id || 0));
    return `<div class="bubbleRow ${dir}"><div>
      <div class="bubble ${dir}">${body}${mediaHtml}</div>
      <div class="bubbleMeta">${meta}</div>
    </div></div>`;
  }).join('');

  list.scrollTop = list.scrollHeight;
}

function renderNotes(notes) {
  const list = el('chatNotesList') || el('notesList');
  if (!list) return;

  if (!Array.isArray(notes) || notes.length === 0) {
    list.innerHTML = '<div class="small">No notes yet</div>';
    return;
  }

  list.innerHTML = notes.map((n) => {
    const who = escapeHtml(n.user_email || '');
    const when = escapeHtml(fmtWhen(n.created_at));
    const note = escapeHtml(n.note || '');
    return `<div class="noteItem"><div class="small">${who} • ${when}</div><div style="margin-top:6px">${note}</div></div>`;
  }).join('');
}

function renderFromNumbers() {
  const sel = el('fromNumberSelect');
  if (!sel) return;

  const items = Array.isArray(state.myNumbers) ? state.myNumbers : [];
  if (items.length === 0) {
    sel.innerHTML = '<option value="">No numbers</option>';
    sel.disabled = true;
    return;
  }

  sel.disabled = false;
  sel.innerHTML = items.map((n) => {
    const id = Number(n.id);
    const pn = escapeHtml(n.phone_number);
    return `<option value="${id}">${pn}${Number(n.is_default) === 1 ? ' (default)' : ''}</option>`;
  }).join('');

  const conv = state.activeConversation;
  if (conv && conv.default_number_id) {
    const match = items.find((x) => Number(x.id) === Number(conv.default_number_id));
    if (match) sel.value = String(match.id);
  }
}

function renderUsersSelect() {
  const sel = el('assignedUserSelect');
  if (!sel) return;
  const users = Array.isArray(state.users) ? state.users : [];
  sel.innerHTML = '<option value="">Unassigned</option>' + users.map((u) => {
    const id = Number(u.id);
    const label = escapeHtml(`${u.email}${u.role === 'admin' ? ' (admin)' : ''}`);
    return `<option value="${id}">${label}</option>`;
  }).join('');

  if (state.activeConversation && state.activeConversation.assigned_user_id) {
    sel.value = String(state.activeConversation.assigned_user_id);
  } else {
    sel.value = '';
  }
}

async function loadConversations(opts) {
  const list = el('conversationList');
  const silent = !!(opts && opts.silent);
  if (list && !silent) {
    list.innerHTML = '<div class="conversationItem"><div class="small">Loading...</div></div>';
  }
  const q = encodeURIComponent(state.query || '');
  const assigned = state.assignedFilter === 'me' ? '&assigned=me' : '';
  let data;
  try {
    data = await apiGet(`/api/inbox/conversations?q=${q}${assigned}`);
  } catch (e) {
    if (list && !silent) {
      const msg = escapeHtml(e && e.message ? e.message : String(e));
      list.innerHTML = `<div class="conversationItem"><div class="small">${msg}</div></div>`;
    }
    throw e;
  }
  const next = (data && data.conversations) ? data.conversations : [];
  const fp = conversationsFingerprint(next);
  if (silent && fp === state._conversationsFp) {
    return;
  }
  state._conversationsFp = fp;
  state.conversations = next;
  try {
    maybeNotifyNewSms(next);
  } catch {
  }
  const count = el('convCount');
  if (count) count.textContent = `${state.conversations.length} conversations`;
  renderConversationList();
  updateInboxUnreadIndicators();
}

async function loadUsersAndNumbers() {
  if (_inflight.usersAndNumbers) return _inflight.usersAndNumbers;
  _inflight.usersAndNumbers = (async () => {
    const [nums, users] = await Promise.all([
      apiGet('/api/inbox/my-numbers'),
      apiGet('/api/inbox/users')
    ]);
    state.myNumbers = nums.numbers || [];
    state.users = users.users || [];
  })().finally(() => { _inflight.usersAndNumbers = null; });
  return _inflight.usersAndNumbers;
}

async function selectConversation(conversationId) {
  state.activeConversationId = conversationId;
  state.activeConversation = state.conversations.find((c) => Number(c.conversation_id) === Number(conversationId)) || null;

  renderConversationList();

  const title = el('chatTitle');
  const sub = el('chatSub');
  if (title) title.textContent = state.activeConversation ? (state.activeConversation.contact_name || state.activeConversation.contact_phone || 'Conversation') : 'Conversation';
  if (sub) sub.textContent = state.activeConversation ? (state.activeConversation.contact_phone || '') : '';

  const phone = el('contactPhone');
  if (phone) phone.textContent = state.activeConversation ? (state.activeConversation.contact_phone || '') : '';

  const toNumber = el('conversationToNumber');
  if (toNumber) {
    const n = state.activeConversation ? (state.activeConversation.conversation_number || '') : '';
    toNumber.textContent = n ? `Inbox number: ${n}` : '';
  }

  const firstName = el('contactFirstName');
  if (firstName) firstName.value = state.activeConversation ? (state.activeConversation.contact_first_name || '') : '';
  const lastName = el('contactLastName');
  if (lastName) lastName.value = state.activeConversation ? (state.activeConversation.contact_last_name || '') : '';
  const email = el('contactEmail');
  if (email) email.value = state.activeConversation ? (state.activeConversation.contact_email || '') : '';

  const name = el('contactName');
  if (name) name.value = state.activeConversation ? (state.activeConversation.contact_name || '') : '';

  renderUsersSelect();
  renderFromNumbers();

  const msgList = el('messageList');
  if (msgList) msgList.innerHTML = '<div class="small">Loading...</div>';
  const notesList = el('chatNotesList') || el('notesList');
  if (notesList) notesList.innerHTML = '<div class="small">Loading...</div>';
  state._activeLastMessageId = 0;

  await refreshActiveThread(false);

  try {
    const lastId = state._activeLastMessageId || 0;
    await apiPost('/api/inbox/conversations/mark-read', { conversation_id: conversationId, message_id: lastId });
    try {
      state.conversations = (Array.isArray(state.conversations) ? state.conversations : []).map((c) => {
        if (Number(c && c.conversation_id || 0) === Number(conversationId)) {
          return { ...c, is_unread: 0 };
        }
        return c;
      });
      renderConversationList();
      updateInboxUnreadIndicators();
    } catch {
    }
    await loadConversations({ silent: true });
  } catch {
  }
}

function conversationsFingerprint(convs) {
  if (!Array.isArray(convs)) return '';
  return convs.map((c) => `${c.conversation_id}:${c.last_message_at || ''}:${c.last_message_preview || ''}`).join('|');
}

async function refreshActiveThread(silent) {
  const cid = state.activeConversationId;
  if (!cid) return;

  let msgs;
  try {
    msgs = await apiGet(`/api/inbox/messages?conversation_id=${encodeURIComponent(cid)}`);
  } catch (e) {
    if (!silent) {
      const list = el('messageList');
      if (list) list.innerHTML = `<div class="small">${escapeHtml(e && e.message ? e.message : String(e))}</div>`;
    }
    throw e;
  }
  const nextMsgs = msgs && Array.isArray(msgs.messages) ? msgs.messages : [];

  const nextLastId = nextMsgs.length ? Number(nextMsgs[nextMsgs.length - 1].id || 0) : 0;
  const prevLastId = state._activeLastMessageId || 0;

  if (!silent || nextLastId !== prevLastId) {
    state._activeLastMessageId = nextLastId;
    renderMessages(nextMsgs);
  }

  const notes = await apiGet(`/api/inbox/notes?conversation_id=${encodeURIComponent(cid)}`);
  if (!silent) {
    renderNotes(notes.notes);
  }
}

async function sendMessage() {
  if (sendMessage._sending) return;
  sendMessage._sending = true;
  const cid = state.activeConversationId;
  if (!cid) {
    toastError('Select a conversation');
    sendMessage._sending = false;
    return;
  }

  const bodyEl = el('messageBody');
  const body = String(bodyEl ? bodyEl.value : '').trim();
  if (!body && !statePendingMmsFile) {
    sendMessage._sending = false;
    return;
  }

  const fromSel = el('fromNumberSelect');
  const fromNumberId = fromSel && fromSel.value ? Number(fromSel.value) : 0;

  const btn = el('sendBtn');
  if (btn) btn.disabled = true;
  try {
    const mediaUrl = await uploadPendingMmsIfAny();
    const res = await apiPost('/api/inbox/send', { conversation_id: cid, body, from_number_id: fromNumberId, media_urls: mediaUrl ? [mediaUrl] : [] });
    if (bodyEl) bodyEl.value = '';
    if (bodyEl) renderSmsCounter(bodyEl.value, 'inboxSmsCounter');
    statePendingMmsFile = null;
    statePendingMmsUrl = '';
    const input = el('mmsFileInput');
    if (input) {
      try { input.value = ''; } catch {}
    }
    const label = el('mmsPickedLabel');
    if (label) label.style.display = 'none';
    const clearBtn = el('mmsClearBtn');
    if (clearBtn) clearBtn.style.display = 'none';
    await refreshActive();
    try {
      state.conversations = (Array.isArray(state.conversations) ? state.conversations : []).map((c) => {
        if (Number(c && c.conversation_id || 0) === Number(cid)) {
          return { ...c, is_unread: 0 };
        }
        return c;
      });
      renderConversationList();
      updateInboxUnreadIndicators();
    } catch {
    }
    const nextCid = res && res.conversation_id ? Number(res.conversation_id) : 0;
    if (nextCid && nextCid !== cid) {
      await selectConversation(nextCid);
    }
  } catch (e) {
    toastError(e && e.message ? e.message : String(e));
  } finally {
    if (btn) btn.disabled = false;
    sendMessage._sending = false;
  }
}

async function refreshActive(silent) {
  await loadConversations({ silent: !!silent });
  if (state.activeConversationId) {
    state.activeConversation = state.conversations.find((c) => Number(c.conversation_id) === Number(state.activeConversationId)) || state.activeConversation;
    renderConversationList();
    await refreshActiveThread(!!silent);
  }
}

function fmtTime(s) {
  const m = Math.floor(s / 60);
  const ss = s % 60;
  return String(m).padStart(2, '0') + ':' + String(ss).padStart(2, '0');
}

function showCallBar(statusText) {
  const bar = el('callBar');
  if (!bar) return;
  bar.style.display = 'flex';
  const st = el('callBarStatus');
  if (st) st.textContent = statusText || 'Call';

  try { if (typeof wireCallControls === 'function') wireCallControls(); } catch {}
  try { if (typeof wireCallDtmfPad === 'function') wireCallDtmfPad(); } catch {}
}

function hideCallBar() {
  const bar = el('callBar');
  if (!bar) return;
  bar.style.display = 'none';
}

function resetCallTimer() {
  callSeconds = 0;
  const t = el('callBarTimer');
  if (t) t.textContent = '00:00';
  if (callTimer) clearInterval(callTimer);
  callTimer = null;
}

function startCallTimer() {
  resetCallTimer();
  const t = el('callBarTimer');
  callTimer = setInterval(() => {
    callSeconds += 1;
    if (t) t.textContent = fmtTime(callSeconds);
  }, 1000);
}

function setMuteUi(m) {
  isMuted = !!m;
  const b = el('muteBtn');
  if (b) b.textContent = isMuted ? 'Unmute' : 'Mute';
}

function wireCallControls() {
  const root = el('callBar') || document;
  const hangup = el('hangupBtn');
  const mute = el('muteBtn');
  const rec = el('recordBtn');

  if (!hangup && !mute && !rec) {
    return;
  }

  if (root && root._callControlsWired) return;
  if (root) root._callControlsWired = true;

  if (hangup) {
    hangup.addEventListener('click', () => {
      try {
        if (activeCall && typeof activeCall.disconnect === 'function') {
          activeCall.disconnect();
          return;
        }
        if (device && typeof device.disconnectAll === 'function') {
          device.disconnectAll();
        }
      } catch {
      }
    });
  }
  if (mute) {
    mute.addEventListener('click', () => {
      if (!activeCall) return;
      try {
        const next = !isMuted;
        activeCall.mute(next);
        setMuteUi(next);
      } catch {
      }
    });
  }
  if (rec) {
    rec.addEventListener('click', async () => {
      if (!activeCall) return;
      if (isRecording) return;
      const sid = String(activeCallSid || (activeCall.parameters ? (activeCall.parameters.CallSid || '') : '')).trim();
      if (!sid) {
        toastError('Call SID not available yet');
        return;
      }
      rec.disabled = true;
      try {
        await apiPost('/api/voice/record-start', { call_sid: sid });
        isRecording = true;
        rec.textContent = 'Recording';
      } catch (e) {
        toastError(e && e.message ? e.message : String(e));
      } finally {
        rec.disabled = false;
      }
    });
  }
}

function attachCallHandlers(call, direction, displayNumber) {
  activeCall = call;
  activeCallSid = String(call && call.parameters ? (call.parameters.CallSid || '') : '');
  isRecording = false;
  const pad = el('callDtmfPad');
  if (pad) pad.style.display = 'none';
  const rec = el('recordBtn');
  if (rec) {
    rec.textContent = 'Record';
    rec.disabled = true;
  }
  setMuteUi(false);
  resetCallTimer();
  showCallBar((direction === 'in' ? 'Incoming' : 'Calling') + (displayNumber ? ' ' + displayNumber : ''));

  call.on('accept', () => {
    activeCallSid = String(call && call.parameters ? (call.parameters.CallSid || '') : activeCallSid);
    showCallBar('In call' + (displayNumber ? ' ' + displayNumber : ''));
    const pad2 = el('callDtmfPad');
    if (pad2) pad2.style.display = 'flex';
    startCallTimer();
    playConnectedTone();

    const rec2 = el('recordBtn');
    if (rec2 && !isRecording) {
      const tryEnable = () => {
        activeCallSid = String(call && call.parameters ? (call.parameters.CallSid || '') : activeCallSid);
        if (String(activeCallSid || '').trim() !== '') {
          rec2.disabled = false;
          return true;
        }
        return false;
      };
      if (!tryEnable()) {
        let tries = 0;
        const t = setInterval(() => {
          tries += 1;
          if (tryEnable() || tries >= 20) {
            clearInterval(t);
          }
        }, 200);
      }
    }
  });
  call.on('disconnect', () => {
    activeCall = null;
    activeCallSid = '';
    hideIncoming();
    hideIncomingModal();
    hideCallBar();
    const pad2 = el('callDtmfPad');
    if (pad2) pad2.style.display = 'none';
    resetCallTimer();
    setStatus('Ready');
    loadCalls().catch(() => {});
  });
  call.on('cancel', () => {
    activeCall = null;
    activeCallSid = '';
    hideIncoming();
    hideIncomingModal();
    hideCallBar();
    const pad2 = el('callDtmfPad');
    if (pad2) pad2.style.display = 'none';
    resetCallTimer();
    setStatus('Ready');
    loadCalls().catch(() => {});
  });
}

async function initVoice() {
  const ok = await ensureTwilioSdk();
  if (!ok || !window.Twilio || !Twilio.Device) {
    setStatus('Voice disabled: Twilio SDK not loaded (blocked or failed to download)');
    return;
  }

  setStatus('Fetching token...');
  let token;
  try {
    const t = await getToken();
    token = t.token;
  } catch (e) {
    setStatus('Voice token error: ' + (e && e.message ? e.message : String(e)));
    return;
  }

  if (!token) {
    setStatus('Voice token error: empty token');
    return;
  }

  device = new Twilio.Device(token, {
    closeProtection: true,
    codecPreferences: ['opus', 'pcmu'],
    logLevel: 1
  });

  device.on('error', (e) => setStatus('Error: ' + (e && e.message ? e.message : String(e))));
  device.on('registered', () => setStatus('Ready'));
  device.on('registering', () => setStatus('Registering...'));
  device.on('unregistered', () => setStatus('Unregistered'));

  device.on('incoming', (call) => {
    if (activeCall) {
      call.reject();
      return;
    }

    const from = call.parameters && (call.parameters.From || call.parameters.Caller) ? (call.parameters.From || call.parameters.Caller) : 'Unknown';
    attachCallHandlers(call, 'in', from);

    if ('Notification' in window && Notification.permission === 'granted') {
      try {
        new Notification('Incoming call', { body: `From ${from}` });
      } catch {
      }
    }
    showIncoming(from, () => call.accept(), () => call.reject());
    showIncomingModal(from, () => call.accept(), () => call.reject());
  });

  await device.register();
}

function wireCalling() {
  const callBtn = el('callBtn');
  const dialInput = el('dialInput');
  if (!callBtn || !dialInput) return;

  const fromSel = el('dialFromNumberSelect');

  callBtn.addEventListener('click', async () => {
    const to = normalizeE164(dialInput.value);
    if (!to) return;

    const fromNumberId = fromSel && fromSel.value ? Number(fromSel.value) : 0;
    if (!fromNumberId) {
      toastError('Select a From number');
      return;
    }

    if (!device) {
      await initVoice();
    }

    if (!device) {
      setStatus('Voice not ready');
      return;
    }

    try {
      setStatus('Calling...');
      const call = await device.connect({ params: { To: to, FromNumberId: String(fromNumberId) } });
      attachCallHandlers(call, 'out', to);
    } catch (e) {
      setStatus('Error: ' + (e && e.message ? e.message : String(e)));
    }
  });
}

function ensureTwilioSdk() {
  if (window.Twilio && Twilio.Device) return Promise.resolve(true);

  return new Promise((resolve) => {
    const existing = document.querySelector('script[data-twilio-voice="1"]');
    if (existing) {
      existing.addEventListener('load', () => resolve(!!(window.Twilio && Twilio.Device)));
      existing.addEventListener('error', () => resolve(false));
      return;
    }

    const sources = [
      'https://cdn.jsdelivr.net/npm/@twilio/voice-sdk@2.11.2/dist/twilio.min.js',
      'https://unpkg.com/@twilio/voice-sdk@2.11.2/dist/twilio.min.js'
    ];

    const tryLoad = (i) => {
      if (i >= sources.length) {
        setStatus('Voice disabled: Twilio SDK blocked (jsdelivr/unpkg)');
        resolve(false);
        return;
      }

      const url = sources[i];
      const s = document.createElement('script');
      s.src = url;
      s.async = true;
      s.dataset.twilioVoice = '1';
      s.onload = () => resolve(true);
      s.onerror = () => {
        try {
          s.remove();
        } catch {
        }
        setStatus('Voice SDK blocked: ' + url);
        tryLoad(i + 1);
      };
      document.head.appendChild(s);
    };

    tryLoad(0);
  });
}

function startPolling() {
  if (state.polling) clearInterval(state.polling);
  state.polling = setInterval(() => {
    refreshActive(true).catch(() => {});
  }, 8000);
}

window.addEventListener('load', async () => {
  const safeCall = (fn) => {
    try {
      if (typeof fn === 'function') fn();
    } catch (e) {
      try { console.warn(e); } catch {}
    }
  };
  const safeAwait = async (p) => {
    try {
      await p;
    } catch (e) {
      try { console.warn(e); } catch {}
    }
  };

  safeCall(themeInit);
  safeCall(rightPanelInit);
  safeCall(() => setStatus('Not initialized'));

  if (!window.location.hash) {
    window.location.hash = '#inbox';
  }

  await safeAwait(loadMe());
  safeCall(applyNavPermissions);

  if (hasPerm('users.manage')) {
    await safeAwait(refreshRbacUi());
    await safeAwait(loadNotifRules());
    safeCall(syncNotifRuleEditor);
  }

  safeCall(wireNavigation);
  safeCall(wireAnalytics);
  safeCall(wireDialpadAnalytics);
  safeCall(wireBroadcast);
  safeCall(wireBroadcastTabs);
  safeCall(wireBroadcastTemplatesManager);
  safeCall(wireSettingsTabs);
  safeCall(wireTimezoneSettings);
  safeCall(wireOptOutSettings);
  safeCall(() => { if (typeof wireCustomFieldsSettings === 'function') wireCustomFieldsSettings(); });
  safeCall(wireInboxTemplatesAndMms);
  safeCall(wireInboxControls);
  safeCall(wireRightPanelActions);
  safeCall(wireContacts);
  safeCall(wireUserManager);
  safeCall(() => { if (typeof wireUsers === 'function') wireUsers(); });
  safeCall(wireRbacUi);
  safeCall(wireNotifRulesUi);
  safeCall(wireNumbersAdmin);
  safeCall(wireTwilioAccountsSettings);
  safeCall(wireSmtpSettings);
  safeCall(wireVoiceRoutingSettings);
  safeCall(wireVoicemails);
  safeCall(wireCalls);
  safeCall(wireDefaultTwilioSettings);
  safeCall(wireWebhooksInfo);
  safeCall(() => { if (typeof wireCallDtmfPad === 'function') wireCallDtmfPad(); });
  safeCall(() => { if (typeof wireDialPad === 'function') wireDialPad(); });
  safeCall(wireNewMessage);
  safeCall(wireInboxSmsCounter);
  safeCall(() => { if (typeof wireCalling === 'function') wireCalling(); });
  safeCall(() => { if (typeof wireCallControls === 'function') wireCallControls(); });

  safeAwait(loadTimezoneSettings().catch(() => {}));

  loadTemplates().then(() => {
    renderInboxTemplates();
    renderBroadcastTemplates();
  }).catch(() => {});

  renderTimezoneNow();
  setInterval(() => renderTimezoneNow(), 1000);

  safeCall(() => {
    loadUsersAndNumbers().then(() => {
      renderDialFromNumbers();
    }).catch(() => {});
    loadConversations().catch(() => {});
  });
  try { startPolling(); } catch {}
});
