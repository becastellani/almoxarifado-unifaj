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
    $acao   = $_POST['acao']   ?? '';
    $sol_id = (int)($_POST['sol_id'] ?? 0);
    $obs    = trim($_POST['observacao'] ?? '');
    $uid    = $_SESSION['usuario_id'];

    $stmt = $db->prepare("SELECT * FROM solicitacoes WHERE id = ?");
    $stmt->execute([$sol_id]);
    $sol = $stmt->fetch();

    if (!$sol) {
        $erro = 'Solicitação não encontrada.';

    } elseif ($acao === 'separar' && $sol['status'] === 'aprovada') {
        $db->prepare("UPDATE solicitacoes SET status = 'separada' WHERE id = ?")
           ->execute([$sol_id]);
        $db->prepare("INSERT INTO movimentacoes (solicitacao_id, tipo, usuario_id, observacao) VALUES (?, 'separacao', ?, ?)")
           ->execute([$sol_id, $uid, $obs ?: null]);
        $msg = "Solicitação #" . str_pad($sol_id, 4, '0', STR_PAD_LEFT) . " marcada como separada.";

    } elseif ($acao === 'retirar' && $sol['status'] === 'separada') {
        // Busca itens
        $itens = $db->prepare("SELECT * FROM itens_solicitacao WHERE solicitacao_id = ?");
        $itens->execute([$sol_id]);
        $itens = $itens->fetchAll();

        // Valida estoque antes de baixar
        $erros_estoque = [];
        foreach ($itens as $it) {
            $mat = $db->prepare("SELECT nome, qtd_disponivel FROM materiais WHERE id = ?");
            $mat->execute([$it['material_id']]);
            $mat = $mat->fetch();
            if ($mat && $mat['qtd_disponivel'] < $it['qtd_solicitada']) {
                $erros_estoque[] = "\"{$mat['nome']}\": disponível {$mat['qtd_disponivel']}, solicitado {$it['qtd_solicitada']}";
            }
        }

        if ($erros_estoque) {
            $erro = 'Estoque insuficiente para: ' . implode('; ', $erros_estoque);
        } else {
            $db->beginTransaction();
            try {
                // Baixa no estoque
                $upd = $db->prepare("UPDATE materiais SET qtd_disponivel = qtd_disponivel - ? WHERE id = ?");
                foreach ($itens as $it) {
                    $upd->execute([$it['qtd_solicitada'], $it['material_id']]);
                }
                // Atualiza status
                $db->prepare("UPDATE solicitacoes SET status = 'retirada' WHERE id = ?")
                   ->execute([$sol_id]);
                // Registra movimentação
                $db->prepare("INSERT INTO movimentacoes (solicitacao_id, tipo, usuario_id, observacao) VALUES (?, 'retirada', ?, ?)")
                   ->execute([$sol_id, $uid, $obs ?: null]);

                $db->commit();
                $msg = "Retirada confirmada para solicitação #" . str_pad($sol_id, 4, '0', STR_PAD_LEFT) . ". Estoque atualizado.";
            } catch (Exception $e) {
                $db->rollBack();
                $erro = 'Erro ao confirmar retirada. Tente novamente.';
            }
        }
    }
}

