<?php
session_start();
require_once __DIR__ . '/../db.php';
require_once __DIR__ . '/../includes/auth.php';
requer_admin();

$db   = get_db();
$msg  = '';
$erro = '';

// --- AÇÃO POST ---
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $sol_id = (int)($_POST['sol_id'] ?? 0);
    $obs    = trim($_POST['observacao'] ?? '');
    $uid    = $_SESSION['usuario_id'];

    $stmt = $db->prepare("SELECT * FROM solicitacoes WHERE id = ?");
    $stmt->execute([$sol_id]);
    $sol = $stmt->fetch();

    if (!$sol || $sol['status'] !== 'retirada') {
        $erro = 'Solicitação inválida ou não está com status "retirada".';
    } else {
        $itens = $db->prepare("SELECT * FROM itens_solicitacao WHERE solicitacao_id = ?");
        $itens->execute([$sol_id]);
        $itens = $itens->fetchAll();

        $db->beginTransaction();
        try {
            // Repõe estoque
            $upd = $db->prepare("
                UPDATE materiais
                SET qtd_disponivel = qtd_disponivel + ?
                WHERE id = ?
            ");
            foreach ($itens as $it) {
                $upd->execute([$it['qtd_solicitada'], $it['material_id']]);
            }

            // Garante que disponível não passa do total (segurança)
            $db->exec("UPDATE materiais SET qtd_disponivel = qtd_total WHERE qtd_disponivel > qtd_total");

            // Atualiza status
            $db->prepare("UPDATE solicitacoes SET status = 'devolvida' WHERE id = ?")
               ->execute([$sol_id]);

            // Registra movimentação
            $db->prepare("INSERT INTO movimentacoes (solicitacao_id, tipo, usuario_id, observacao) VALUES (?, 'devolucao', ?, ?)")
               ->execute([$sol_id, $uid, $obs ?: null]);

            $db->commit();
            $msg = "Devolução confirmada para solicitação #" . str_pad($sol_id, 4, '0', STR_PAD_LEFT) . ". Estoque reposto.";
        } catch (Exception $e) {
            $db->rollBack();
            $erro = 'Erro ao registrar devolução. Tente novamente.';
        }
    }
}

