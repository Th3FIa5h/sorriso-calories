// ═══════════════════════════════════════════════════════════════
//  Sorriso Calories — API Client + UI Shared v2.0
//  Com autenticação por token JWT
// ═══════════════════════════════════════════════════════════════

// Detecta automaticamente se está em produção ou local
const IS_PROD = window.location.hostname.includes('infinityfreeapp.com');
const BASE = '/sorriso-calories/api';

const IS_SUBPAGE = window.location.pathname.includes('/pages/');
const ROOT  = IS_SUBPAGE ? '../'  : './';
const PAGES = IS_SUBPAGE ? './'   : './pages/';

// ── Autenticação ───────────────────────────────────────────────
const Auth = {
  getToken() { return localStorage.getItem('sc_token'); },
  getUid()   { return parseInt(localStorage.getItem('sc_uid') || '0'); },

  save(token, uid) {
    localStorage.setItem('sc_token', String(token));
    localStorage.setItem('sc_uid',   String(uid));
  },

  clear() {
    localStorage.removeItem('sc_token');
    localStorage.removeItem('sc_uid');
    sessionStorage.removeItem('sc_user');
  },

  requireAuth() {
    if (!this.getToken()) {
      location.href = ROOT + 'login.html';
      return false;
    }
    return true;
  },
};

// Compatibilidade com código legado
const UID = {
  get()  { return Auth.getUid(); },
  set(v) { localStorage.setItem('sc_uid', String(v)); },
};

// ── HTTP helpers ───────────────────────────────────────────────
async function GET(path, params = {}) {
  const qs    = new URLSearchParams(params).toString();
  const token = Auth.getToken();
  const res   = await fetch(`${BASE}/${path}${qs ? '?' + qs : ''}`, {
    headers: { 'Authorization': `Bearer ${token}` },
  });
  const d = await res.json();
  if (res.status === 401) { Auth.clear(); location.href = ROOT + 'login.html'; return; }
  if (!res.ok) throw new Error(d.error || 'Erro na requisição');
  return d;
}

async function POST(path, body = {}) {
  const token = Auth.getToken();
  const res   = await fetch(`${BASE}/${path}`, {
    method:  'POST',
    headers: {
      'Content-Type':  'application/json',
      'Authorization': `Bearer ${token}`,
    },
    body: JSON.stringify(body),
  });
  const d = await res.json();
  if (res.status === 401) { Auth.clear(); location.href = ROOT + 'login.html'; return; }
  if (!res.ok) throw new Error(d.error || 'Erro ao criar');
  return d;
}

async function PUT(path, body = {}) {
  const token = Auth.getToken();
  // Adiciona _method=PUT na URL e envia como POST
  const url = `${BASE}/${path}${path.includes('?') ? '&' : '?'}_method=PUT`;
  const res  = await fetch(url, {
    method:  'POST',
    headers: {
      'Content-Type':  'application/json',
      'Authorization': `Bearer ${token}`,
    },
    body: JSON.stringify(body),
  });
  const d = await res.json();
  if (res.status === 401) { Auth.clear(); location.href = ROOT + 'login.html'; return; }
  if (!res.ok) throw new Error(d.error || 'Erro ao atualizar');
  return d;
}

async function DEL(path) {
  const token = Auth.getToken();

  // Se for deletar usuário usa rota especial sem ID na URL
  // porque InfinityFree bloqueia métodos em URLs com números
  if (path.startsWith('usuarios/')) {
    const res = await fetch(`${BASE}/deletar-conta`, {
      method:  'POST',
      headers: {
        'Content-Type':  'application/json',
        'Authorization': `Bearer ${token}`,
      },
      body: JSON.stringify({}),
    });
    const d = await res.json();
    if (res.status === 401) { Auth.clear(); location.href = ROOT + 'login.html'; return; }
    if (!res.ok) throw new Error(d.error || 'Erro ao remover');
    return d;
  }

  // Para outros recursos mantém o comportamento normal
  const res = await fetch(`${BASE}/${path}?_method=DELETE`, {
    method:  'POST',
    headers: {
      'Content-Type':  'application/json',
      'Authorization': `Bearer ${token}`,
    },
    body: JSON.stringify({}),
  });
  const d = await res.json();
  if (res.status === 401) { Auth.clear(); location.href = ROOT + 'login.html'; return; }
  if (!res.ok) throw new Error(d.error || 'Erro ao remover');
  return d;
}

