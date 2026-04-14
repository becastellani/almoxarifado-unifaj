<?php
session_start();
require_once __DIR__ . '/../db.php';
require_once __DIR__ . '/../includes/auth.php';
requer_admin();

$db = get_db();

// --- FILTROS DE PERÍODO ---
$data_ini = $_GET['de']  ?? date('Y-m-01');          // início do mês atual
$data_fim = $_GET['ate'] ?? date('Y-m-d');            // hoje
$secao    = $_GET['sec'] ?? 'geral';                  // geral | consumo | materiais | alunos

// Sanitiza datas
if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $data_ini)) $data_ini = date('Y-m-01');
if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $data_fim)) $data_fim = date('Y-m-d');
if ($data_ini > $data_fim) [$data_ini, $data_fim] = [$data_fim, $data_ini];

$fim_completo = $data_fim . ' 23:59:59';

// =====================================================================
// QUERIES — todas filtradas pelo período
// =====================================================================

// 1. Totais gerais no período
$totais = $db->prepare("
    SELECT
        COUNT(*)                                   AS total,
        SUM(status = 'devolvida')                  AS devolvidas,
        SUM(status = 'retirada')                   AS em_uso,
        SUM(status = 'pendente')                   AS pendentes,
        SUM(status = 'rejeitada')                  AS rejeitadas,
        SUM(status IN ('aprovada','separada','retirada','devolvida')) AS atendidas
    FROM solicitacoes
    WHERE criado_em BETWEEN ? AND ?
");
$totais->execute([$data_ini, $fim_completo]);
$totais = $totais->fetch();

// 2. Materiais mais solicitados no período
$top_materiais = $db->prepare("
    SELECT m.id, m.codigo, m.nome, m.unidade,
           SUM(i.qtd_solicitada)  AS total_solicitado,
           COUNT(DISTINCT i.solicitacao_id) AS num_solicitacoes,
           m.qtd_disponivel, m.qtd_total
    FROM itens_solicitacao i
    JOIN materiais m ON m.id = i.material_id
    JOIN solicitacoes s ON s.id = i.solicitacao_id
    WHERE s.criado_em BETWEEN ? AND ?
      AND s.status NOT IN ('pendente','rejeitada')
    GROUP BY m.id
    ORDER BY total_solicitado DESC
    LIMIT 15
");
$top_materiais->execute([$data_ini, $fim_completo]);
$top_materiais = $top_materiais->fetchAll();

// 3. Alunos mais ativos no período
$top_alunos = $db->prepare("
    SELECT u.id, u.nome, u.email,
           COUNT(DISTINCT s.id) AS total_sol,
           SUM(s.status = 'devolvida') AS devolvidas,
           SUM(s.status IN ('retirada')) AS em_uso,
           SUM(i.qtd_solicitada) AS total_itens
    FROM solicitacoes s
    JOIN usuarios u ON u.id = s.usuario_id
    LEFT JOIN itens_solicitacao i ON i.solicitacao_id = s.id
    WHERE s.criado_em BETWEEN ? AND ?
    GROUP BY u.id
    ORDER BY total_sol DESC
    LIMIT 10
");
$top_alunos->execute([$data_ini, $fim_completo]);
$top_alunos = $top_alunos->fetchAll();

// 4. Histórico de movimentações no período (para impressão detalhada)
$historico = $db->prepare("
    SELECT s.id, s.status, s.urgencia, s.criado_em,
           u.nome  AS aluno_nome,
           u.email AS aluno_email,
           COUNT(DISTINCT i.id)    AS qtd_itens,
           SUM(i.qtd_solicitada)   AS total_unidades,
           GROUP_CONCAT(m.nome || ' (' || i.qtd_solicitada || ' ' || m.unidade || ')', ' | ') AS itens_resumo,
           mov_ret.criado_em       AS data_retirada,
           mov_dev.criado_em       AS data_devolucao,
           mov_dev.observacao      AS obs_conferencia
    FROM solicitacoes s
    JOIN  usuarios u ON u.id = s.usuario_id
    LEFT JOIN itens_solicitacao i ON i.solicitacao_id = s.id
    LEFT JOIN materiais m ON m.id = i.material_id
    LEFT JOIN movimentacoes mov_ret ON mov_ret.solicitacao_id = s.id AND mov_ret.tipo = 'retirada'
    LEFT JOIN movimentacoes mov_dev ON mov_dev.solicitacao_id = s.id AND mov_dev.tipo = 'devolucao'
    WHERE s.criado_em BETWEEN ? AND ?
      AND s.status IN ('retirada','devolvida')
    GROUP BY s.id
    ORDER BY s.id DESC
");
$historico->execute([$data_ini, $fim_completo]);
$historico = $historico->fetchAll();

// 5. Solicitações por dia (sparkline data)
$por_dia = $db->prepare("
    SELECT DATE(criado_em) AS dia, COUNT(*) AS qtd
    FROM solicitacoes
    WHERE criado_em BETWEEN ? AND ?
    GROUP BY DATE(criado_em)
    ORDER BY dia ASC
");
$por_dia->execute([$data_ini, $fim_completo]);
$por_dia = $por_dia->fetchAll();

// Max do top material (para barra de progresso)
$max_sol = !empty($top_materiais) ? $top_materiais[0]['total_solicitado'] : 1;

$page_title = 'Relatórios — ALMOX.SYS';
require_once __DIR__ . '/../includes/header.php';
?>

<!-- CSS extra só desta página -->
<style>
  @media print {
    .topbar, .site-footer, .no-print { display: none !important; }
    body { background: #fff !important; color: #000 !important; }
    .card { border: 1px solid #ccc !important; box-shadow: none !important; }
    .print-break { page-break-before: always; }
    [data-theme] { --text: #000; --text-muted: #555; --surface: #fff; --surface2: #f5f5f5; --border: #ddd; }
  }

  .progress-bar-wrap {
    background: var(--surface2);
    border-radius: 4px;
    height: 8px;
    flex: 1;
    overflow: hidden;
  }
  .progress-bar-fill {
    height: 100%;
    border-radius: 4px;
    background: var(--accent);
    transition: width .4s ease;
  }

  .sparkline-bar {
    background: var(--accent-dim);
    border-top: 2px solid var(--accent);
    border-radius: 2px 2px 0 0;
    flex: 1;
    min-width: 4px;
    transition: background .2s;
    position: relative;
  }
  .sparkline-bar:hover { background: var(--accent-dim); }
  .sparkline-bar:hover::after {
    content: attr(data-tip);
    position: absolute;
    bottom: 100%;
    left: 50%;
    transform: translateX(-50%);
    background: var(--surface);
    border: 1px solid var(--border);
    border-radius: 4px;
    padding: 3px 7px;
    font-size: 10px;
    white-space: nowrap;
    color: var(--text);
    pointer-events: none;
    z-index: 10;
  }
</style>

<div class="wrapper">

  <!-- Cabeçalho -->
  <div class="page-header fade-up">
    <div class="breadcrumb">Admin <span class="sep">/</span> <a href="/admin/dashboard.php">Dashboard</a> <span class="sep">/</span> Relatórios</div>
    <h1 class="page-title">Relatórios de <span>Consumo</span></h1>
    <p class="page-subtitle">Análise de solicitações, materiais mais utilizados e histórico de movimentações.</p>
  </div>

  <!-- Filtro de período -->
  <div class="card fade-up-1 no-print" style="margin-bottom:24px">
    <form method="GET" action="/admin/relatorios.php" style="display:flex;align-items:flex-end;gap:14px;flex-wrap:wrap">
      <input type="hidden" name="sec" value="<?= htmlspecialchars($secao) ?>" />
      <div class="field" style="margin-bottom:0">
        <label class="field-label">De</label>
        <input type="date" name="de" value="<?= $data_ini ?>" />
      </div>
      <div class="field" style="margin-bottom:0">
        <label class="field-label">Até</label>
        <input type="date" name="ate" value="<?= $data_fim ?>" />
      </div>
      <button type="submit" class="btn btn-primary">Filtrar</button>
      <!-- Atalhos rápidos -->
      <div style="display:flex;gap:6px;flex-wrap:wrap">
        <?php
        $atalhos = [
          'Hoje'       => [date('Y-m-d'), date('Y-m-d')],
          'Esta semana'=> [date('Y-m-d', strtotime('monday this week')), date('Y-m-d')],
          'Este mês'   => [date('Y-m-01'), date('Y-m-d')],
          'Último mês' => [date('Y-m-01', strtotime('first day of last month')), date('Y-m-t', strtotime('last month'))],
          'Este ano'   => [date('Y-01-01'), date('Y-m-d')],
        ];
        foreach ($atalhos as $label => [$de, $ate]):
          $ativo = $data_ini === $de && $data_fim === $ate;
        ?>
          <a href="?de=<?= $de ?>&ate=<?= $ate ?>&sec=<?= urlencode($secao) ?>"
             class="btn btn-xs <?= $ativo ? 'btn-primary' : 'btn-ghost' ?>">
            <?= $label ?>
          </a>
        <?php endforeach; ?>
      </div>
      <button type="button" onclick="window.print()" class="btn btn-secondary" style="margin-left:auto">
        🖨 Imprimir
      </button>
    </form>
  </div>

  <!-- Período exibido -->
  <div style="margin-bottom:20px;font-family:var(--mono);font-size:11px;color:var(--text-muted)" class="fade-up-1">
    Período: <strong style="color:var(--accent)"><?= date('d/m/Y', strtotime($data_ini)) ?></strong>
    até <strong style="color:var(--accent)"><?= date('d/m/Y', strtotime($data_fim)) ?></strong>
  </div>

  <!-- ======================== STATS ======================== -->
  <div class="grid-4 fade-up-2" style="margin-bottom:24px">
    <div class="stat-card">
      <span class="stat-label">Total de solicitações</span>
      <span class="stat-value"><?= (int)$totais['total'] ?></span>
      <span class="stat-sub"><?= (int)$totais['atendidas'] ?> atendidas</span>
    </div>
    <div class="stat-card">
      <span class="stat-label">Devolvidas (concluídas)</span>
      <span class="stat-value success"><?= (int)$totais['devolvidas'] ?></span>
      <span class="stat-sub">
        <?= $totais['atendidas'] > 0
          ? round(($totais['devolvidas'] / $totais['atendidas']) * 100) . '% de retorno'
          : '—' ?>
      </span>
    </div>
    <div class="stat-card">
      <span class="stat-label">Em uso (não devolvidas)</span>
      <span class="stat-value <?= $totais['em_uso'] > 0 ? 'accent' : '' ?>"><?= (int)$totais['em_uso'] ?></span>
      <span class="stat-sub">retiradas</span>
    </div>
    <div class="stat-card">
      <span class="stat-label">Rejeitadas</span>
      <span class="stat-value <?= $totais['rejeitadas'] > 0 ? 'danger' : '' ?>"><?= (int)$totais['rejeitadas'] ?></span>
      <span class="stat-sub">
        <?= $totais['total'] > 0
          ? round(($totais['rejeitadas'] / $totais['total']) * 100) . '% do total'
          : '—' ?>
      </span>
    </div>
  </div>

  <!-- ======================== SPARKLINE ======================== -->
  <?php if (!empty($por_dia)): ?>
  <div class="card fade-up-2 no-print" style="margin-bottom:24px">
    <div class="card-title" style="margin-bottom:16px">Solicitações por Dia</div>
    <?php
      $max_dia = max(array_column($por_dia, 'qtd'));
      $dias_map = [];
      foreach ($por_dia as $d) $dias_map[$d['dia']] = $d['qtd'];
    ?>
    <div style="display:flex;align-items:flex-end;gap:3px;height:60px">
      <?php
      $cur = new DateTime($data_ini);
      $end = new DateTime($data_fim);
      while ($cur <= $end):
        $key = $cur->format('Y-m-d');
        $qtd = $dias_map[$key] ?? 0;
        $h   = $max_dia > 0 ? max(4, round(($qtd / $max_dia) * 56)) : 4;
        $cur->modify('+1 day');
      ?>
        <div class="sparkline-bar"
             style="height:<?= $h ?>px"
             data-tip="<?= date('d/m', strtotime($key)) ?>: <?= $qtd ?> sol.">
        </div>
      <?php endwhile; ?>
    </div>
    <div style="display:flex;justify-content:space-between;margin-top:6px;font-family:var(--mono);font-size:10px;color:var(--text-muted)">
      <span><?= date('d/m', strtotime($data_ini)) ?></span>
      <span><?= date('d/m', strtotime($data_fim)) ?></span>
    </div>
  </div>
  <?php endif; ?>

  <div class="grid-2 fade-up-3" style="align-items:start;gap:24px;margin-bottom:24px">

    <!-- ======================== TOP MATERIAIS ======================== -->
    <div class="card">
      <div class="card-title">Materiais Mais Solicitados</div>

      <?php if (empty($top_materiais)): ?>
        <p class="text-muted" style="font-size:13px;text-align:center;padding:20px 0">
          Nenhuma movimentação no período.
        </p>
      <?php else: ?>
        <div style="display:flex;flex-direction:column;gap:14px">
          <?php foreach ($top_materiais as $i => $m):
            $pct = $max_sol > 0 ? ($m['total_solicitado'] / $max_sol) * 100 : 0;
            $cor_est = $m['qtd_disponivel'] == 0 ? 'var(--danger)' : ($m['qtd_disponivel'] <= 3 ? 'var(--warning)' : 'var(--success)');
          ?>
          <div>
            <div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:5px;gap:8px">
              <div style="display:flex;align-items:center;gap:8px;min-width:0">
                <span class="mono" style="font-size:10px;color:var(--text-muted);flex-shrink:0"><?= $i + 1 ?>.</span>
                <span style="font-size:13px;font-weight:600;overflow:hidden;text-overflow:ellipsis;white-space:nowrap" title="<?= htmlspecialchars($m['nome']) ?>">
                  <?= htmlspecialchars($m['nome']) ?>
                </span>
              </div>
              <div style="display:flex;align-items:center;gap:8px;flex-shrink:0">
                <span class="mono" style="font-size:12px;font-weight:700;color:var(--accent)"><?= (int)$m['total_solicitado'] ?> <?= htmlspecialchars($m['unidade']) ?></span>
                <span style="font-size:10px;color:<?= $cor_est ?>;font-family:var(--mono)">(<?= (int)$m['qtd_disponivel'] ?> disp.)</span>
              </div>
            </div>
            <div style="display:flex;align-items:center;gap:8px">
              <div class="progress-bar-wrap">
                <div class="progress-bar-fill" style="width:<?= round($pct) ?>%"></div>
              </div>
              <span class="text-muted mono" style="font-size:10px;flex-shrink:0"><?= (int)$m['num_solicitacoes'] ?> sol.</span>
            </div>
          </div>
          <?php endforeach; ?>
        </div>
      <?php endif; ?>
    </div>

    <!-- ======================== TOP ALUNOS ======================== -->
    <div class="card">
      <div class="card-title">Alunos Mais Ativos</div>

      <?php if (empty($top_alunos)): ?>
        <p class="text-muted" style="font-size:13px;text-align:center;padding:20px 0">
          Nenhuma solicitação no período.
        </p>
      <?php else: ?>
        <div class="table-wrap">
          <table>
            <thead>
              <tr>
                <th>#</th>
                <th>Aluno</th>
                <th style="text-align:center">Sol.</th>
                <th style="text-align:center">Dev.</th>
                <th style="text-align:center">Em uso</th>
              </tr>
            </thead>
            <tbody>
              <?php foreach ($top_alunos as $i => $a): ?>
              <tr>
                <td class="mono" style="font-size:11px;color:var(--text-muted)"><?= $i + 1 ?></td>
                <td>
                  <span style="font-size:13px;font-weight:600"><?= htmlspecialchars($a['nome']) ?></span><br>
                  <span class="text-muted mono" style="font-size:10px"><?= htmlspecialchars($a['email']) ?></span>
                </td>
                <td class="mono" style="text-align:center;font-weight:700"><?= (int)$a['total_sol'] ?></td>
                <td class="mono" style="text-align:center;color:var(--success)"><?= (int)$a['devolvidas'] ?></td>
                <td class="mono" style="text-align:center;color:<?= $a['em_uso'] > 0 ? 'var(--accent)' : 'var(--text-muted)' ?>">
                  <?= (int)$a['em_uso'] ?>
                </td>
              </tr>
              <?php endforeach; ?>
            </tbody>
          </table>
        </div>
      <?php endif; ?>
    </div>

  </div>

  <!-- ======================== HISTÓRICO COMPLETO ======================== -->
  <div class="card fade-up-3 print-break">
    <div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:16px">
      <div class="card-title" style="margin-bottom:0">Histórico de Movimentações</div>
      <span class="text-muted mono" style="font-size:11px"><?= count($historico) ?> registro(s)</span>
    </div>

    <?php if (empty($historico)): ?>
      <p class="text-muted" style="font-size:13px;text-align:center;padding:20px 0">
        Nenhuma movimentação concluída no período selecionado.
      </p>
    <?php else: ?>
      <div class="table-wrap">
        <table>
          <thead>
            <tr>
              <th>Nº</th>
              <th>Aluno</th>
              <th>Materiais</th>
              <th style="text-align:center">Itens</th>
              <th style="text-align:center">Urg.</th>
              <th>Solicitado</th>
              <th>Retirado</th>
              <th>Devolvido</th>
              <th>Status</th>
              <th>Obs. Conferência</th>
            </tr>
          </thead>
          <tbody>
            <?php foreach ($historico as $h): ?>
            <tr>
              <td class="mono" style="font-size:11px">#<?= str_pad($h['id'], 4, '0', STR_PAD_LEFT) ?></td>
              <td style="white-space:nowrap">
                <span style="font-size:12px;font-weight:600"><?= htmlspecialchars($h['aluno_nome']) ?></span>
              </td>
              <td style="font-size:11px;max-width:180px;overflow:hidden;text-overflow:ellipsis;white-space:nowrap"
                  title="<?= htmlspecialchars($h['itens_resumo'] ?? '') ?>">
                <?= htmlspecialchars($h['itens_resumo'] ?? '—') ?>
              </td>
              <td class="mono" style="text-align:center"><?= (int)$h['qtd_itens'] ?></td>
              <td style="text-align:center"><span class="badge badge-<?= $h['urgencia'] ?>"><?= ucfirst($h['urgencia']) ?></span></td>
              <td style="font-size:11px;white-space:nowrap"><?= date('d/m/Y', strtotime($h['criado_em'])) ?></td>
              <td style="font-size:11px;white-space:nowrap">
                <?= $h['data_retirada'] ? date('d/m/Y H:i', strtotime($h['data_retirada'])) : '—' ?>
              </td>
              <td style="font-size:11px;white-space:nowrap">
                <?= $h['data_devolucao'] ? date('d/m/Y H:i', strtotime($h['data_devolucao'])) : '<span style="color:var(--warning)">Pendente</span>' ?>
              </td>
              <td><span class="badge badge-<?= $h['status'] ?>"><?= ucfirst($h['status']) ?></span></td>
              <td style="font-size:11px;color:var(--text-muted);max-width:140px;overflow:hidden;text-overflow:ellipsis;white-space:nowrap"
                  title="<?= htmlspecialchars($h['obs_conferencia'] ?? '') ?>">
                <?= htmlspecialchars($h['obs_conferencia'] ?? '—') ?>
              </td>
            </tr>
            <?php endforeach; ?>
          </tbody>
        </table>
      </div>

      <!-- Rodapé de impressão -->
      <div style="margin-top:16px;padding-top:12px;border-top:1px solid var(--border);display:flex;justify-content:space-between;align-items:center;flex-wrap:wrap;gap:8px">
        <span class="text-muted mono" style="font-size:10px">
          ALMOX.SYS · UNIFAJ · Período: <?= date('d/m/Y', strtotime($data_ini)) ?> – <?= date('d/m/Y', strtotime($data_fim)) ?> · Gerado em: <?= date('d/m/Y H:i') ?>
        </span>
        <div style="display:flex;gap:8px" class="no-print">
          <button onclick="window.print()" class="btn btn-secondary btn-sm">🖨 Imprimir relatório</button>
        </div>
      </div>
    <?php endif; ?>
  </div>

</div>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
