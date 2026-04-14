<?php
session_start();
require_once __DIR__ . '/../db.php';
require_once __DIR__ . '/../includes/auth.php';
requer_aluno();

$db  = get_db();
$uid = $_SESSION['usuario_id'];

// Filtro de status
$filtro_status = $_GET['status'] ?? '';
$where  = "WHERE s.usuario_id = ?";
$params = [$uid];

if ($filtro_status && in_array($filtro_status, ['pendente','aprovada','rejeitada','separada','retirada','devolvida'])) {
    $where   .= " AND s.status = ?";
    $params[] = $filtro_status;
}

$solicitacoes = $db->prepare("
    SELECT s.*,
           COUNT(DISTINCT i.id) AS qtd_itens,
           GROUP_CONCAT(m.nome, ', ') AS materiais_nomes
    FROM solicitacoes s
    LEFT JOIN itens_solicitacao i ON i.solicitacao_id = s.id
    LEFT JOIN materiais m ON m.id = i.material_id
    $where
    GROUP BY s.id
    ORDER BY s.criado_em DESC
");
$solicitacoes->execute($params);
$solicitacoes = $solicitacoes->fetchAll();

// Labels legíveis
$status_label = [
    'pendente'  => 'Aguardando análise',
    'aprovada'  => 'Aprovada — retirar no almoxarifado',
    'rejeitada' => 'Rejeitada',
    'separada'  => 'Em separação',
    'retirada'  => 'Retirada — pendente devolução',
    'devolvida' => 'Concluída',
];

$page_title = 'Minhas Solicitações — ALMOX.SYS';
require_once __DIR__ . '/../includes/header.php';
?>

<div class="wrapper">

  <div class="page-header fade-up">
    <div class="breadcrumb">
      Início <span class="sep">/</span> <a href="/aluno/dashboard.php">Dashboard</a> <span class="sep">/</span> Minhas Solicitações
    </div>
    <h1 class="page-title">Minhas <span>Solicitações</span></h1>
    <p class="page-subtitle">Acompanhe o status de todas as suas solicitações de material.</p>
  </div>

  <!-- Filtros + ação -->
  <div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:20px;flex-wrap:wrap;gap:12px" class="fade-up-1">
    <div style="display:flex;gap:8px;flex-wrap:wrap">
      <?php
      $filtros = [
        ''          => 'Todos',
        'pendente'  => 'Pendentes',
        'aprovada'  => 'Aprovadas',
        'separada'  => 'Em separação',
        'retirada'  => 'Retiradas',
        'devolvida' => 'Devolvidas',
        'rejeitada' => 'Rejeitadas',
      ];
      foreach ($filtros as $val => $label):
        $ativo = $filtro_status === $val;
      ?>
        <a href="?status=<?= urlencode($val) ?>"
           class="btn btn-xs <?= $ativo ? 'btn-primary' : 'btn-ghost' ?>">
          <?= $label ?>
        </a>
      <?php endforeach; ?>
    </div>
    <a href="/aluno/solicitar.php" class="btn btn-primary btn-sm">+ Nova Solicitação</a>
  </div>

  <!-- Tabela -->
  <div class="card fade-up-2">
    <div class="table-wrap">
      <table>
        <thead>
          <tr>
            <th>Nº</th>
            <th>Data</th>
            <th>Materiais</th>
            <th>Itens</th>
            <th>Urgência</th>
            <th>Status</th>
            <th>Detalhe</th>
          </tr>
        </thead>
        <tbody>
          <?php if (empty($solicitacoes)): ?>
            <tr class="empty-row">
              <td colspan="7">
                <?= $filtro_status ? 'Nenhuma solicitação com este status.' : 'Você ainda não fez nenhuma solicitação.' ?>
                <?php if (!$filtro_status): ?>
                  <a href="/aluno/solicitar.php"> Criar agora →</a>
                <?php endif; ?>
              </td>
            </tr>
          <?php else: ?>
            <?php foreach ($solicitacoes as $sol): ?>
            <tr>
              <td class="mono" style="font-size:12px">#<?= str_pad($sol['id'], 4, '0', STR_PAD_LEFT) ?></td>
              <td style="font-size:12px;white-space:nowrap">
                <?= date('d/m/Y', strtotime($sol['criado_em'])) ?><br>
                <span class="text-muted" style="font-size:11px"><?= date('H:i', strtotime($sol['criado_em'])) ?></span>
              </td>
              <td style="font-size:12px;max-width:200px;overflow:hidden;text-overflow:ellipsis;white-space:nowrap"
                  title="<?= htmlspecialchars($sol['materiais_nomes'] ?? '') ?>">
                <?= htmlspecialchars($sol['materiais_nomes'] ?? '—') ?>
              </td>
              <td class="mono text-center"><?= (int)$sol['qtd_itens'] ?></td>
              <td><span class="badge badge-<?= $sol['urgencia'] ?>"><?= ucfirst($sol['urgencia']) ?></span></td>
              <td><span class="badge badge-<?= $sol['status'] ?>"><?= ucfirst($sol['status']) ?></span></td>
              <td>
                <button onclick="verDetalhe(<?= $sol['id'] ?>)" class="btn btn-ghost btn-xs">Ver</button>
              </td>
            </tr>
            <?php endforeach; ?>
          <?php endif; ?>
        </tbody>
      </table>
    </div>
  </div>

</div>

<!-- Modal de detalhe -->
<div id="modalOverlay" style="display:none;position:fixed;inset:0;background:rgba(0,0,0,.6);z-index:500;align-items:center;justify-content:center">
  <div id="modalBox" style="background:var(--surface);border:1px solid var(--border);border-radius:var(--radius);padding:28px;width:100%;max-width:560px;max-height:85vh;overflow-y:auto;position:relative">
    <button onclick="fecharModal()" style="position:absolute;top:14px;right:16px;background:none;border:none;color:var(--text-muted);cursor:pointer;font-size:20px">✕</button>
    <div id="modalConteudo"><p class="text-muted">Carregando...</p></div>
  </div>
</div>

<!-- Dados das solicitações para modal -->
<script>
const SOLICITACOES = <?= json_encode(array_column($solicitacoes, null, 'id')) ?>;

const STATUS_LABEL = {
  pendente:  'Aguardando análise',
  aprovada:  'Aprovada — retirar no almoxarifado',
  rejeitada: 'Rejeitada',
  separada:  'Em separação',
  retirada:  'Retirada — pendente devolução',
  devolvida: 'Concluída',
};

function verDetalhe(id) {
  const s = SOLICITACOES[id];
  if (!s) return;

  const overlay = document.getElementById('modalOverlay');
  const box     = document.getElementById('modalConteudo');

  const urgColor = { baixa: 'var(--success)', media: 'var(--accent)', alta: 'var(--danger)' };

  box.innerHTML = `
    <div style="margin-bottom:20px">
      <p class="mono" style="font-size:10px;color:var(--text-muted);margin-bottom:4px">SOLICITAÇÃO</p>
      <h2 style="font-size:20px;font-weight:700">#${String(id).padStart(4,'0')}</h2>
    </div>
    <div style="display:grid;grid-template-columns:1fr 1fr;gap:12px;margin-bottom:20px">
      <div style="background:var(--surface2);border:1px solid var(--border);border-radius:var(--radius-sm);padding:12px">
        <p style="font-size:10px;color:var(--text-muted);font-family:var(--mono);text-transform:uppercase;letter-spacing:.1em;margin-bottom:4px">Status</p>
        <span class="badge badge-${s.status}">${s.status.charAt(0).toUpperCase() + s.status.slice(1)}</span>
      </div>
      <div style="background:var(--surface2);border:1px solid var(--border);border-radius:var(--radius-sm);padding:12px">
        <p style="font-size:10px;color:var(--text-muted);font-family:var(--mono);text-transform:uppercase;letter-spacing:.1em;margin-bottom:4px">Urgência</p>
        <span style="font-weight:700;color:${urgColor[s.urgencia]}">${s.urgencia.charAt(0).toUpperCase() + s.urgencia.slice(1)}</span>
      </div>
    </div>
    ${s.justificativa ? `
    <div style="margin-bottom:16px">
      <p style="font-size:11px;color:var(--text-muted);text-transform:uppercase;letter-spacing:.06em;margin-bottom:6px">Justificativa</p>
      <p style="font-size:13px;background:var(--surface2);border:1px solid var(--border);border-radius:var(--radius-sm);padding:12px">${s.justificativa}</p>
    </div>` : ''}
    ${s.local_entrega ? `
    <div style="margin-bottom:16px">
      <p style="font-size:11px;color:var(--text-muted);text-transform:uppercase;letter-spacing:.06em;margin-bottom:4px">Local de entrega</p>
      <p style="font-size:13px">${s.local_entrega}</p>
    </div>` : ''}
    ${s.data_necessaria ? `
    <div style="margin-bottom:16px">
      <p style="font-size:11px;color:var(--text-muted);text-transform:uppercase;letter-spacing:.06em;margin-bottom:4px">Data necessária</p>
      <p style="font-size:13px">${s.data_necessaria}</p>
    </div>` : ''}
    <div>
      <p style="font-size:11px;color:var(--text-muted);text-transform:uppercase;letter-spacing:.06em;margin-bottom:6px">Materiais</p>
      <p style="font-size:13px;background:var(--surface2);border:1px solid var(--border);border-radius:var(--radius-sm);padding:12px">${s.materiais_nomes || '—'}</p>
    </div>
    <p style="font-size:11px;color:var(--text-muted);margin-top:16px;font-family:var(--mono)">
      Criado em: ${new Date(s.criado_em).toLocaleString('pt-BR')}
    </p>
  `;

  overlay.style.display = 'flex';
}

function fecharModal() {
  document.getElementById('modalOverlay').style.display = 'none';
}

document.getElementById('modalOverlay').addEventListener('click', function(e) {
  if (e.target === this) fecharModal();
});
</script>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
