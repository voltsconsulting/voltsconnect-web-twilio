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
  calls: [],
  adminNumbers: [],
  adminNumberMappings: [],
  twilioAccounts: [],
  polling: null
};

function el(id) {
  return document.getElementById(id);
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
  if (!btn || !panel || !cancel || !start || !toEl || !fromEl) return;

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
      alert('Enter a valid To number');
      return;
    }
    if (!fromId) {
      alert('Select a From number');
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
      alert(e && e.message ? e.message : String(e));
    } finally {
      start.disabled = false;
    }
  });
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
      } catch (e) {
        alert(e && e.message ? e.message : String(e));
      }
    });
  });
}

async function loadTwilioAccounts() {
  const list = el('twilioAccountsList');
  if (list) list.innerHTML = '<div class="item"><div class="small">Loading...</div></div>';
  try {
    const data = await apiGet('/api/admin/twilio-accounts');
    state.twilioAccounts = data.accounts || [];
  } catch (e) {
    state.twilioAccounts = [];
    if (list) list.innerHTML = `<div class="item"><div class="small">${escapeHtml(e && e.message ? e.message : String(e))}</div></div>`;
    return;
  }
  renderTwilioAccounts();
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
      } catch (e) {
        alert(e && e.message ? e.message : String(e));
      }
    });
  }
}

function renderVoicemails() {
  const list = el('voicemailsList');
  if (!list) return;
  const items = Array.isArray(state.voicemails) ? state.voicemails : [];
  if (items.length === 0) {
    list.innerHTML = '<div class="item"><div class="small">No voicemails yet</div></div>';
    return;
  }
  list.innerHTML = items.map((v) => {
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
    const proxied = sid ? `/api/voice/recording?sid=${encodeURIComponent(sid)}` : '';
    const link = proxied ? `<a class="btn" href="${escapeHtml(proxied)}" target="_blank">Open</a>` : '';
    return `<div class="item">
      <div class="row" style="align-items:center;justify-content:space-between">
        <div><strong>Voicemail</strong></div>
        <div class="small">${when}</div>
      </div>
      <div class="small" style="margin-top:6px">${from} → ${to}</div>
      <div class="row" style="margin-top:10px;align-items:center;justify-content:space-between">
        <div class="small">${escapeHtml(dur)}</div>
        <div class="row">${link}</div>
      </div>
    </div>`;
  }).join('');
}

async function loadVoicemails() {
  const list = el('voicemailsList');
  if (list) list.innerHTML = '<div class="item"><div class="small">Loading...</div></div>';
  try {
    const data = await apiGet('/api/admin/voicemails?limit=50');
    state.voicemails = data.voicemails || [];
  } catch (e) {
    state.voicemails = [];
    if (list) list.innerHTML = `<div class="item"><div class="small">${escapeHtml(e && e.message ? e.message : String(e))}</div></div>`;
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
        await saveVoiceRouting();
        await loadVoiceRouting();
        alert('Saved');
      } catch (e) {
        alert(e && e.message ? e.message : String(e));
      }
    });
  }
}

function wireVoicemails() {
  const refresh = el('refreshVoicemails');
  if (refresh) refresh.addEventListener('click', () => loadVoicemails().catch(() => {}));
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
  const d = new Date(String(s || ''));
  if (Number.isNaN(d.getTime())) return String(s || '');
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
    const text = await res.text();
    try {
      const asJson = JSON.parse(text);
      if (asJson && asJson.error) {
        throw new Error(String(asJson.error));
      }
    } catch {
    }
    throw new Error(text || 'Request failed');
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
    const text = await res.text();
    try {
      const asJson = JSON.parse(text);
      if (asJson && asJson.error) {
        throw new Error(String(asJson.error));
      }
    } catch {
    }
    throw new Error(text || 'Request failed');
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
    const badge = assigned ? `<span class="badge">${assigned}</span>` : '';
    const active = state.activeConversationId === id ? ' active' : '';
    return `<div class="conversationItem${active}" data-id="${id}">
      <div class="conversationTop">
        <div class="conversationName">${name}</div>
        ${badge}
      </div>
      <div class="small" style="margin-top:4px">${phone}</div>
      ${inboxNumber ? `<div class="small" style="margin-top:4px">to ${inboxNumber}</div>` : ''}
      <div class="small" style="margin-top:6px">${preview}</div>
      <div class="small" style="margin-top:6px">${when}</div>
    </div>`;
  }).join('');

  list.querySelectorAll('.conversationItem').forEach((node) => {
    node.addEventListener('click', async () => {
      const id = Number(node.dataset.id || 0);
      if (!id) return;
      await selectConversation(id);
    });
  });
}