// --- CARREGA DADOS ---
// Aprovadas (aguardando separação)
$aprovadas = $db->query("
    SELECT s.*, u.nome AS aluno_nome, u.email AS aluno_email,
           COUNT(DISTINCT i.id) AS qtd_itens
    FROM solicitacoes s
    JOIN  usuarios u ON u.id = s.usuario_id
    LEFT JOIN itens_solicitacao i ON i.solicitacao_id = s.id
    WHERE s.status = 'aprovada'
    GROUP BY s.id
    ORDER BY CASE s.urgencia WHEN 'alta' THEN 1 WHEN 'media' THEN 2 ELSE 3 END, s.criado_em ASC
")->fetchAll();

// Separadas (aguardando retirada do aluno)
$separadas = $db->query("
    SELECT s.*, u.nome AS aluno_nome, u.email AS aluno_email,
           COUNT(DISTINCT i.id) AS qtd_itens
    FROM solicitacoes s
    JOIN  usuarios u ON u.id = s.usuario_id
    LEFT JOIN itens_solicitacao i ON i.solicitacao_id = s.id
    WHERE s.status = 'separada'
    GROUP BY s.id
    ORDER BY CASE s.urgencia WHEN 'alta' THEN 1 WHEN 'media' THEN 2 ELSE 3 END, s.criado_em ASC
")->fetchAll();

// Itens de todas as solicitações visíveis (sem N+1)
$todos_ids = array_merge(
    array_column($aprovadas, 'id'),
    array_column($separadas, 'id')
);
$itens_map = [];
if ($todos_ids) {
    $ph  = implode(',', array_fill(0, count($todos_ids), '?'));
    $raw = $db->prepare("
        SELECT i.*, m.nome AS mat_nome, m.codigo, m.unidade, m.qtd_disponivel
        FROM itens_solicitacao i
        JOIN materiais m ON m.id = i.material_id
        WHERE i.solicitacao_id IN ($ph)
    ");
    $raw->execute($todos_ids);
    foreach ($raw->fetchAll() as $item) {
        $itens_map[$item['solicitacao_id']][] = $item;
    }
}

$page_title = 'Separação / Retirada — ALMOX.SYS';
require_once __DIR__ . '/../includes/header.php';
?>

<div class="wrapper">

  <div class="page-header fade-up">
    <div class="breadcrumb">Admin <span class="sep">/</span> <a href="/admin/dashboard.php">Dashboard</a> <span class="sep">/</span> Separação</div>
    <h1 class="page-title">Separação e <span>Retirada</span></h1>
    <p class="page-subtitle">Gerencie a separação dos materiais e confirme a retirada pelo aluno. A baixa no estoque ocorre na confirmação da retirada.</p>
  </div>

  <?php if ($msg):  ?><div class="alert alert-success fade-up"><?= htmlspecialchars($msg) ?></div><?php endif; ?>
  <?php if ($erro): ?><div class="alert alert-error fade-up"><?= htmlspecialchars($erro) ?></div><?php endif; ?>

  <!-- SEÇÃO: Aguardando separação -->
  <div class="fade-up-1" style="margin-bottom:32px">
    <div style="display:flex;align-items:center;gap:12px;margin-bottom:14px">
      <h2 style="font-size:16px;font-weight:700">Aguardando Separação</h2>
      <span style="background:var(--accent-dim);color:var(--accent);border:1px solid var(--accent);border-radius:20px;font-family:var(--mono);font-size:10px;font-weight:700;padding:2px 10px">
        <?= count($aprovadas) ?>
      </span>
    </div>

    <?php if (empty($aprovadas)): ?>
      <div class="card" style="text-align:center;padding:28px">
        <p class="text-muted" style="font-size:13px">Nenhuma solicitação aguardando separação.</p>
      </div>
    <?php else: ?>
      <div style="display:flex;flex-direction:column;gap:12px">
        <?php foreach ($aprovadas as $sol):
          $itens = $itens_map[$sol['id']] ?? [];
        ?>
        <div class="card" style="padding:0;overflow:hidden">
          <!-- Header -->
          <div style="display:flex;align-items:center;justify-content:space-between;padding:14px 20px;gap:12px;cursor:pointer;flex-wrap:wrap"
               onclick="toggleSol('apr-<?= $sol['id'] ?>')">
            <div style="display:flex;align-items:center;gap:12px;flex-wrap:wrap">
              <span class="mono" style="font-size:13px;font-weight:700">#<?= str_pad($sol['id'], 4, '0', STR_PAD_LEFT) ?></span>
              <span class="badge badge-<?= $sol['urgencia'] ?>"><?= ucfirst($sol['urgencia']) ?></span>
              <span class="badge badge-aprovada">Aprovada</span>
              <span style="font-size:13px;font-weight:600"><?= htmlspecialchars($sol['aluno_nome']) ?></span>
              <span class="text-muted" style="font-size:12px"><?= (int)$sol['qtd_itens'] ?> item(ns)</span>
            </div>
            <div style="display:flex;align-items:center;gap:10px">
              <span class="text-muted" style="font-size:12px"><?= date('d/m/Y H:i', strtotime($sol['criado_em'])) ?></span>
              <span id="chevron-apr-<?= $sol['id'] ?>" style="color:var(--text-muted)">▼</span>
            </div>
          </div>

          <!-- Detalhe -->
          <div id="detalhe-apr-<?= $sol['id'] ?>" style="display:none;border-top:1px solid var(--border)">
            <div style="padding:20px;display:grid;grid-template-columns:1fr auto;gap:20px;align-items:start">

              <!-- Itens -->
              <div>
                <p style="font-size:10px;font-family:var(--mono);color:var(--text-muted);text-transform:uppercase;letter-spacing:.1em;margin-bottom:10px">Itens para Separar</p>
                <div class="table-wrap">
                  <table>
                    <thead>
                      <tr>
                        <th>Material</th>
                        <th>Cód.</th>
                        <th style="text-align:center">Qtd. Solicitada</th>
                        <th style="text-align:center">Estoque Atual</th>
                      </tr>
                    </thead>
                    <tbody>
                      <?php foreach ($itens as $it):
                        $sem = $it['qtd_disponivel'] < $it['qtd_solicitada'];
                      ?>
                      <tr <?= $sem ? 'style="background:var(--danger-dim)"' : '' ?>>
                        <td style="font-size:13px"><?= htmlspecialchars($it['mat_nome']) ?></td>
                        <td class="mono" style="font-size:11px;color:var(--text-muted)"><?= htmlspecialchars($it['codigo']) ?></td>
                        <td style="text-align:center;font-weight:700"><?= (int)$it['qtd_solicitada'] ?> <?= htmlspecialchars($it['unidade']) ?></td>
                        <td style="text-align:center;color:<?= $sem ? 'var(--danger)' : 'var(--success)' ?>;font-weight:600">
                          <?= (int)$it['qtd_disponivel'] ?> disp.
                          <?= $sem ? '<br><small style="font-size:10px">INSUFICIENTE</small>' : '' ?>
                        </td>
                      </tr>
                      <?php endforeach; ?>
                    </tbody>
                  </table>
                </div>

                <?php if ($sol['justificativa']): ?>
                <div style="margin-top:12px;background:var(--surface2);border:1px solid var(--border);border-radius:var(--radius-sm);padding:10px">
                  <p style="font-size:10px;color:var(--text-muted);font-family:var(--mono);text-transform:uppercase;margin-bottom:4px">Justificativa</p>
                  <p style="font-size:12px"><?= htmlspecialchars($sol['justificativa']) ?></p>
                </div>
                <?php endif; ?>
              </div>

              <!-- Ação -->
              <div style="min-width:220px">
                <form method="POST" action="/admin/separacao.php">
                  <input type="hidden" name="acao"   value="separar" />
                  <input type="hidden" name="sol_id" value="<?= $sol['id'] ?>" />
                  <div class="field">
                    <label class="field-label">Observação (opcional)</label>
                    <textarea name="observacao" placeholder="Ex: Separado na prateleira B3..." style="min-height:70px"></textarea>
                  </div>
                  <button type="submit" class="btn btn-primary w-full" style="justify-content:center">
                    ✓ Confirmar Separação
                  </button>
                </form>
                <?php if ($sol['local_entrega']): ?>
                <div style="margin-top:10px;padding:10px;background:var(--surface2);border:1px solid var(--border);border-radius:var(--radius-sm)">
                  <p style="font-size:10px;color:var(--text-muted);font-family:var(--mono);text-transform:uppercase;margin-bottom:4px">Entregar em</p>
                  <p style="font-size:12px"><?= htmlspecialchars($sol['local_entrega']) ?></p>
                </div>
                <?php endif; ?>
              </div>

            </div>
          </div>
        </div>
        <?php endforeach; ?>
      </div>
    <?php endif; ?>
  </div>

  <!-- SEÇÃO: Aguardando retirada -->
  <div class="fade-up-2">
    <div style="display:flex;align-items:center;gap:12px;margin-bottom:14px">
      <h2 style="font-size:16px;font-weight:700">Separados — Aguardando Retirada</h2>
      <span style="background:rgba(100,120,255,.12);color:#8b9fff;border:1px solid #8b9fff;border-radius:20px;font-family:var(--mono);font-size:10px;font-weight:700;padding:2px 10px">
        <?= count($separadas) ?>
      </span>
    </div>

    <?php if (empty($separadas)): ?>
      <div class="card" style="text-align:center;padding:28px">
        <p class="text-muted" style="font-size:13px">Nenhum material aguardando retirada.</p>
      </div>
    <?php else: ?>
      <div style="display:flex;flex-direction:column;gap:12px">
        <?php foreach ($separadas as $sol):
          $itens = $itens_map[$sol['id']] ?? [];
        ?>
        <div class="card" style="padding:0;overflow:hidden;border-color:rgba(100,120,255,.3)">
          <!-- Header -->
          <div style="display:flex;align-items:center;justify-content:space-between;padding:14px 20px;gap:12px;cursor:pointer;flex-wrap:wrap"
               onclick="toggleSol('sep-<?= $sol['id'] ?>')">
            <div style="display:flex;align-items:center;gap:12px;flex-wrap:wrap">
              <span class="mono" style="font-size:13px;font-weight:700">#<?= str_pad($sol['id'], 4, '0', STR_PAD_LEFT) ?></span>
              <span class="badge badge-<?= $sol['urgencia'] ?>"><?= ucfirst($sol['urgencia']) ?></span>
              <span class="badge badge-separada">Separada</span>
              <span style="font-size:13px;font-weight:600"><?= htmlspecialchars($sol['aluno_nome']) ?></span>
              <span class="text-muted" style="font-size:12px"><?= (int)$sol['qtd_itens'] ?> item(ns)</span>
            </div>
            <div style="display:flex;align-items:center;gap:10px">
              <span class="text-muted" style="font-size:12px"><?= date('d/m/Y H:i', strtotime($sol['criado_em'])) ?></span>
              <span id="chevron-sep-<?= $sol['id'] ?>" style="color:var(--text-muted)">▼</span>
            </div>
          </div>

          <!-- Detalhe -->
          <div id="detalhe-sep-<?= $sol['id'] ?>" style="display:none;border-top:1px solid var(--border)">
            <div style="padding:20px;display:grid;grid-template-columns:1fr auto;gap:20px;align-items:start">

              <!-- Itens -->
              <div>
                <p style="font-size:10px;font-family:var(--mono);color:var(--text-muted);text-transform:uppercase;letter-spacing:.1em;margin-bottom:10px">Itens a Retirar</p>
                <div class="table-wrap">
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
              </div>

              <!-- Ação retirada -->
              <div style="min-width:220px">
                <div style="background:var(--accent-dim);border:1px solid var(--accent);border-radius:var(--radius-sm);padding:12px;margin-bottom:14px">
                  <p style="font-size:12px;color:var(--accent);font-weight:600">
                    ⚠ Confirme a identidade do aluno antes de liberar os materiais.
                  </p>
                  <p style="font-size:12px;margin-top:6px">
                    <strong><?= htmlspecialchars($sol['aluno_nome']) ?></strong><br>
                    <span class="text-muted mono" style="font-size:11px"><?= htmlspecialchars($sol['aluno_email']) ?></span>
                  </p>
                </div>
                <form method="POST" action="/admin/separacao.php"
                      onsubmit="return confirm('Confirmar retirada por <?= htmlspecialchars(addslashes($sol['aluno_nome'])) ?>? O estoque será atualizado.')">
                  <input type="hidden" name="acao"   value="retirar" />
                  <input type="hidden" name="sol_id" value="<?= $sol['id'] ?>" />
                  <div class="field">
                    <label class="field-label">Observação (opcional)</label>
                    <textarea name="observacao" placeholder="Ex: Retirado com assinatura..." style="min-height:60px"></textarea>
                  </div>
                  <button type="submit" class="btn btn-primary w-full" style="justify-content:center">
                    ✓ Confirmar Retirada
                  </button>
                </form>
              </div>

            </div>
          </div>
        </div>
        <?php endforeach; ?>
      </div>
    <?php endif; ?>
  </div>

</div>

<script>
function toggleSol(key) {
  const det = document.getElementById('detalhe-' + key);
  const ch  = document.getElementById('chevron-' + key);
  const aberto = det.style.display !== 'none';
  det.style.display = aberto ? 'none' : 'block';
  ch.textContent   = aberto ? '▼' : '▲';
}
</script>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
