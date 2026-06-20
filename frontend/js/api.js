// frontend/js/api.js
// Central API client. All fetch calls go through here.

const API_BASE = 'https://your-backend.example.com/api'; // ← update before deploy

async function apiFetch(path, options = {}) {
  const url = `${API_BASE}${path}`;
  const res = await fetch(url, {
    credentials: 'include',           // send session cookie
    headers: { 'Content-Type': 'application/json', ...options.headers },
    ...options,
  });

  const data = await res.json().catch(() => ({ ok: false, error: 'Invalid server response' }));

  if (!res.ok || !data.ok) {
    const err = new Error(data.error || `HTTP ${res.status}`);
    err.status  = res.status;
    err.errors  = data.errors  || [];
    err.warnings = data.warnings || [];
    throw err;
  }

  return data.data;
}

// ── Auth ──────────────────────────────────────────────────────────────────────
export const Auth = {
  login:  (netid, password) =>
    apiFetch('/login.php', { method: 'POST', body: JSON.stringify({ netid, password }) }),
  logout: () => apiFetch('/logout.php', { method: 'POST' }),
};

// ── Student ───────────────────────────────────────────────────────────────────
export const Student = {
  getDashboard: () => apiFetch('/dashboard.php'),
  saveDraft:    (payload) =>
    apiFetch('/submit.php', { method: 'POST', body: JSON.stringify({ ...payload, is_final: false }) }),
  finalSubmit:  (payload) =>
    apiFetch('/submit.php', { method: 'POST', body: JSON.stringify({ ...payload, is_final: true }) }),
};

// ── Admin: imports ────────────────────────────────────────────────────────────
export const AdminImport = {
  upload: (type, file) => {
    const fd = new FormData();
    fd.append('file', file);
    return apiFetch(`/admin/import.php?type=${type}`, {
      method: 'POST',
      body: fd,
      headers: {},  // let browser set multipart boundary
    });
  },
};

// ── Admin: reports ────────────────────────────────────────────────────────────
export const AdminReports = {
  student:     (id)         => apiFetch(`/admin/reports.php?type=student&id=${id}`),
  advisor:     (id)         => apiFetch(`/admin/reports.php?type=advisor&id=${id}`),
  team:        (id)         => apiFetch(`/admin/reports.php?type=team&id=${id}`),
  listStudents: ()          => apiFetch('/admin/reports.php?type=list_students'),
  listAdvisors: ()          => apiFetch('/admin/reports.php?type=list_advisors'),
  listTeams:    ()          => apiFetch('/admin/reports.php?type=list_teams'),
  exportCsv:   (type, id)  =>
    window.open(`${API_BASE}/admin/reports.php?type=${type}&id=${id}&export=csv`, '_blank'),
};

// ── Admin: manage ─────────────────────────────────────────────────────────────
export const AdminManage = {
  list:   (entity)        => apiFetch(`/admin/manage.php?entity=${entity}`),
  get:    (entity, id)    => apiFetch(`/admin/manage.php?entity=${entity}&id=${id}`),
  create: (entity, body)  =>
    apiFetch(`/admin/manage.php?entity=${entity}`, { method: 'POST', body: JSON.stringify(body) }),
  update: (entity, id, body) =>
    apiFetch(`/admin/manage.php?entity=${entity}&id=${id}`, { method: 'PUT', body: JSON.stringify(body) }),
  delete: (entity, id) =>
    apiFetch(`/admin/manage.php?entity=${entity}&id=${id}`, { method: 'DELETE' }),
  reopenSubmission: (submission_id) =>
    apiFetch('/admin/manage.php?entity=reopen_submission', { method: 'POST', body: JSON.stringify({ submission_id }) }),
};
