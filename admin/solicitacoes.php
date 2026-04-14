<?php
session_start();
require_once __DIR__ . '/../db.php';
require_once __DIR__ . '/../includes/auth.php';
requer_admin();

$db   = get_db();
$msg  = '';
$erro = '';

// --- AÇÕES POST ---
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $acao  = $_POST['acao']  ?? '';
    $sol_id = (int)($_POST['sol_id'] ?? 0);

    $sol = $db->prepare("SELECT * FROM solicitacoes WHERE id = ?")->execute([$sol_id]) ? null : null;
    $stmt = $db->prepare("SELECT * FROM solicitacoes WHERE id = ?");
    $stmt->execute([$sol_id]);
    $sol = $stmt->fetch();

    if (!$sol) {
        $erro = 'Solicitação não encontrada.';
    } elseif ($acao === 'aprovar' && $sol['status'] === 'pendente') {
        $db->prepare("UPDATE solicitacoes SET status = 'aprovada' WHERE id = ?")->execute([$sol_id]);
        $msg = "Solicitação #" . str_pad($sol_id, 4, '0', STR_PAD_LEFT) . " aprovada.";

    } elseif ($acao === 'rejeitar' && $sol['status'] === 'pendente') {
        $db->prepare("UPDATE solicitacoes SET status = 'rejeitada' WHERE id = ?")->execute([$sol_id]);
        $msg = "Solicitação #" . str_pad($sol_id, 4, '0', STR_PAD_LEFT) . " rejeitada.";
    }
}

// --- FILTROS ---
$filtro_status = $_GET['status'] ?? '';
$busca         = trim($_GET['q'] ?? '');
$highlight_id  = (int)($_GET['id'] ?? 0);

$where   = "WHERE 1=1";
$params  = [];

if ($filtro_status && in_array($filtro_status, ['pendente','aprovada','rejeitada','separada','retirada','devolvida'])) {
    $where   .= " AND s.status = ?";
    $params[] = $filtro_status;
}
if ($busca) {
    $where   .= " AND (u.nome LIKE ? OR s.id LIKE ?)";
    $params[] = "%$busca%";
    $params[] = "%$busca%";
}

