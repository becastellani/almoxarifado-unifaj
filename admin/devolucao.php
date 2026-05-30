<?php
require_once __DIR__ . '/../includes/session.php';
require_once __DIR__ . '/../db.php';
require_once __DIR__ . '/../includes/auth.php';
requer_admin();

$db   = get_db();
$msg  = isset($_GET['msg']) && $_GET['msg'] === 'quitada' ? 'Cobrança marcada como quitada.' : '';
$erro = '';

// --- AÇÃO POST ---
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verificar_csrf();
    // Quitar cobrança
    if (($_POST['acao'] ?? '') === 'quitar_cobranca') {
        $cob_id = (int)($_POST['cob_id'] ?? 0);
        $obs_c  = trim($_POST['obs_cobranca'] ?? '');
        $db->prepare("UPDATE cobrancas SET status = 'quitada', observacao = ? WHERE id = ? AND status = 'pendente'")
           ->execute([$obs_c ?: null, $cob_id]);
        $msg = 'Cobrança marcada como quitada.';
        // Redireciona para limpar o POST
        header('Location: /admin/devolucao.php?msg=quitada');
        exit;
    }

    $sol_id = (int)($_POST['sol_id'] ?? 0);
    $obs    = trim($_POST['observacao'] ?? '');
    $uid    = $_SESSION['usuario_id'];

    $stmt = $db->prepare("SELECT * FROM solicitacoes WHERE id = ?");
    $stmt->execute([$sol_id]);
    $sol = $stmt->fetch();

    if (!$sol || $sol['status'] !== 'retirada') {
        $erro = 'Solicitação inválida ou não está com status "retirada".';
    } else {
        // Upload de foto (opcional)
        $foto_path = null;
        if (!empty($_FILES['foto']['tmp_name'])) {
            $ext     = strtolower(pathinfo($_FILES['foto']['name'], PATHINFO_EXTENSION));
            $exts_ok = ['jpg','jpeg','png','webp','gif'];
            if (in_array($ext, $exts_ok) && $_FILES['foto']['size'] <= 5 * 1024 * 1024) {
                $dir = getenv('STORAGE_PATH') ? getenv('STORAGE_PATH') . '/uploads/devolucao/' : __DIR__ . '/../uploads/devolucao/';
                if (!is_dir($dir)) mkdir($dir, 0755, true);
                $fname = 'dev_' . $sol_id . '_' . time() . '.' . $ext;
                if (move_uploaded_file($_FILES['foto']['tmp_name'], $dir . $fname)) {
                    $foto_path = '/uploads/devolucao/' . $fname;
                }
            } else {
                $erro = 'Foto inválida. Use JPG, PNG ou WebP (máx. 5 MB).';
            }
        }

        if (!$erro) {
            // Carrega itens aprovados ainda pendentes de devolução
            $itens_stmt = $db->prepare("
                SELECT i.*, m.nome AS mat_nome, m.consumivel
                FROM itens_solicitacao i
                JOIN materiais m ON m.id = i.material_id
                WHERE i.solicitacao_id = ?
                AND (i.status_item IS NULL OR i.status_item = 'aprovado')
            ");
            $itens_stmt->execute([$sol_id]);
            $itens = $itens_stmt->fetchAll();

            $baixas   = $_POST['baixa_direta'] ?? [];   // [item_id => '1']
            $qtds_dev = $_POST['qtd_devolver']  ?? [];  // [item_id => qty]

            $db->beginTransaction();
            try {
                foreach ($itens as $it) {
                    $iid = (int)$it['id'];
                    if ($it['item_fechado'] || $it['qtd_devolvida'] >= $it['qtd_solicitada']) continue;

                    if (array_key_exists($iid, $baixas)) {
                        // Baixa direta: consumido, sem reposição de estoque
                        $db->prepare("UPDATE itens_solicitacao SET item_fechado = 1 WHERE id = ?")->execute([$iid]);
                    } elseif (isset($qtds_dev[$iid]) && (int)$qtds_dev[$iid] > 0) {
                        $restante = $it['qtd_solicitada'] - $it['qtd_devolvida'];
                        $qtd      = min((int)$qtds_dev[$iid], $restante);
                        if ($qtd > 0) {
                            $db->prepare("UPDATE materiais SET qtd_disponivel = qtd_disponivel + ? WHERE id = ?")
                               ->execute([$qtd, $it['material_id']]);
                            $db->prepare("UPDATE itens_solicitacao SET qtd_devolvida = qtd_devolvida + ? WHERE id = ?")
                               ->execute([$qtd, $iid]);
                        }
                    }
                }

                // Garante que disponível não passa do total
                $db->exec("UPDATE materiais SET qtd_disponivel = qtd_total WHERE qtd_disponivel > qtd_total");

                // Verifica se todos os itens aprovados estão concluídos
                $pendentes = $db->prepare("
                    SELECT COUNT(*) FROM itens_solicitacao
                    WHERE solicitacao_id = ?
                    AND (status_item IS NULL OR status_item = 'aprovado')
                    AND item_fechado = 0
                    AND qtd_devolvida < qtd_solicitada
                ");
                $pendentes->execute([$sol_id]);
                $tudo_pronto = ($pendentes->fetchColumn() == 0);

                if ($tudo_pronto) {
                    $db->prepare("UPDATE solicitacoes SET status = 'devolvida' WHERE id = ?")->execute([$sol_id]);
                    $db->prepare("UPDATE cobrancas SET status='quitada', observacao='Devolvido' WHERE solicitacao_id=? AND status='pendente'")->execute([$sol_id]);
                }

                // Registra movimentação
                $db->prepare("INSERT INTO movimentacoes (solicitacao_id, tipo, usuario_id, observacao, foto_path) VALUES (?, 'devolucao', ?, ?, ?)")
                   ->execute([$sol_id, $uid, $obs ?: null, $foto_path]);

                $db->commit();
                $n   = str_pad($sol_id, 4, '0', STR_PAD_LEFT);
                $msg = $tudo_pronto
                    ? "Devolução concluída para a solicitação #$n. Estoque reposto."
                    : "Devolução parcial registrada para #$n. Solicitação permanece em aberto.";
            } catch (Exception $e) {
                $db->rollBack();
                $erro = 'Erro ao registrar devolução. Tente novamente.';
            }
        }
    }
}

// --- GERA COBRANÇAS AUTOMÁTICAS ---
// Regra: solicitações com ferramentaria no status 'retirada' cujo horário de retirada
// foi hoje e já passaram das 22h → gera cobrança se ainda não existe.
$agora       = time();
$hora_atual  = (int)date('H');
$hoje        = date('Y-m-d');

if ($hora_atual >= 22) {
    // Busca retiradas com pelo menos uma ferramenta, retiradas hoje, sem cobrança
    $candidatas = $db->query("
        SELECT DISTINCT s.id, s.usuario_id,
               (SELECT criado_em FROM movimentacoes WHERE solicitacao_id = s.id AND tipo = 'retirada' ORDER BY id DESC LIMIT 1) AS data_retirada
        FROM solicitacoes s
        JOIN itens_solicitacao i ON i.solicitacao_id = s.id
        JOIN materiais m ON m.id = i.material_id
        WHERE s.status = 'retirada'
          AND m.tipo = 'ferramenta'
          AND NOT EXISTS (SELECT 1 FROM cobrancas c WHERE c.solicitacao_id = s.id)
    ")->fetchAll();

    $ins_cob = $db->prepare("INSERT INTO cobrancas (solicitacao_id, usuario_id) VALUES (?, ?)");
    foreach ($candidatas as $c) {
        // Só gera cobrança se a retirada foi hoje
        if ($c['data_retirada'] && date('Y-m-d', strtotime($c['data_retirada'])) === $hoje) {
            $ins_cob->execute([$c['id'], $c['usuario_id']]);
        }
    }
}

// --- CARREGA SOLICITAÇÕES RETIRADAS ---
$retiradas = $db->query("
    SELECT s.*, u.nome AS aluno_nome, u.email AS aluno_email,
           COUNT(DISTINCT i.id) AS qtd_itens,
           (SELECT criado_em FROM movimentacoes WHERE solicitacao_id = s.id AND tipo = 'retirada' ORDER BY id DESC LIMIT 1) AS data_retirada,
           EXISTS (SELECT 1 FROM cobrancas c WHERE c.solicitacao_id = s.id AND c.status = 'pendente') AS tem_cobranca,
           MAX(CASE WHEN m.tipo = 'ferramenta' THEN 1 ELSE 0 END) AS tem_ferramenta,
           EXISTS (SELECT 1 FROM movimentacoes mv WHERE mv.solicitacao_id = s.id AND mv.tipo = 'aviso_devolucao') AS aluno_avisou
    FROM solicitacoes s
    JOIN  usuarios u ON u.id = s.usuario_id
    LEFT JOIN itens_solicitacao i ON i.solicitacao_id = s.id
    LEFT JOIN materiais m ON m.id = i.material_id
    WHERE s.status = 'retirada'
    GROUP BY s.id
    ORDER BY CASE s.urgencia WHEN 'alta' THEN 1 WHEN 'media' THEN 2 ELSE 3 END, s.criado_em ASC
")->fetchAll();

// --- COBRANÇAS PENDENTES ---
$cobrancas_pendentes = $db->query("
    SELECT c.*, s.id AS sol_id, s.urgencia,
           u.nome AS aluno_nome, u.email AS aluno_email,
           (SELECT criado_em FROM movimentacoes WHERE solicitacao_id = s.id AND tipo = 'retirada' ORDER BY id DESC LIMIT 1) AS data_retirada
    FROM cobrancas c
    JOIN solicitacoes s ON s.id = c.solicitacao_id
    JOIN usuarios u ON u.id = c.usuario_id
    WHERE c.status = 'pendente'
    ORDER BY c.gerada_em DESC
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
        SELECT i.*, m.nome AS mat_nome, m.codigo, m.unidade, m.tipo AS mat_tipo, m.consumivel
        FROM itens_solicitacao i
        JOIN materiais m ON m.id = i.material_id
        WHERE i.solicitacao_id IN ($ph)
        AND (i.status_item IS NULL OR i.status_item = 'aprovado')
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
          <div class="card" style="padding:0;overflow:hidden;<?= $sol['tem_cobranca'] ? 'border-color:var(--danger)' : ($alerta_dias ? 'border-color:var(--danger)' : ($sol['tem_ferramenta'] ? 'border-color:var(--warning)' : 'border-color:rgba(180,100,255,.3)')) ?>">

            <!-- Header -->
            <div style="display:flex;align-items:center;justify-content:space-between;padding:14px 20px;gap:12px;flex-wrap:wrap;cursor:pointer"
                 onclick="toggleSol(<?= $sol['id'] ?>)">
              <div style="display:flex;align-items:center;gap:10px;flex-wrap:wrap">
                <span class="mono" style="font-size:13px;font-weight:700">#<?= str_pad($sol['id'], 4, '0', STR_PAD_LEFT) ?></span>
                <span class="badge badge-<?= $sol['urgencia'] ?>"><?= ucfirst($sol['urgencia']) ?></span>
                <span class="badge badge-retirada">Retirada</span>
                <?php if ($sol['tem_ferramenta']): ?>
                  <span style="font-size:10px;font-weight:700;padding:2px 7px;border-radius:4px;background:rgba(255,170,0,.12);color:var(--warning);border:1px solid var(--warning);white-space:nowrap">Ferramentaria</span>
                <?php endif; ?>
                <?php if ($sol['aluno_avisou']): ?>
                  <span class="badge" style="background:var(--success-dim);color:var(--success);border:1px solid var(--success)">
                    ✔ Aluno avisou devolução
                  </span>
                <?php endif; ?>
                <?php if ($sol['tem_cobranca']): ?>
                  <span class="badge" style="background:var(--danger-dim);color:var(--danger);border:1px solid var(--danger)">
                    ⚡ Cobrança gerada
                  </span>
                <?php elseif ($alerta_dias): ?>
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

                <!-- Itens com devolução parcial -->
                <p style="font-size:10px;font-family:var(--mono);color:var(--text-muted);text-transform:uppercase;letter-spacing:.1em;margin-bottom:10px">Itens — Informe quantidades a devolver</p>

                <form method="POST" action="/admin/devolucao.php" enctype="multipart/form-data">
                  <?= csrf_field() ?>
                  <input type="hidden" name="sol_id" value="<?= $sol['id'] ?>" />

                  <div class="table-wrap" style="margin-bottom:14px">
                    <table>
                      <thead>
                        <tr>
                          <th>Material</th>
                          <th>Tipo</th>
                          <th style="text-align:center">Retirado</th>
                          <th style="text-align:center">Já devolvido</th>
                          <th>Devolver agora / Baixa direta</th>
                        </tr>
                      </thead>
                      <tbody>
                        <?php foreach ($itens as $it):
                          $ferr       = ($it['mat_tipo'] ?? 'material') === 'ferramenta';
                          $consumivel = (int)($it['consumivel'] ?? 1);
                          $restante   = (int)$it['qtd_solicitada'] - (int)$it['qtd_devolvida'];
                          $concluido  = $it['item_fechado'] || $restante <= 0;
                        ?>
                        <tr style="<?= $concluido ? 'opacity:.55' : '' ?><?= $ferr && !$concluido ? ';background:rgba(255,170,0,.04)' : '' ?>">
                          <td style="font-size:13px"><?= htmlspecialchars($it['mat_nome']) ?></td>
                          <td>
                            <?php if ($ferr): ?>
                              <span style="font-size:10px;font-weight:700;padding:1px 6px;border-radius:4px;background:rgba(255,170,0,.12);color:var(--warning);border:1px solid var(--warning);white-space:nowrap">Ferramenta</span>
                            <?php else: ?>
                              <span style="font-size:10px;padding:1px 6px;border-radius:4px;background:var(--accent-dim);color:var(--accent);border:1px solid var(--accent)">Material</span>
                            <?php endif; ?>
                            <?php if ($consumivel): ?>
                              <br><span style="font-size:9px;padding:1px 5px;border-radius:3px;background:rgba(0,200,100,.1);color:var(--success);border:1px solid var(--success)">Consumível</span>
                            <?php endif; ?>
                          </td>
                          <td style="text-align:center;font-weight:700"><?= (int)$it['qtd_solicitada'] ?> <?= htmlspecialchars($it['unidade']) ?></td>
                          <td style="text-align:center;color:var(--success)"><?= (int)$it['qtd_devolvida'] ?></td>
                          <td>
                            <?php if ($concluido): ?>
                              <span style="font-size:11px;color:var(--success);font-weight:600">
                                <?= $it['item_fechado'] ? '✓ Baixa direta' : '✓ Devolvido' ?>
                              </span>
                            <?php elseif ($consumivel): ?>
                              <label style="display:flex;align-items:center;gap:6px;cursor:pointer;font-size:12px;margin-bottom:6px">
                                <input type="checkbox" name="baixa_direta[<?= $it['id'] ?>]" value="1"
                                       onchange="toggleQtdInput(this, 'qtd-<?= $it['id'] ?>')" />
                                Baixa direta (consumido)
                              </label>
                              <div id="qtd-<?= $it['id'] ?>" style="display:flex;align-items:center;gap:6px">
                                <input type="number" name="qtd_devolver[<?= $it['id'] ?>]"
                                       min="0" max="<?= $restante ?>" value="0"
                                       style="width:70px;padding:4px 8px;background:var(--surface2);border:1px solid var(--border);border-radius:4px;color:var(--text);font-size:12px" />
                                <span style="font-size:11px;color:var(--text-muted)">máx. <?= $restante ?></span>
                              </div>
                            <?php else: ?>
                              <div style="display:flex;align-items:center;gap:6px">
                                <input type="number" name="qtd_devolver[<?= $it['id'] ?>]"
                                       min="0" max="<?= $restante ?>" value="<?= $restante ?>"
                                       style="width:70px;padding:4px 8px;background:var(--surface2);border:1px solid var(--border);border-radius:4px;color:var(--text);font-size:12px" />
                                <span style="font-size:11px;color:var(--text-muted)">máx. <?= $restante ?></span>
                              </div>
                            <?php endif; ?>
                          </td>
                        </tr>
                        <?php endforeach; ?>
                      </tbody>
                    </table>
                  </div>

                  <div style="background:var(--success-dim);border:1px solid var(--success);border-radius:var(--radius-sm);padding:10px;margin-bottom:12px">
                    <p style="font-size:12px;color:var(--success);font-weight:600">
                      ✓ Devolução parcial é permitida. Informe 0 nos itens que ainda não foram devolvidos — a solicitação permanecerá em aberto.
                    </p>
                  </div>

                  <div class="field">
                    <label class="field-label">Observação da conferência (opcional)</label>
                    <textarea name="observacao" placeholder="Ex: 5 parafusos devolvidos, 3 consumidos no projeto..." style="min-height:60px"></textarea>
                  </div>
                  <div class="field">
                    <label class="field-label">Foto da conferência (opcional)</label>
                    <div style="border:1px dashed var(--border);border-radius:var(--radius-sm);padding:12px;background:var(--surface2)">
                      <input type="file" name="foto" accept="image/*" capture="environment"
                             style="font-size:12px;width:100%;background:none;border:none;padding:0;color:var(--text-muted)"
                             onchange="previewFoto(this, 'prev-<?= $sol['id'] ?>')" />
                      <img id="prev-<?= $sol['id'] ?>" src="" alt=""
                           style="display:none;max-width:100%;max-height:160px;margin-top:10px;border-radius:4px;object-fit:cover" />
                    </div>
                  </div>
                  <button type="submit" class="btn btn-success w-full" style="justify-content:center">
                    ✓ Registrar Devolução
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

  <!-- SEÇÃO: Cobranças Pendentes -->
  <?php if (!empty($cobrancas_pendentes)): ?>
  <div class="fade-up-2" style="margin-top:36px">
    <div style="display:flex;align-items:center;gap:12px;margin-bottom:14px">
      <h2 style="font-size:16px;font-weight:700">Cobranças Pendentes</h2>
      <span style="background:var(--danger-dim);color:var(--danger);border:1px solid var(--danger);border-radius:20px;font-family:var(--mono);font-size:10px;font-weight:700;padding:2px 10px">
        <?= count($cobrancas_pendentes) ?>
      </span>
      <span style="font-size:12px;color:var(--text-muted)">Ferramentas retiradas e não devolvidas até as 22h</span>
    </div>

    <div style="display:flex;flex-direction:column;gap:10px">
      <?php foreach ($cobrancas_pendentes as $cob): ?>
      <div class="card" style="border-color:var(--danger);padding:0;overflow:hidden">
        <div style="display:flex;align-items:center;justify-content:space-between;padding:14px 20px;gap:12px;flex-wrap:wrap">
          <div style="display:flex;align-items:center;gap:10px;flex-wrap:wrap">
            <span class="mono" style="font-size:13px;font-weight:700">#<?= str_pad($cob['sol_id'], 4, '0', STR_PAD_LEFT) ?></span>
            <span class="badge" style="background:var(--danger-dim);color:var(--danger);border:1px solid var(--danger)">⚡ Cobrança</span>
            <span class="badge badge-<?= $cob['urgencia'] ?>"><?= ucfirst($cob['urgencia']) ?></span>
            <span style="font-size:13px;font-weight:600"><?= htmlspecialchars($cob['aluno_nome']) ?></span>
            <span class="text-muted mono" style="font-size:11px"><?= htmlspecialchars($cob['aluno_email']) ?></span>
          </div>
          <div style="display:flex;align-items:center;gap:10px;flex-wrap:wrap">
            <span class="text-muted" style="font-size:11px">
              Retirado: <?= $cob['data_retirada'] ? date('d/m H:i', strtotime($cob['data_retirada'])) : '—' ?>
              · Cobrança gerada: <?= date('d/m H:i', strtotime($cob['gerada_em'])) ?>
            </span>
            <button type="button" class="btn btn-ghost btn-xs" onclick="toggleCob(<?= $cob['id'] ?>)">Quitar cobrança</button>
          </div>
        </div>
        <div id="cob-form-<?= $cob['id'] ?>" style="display:none;border-top:1px solid var(--border);padding:16px 20px;background:var(--surface2)">
          <form method="POST" action="/admin/devolucao.php">
            <?= csrf_field() ?>
            <input type="hidden" name="acao"   value="quitar_cobranca" />
            <input type="hidden" name="cob_id" value="<?= $cob['id'] ?>" />
            <div style="display:flex;gap:10px;align-items:flex-end;flex-wrap:wrap">
              <div class="field" style="flex:1;margin:0">
                <label class="field-label" style="margin-bottom:4px">Observação da quitação (opcional)</label>
                <input type="text" name="obs_cobranca" placeholder="Ex: Devolvido em atraso, sem avarias..." />
              </div>
              <button type="submit" class="btn btn-danger" style="flex-shrink:0">✓ Confirmar quitação</button>
            </div>
          </form>
        </div>
      </div>
      <?php endforeach; ?>
    </div>
  </div>
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

function toggleQtdInput(checkbox, divId) {
  const div = document.getElementById(divId);
  if (!div) return;
  if (checkbox.checked) {
    div.style.display = 'none';
    const inp = div.querySelector('input[type=number]');
    if (inp) inp.value = 0;
  } else {
    div.style.display = 'flex';
  }
}
function toggleCob(id) {
  const el = document.getElementById('cob-form-' + id);
  if (el) el.style.display = el.style.display === 'none' ? 'block' : 'none';
}
function previewFoto(input, imgId) {
  const img = document.getElementById(imgId);
  if (!img || !input.files || !input.files[0]) return;
  const reader = new FileReader();
  reader.onload = e => { img.src = e.target.result; img.style.display = 'block'; };
  reader.readAsDataURL(input.files[0]);
}
</script>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