function wireDialPad() {
  const dialInput = el('dialInput');
  if (!dialInput) return;

  document.querySelectorAll('.key').forEach((b) => {
    b.addEventListener('click', () => {
      dialInput.value += b.dataset.k;
    });
  });

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

function setActiveNav(view) {
  const views = {
    inbox: el('viewInbox'),
    dialpad: el('viewDialpad'),
    calls: el('viewCalls'),
    contacts: el('viewContacts'),
    numbers: el('viewNumbers'),
    settings: el('viewSettings')
    ,users: el('viewUsers')
  };
  Object.entries(views).forEach(([k, node]) => {
    if (!node) return;
    node.style.display = k === view ? '' : 'none';
  });

  const items = {
    inbox: el('navInbox'),
    dialpad: el('navDialpad'),
    calls: el('navCalls'),
    contacts: el('navContacts'),
    numbers: el('navNumbers'),
    settings: el('navSettings')
    ,users: el('navUsers')
  };
  Object.entries(items).forEach(([k, node]) => {
    if (!node) return;
    if (k === view) node.classList.add('active');
    else node.classList.remove('active');
  });

  if (view === 'calls') {
    loadCalls().catch(() => {});
  }
  if (view === 'numbers') {
    loadNumbersAdmin().catch(() => {});
  }
  if (view === 'contacts') {
    loadContacts().catch(() => {});
  }
  if (view === 'users') {
    loadAdminUsers().catch(() => {});
  }
  if (view === 'settings') {
    loadTwilioAccounts().catch(() => {});
    loadDefaultTwilioSettings().catch(() => {});
    loadVoiceRouting().catch(() => {});
    loadVoicemails().catch(() => {});
  }
}

function wireNavigation() {
  const applyFromHash = () => {
    const h = String(window.location.hash || '').replace('#', '');
    if (h === 'dialpad') return setActiveNav('dialpad');
    if (h === 'calls') return setActiveNav('calls');
    if (h === 'contacts') return setActiveNav('contacts');
    if (h === 'numbers') return setActiveNav('numbers');
    if (h === 'settings') return setActiveNav('settings');
    if (h === 'users') return setActiveNav('users');
    return setActiveNav('inbox');
  };

  window.addEventListener('hashchange', applyFromHash);
  applyFromHash();
}

function renderContacts() {
  const list = el('contactsList');
  if (!list) return;
  const items = Array.isArray(state.contacts) ? state.contacts : [];
  if (items.length === 0) {
    list.innerHTML = '<div class="item"><div class="small">No contacts</div></div>';
    return;
  }
  list.innerHTML = items.map((c) => {
    const id = Number(c.id);
    const name = escapeHtml(c.name || '');
    const phone = escapeHtml(c.phone_number || '');
    return `<div class="item" data-id="${id}">
      <div class="small">${phone}</div>
      <div style="margin-top:8px" class="row">
        <input class="input" data-name="1" value="${name}" placeholder="Name" style="flex:1">
        <button class="btn" data-save="1" type="button">Save</button>
      </div>
    </div>`;
  }).join('');

  list.querySelectorAll('[data-save="1"]').forEach((btn) => {
    btn.addEventListener('click', async () => {
      const item = btn.closest('.item');
      if (!item) return;
      const id = Number(item.dataset.id || 0);
      const input = item.querySelector('[data-name="1"]');
      const name = String(input ? input.value : '').trim();
      try {
        await apiPost('/api/contacts/update', { id, name });
      } catch (e) {
        alert(e && e.message ? e.message : String(e));
      }
    });
  });
}

async function loadContacts() {
  const qEl = el('contactsSearch');
  const q = qEl ? String(qEl.value || '').trim() : '';
  const data = await apiGet(`/api/contacts?q=${encodeURIComponent(q)}`);
  state.contacts = data.contacts || [];
  renderContacts();
}

function wireContacts() {
  const qEl = el('contactsSearch');
  if (!qEl) return;
  let t = null;
  qEl.addEventListener('input', () => {
    if (t) clearTimeout(t);
    t = setTimeout(() => loadContacts().catch(() => {}), 250);
  });
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
    return;
  }

  list.innerHTML = items.map((c) => {
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
    return `<div class="item">
      <div class="row" style="align-items:center;justify-content:space-between">
        <div><strong>${dir}</strong></div>
        <div class="small">${when}</div>
      </div>
      <div class="small" style="margin-top:6px">${from} → ${to}</div>
      <div class="small" style="margin-top:6px">${escapeHtml(extra)}</div>
      ${recLink ? `<div class="row" style="margin-top:10px">${recLink}</div>` : ''}
    </div>`;
  }).join('');
}