$solicitacoes = $db->prepare("
    SELECT s.*, u.nome AS aluno_nome, u.email AS aluno_email,
           COUNT(DISTINCT i.id) AS qtd_itens
    FROM solicitacoes s
    JOIN  usuarios u ON u.id = s.usuario_id
    LEFT JOIN itens_solicitacao i ON i.solicitacao_id = s.id
    $where
    GROUP BY s.id
    ORDER BY
        CASE s.status WHEN 'pendente' THEN 1 ELSE 2 END,
        CASE s.urgencia WHEN 'alta' THEN 1 WHEN 'media' THEN 2 ELSE 3 END,
        s.criado_em DESC
");
$solicitacoes->execute($params);
$solicitacoes = $solicitacoes->fetchAll();

// Carrega itens de todas as solicitações exibidas de uma vez (evita N+1)
$ids = array_column($solicitacoes, 'id');
$itens_map = [];
if ($ids) {
    $placeholders = implode(',', array_fill(0, count($ids), '?'));
    $itens_raw = $db->prepare("
        SELECT i.*, m.nome AS mat_nome, m.codigo, m.unidade, m.qtd_disponivel
        FROM itens_solicitacao i
        JOIN materiais m ON m.id = i.material_id
        WHERE i.solicitacao_id IN ($placeholders)
    ");
    $itens_raw->execute($ids);
    foreach ($itens_raw->fetchAll() as $item) {
        $itens_map[$item['solicitacao_id']][] = $item;
    }
}

$page_title = 'Solicitações — ALMOX.SYS';
require_once __DIR__ . '/../includes/header.php';
?>

<div class="wrapper">

  <div class="page-header fade-up">
    <div class="breadcrumb">Admin <span class="sep">/</span> <a href="/admin/dashboard.php">Dashboard</a> <span class="sep">/</span> Solicitações</div>
    <h1 class="page-title">Análise de <span>Solicitações</span></h1>
    <p class="page-subtitle">Aprove ou rejeite solicitações dos alunos. Pendentes ordenadas por urgência.</p>
  </div>

  <?php if ($msg):  ?><div class="alert alert-success fade-up"><?= htmlspecialchars($msg) ?></div><?php endif; ?>
  <?php if ($erro): ?><div class="alert alert-error fade-up"><?= htmlspecialchars($erro) ?></div><?php endif; ?>

  <!-- Filtros -->
  <div style="display:flex;gap:12px;flex-wrap:wrap;margin-bottom:20px;align-items:center" class="fade-up-1">
    <div style="display:flex;gap:6px;flex-wrap:wrap">
      <?php
      $filtros = [
        ''          => 'Todas',
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
        <a href="?status=<?= urlencode($val) ?><?= $busca ? '&q='.urlencode($busca) : '' ?>"
           class="btn btn-xs <?= $ativo ? 'btn-primary' : 'btn-ghost' ?>">
          <?= $label ?>
        </a>
      <?php endforeach; ?>
    </div>

    <form method="GET" style="display:flex;gap:8px;margin-left:auto">
      <input type="hidden" name="status" value="<?= htmlspecialchars($filtro_status) ?>" />
      <input type="text" name="q" placeholder="Buscar aluno ou Nº..." value="<?= htmlspecialchars($busca) ?>" style="width:200px" />
      <button type="submit" class="btn btn-secondary btn-sm">Buscar</button>
      <?php if ($busca): ?>
        <a href="?status=<?= urlencode($filtro_status) ?>" class="btn btn-ghost btn-sm">✕</a>
      <?php endif; ?>
    </form>
  </div>

  <!-- Lista de solicitações -->
  <div style="display:flex;flex-direction:column;gap:14px" class="fade-up-2">

    <?php if (empty($solicitacoes)): ?>
      <div class="card" style="text-align:center;padding:40px">
        <p class="text-muted">Nenhuma solicitação encontrada com estes filtros.</p>
      </div>
    <?php endif; ?>

    <?php foreach ($solicitacoes as $sol):
      $itens    = $itens_map[$sol['id']] ?? [];
      $aberta   = $highlight_id === $sol['id'];
    ?>
    <div class="card" id="sol-<?= $sol['id'] ?>"
         style="<?= $aberta ? 'border-color:var(--accent);' : '' ?>padding:0;overflow:hidden">

      <!-- Cabeçalho da solicitação -->
      <div style="display:flex;align-items:center;justify-content:space-between;padding:16px 20px;gap:12px;flex-wrap:wrap;cursor:pointer"
           onclick="toggleSol(<?= $sol['id'] ?>)">

        <div style="display:flex;align-items:center;gap:14px;flex-wrap:wrap">
          <span class="mono" style="font-size:13px;font-weight:700">#<?= str_pad($sol['id'], 4, '0', STR_PAD_LEFT) ?></span>
          <span class="badge badge-<?= $sol['urgencia'] ?>"><?= ucfirst($sol['urgencia']) ?></span>
          <span class="badge badge-<?= $sol['status'] ?>"><?= ucfirst($sol['status']) ?></span>
          <span style="font-size:13px;font-weight:600"><?= htmlspecialchars($sol['aluno_nome']) ?></span>
          <span class="text-muted" style="font-size:12px"><?= htmlspecialchars($sol['aluno_email']) ?></span>
        </div>

        <div style="display:flex;align-items:center;gap:12px">
          <span class="text-muted" style="font-size:12px"><?= (int)$sol['qtd_itens'] ?> item(ns) · <?= date('d/m/Y H:i', strtotime($sol['criado_em'])) ?></span>
          <span style="color:var(--text-muted);font-size:18px" id="chevron-<?= $sol['id'] ?>"><?= $aberta ? '▲' : '▼' ?></span>
        </div>
      </div>

      <!-- Detalhe expansível -->
      <div id="detalhe-<?= $sol['id'] ?>" style="<?= $aberta ? '' : 'display:none' ?>;border-top:1px solid var(--border)">

        <div style="padding:20px;display:grid;grid-template-columns:1fr 1fr;gap:20px">

          <!-- Itens solicitados -->
          <div>
            <p style="font-size:10px;font-family:var(--mono);color:var(--text-muted);text-transform:uppercase;letter-spacing:.1em;margin-bottom:10px">Itens Solicitados</p>
            <div class="table-wrap">
              <table>
                <thead>
                  <tr>
                    <th>Material</th>
                    <th>Cód.</th>
                    <th style="text-align:center">Qtd.</th>
                    <th style="text-align:center">Estoque</th>
                  </tr>
                </thead>
                <tbody>
                  <?php if (empty($itens)): ?>
                    <tr class="empty-row"><td colspan="4">Sem itens registrados.</td></tr>
                  <?php else: ?>
                    <?php foreach ($itens as $it):
                      $sem_estoque = $it['qtd_disponivel'] < $it['qtd_solicitada'];
                    ?>
                    <tr <?= $sem_estoque ? 'style="background:var(--danger-dim)"' : '' ?>>
                      <td style="font-size:12px"><?= htmlspecialchars($it['mat_nome']) ?></td>
                      <td class="mono" style="font-size:10px;color:var(--text-muted)"><?= htmlspecialchars($it['codigo']) ?></td>
                      <td style="text-align:center;font-weight:700"><?= (int)$it['qtd_solicitada'] ?> <?= htmlspecialchars($it['unidade']) ?></td>
                      <td style="text-align:center;font-size:12px;color:<?= $sem_estoque ? 'var(--danger)' : 'var(--success)' ?>">
                        <?= (int)$it['qtd_disponivel'] ?> disp.
                      </td>
                    </tr>
                    <?php endforeach; ?>
                  <?php endif; ?>
                </tbody>
              </table>
            </div>
          </div>

          <!-- Infos + ações -->
          <div style="display:flex;flex-direction:column;gap:14px">

            <?php if ($sol['justificativa']): ?>
            <div>
              <p style="font-size:10px;font-family:var(--mono);color:var(--text-muted);text-transform:uppercase;letter-spacing:.1em;margin-bottom:6px">Justificativa</p>
              <p style="font-size:13px;background:var(--surface2);border:1px solid var(--border);border-radius:var(--radius-sm);padding:10px"><?= htmlspecialchars($sol['justificativa']) ?></p>
            </div>
            <?php endif; ?>

            <div style="display:grid;grid-template-columns:1fr 1fr;gap:10px">
              <?php if ($sol['local_entrega']): ?>
              <div style="background:var(--surface2);border:1px solid var(--border);border-radius:var(--radius-sm);padding:10px">
                <p style="font-size:10px;color:var(--text-muted);text-transform:uppercase;font-family:var(--mono);letter-spacing:.08em;margin-bottom:4px">Local</p>
                <p style="font-size:12px"><?= htmlspecialchars($sol['local_entrega']) ?></p>
              </div>
              <?php endif; ?>
              <?php if ($sol['data_necessaria']): ?>
              <div style="background:var(--surface2);border:1px solid var(--border);border-radius:var(--radius-sm);padding:10px">
                <p style="font-size:10px;color:var(--text-muted);text-transform:uppercase;font-family:var(--mono);letter-spacing:.08em;margin-bottom:4px">Data Limite</p>
                <p style="font-size:12px"><?= date('d/m/Y', strtotime($sol['data_necessaria'])) ?></p>
              </div>
              <?php endif; ?>
            </div>

            <!-- Botões de ação -->
            <?php if ($sol['status'] === 'pendente'): ?>
            <div style="display:flex;gap:10px;margin-top:auto;padding-top:8px;border-top:1px solid var(--border)">
              <form method="POST" action="/admin/solicitacoes.php?status=<?= urlencode($filtro_status) ?>" style="flex:1">
                <input type="hidden" name="acao"   value="aprovar" />
                <input type="hidden" name="sol_id" value="<?= $sol['id'] ?>" />
                <button type="submit" class="btn btn-success w-full" style="justify-content:center">
                  ✓ Aprovar
                </button>
              </form>
              <form method="POST" action="/admin/solicitacoes.php?status=<?= urlencode($filtro_status) ?>"
                    onsubmit="return confirm('Rejeitar esta solicitação?')" style="flex:1">
                <input type="hidden" name="acao"   value="rejeitar" />
                <input type="hidden" name="sol_id" value="<?= $sol['id'] ?>" />
                <button type="submit" class="btn btn-danger w-full" style="justify-content:center">
                  ✕ Rejeitar
                </button>
              </form>
            </div>
            <?php else: ?>
            <div style="padding:10px;background:var(--surface2);border-radius:var(--radius-sm);text-align:center">
              <p style="font-size:12px;color:var(--text-muted)">
                Status atual: <span class="badge badge-<?= $sol['status'] ?>"><?= ucfirst($sol['status']) ?></span>
              </p>
              <?php if ($sol['status'] === 'aprovada'): ?>
                <p style="font-size:12px;color:var(--text-muted);margin-top:6px">Aguardando separação dos materiais.</p>
              <?php elseif ($sol['status'] === 'retirada'): ?>
                <p style="font-size:12px;color:var(--text-muted);margin-top:6px">Material retirado. Aguardando devolução.</p>
              <?php endif; ?>
            </div>
            <?php endif; ?>

          </div>
        </div>
      </div>

    </div>
    <?php endforeach; ?>

  </div>

  <?php if (!empty($solicitacoes)): ?>
  <p class="text-muted" style="font-size:12px;text-align:right;margin-top:16px">
    <?= count($solicitacoes) ?> solicitação(ões) exibida(s)
  </p>
  <?php endif; ?>

</div>

<script>
function toggleSol(id) {
  const det = document.getElementById('detalhe-' + id);
  const ch  = document.getElementById('chevron-' + id);
  const aberto = det.style.display !== 'none';
  det.style.display = aberto ? 'none' : 'block';
  ch.textContent   = aberto ? '▼' : '▲';
}

// Abre automaticamente se veio via ?id=
<?php if ($highlight_id): ?>
document.addEventListener('DOMContentLoaded', () => {
  const el = document.getElementById('sol-<?= $highlight_id ?>');
  if (el) el.scrollIntoView({ behavior: 'smooth', block: 'center' });
});
<?php endif; ?>
</script>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
