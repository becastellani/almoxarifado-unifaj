/* ALMOX.SYS — app.js */

// ---------- TEMA ----------
(function () {
  const html    = document.documentElement;
  const btn     = document.getElementById('themeToggle');
  const iconEl  = btn ? btn.querySelector('.theme-icon') : null;
  const STORAGE = 'almox-theme';

  const icons = { dark: '🌙', light: '☀️' };

  function applyTheme(theme) {
    html.setAttribute('data-theme', theme);
    if (iconEl) iconEl.textContent = icons[theme];
    localStorage.setItem(STORAGE, theme);
  }

  // Aplica tema salvo (ou padrão dark)
  applyTheme(localStorage.getItem(STORAGE) || 'dark');

  if (btn) {
    btn.addEventListener('click', () => {
      const next = html.getAttribute('data-theme') === 'dark' ? 'light' : 'dark';
      applyTheme(next);
    });
  }
})();

// ---------- TOAST ----------
function showToast(msg, tipo = 'success') {
  let toast = document.getElementById('_toast');
  if (!toast) {
    toast = document.createElement('div');
    toast.id = '_toast';
    toast.className = 'toast';
    document.body.appendChild(toast);
  }
  toast.textContent = msg;
  toast.className = `toast toast-${tipo} show`;
  clearTimeout(toast._timer);
  toast._timer = setTimeout(() => toast.classList.remove('show'), 3200);
}

// ---------- CONFIRM DELETE ----------
function confirmarExclusao(msg) {
  return confirm(msg || 'Tem certeza que deseja excluir este item?');
}

// ---------- TABELA DE ITENS (Solicitar) ----------
let _itemCounter = 0;

function adicionarItem() {
  _itemCounter++;
  const tbody    = document.getElementById('corpoTabela');
  const linhaVazia = document.getElementById('linhaVazia');
  if (linhaVazia) linhaVazia.remove();

  const materiais = window.MATERIAIS || [];
  const opts = materiais.map(m => {
    const label = m.tipo === 'ferramenta'
      ? `${m.codigo} — ${m.nome} [FERRAMENTA]`
      : `${m.codigo} — ${m.nome}`;
    return `<option value="${m.id}" data-un="${m.unidade}" data-tipo="${m.tipo}">${label}</option>`;
  }).join('');

  const tr = document.createElement('tr');
  tr.id = `item-${_itemCounter}`;
  tr.innerHTML = `
    <td class="mono" style="font-size:11px;color:var(--text-muted)">${String(_itemCounter).padStart(2,'0')}</td>
    <td>
      <select name="material_id[]" onchange="syncUnidade(this, ${_itemCounter})" required style="width:100%;min-width:200px">
        <option value="">Selecione...</option>
        ${opts}
      </select>
    </td>
    <td id="tipo-badge-${_itemCounter}"></td>
    <td>
      <input type="text" id="un-${_itemCounter}" name="unidade[]" readonly style="width:70px" placeholder="UN" />
    </td>
    <td>
      <input type="number" name="quantidade[]" min="1" value="1" required style="width:80px" />
    </td>
    <td>
      <button type="button" class="btn-remove" onclick="removerItem(${_itemCounter})" title="Remover">✕</button>
    </td>
  `;
  tbody.appendChild(tr);
  atualizarContador();
}

function syncUnidade(select, id) {
  const opt = select.options[select.selectedIndex];
  const unEl    = document.getElementById(`un-${id}`);
  const badgeEl = document.getElementById(`tipo-badge-${id}`);
  if (unEl) unEl.value = opt.dataset.un || '';
  if (badgeEl) {
    if (opt.dataset.tipo === 'ferramenta') {
      badgeEl.innerHTML = '<span style="font-size:10px;font-weight:700;padding:2px 6px;border-radius:4px;background:rgba(255,170,0,.12);color:var(--warning);border:1px solid var(--warning);white-space:nowrap">Ferramenta<br><span style="font-weight:400;font-size:9px">dev. até 22h</span></span>';
    } else if (opt.value) {
      badgeEl.innerHTML = '<span style="font-size:10px;padding:2px 6px;border-radius:4px;background:var(--accent-dim);color:var(--accent);border:1px solid var(--accent)">Material</span>';
    } else {
      badgeEl.innerHTML = '';
    }
  }
  atualizarAvisoFerramenta();
}

function removerItem(id) {
  const tr = document.getElementById(`item-${id}`);
  if (tr) tr.remove();
  const tbody = document.getElementById('corpoTabela');
  if (!tbody) return;
  if (tbody.querySelectorAll('tr:not(.empty-row)').length === 0) {
    const tr2 = document.createElement('tr');
    tr2.id = 'linhaVazia';
    tr2.className = 'empty-row';
    tr2.innerHTML = '<td colspan="6">Nenhum item adicionado. Clique em "+ Adicionar Item".</td>';
    tbody.appendChild(tr2);
  }
  atualizarContador();
  atualizarAvisoFerramenta();
}

function atualizarContador() {
  const el = document.getElementById('resumoItens');
  if (!el) return;
  const n = document.querySelectorAll('#corpoTabela tr:not(.empty-row)').length;
  el.textContent = n === 0 ? '0 itens' : `${n} ${n === 1 ? 'item' : 'itens'}`;
}

function atualizarAvisoFerramenta() {
  const aviso = document.getElementById('avisoFerramenta');
  if (!aviso) return;
  const temFerramenta = [...document.querySelectorAll('select[name="material_id[]"]')]
    .some(sel => sel.options[sel.selectedIndex]?.dataset?.tipo === 'ferramenta');
  aviso.style.display = temFerramenta ? 'block' : 'none';
}

// Urgência → resumo
document.addEventListener('DOMContentLoaded', () => {
  document.querySelectorAll('input[name="urgencia"]').forEach(el => {
    el.addEventListener('change', () => {
      const map    = { baixa: 'Baixa', media: 'Média', alta: 'Alta' };
      const colors = { baixa: 'var(--success)', media: 'var(--accent)', alta: 'var(--danger)' };
      const r = document.getElementById('resumoUrgencia');
      if (r) { r.textContent = map[el.value]; r.style.color = colors[el.value]; }
    });
  });
});