async function loadCalls() {
  const list = el('callsList');
  if (list) list.innerHTML = '<div class="item"><div class="small">Loading...</div></div>';
  try {
    const data = await apiGet('/api/calls?limit=50');
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
  if (!btn) return;
  btn.addEventListener('click', () => loadCalls().catch((e) => alert(e && e.message ? e.message : String(e))));
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
        <input class="input" data-fn="1" value="${fn}" placeholder="Friendly name" style="flex:1">
      </div>
      <div style="margin-top:10px" class="row">
        <select class="input" data-twilioacct="1" style="flex:1">${accountOptions}</select>
      </div>
      <div class="small" style="margin-top:10px">Inbound call routing (this number)</div>
      <div style="margin-top:8px" class="row">
        <input class="input" data-vfwd="1" value="${vfwd}" placeholder="Forward to number (optional)" style="flex:1">
      </div>
      <div style="margin-top:8px" class="row">
        <input class="input" data-vrt="1" value="${escapeHtml(vrt)}" placeholder="Ring timeout seconds (optional)" style="flex:1">
      </div>
      <div class="small" style="margin-top:10px">Assigned users</div>
      <div style="margin-top:8px" class="row">
        <select class="input" data-usersmulti="1" multiple size="4" style="flex:1;min-width:260px">${userOptions}</select>
        <div style="flex:1;min-width:220px">
          <div class="small">Default user (for this number)</div>
          <select class="input" data-defaultuid="1" style="margin-top:8px">${defaultUserOptions}</select>
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
        alert('Saved');
        await loadNumbersAdmin();
      } catch (e) {
        alert(e && e.message ? e.message : String(e));
      }
    });
  });
}

async function loadNumbersAdmin() {
  try {
    await loadTwilioAccounts().catch(() => {});
    const data = await apiGet('/api/admin/numbers');
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
        alert('Saved');
        await loadDefaultTwilioSettings();
      } catch (e) {
        alert(e && e.message ? e.message : String(e));
      }
    });
  }
}

function wireWebhooksInfo() {
  const b = el('showWebhooksInfo');
  if (!b) return;
  b.addEventListener('click', () => {
    alert('Use these URLs in Twilio. SMS webhook goes in Messaging settings. Voice webhook goes in your TwiML App Voice URL.');
  });
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
      } catch (e) {
        alert(e && e.message ? e.message : String(e));
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
          <select class="input" data-role="1" style="max-width:140px">
            <option value="agent" ${role === 'agent' ? 'selected' : ''}>agent</option>
            <option value="admin" ${role === 'admin' ? 'selected' : ''}>admin</option>
          </select>
          <button class="btn" data-setrole="1" type="button">Save</button>
        </div>
      </div>
      <div style="height:10px"></div>
      <div class="row">
        <input class="input" data-newpass="1" placeholder="New password (min 8)" type="password" style="flex:1">
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
      } catch (e) {
        alert(e && e.message ? e.message : String(e));
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
        alert('Password reset');
      } catch (e) {
        alert(e && e.message ? e.message : String(e));
      }
    });
  });
}