// ── Toast ──────────────────────────────────────────────────────
function toast(msg, type = 'success') {
  const el = document.createElement('div');
  el.className = `toast t${type[0]}`;
  el.textContent = msg;
  document.body.appendChild(el);
  requestAnimationFrame(() => el.classList.add('in'));
  setTimeout(() => {
    el.classList.remove('in');
    setTimeout(() => el.remove(), 400);
  }, 3500);
}

// ── Helpers ────────────────────────────────────────────────────
function initials(n)  { return (n||'?').trim().split(' ').slice(0,2).map(w=>w[0].toUpperCase()).join(''); }
function goalLabel(g) { return {loss:'Perda de peso',gain:'Ganho de massa',maintain:'Manutenção'}[g]||'—'; }

// ── Navegação ──────────────────────────────────────────────────
function getNav() {
  return [
    { id:'dashboard',          href:`${ROOT}index.html`,               label:'Dashboard',           ico:'<rect x="3" y="3" width="7" height="7" rx="1"/><rect x="14" y="3" width="7" height="7" rx="1"/><rect x="3" y="14" width="7" height="7" rx="1"/><rect x="14" y="14" width="7" height="7" rx="1"/>' },
    { id:'refeicoes',          href:`${PAGES}refeicoes.html`,          label:'Minhas Refeições',    ico:'<path d="M3 2v7c0 1.1.9 2 2 2h4a2 2 0 0 0 2-2V2"/><path d="M7 2v20M21 15V2a5 5 0 0 0-5 5v6c0 1.1.9 2 2 2h3Zm0 0v7"/>' },
    { id:'adicionar-refeicao', href:`${PAGES}adicionar-refeicao.html`, label:'Adicionar Refeição',  ico:'<circle cx="12" cy="12" r="10"/><line x1="12" y1="8" x2="12" y2="16"/><line x1="8" y1="12" x2="16" y2="12"/>' },
    { id:'calculo',            href:`${PAGES}calculo.html`,            label:'Cálculo de Calorias', ico:'<path d="M4 7h16M4 12h10M4 17h7"/><circle cx="17" cy="17" r="3"/><path d="m19.5 19.5 1.5 1.5"/>' },
    { id:'alimentos',          href:`${PAGES}alimentos.html`,          label:'Alimentos',           ico:'<circle cx="11" cy="11" r="8"/><path d="m21 21-4.35-4.35"/>' },
    { id:'cadastro-alimento',  href:`${PAGES}cadastro-alimento.html`,  label:'Cadastrar Alimento',  ico:'<path d="M11 4H4a2 2 0 0 0-2 2v14a2 2 0 0 0 2 2h14a2 2 0 0 0 2-2v-7"/><path d="M18.5 2.5a2.121 2.121 0 0 1 3 3L12 15l-4 1 1-4Z"/>' },
    { id:'perfil',             href:`${PAGES}perfil.html`,             label:'Perfil & Objetivos',  ico:'<path d="M20 21v-2a4 4 0 0 0-4-4H8a4 4 0 0 0-4 4v2"/><circle cx="12" cy="7" r="4"/>' },
  ];
}

