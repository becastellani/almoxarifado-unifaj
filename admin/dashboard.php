<?php
session_start();
require_once __DIR__ . '/../db.php';
require_once __DIR__ . '/../includes/auth.php';
requer_admin();

$db = get_db();

// Stats gerais
$s = $db->query("
    SELECT
        (SELECT COUNT(*) FROM materiais)                                  AS total_materiais,
        (SELECT COUNT(*) FROM materiais WHERE qtd_disponivel = 0)         AS sem_estoque,
        (SELECT COUNT(*) FROM materiais WHERE qtd_disponivel > 0 AND qtd_disponivel <= 3) AS estoque_baixo,
        (SELECT COUNT(*) FROM usuarios WHERE papel = 'aluno')             AS total_alunos,
        (SELECT COUNT(*) FROM solicitacoes WHERE status = 'pendente')     AS pendentes,
        (SELECT COUNT(*) FROM solicitacoes WHERE status = 'aprovada')     AS aprovadas,
        (SELECT COUNT(*) FROM solicitacoes WHERE status = 'retirada')     AS retiradas,
        (SELECT COUNT(*) FROM solicitacoes)                               AS total_sol
")->fetch();

// Solicitações pendentes (últimas 8)
$pendentes = $db->query("
    SELECT s.*, u.nome AS aluno_nome, COUNT(i.id) AS qtd_itens
    FROM solicitacoes s
    JOIN usuarios u ON u.id = s.usuario_id
    LEFT JOIN itens_solicitacao i ON i.solicitacao_id = s.id
    WHERE s.status = 'pendente'
    GROUP BY s.id
    ORDER BY
        CASE s.urgencia WHEN 'alta' THEN 1 WHEN 'media' THEN 2 ELSE 3 END,
        s.criado_em ASC
    LIMIT 8
")->fetchAll();

// Materiais com estoque crítico
$criticos = $db->query("
    SELECT * FROM materiais
    WHERE qtd_disponivel <= 3
    ORDER BY qtd_disponivel ASC
    LIMIT 6
")->fetchAll();

$page_title = 'Dashboard Admin — ALMOX.SYS';
require_once __DIR__ . '/../includes/header.php';
?>

<div class="wrapper">

  <div class="page-header fade-up">
    <div class="breadcrumb">Admin <span class="sep">/</span> Dashboard</div>
    <h1 class="page-title">Painel do <span>Almoxarife</span></h1>
    <p class="page-subtitle">Visão geral do sistema — <?= date('d/m/Y, H:i') ?></p>
  </div>

  <!-- Stats -->
  <div class="grid-4 fade-up-1" style="margin-bottom:24px">
    <div class="stat-card">
      <span class="stat-label">Solicitações pendentes</span>
      <span class="stat-value <?= $s['pendentes'] > 0 ? 'accent' : '' ?>"><?= (int)$s['pendentes'] ?></span>
      <span class="stat-sub"><a href="/admin/solicitacoes.php?status=pendente">Ver agora →</a></span>
    </div>
    <div class="stat-card">
      <span class="stat-label">Materiais em estoque</span>
      <span class="stat-value"><?= (int)$s['total_materiais'] ?></span>
      <span class="stat-sub"><a href="/admin/materiais.php">Gerenciar →</a></span>
    </div>
    <div class="stat-card">
      <span class="stat-label">Estoque crítico (≤ 3)</span>
      <span class="stat-value <?= ($s['sem_estoque'] + $s['estoque_baixo']) > 0 ? 'danger' : 'success' ?>">
        <?= (int)$s['sem_estoque'] + (int)$s['estoque_baixo'] ?>
      </span>
      <span class="stat-sub"><?= (int)$s['sem_estoque'] ?> zerado(s)</span>
    </div>
    <div class="stat-card">
      <span class="stat-label">Alunos cadastrados</span>
      <span class="stat-value"><?= (int)$s['total_alunos'] ?></span>
      <span class="stat-sub"><?= (int)$s['total_sol'] ?> sol. no total</span>
    </div>
  </div>

  <!-- Status linha -->
  <div style="display:flex;gap:12px;margin-bottom:24px;flex-wrap:wrap" class="fade-up-1">
    <?php
    $links = [
      ['label' => 'Aprovadas aguardando retirada', 'val' => $s['aprovadas'],  'status' => 'aprovada',  'cor' => 'success'],
      ['label' => 'Retiradas (pendentes devolução)', 'val' => $s['retiradas'], 'status' => 'retirada', 'cor' => 'accent'],
    ];
    foreach ($links as $l): ?>
    <a href="/admin/solicitacoes.php?status=<?= $l['status'] ?>"
       style="flex:1;min-width:200px;background:var(--surface);border:1px solid var(--border);border-radius:var(--radius);padding:14px 18px;text-decoration:none;display:flex;justify-content:space-between;align-items:center;transition:border-color .2s"
       onmouseover="this.style.borderColor='var(--accent)'" onmouseout="this.style.borderColor='var(--border)'">
      <span style="font-size:13px;color:var(--text-muted)"><?= $l['label'] ?></span>
      <span class="mono" style="font-size:20px;font-weight:700;color:var(--<?= $l['cor'] ?>)"><?= (int)$l['val'] ?></span>
    </a>
    <?php endforeach; ?>
  </div>

  <div class="grid-2 fade-up-2" style="align-items:start">

    <!-- Pendentes -->
    <div class="card">
      <div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:16px">
        <div class="card-title" style="margin-bottom:0">Solicitações Pendentes</div>
        <a href="/admin/solicitacoes.php" class="btn btn-ghost btn-xs">Ver todas</a>
      </div>

      <?php if (empty($pendentes)): ?>
        <p class="text-muted" style="font-size:13px;text-align:center;padding:20px 0">
          Nenhuma solicitação pendente. ✓
        </p>
      <?php else: ?>
        <div style="display:flex;flex-direction:column;gap:10px">
          <?php foreach ($pendentes as $p): ?>
          <div style="background:var(--surface2);border:1px solid var(--border);border-radius:var(--radius-sm);padding:12px 14px;display:flex;align-items:center;justify-content:space-between;gap:12px">
            <div style="flex:1;min-width:0">
              <div style="display:flex;align-items:center;gap:8px;margin-bottom:4px">
                <span class="mono" style="font-size:11px;color:var(--text-muted)">#<?= str_pad($p['id'], 4, '0', STR_PAD_LEFT) ?></span>
                <span class="badge badge-<?= $p['urgencia'] ?>"><?= ucfirst($p['urgencia']) ?></span>
              </div>
              <p style="font-size:13px;font-weight:600;white-space:nowrap;overflow:hidden;text-overflow:ellipsis">
                <?= htmlspecialchars($p['aluno_nome']) ?>
              </p>
              <p style="font-size:11px;color:var(--text-muted)"><?= (int)$p['qtd_itens'] ?> item(ns) · <?= date('d/m H:i', strtotime($p['criado_em'])) ?></p>
            </div>
            <a href="/admin/solicitacoes.php?id=<?= $p['id'] ?>" class="btn btn-primary btn-xs" style="flex-shrink:0">Analisar</a>
          </div>
          <?php endforeach; ?>
        </div>
      <?php endif; ?>
    </div>

    <!-- Estoque crítico -->
    <div class="card">
      <div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:16px">
        <div class="card-title" style="margin-bottom:0">Estoque Crítico</div>
        <a href="/admin/materiais.php" class="btn btn-ghost btn-xs">Gerenciar</a>
      </div>

      <?php if (empty($criticos)): ?>
        <p class="text-muted" style="font-size:13px;text-align:center;padding:20px 0">
          Todos os materiais com estoque adequado. ✓
        </p>
      <?php else: ?>
        <div class="table-wrap">
          <table>
            <thead>
              <tr>
                <th>Material</th>
                <th>Cód.</th>
                <th style="text-align:center">Disponível</th>
                <th style="text-align:center">Total</th>
              </tr>
            </thead>
            <tbody>
              <?php foreach ($criticos as $m): ?>
              <tr>
                <td style="font-size:13px"><?= htmlspecialchars($m['nome']) ?></td>
                <td class="mono" style="font-size:11px;color:var(--text-muted)"><?= htmlspecialchars($m['codigo']) ?></td>
                <td style="text-align:center">
                  <span style="font-weight:700;color:<?= $m['qtd_disponivel'] == 0 ? 'var(--danger)' : 'var(--warning)' ?>">
                    <?= (int)$m['qtd_disponivel'] ?>
                  </span>
                </td>
                <td style="text-align:center;font-size:12px;color:var(--text-muted)"><?= (int)$m['qtd_total'] ?></td>
              </tr>
              <?php endforeach; ?>
            </tbody>
          </table>
        </div>
      <?php endif; ?>
    </div>

  </div>

</div>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
