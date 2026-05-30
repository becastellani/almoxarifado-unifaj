<?php
require_once __DIR__ . '/../includes/session.php';
require_once __DIR__ . '/../db.php';
require_once __DIR__ . '/../includes/auth.php';
requer_aluno();

$db  = get_db();
$uid = $_SESSION['usuario_id'];

// Stats do aluno
$stats = $db->prepare("
    SELECT
        COUNT(*) AS total,
        SUM(status = 'pendente')  AS pendente,
        SUM(status = 'aprovada')  AS aprovada,
        SUM(status = 'retirada')  AS retirada,
        SUM(status = 'devolvida') AS devolvida,
        SUM(status = 'rejeitada') AS rejeitada
    FROM solicitacoes WHERE usuario_id = ?
");
$stats->execute([$uid]);
$s = $stats->fetch();

// Últimas 5 solicitações
$recentes = $db->prepare("
    SELECT s.*, COUNT(i.id) AS qtd_itens
    FROM solicitacoes s
    LEFT JOIN itens_solicitacao i ON i.solicitacao_id = s.id
    WHERE s.usuario_id = ?
    GROUP BY s.id
    ORDER BY s.criado_em DESC
    LIMIT 5
");
$recentes->execute([$uid]);
$recentes = $recentes->fetchAll();

$page_title = 'Início — ALMOX.SYS';
require_once __DIR__ . '/../includes/header.php';
?>

<div class="wrapper">

  <!-- Page header -->
  <div class="page-header fade-up">
    <div class="breadcrumb">Início <span class="sep">/</span> Dashboard</div>
    <h1 class="page-title">Olá, <span><?= htmlspecialchars(explode(' ', $_SESSION['nome'])[0]) ?></span> 👋</h1>
    <p class="page-subtitle">Acompanhe suas solicitações e gerencie seus materiais.</p>
  </div>

  <!-- Stats -->
  <div class="grid-4 fade-up-1" style="margin-bottom:24px">
    <div class="stat-card">
      <span class="stat-label">Total de solicitações</span>
      <span class="stat-value"><?= (int)$s['total'] ?></span>
    </div>
    <div class="stat-card">
      <span class="stat-label">Pendentes</span>
      <span class="stat-value accent"><?= (int)$s['pendente'] ?></span>
    </div>
    <div class="stat-card">
      <span class="stat-label">Em aberto</span>
      <span class="stat-value" style="color:var(--text)"><?= (int)$s['aprovada'] + (int)$s['retirada'] ?></span>
    </div>
    <div class="stat-card">
      <span class="stat-label">Concluídas</span>
      <span class="stat-value success"><?= (int)$s['devolvida'] ?></span>
    </div>
  </div>

  <!-- Ação rápida + recentes -->
  <div class="grid-2 fade-up-2" style="align-items:start">

    <!-- Quick actions -->
    <div class="card">
      <div class="card-title">Ações rápidas</div>

      <a href="/aluno/solicitar.php" class="btn btn-primary w-full" style="justify-content:center;margin-bottom:12px">
        + Nova Solicitação de Material
      </a>
      <a href="/aluno/minhas-solicitacoes.php" class="btn btn-secondary w-full" style="justify-content:center">
        Ver todas as solicitações
      </a>

      <?php if ($s['rejeitada'] > 0): ?>
      <div class="alert alert-error" style="margin-top:16px;margin-bottom:0">
        Você tem <?= (int)$s['rejeitada'] ?> solicitação(ões) rejeitada(s).
        <a href="/aluno/minhas-solicitacoes.php" style="color:inherit;font-weight:700"> Ver →</a>
      </div>
      <?php endif; ?>

      <?php if ($s['aprovada'] > 0): ?>
      <div class="alert alert-info" style="margin-top:16px;margin-bottom:0">
        <?= (int)$s['aprovada'] ?> solicitação(ões) aprovada(s) aguardando retirada.
      </div>
      <?php endif; ?>
    </div>

    <!-- Recentes -->
    <div class="card">
      <div class="card-title">Solicitações recentes</div>

      <?php if (empty($recentes)): ?>
        <p class="text-muted" style="font-size:13px;text-align:center;padding:20px 0">
          Nenhuma solicitação ainda. <a href="/aluno/solicitar.php">Criar a primeira →</a>
        </p>
      <?php else: ?>
        <div class="table-wrap">
          <table>
            <thead>
              <tr>
                <th>Nº</th>
                <th>Data</th>
                <th>Itens</th>
                <th>Urgência</th>
                <th>Status</th>
              </tr>
            </thead>
            <tbody>
              <?php foreach ($recentes as $r): ?>
              <tr>
                <td class="mono" style="font-size:12px">
                  <a href="/aluno/minhas-solicitacoes.php">#<?= str_pad($r['id'], 4, '0', STR_PAD_LEFT) ?></a>
                </td>
                <td style="font-size:12px"><?= date('d/m/Y', strtotime($r['criado_em'])) ?></td>
                <td class="mono"><?= (int)$r['qtd_itens'] ?></td>
                <td><span class="badge badge-<?= $r['urgencia'] ?>"><?= ucfirst($r['urgencia']) ?></span></td>
                <td><span class="badge badge-<?= $r['status'] ?>"><?= ucfirst($r['status']) ?></span></td>
              </tr>
              <?php endforeach; ?>
            </tbody>
          </table>
        </div>
        <div style="text-align:right;margin-top:12px">
          <a href="/aluno/minhas-solicitacoes.php" class="btn btn-ghost btn-sm">Ver todas →</a>
        </div>
      <?php endif; ?>
    </div>

  </div>

</div>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