// ── Sidebar ────────────────────────────────────────────────────
function buildSidebar(activeId) {
  const u    = JSON.parse(sessionStorage.getItem('sc_user') || 'null');
  const av   = u ? initials(u.nome) : '?';
  const name = u ? u.nome.split(' ')[0] : '...';
  const goal = u ? goalLabel(u.objetivo) : 'Carregando...';

  const items = getNav().map(p => `
    <a href="${p.href}" class="sb-item${p.id === activeId ? ' on' : ''}">
      <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">${p.ico}</svg>
      ${p.label}
    </a>`).join('');

  document.getElementById('sidebar-root').innerHTML = `
    <aside class="sidebar">
      <div class="sb-logo">
        <h1>Sorriso<br>Calories</h1>
        <p>Nutrição inteligente</p>
      </div>
      <nav class="sb-nav">${items}</nav>
      <div class="sb-foot">
        <a href="${PAGES}perfil.html" class="sb-user">
          <div class="u-av" id="sb-av">${av}</div>
          <div>
            <p id="sb-nm">${name}</p>
            <span id="sb-gl">${goal}</span>
          </div>
        </a>

        <!-- Toggle de tema claro/escuro -->
        <button class="theme-toggle" onclick="toggleTheme()" id="theme-btn">
          <span id="theme-label">🌙 Tema escuro</span>
          <div class="toggle-track">
            <div class="toggle-thumb"></div>
          </div>
        </button>

        <!-- Botão de logout -->
        <button onclick="logout()"
          style="margin-top:8px;width:100%;padding:10px;
                border:1.5px solid var(--gray200);border-radius:var(--rs);
                background:transparent;color:var(--gray600);
                font-family:var(--fb);font-size:13px;cursor:pointer;transition:all .18s"
          onmouseover="this.style.borderColor='#c0392b';this.style.color='#c0392b'"
          onmouseout="this.style.borderColor='var(--gray200)';this.style.color='var(--gray600)'">
          🚪 Sair da conta
        </button>
      </div>
    </aside>`;
}

async function refreshSidebar() {
  try {
    const u = await GET(`usuarios/${UID.get()}`);
    if (!u) return;
    sessionStorage.setItem('sc_user', JSON.stringify(u));
    const av = document.getElementById('sb-av');
    const nm = document.getElementById('sb-nm');
    const gl = document.getElementById('sb-gl');
    if (av) av.textContent = initials(u.nome);
    if (nm) nm.textContent = u.nome.split(' ')[0];
    if (gl) gl.textContent = goalLabel(u.objetivo);
  } catch(e) {
    console.warn('refreshSidebar falhou:', e.message);
  }
}

// ── Logout ─────────────────────────────────────────────────────
async function logout() {
  try {
    await fetch(`${BASE}/auth/logout`, {
      method:  'POST',
      headers: { 'Authorization': `Bearer ${Auth.getToken()}` },
    });
  } catch(e) {}
  Auth.clear();
  location.href = ROOT + 'login.html';
}

// ── Tema claro / escuro ────────────────────────────────────────

// Aplica o tema salvo quando a página carrega
function initTheme() {
  const tema = localStorage.getItem('sc_tema') || 'light';
  applyTheme(tema);
}

// Alterna entre claro e escuro
function toggleTheme() {
  const atual = localStorage.getItem('sc_tema') || 'light';
  applyTheme(atual === 'light' ? 'dark' : 'light');
}

// Aplica o tema e atualiza o botão
function applyTheme(tema) {
  // Salva a preferência
  localStorage.setItem('sc_tema', tema);

  // Aplica ou remove o atributo no <html>
  if (tema === 'dark') {
    document.documentElement.setAttribute('data-theme', 'dark');
  } else {
    document.documentElement.removeAttribute('data-theme');
  }

  // Atualiza o texto do botão na sidebar
  const label = document.getElementById('theme-label');
  if (label) {
    label.textContent = tema === 'dark' ? '☀️ Tema claro' : '🌙 Tema escuro';
  }
}

// ── Init ───────────────────────────────────────────────────────
async function scInit(activeId) {
  initTheme(); // aplica o tema salvo antes de qualquer coisa
  if (!Auth.requireAuth()) return;
  buildSidebar(activeId)
  if (!Auth.requireAuth()) return;

  buildSidebar(activeId);

  try {
    const u = await GET(`usuarios/${UID.get()}`);
    if (!u) return;
    sessionStorage.setItem('sc_user', JSON.stringify(u));

    // Atualiza a sidebar com os dados reais do usuário
    const av = document.getElementById('sb-av');
    const nm = document.getElementById('sb-nm');
    const gl = document.getElementById('sb-gl');
    if (av) av.textContent = initials(u.nome);
    if (nm) nm.textContent = u.nome.split(' ')[0];
    if (gl) gl.textContent = goalLabel(u.objetivo);

  } catch(e) {
    console.error('scInit falhou:', e.message);
    Auth.clear();
    location.href = ROOT + 'login.html';
  }
}