async function loadAdminUsers() {
  try {
    const data = await apiGet('/api/admin/users');
    state.adminUsers = data.users || [];
  } catch {
    state.adminUsers = [];
  }
  renderAdminUsers();
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
    } catch (e) {
      alert(e && e.message ? e.message : String(e));
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

function wireInboxControls() {
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
  const save = el('saveContact');
  if (save) {
    save.addEventListener('click', async () => {
      const cid = state.activeConversationId;
      if (!cid) return;
      const name = String(el('contactName') ? el('contactName').value : '').trim();
      try {
        await apiPost('/api/inbox/contact', { conversation_id: cid, name });
        await refreshActive();
      } catch (e) {
        alert(e && e.message ? e.message : String(e));
      }
    });
  }

  const add = el('addNote');
  if (add) {
    add.addEventListener('click', async () => {
      const cid = state.activeConversationId;
      if (!cid) return;
      const noteEl = el('noteBody');
      const note = String(noteEl ? noteEl.value : '').trim();
      if (!note) return;
      try {
        await apiPost('/api/inbox/notes', { conversation_id: cid, note });
        if (noteEl) noteEl.value = '';
        const notes = await apiGet(`/api/inbox/notes?conversation_id=${encodeURIComponent(cid)}`);
        renderNotes(notes.notes);
      } catch (e) {
        alert(e && e.message ? e.message : String(e));
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
      } catch (e) {
        alert(e && e.message ? e.message : String(e));
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
      } catch (e) {
        alert(e && e.message ? e.message : String(e));
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
      } catch (e) {
        alert(e && e.message ? e.message : String(e));
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
    return `<div class="bubbleRow ${dir}"><div>
      <div class="bubble ${dir}">${body}</div>
      <div class="bubbleMeta">${meta}</div>
    </div></div>`;
  }).join('');

  list.scrollTop = list.scrollHeight;
}

function renderNotes(notes) {
  const list = el('notesList');
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
  const data = await apiGet(`/api/inbox/conversations?q=${q}${assigned}`);
  const next = data.conversations || [];
  const fp = conversationsFingerprint(next);
  if (silent && fp === state._conversationsFp) {
    return;
  }
  state._conversationsFp = fp;
  state.conversations = next;
  const count = el('convCount');
  if (count) count.textContent = `${state.conversations.length} conversations`;
  renderConversationList();
}

async function loadUsersAndNumbers() {
  const [nums, users] = await Promise.all([
    apiGet('/api/inbox/my-numbers'),
    apiGet('/api/inbox/users')
  ]);
  state.myNumbers = nums.numbers || [];
  state.users = users.users || [];
  renderUsersSelect();
  renderFromNumbers();
  renderDialFromNumbers();
  renderNewMessageFromNumbers();
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
  const name = el('contactName');
  if (name) name.value = state.activeConversation ? (state.activeConversation.contact_name || '') : '';

  renderUsersSelect();
  renderFromNumbers();

  await refreshActiveThread(true);
}

function conversationsFingerprint(convs) {
  if (!Array.isArray(convs)) return '';
  return convs.map((c) => `${c.conversation_id}:${c.last_message_at || ''}:${c.last_message_preview || ''}`).join('|');
}

async function refreshActiveThread(silent) {
  const cid = state.activeConversationId;
  if (!cid) return;

  const msgs = await apiGet(`/api/inbox/messages?conversation_id=${encodeURIComponent(cid)}`);
  const nextMsgs = Array.isArray(msgs.messages) ? msgs.messages : [];

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
  const cid = state.activeConversationId;
  if (!cid) {
    alert('Select a conversation');
    return;
  }

  const bodyEl = el('messageBody');
  const body = String(bodyEl ? bodyEl.value : '').trim();
  if (!body) return;

  const fromSel = el('fromNumberSelect');
  const fromNumberId = fromSel && fromSel.value ? Number(fromSel.value) : 0;

  const btn = el('sendBtn');
  if (btn) btn.disabled = true;
  try {
    const res = await apiPost('/api/inbox/send', { conversation_id: cid, body, from_number_id: fromNumberId });
    if (bodyEl) bodyEl.value = '';
    await refreshActive();
    const nextCid = res && res.conversation_id ? Number(res.conversation_id) : 0;
    if (nextCid && nextCid !== cid) {
      await selectConversation(nextCid);
    }
  } catch (e) {
    alert(e && e.message ? e.message : String(e));
  } finally {
    if (btn) btn.disabled = false;
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
  const hangup = el('hangupBtn');
  if (hangup) {
    hangup.addEventListener('click', () => {
      try {
        if (activeCall) activeCall.disconnect();
      } catch {
      }
    });
  }

  const mute = el('muteBtn');
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

  const rec = el('recordBtn');
  if (rec) {
    rec.addEventListener('click', async () => {
      if (!activeCall) return;
      if (isRecording) return;
      const sid = String(activeCallSid || (activeCall.parameters ? (activeCall.parameters.CallSid || '') : '')).trim();
      if (!sid) {
        alert('Call SID not available yet');
        return;
      }
      rec.disabled = true;
      try {
        await apiPost('/api/voice/record-start', { call_sid: sid });
        isRecording = true;
        rec.textContent = 'Recording';
      } catch (e) {
        alert(e && e.message ? e.message : String(e));
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
      alert('Select a From number');
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
  themeInit();
  rightPanelInit();
  wireNavigation();
  wireInboxControls();
  wireRightPanelActions();
  wireContacts();
  wireUserManager();
  wireCalls();
  wireNumbersAdmin();
  wireTwilioAccountsSettings();
  wireVoiceRoutingSettings();
  wireVoicemails();
  wireDefaultTwilioSettings();
  wireWebhooksInfo();
  wireDialPad();
  wireNewMessage();
  wireCalling();
  wireCallControls();

  if (!window.location.hash) {
    window.location.hash = '#inbox';
  }

  try { await Promise.all([loadUsersAndNumbers(), loadConversations()]); } catch (e) { console.warn(e); }
  try { startPolling(); } catch {}

  setTimeout(() => { ensureNotificationPermission().catch(() => {}); }, 50);
  setTimeout(() => { ensureMicPermission().catch(() => {}); }, 150);
  setTimeout(() => { initVoice().catch(() => {}); }, 400);
});