// --- CARREGA SOLICITAÇÕES RETIRADAS ---
$retiradas = $db->query("
    SELECT s.*, u.nome AS aluno_nome, u.email AS aluno_email,
           COUNT(DISTINCT i.id) AS qtd_itens,
           (SELECT criado_em FROM movimentacoes WHERE solicitacao_id = s.id AND tipo = 'retirada' ORDER BY id DESC LIMIT 1) AS data_retirada
    FROM solicitacoes s
    JOIN  usuarios u ON u.id = s.usuario_id
    LEFT JOIN itens_solicitacao i ON i.solicitacao_id = s.id
    WHERE s.status = 'retirada'
    GROUP BY s.id
    ORDER BY CASE s.urgencia WHEN 'alta' THEN 1 WHEN 'media' THEN 2 ELSE 3 END, s.criado_em ASC
")->fetchAll();

// Últimas 10 devoluções concluídas (histórico)
$historico = $db->query("
    SELECT s.*, u.nome AS aluno_nome,
           COUNT(DISTINCT i.id) AS qtd_itens,
           (SELECT criado_em FROM movimentacoes WHERE solicitacao_id = s.id AND tipo = 'devolucao' ORDER BY id DESC LIMIT 1) AS data_devolucao,
           (SELECT observacao FROM movimentacoes WHERE solicitacao_id = s.id AND tipo = 'devolucao' ORDER BY id DESC LIMIT 1) AS obs_devolucao
    FROM solicitacoes s
    JOIN  usuarios u ON u.id = s.usuario_id
    LEFT JOIN itens_solicitacao i ON i.solicitacao_id = s.id
    WHERE s.status = 'devolvida'
    GROUP BY s.id
    ORDER BY s.id DESC
    LIMIT 10
")->fetchAll();

// Itens das retiradas (sem N+1)
$itens_map = [];
if ($retiradas) {
    $ids = array_column($retiradas, 'id');
    $ph  = implode(',', array_fill(0, count($ids), '?'));
    $raw = $db->prepare("
        SELECT i.*, m.nome AS mat_nome, m.codigo, m.unidade
        FROM itens_solicitacao i
        JOIN materiais m ON m.id = i.material_id
        WHERE i.solicitacao_id IN ($ph)
    ");
    $raw->execute($ids);
    foreach ($raw->fetchAll() as $item) {
        $itens_map[$item['solicitacao_id']][] = $item;
    }
}

$page_title = 'Devolução — ALMOX.SYS';
require_once __DIR__ . '/../includes/header.php';
?>

<div class="wrapper">

  <div class="page-header fade-up">
    <div class="breadcrumb">Admin <span class="sep">/</span> <a href="/admin/dashboard.php">Dashboard</a> <span class="sep">/</span> Devolução</div>
    <h1 class="page-title">Devolução e <span>Conferência</span></h1>
    <p class="page-subtitle">Confirme o retorno dos materiais e registre a conferência. O estoque é reposto automaticamente.</p>
  </div>

  <?php if ($msg):  ?><div class="alert alert-success fade-up"><?= htmlspecialchars($msg) ?></div><?php endif; ?>
  <?php if ($erro): ?><div class="alert alert-error fade-up"><?= htmlspecialchars($erro) ?></div><?php endif; ?>

  <div class="grid-2 fade-up-1" style="align-items:start;gap:24px">

    <!-- COLUNA ESQUERDA: Pendentes de devolução -->
    <div>
      <div style="display:flex;align-items:center;gap:12px;margin-bottom:14px">
        <h2 style="font-size:16px;font-weight:700">Pendentes de Devolução</h2>
        <span style="background:rgba(180,100,255,.12);color:#c48fff;border:1px solid #c48fff;border-radius:20px;font-family:var(--mono);font-size:10px;font-weight:700;padding:2px 10px">
          <?= count($retiradas) ?>
        </span>
      </div>

      <?php if (empty($retiradas)): ?>
        <div class="card" style="text-align:center;padding:28px">
          <p class="text-muted" style="font-size:13px">Nenhum material pendente de devolução. ✓</p>
        </div>
      <?php else: ?>
        <div style="display:flex;flex-direction:column;gap:12px">
          <?php foreach ($retiradas as $sol):
            $itens = $itens_map[$sol['id']] ?? [];
            // Calcula dias desde retirada
            $data_ret = $sol['data_retirada'] ?? $sol['criado_em'];
            $dias = (int)floor((time() - strtotime($data_ret)) / 86400);
            $alerta_dias = $dias >= 3;
          ?>
          <div class="card" style="padding:0;overflow:hidden;<?= $alerta_dias ? 'border-color:var(--danger)' : 'border-color:rgba(180,100,255,.3)' ?>">

            <!-- Header -->
            <div style="display:flex;align-items:center;justify-content:space-between;padding:14px 20px;gap:12px;flex-wrap:wrap;cursor:pointer"
                 onclick="toggleSol(<?= $sol['id'] ?>)">
              <div style="display:flex;align-items:center;gap:10px;flex-wrap:wrap">
                <span class="mono" style="font-size:13px;font-weight:700">#<?= str_pad($sol['id'], 4, '0', STR_PAD_LEFT) ?></span>
                <span class="badge badge-<?= $sol['urgencia'] ?>"><?= ucfirst($sol['urgencia']) ?></span>
                <span class="badge badge-retirada">Retirada</span>
                <?php if ($alerta_dias): ?>
                  <span class="badge" style="background:var(--danger-dim);color:var(--danger);border:1px solid var(--danger)">
                    ⚠ <?= $dias ?>d sem devolver
                  </span>
                <?php endif; ?>
                <span style="font-size:13px;font-weight:600"><?= htmlspecialchars($sol['aluno_nome']) ?></span>
              </div>
              <div style="display:flex;align-items:center;gap:8px">
                <?php if ($data_ret): ?>
                  <span class="text-muted" style="font-size:11px">Retirado: <?= date('d/m H:i', strtotime($data_ret)) ?></span>
                <?php endif; ?>
                <span id="chevron-<?= $sol['id'] ?>" style="color:var(--text-muted)">▼</span>
              </div>
            </div>

            <!-- Detalhe -->
            <div id="detalhe-<?= $sol['id'] ?>" style="display:none;border-top:1px solid var(--border)">
              <div style="padding:20px">

                <!-- Itens -->
                <p style="font-size:10px;font-family:var(--mono);color:var(--text-muted);text-transform:uppercase;letter-spacing:.1em;margin-bottom:10px">Itens a Devolver</p>
                <div class="table-wrap" style="margin-bottom:16px">
                  <table>
                    <thead>
                      <tr>
                        <th>Material</th>
                        <th>Cód.</th>
                        <th style="text-align:center">Qtd.</th>
                        <th>Un.</th>
                      </tr>
                    </thead>
                    <tbody>
                      <?php foreach ($itens as $it): ?>
                      <tr>
                        <td style="font-size:13px"><?= htmlspecialchars($it['mat_nome']) ?></td>
                        <td class="mono" style="font-size:11px;color:var(--text-muted)"><?= htmlspecialchars($it['codigo']) ?></td>
                        <td style="text-align:center;font-weight:700"><?= (int)$it['qtd_solicitada'] ?></td>
                        <td class="mono" style="font-size:11px"><?= htmlspecialchars($it['unidade']) ?></td>
                      </tr>
                      <?php endforeach; ?>
                    </tbody>
                  </table>
                </div>

                <!-- Formulário de conferência -->
                <div style="background:var(--success-dim);border:1px solid var(--success);border-radius:var(--radius-sm);padding:12px;margin-bottom:14px">
                  <p style="font-size:12px;color:var(--success);font-weight:600">
                    ✓ Confira se todos os materiais foram devolvidos em bom estado antes de confirmar.
                  </p>
                </div>

                <form method="POST" action="/admin/devolucao.php"
                      onsubmit="return confirm('Confirmar devolução de <?= htmlspecialchars(addslashes($sol['aluno_nome'])) ?>? O estoque será reposto.')">
                  <input type="hidden" name="sol_id" value="<?= $sol['id'] ?>" />
                  <div class="field">
                    <label class="field-label">Observação da conferência (opcional)</label>
                    <textarea name="observacao" placeholder="Ex: Todos os itens devolvidos em bom estado. Sem avarias..." style="min-height:70px"></textarea>
                  </div>
                  <button type="submit" class="btn btn-success w-full" style="justify-content:center">
                    ✓ Confirmar Devolução e Repor Estoque
                  </button>
                </form>

              </div>
            </div>

          </div>
          <?php endforeach; ?>
        </div>
      <?php endif; ?>
    </div>

    <!-- COLUNA DIREITA: Histórico recente -->
    <div>
      <div style="display:flex;align-items:center;gap:12px;margin-bottom:14px">
        <h2 style="font-size:16px;font-weight:700">Histórico de Devoluções</h2>
        <span style="font-size:11px;color:var(--text-muted)">(últimas 10)</span>
      </div>

      <?php if (empty($historico)): ?>
        <div class="card" style="text-align:center;padding:28px">
          <p class="text-muted" style="font-size:13px">Nenhuma devolução registrada ainda.</p>
        </div>
      <?php else: ?>
        <div class="card" style="padding:0;overflow:hidden">
          <div class="table-wrap" style="border:none;border-radius:0">
            <table>
              <thead>
                <tr>
                  <th>Nº</th>
                  <th>Aluno</th>
                  <th>Itens</th>
                  <th>Devolvido em</th>
                  <th>Obs.</th>
                </tr>
              </thead>
              <tbody>
                <?php foreach ($historico as $h): ?>
                <tr>
                  <td class="mono" style="font-size:11px">#<?= str_pad($h['id'], 4, '0', STR_PAD_LEFT) ?></td>
                  <td style="font-size:13px"><?= htmlspecialchars($h['aluno_nome']) ?></td>
                  <td class="mono" style="text-align:center"><?= (int)$h['qtd_itens'] ?></td>
                  <td style="font-size:12px;white-space:nowrap">
                    <?= $h['data_devolucao'] ? date('d/m/Y H:i', strtotime($h['data_devolucao'])) : '—' ?>
                  </td>
                  <td style="font-size:11px;color:var(--text-muted);max-width:150px;overflow:hidden;text-overflow:ellipsis;white-space:nowrap"
                      title="<?= htmlspecialchars($h['obs_devolucao'] ?? '') ?>">
                    <?= htmlspecialchars(mb_substr($h['obs_devolucao'] ?? '—', 0, 30)) ?>
                  </td>
                </tr>
                <?php endforeach; ?>
              </tbody>
            </table>
          </div>
        </div>
        <div style="text-align:right;margin-top:10px">
          <a href="/admin/relatorios.php" class="btn btn-ghost btn-sm">Ver relatório completo →</a>
        </div>
      <?php endif; ?>

    </div>

  </div>

</div>

<script>
function toggleSol(id) {
  const det = document.getElementById('detalhe-' + id);
  const ch  = document.getElementById('chevron-' + id);
  const aberto = det.style.display !== 'none';
  det.style.display = aberto ? 'none' : 'block';
  ch.textContent   = aberto ? '▼' : '▲';
}
</script>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
