<?php
require_once __DIR__ . '/../includes/session.php';
require_once __DIR__ . '/../db.php';
require_once __DIR__ . '/../includes/auth.php';
requer_aluno();

$db  = get_db();
$uid = $_SESSION['usuario_id'];

// Busca materiais disponíveis para o select
$materiais = $db->query("SELECT id, codigo, nome, unidade, tipo, qtd_disponivel FROM materiais WHERE qtd_disponivel > 0 ORDER BY nome")->fetchAll();

$erro    = '';
$sucesso = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verificar_csrf();
    $urgencia            = in_array($_POST['urgencia'] ?? '', ['baixa', 'media', 'alta']) ? $_POST['urgencia'] : 'media';
    $justificativa       = trim($_POST['justificativa']       ?? '');
    $local_entrega       = trim($_POST['local_entrega']       ?? '');
    $data_necessaria     = $_POST['data_necessaria']          ?? null;
    $obs_requisitante    = trim($_POST['observacao_requisitante'] ?? '');
    $material_ids   = $_POST['material_id']     ?? [];
    $quantidades    = $_POST['quantidade']      ?? [];

    // Filtra itens válidos
    $itens_validos = [];
    foreach ($material_ids as $k => $mid) {
        $mid = (int)$mid;
        $qtd = (int)($quantidades[$k] ?? 0);
        if ($mid > 0 && $qtd > 0) {
            $itens_validos[] = ['material_id' => $mid, 'qtd' => $qtd];
        }
    }

    if (empty($itens_validos)) {
        $erro = 'Adicione pelo menos um item antes de enviar.';
    } else {
        // Valida estoque
        foreach ($itens_validos as $item) {
            $mat = $db->prepare("SELECT nome, qtd_disponivel FROM materiais WHERE id = ?");
            $mat->execute([$item['material_id']]);
            $mat = $mat->fetch();
            if (!$mat) { $erro = 'Material inválido selecionado.'; break; }
            if ($item['qtd'] > $mat['qtd_disponivel']) {
                $erro = "Quantidade solicitada de \"{$mat['nome']}\" excede o estoque disponível ({$mat['qtd_disponivel']}).";
                break;
            }
        }
    }

    if (!$erro) {
        $db->beginTransaction();
        try {
            $db->prepare("
                INSERT INTO solicitacoes (usuario_id, status, urgencia, justificativa, local_entrega, data_necessaria, observacao_requisitante)
                VALUES (?, 'pendente', ?, ?, ?, ?, ?)
            ")->execute([$uid, $urgencia, $justificativa, $local_entrega ?: null, $data_necessaria ?: null, $obs_requisitante ?: null]);

            $sol_id = $db->lastInsertId();

            $ins = $db->prepare("INSERT INTO itens_solicitacao (solicitacao_id, material_id, qtd_solicitada) VALUES (?, ?, ?)");
            foreach ($itens_validos as $item) {
                $ins->execute([$sol_id, $item['material_id'], $item['qtd']]);
            }

            $db->commit();
            $sucesso = $sol_id;
        } catch (Exception $e) {
            $db->rollBack();
            $erro = 'Erro ao registrar solicitação. Tente novamente.';
        }
    }
}

// Próximo número de solicitação
$prox = $db->query("SELECT COALESCE(MAX(id),0)+1 FROM solicitacoes")->fetchColumn();
$num_sol = 'SOL-' . date('Y') . '-' . str_pad($prox, 4, '0', STR_PAD_LEFT);

$page_title = 'Nova Solicitação — ALMOX.SYS';
require_once __DIR__ . '/../includes/header.php';
?>

<!-- Injeta materiais para o JS -->
<script>
window.MATERIAIS = <?= json_encode(array_map(fn($m) => [
    'id'      => $m['id'],
    'codigo'  => $m['codigo'],
    'nome'    => $m['nome'],
    'unidade' => $m['unidade'],
    'tipo'    => $m['tipo'] ?? 'material',
    'disp'    => $m['qtd_disponivel'],
], $materiais), JSON_HEX_TAG | JSON_HEX_AMP) ?>;
</script>

<div class="wrapper">

  <div class="page-header fade-up">
    <div class="breadcrumb">
      Início <span class="sep">/</span> <a href="/aluno/dashboard.php">Dashboard</a> <span class="sep">/</span> Nova Solicitação
    </div>
    <h1 class="page-title">Solicitação de <span>Materiais</span></h1>
    <p class="page-subtitle">Preencha os campos abaixo. Solicitações urgentes serão notificadas ao almoxarife.</p>
  </div>

  <?php if ($sucesso): ?>
    <div class="alert alert-success fade-up">
      ✓ Solicitação <strong>#<?= str_pad($sucesso, 4, '0', STR_PAD_LEFT) ?></strong> enviada com sucesso! Aguarde aprovação do almoxarife.
    </div>
    <div style="display:flex;gap:12px;margin-bottom:32px" class="fade-up-1">
      <a href="/aluno/minhas-solicitacoes.php" class="btn btn-primary">Ver minhas solicitações →</a>
      <a href="/aluno/solicitar.php" class="btn btn-ghost">Nova solicitação</a>
    </div>
  <?php endif; ?>

  <?php if ($erro): ?>
    <div class="alert alert-error fade-up"><?= htmlspecialchars($erro) ?></div>
  <?php endif; ?>

  <?php if (!$sucesso): ?>
  <form method="POST" action="/aluno/solicitar.php" id="formSolicitacao">
    <?= csrf_field() ?>

    <!-- Linha 1: Identificação + Fluxo -->
    <div class="grid-2 fade-up-1" style="margin-bottom:20px;align-items:start">

      <!-- Identificação -->
      <div class="card">
        <div class="card-title">Identificação</div>

        <div class="field-row">
          <div class="field">
            <label class="field-label">Nº da Solicitação</label>
            <input type="text" value="<?= htmlspecialchars($num_sol) ?>" readonly />
          </div>
          <div class="field">
            <label class="field-label">Data</label>
            <input type="text" value="<?= date('d/m/Y') ?>" readonly />
          </div>
        </div>

        <div class="field">
          <label class="field-label">Solicitante</label>
          <input type="text" value="<?= htmlspecialchars($_SESSION['nome']) ?>" readonly />
        </div>

        <div class="field">
          <label class="field-label">Urgência</label>
          <div class="radio-group">
            <label class="radio-option radio-low">
              <input type="radio" name="urgencia" value="baixa" />
              <span class="radio-label"><span class="dot"></span>Baixa</span>
            </label>
            <label class="radio-option radio-med">
              <input type="radio" name="urgencia" value="media" checked />
              <span class="radio-label"><span class="dot"></span>Média</span>
            </label>
            <label class="radio-option radio-high">
              <input type="radio" name="urgencia" value="alta" />
              <span class="radio-label"><span class="dot"></span>Alta</span>
            </label>
          </div>
        </div>
      </div>

      <!-- Fluxo de aprovação -->
      <div class="card">
        <div class="card-title">Fluxo de Aprovação</div>
        <div class="timeline">
          <div class="tl-step">
            <div class="tl-dot done">✓</div>
            <div class="tl-info">
              <div class="tl-name">Solicitante</div>
              <div class="tl-meta">Em preenchimento</div>
            </div>
          </div>
          <div class="tl-step">
            <div class="tl-dot active">→</div>
            <div class="tl-info">
              <div class="tl-name">Conferência do Almoxarife</div>
              <div class="tl-meta">Aguardando envio</div>
            </div>
          </div>
          <div class="tl-step">
            <div class="tl-dot">3</div>
            <div class="tl-info">
              <div class="tl-name">Separação dos Materiais</div>
              <div class="tl-meta">Pendente</div>
            </div>
          </div>
          <div class="tl-step">
            <div class="tl-dot">4</div>
            <div class="tl-info">
              <div class="tl-name">Retirada</div>
              <div class="tl-meta">Pendente</div>
            </div>
          </div>
          <div class="tl-step">
            <div class="tl-dot">5</div>
            <div class="tl-info">
              <div class="tl-name">Devolução e Conferência</div>
              <div class="tl-meta">Pendente</div>
            </div>
          </div>
        </div>
      </div>
    </div>

    <!-- Itens -->
    <div class="card fade-up-2" style="margin-bottom:20px">
      <div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:16px">
        <div class="card-title" style="margin-bottom:0">Itens Solicitados</div>
        <?php if (empty($materiais)): ?>
          <span style="font-size:12px;color:var(--text-muted)">Nenhum material disponível no estoque.</span>
        <?php else: ?>
          <button type="button" class="btn-add" onclick="adicionarItem()">+ Adicionar Item</button>
        <?php endif; ?>
      </div>

      <div id="avisoFerramenta" style="display:none;background:rgba(255,170,0,.1);border:1px solid var(--warning);border-radius:var(--radius-sm);padding:12px 16px;margin-bottom:12px">
        <p style="font-size:13px;color:var(--warning);font-weight:600;margin:0">
          ⚠ Sua solicitação contém ferramentas — devem ser devolvidas até as 22h do mesmo dia da retirada.
          Ferramentas não devolvidas no prazo gerarão cobrança automática.
        </p>
      </div>

      <div class="table-wrap">
        <table>
          <thead>
            <tr>
              <th>#</th>
              <th>Material</th>
              <th>Tipo</th>
              <th>Unidade</th>
              <th>Qtd. Solicitada</th>
              <th></th>
            </tr>
          </thead>
          <tbody id="corpoTabela">
            <tr class="empty-row" id="linhaVazia">
              <td colspan="6">Nenhum item adicionado. Clique em "+ Adicionar Item" para começar.</td>
            </tr>
          </tbody>
        </table>
      </div>
    </div>

    <!-- Detalhes + Resumo -->
    <div class="grid-2 fade-up-3" style="align-items:start">

      <!-- Observações -->
      <div class="card">
        <div class="card-title">Detalhes</div>

        <div class="field">
          <label class="field-label">Justificativa / Uso Previsto</label>
          <textarea name="justificativa" placeholder="Descreva a finalidade dos materiais, projeto relacionado ou qualquer informação útil ao almoxarife..."><?= htmlspecialchars($_POST['justificativa'] ?? '') ?></textarea>
        </div>

        <div class="field">
          <label class="field-label">Observações adicionais</label>
          <textarea name="observacao_requisitante" placeholder="Informe se algum item já foi encontrado danificado, com defeito, ou qualquer outra observação relevante..."><?= htmlspecialchars($_POST['observacao_requisitante'] ?? '') ?></textarea>
        </div>

        <div class="field-row">
          <div class="field">
            <label class="field-label">Data necessária para entrega</label>
            <input type="date" name="data_necessaria" value="<?= htmlspecialchars($_POST['data_necessaria'] ?? '') ?>" />
          </div>
          <div class="field">
            <label class="field-label">Local de entrega</label>
            <input type="text" name="local_entrega" placeholder="Ex: Sala 204 — Bloco B"
                   value="<?= htmlspecialchars($_POST['local_entrega'] ?? '') ?>" />
          </div>
        </div>
      </div>

      <!-- Resumo -->
      <div class="card">
        <div class="card-title">Resumo</div>

        <div style="background:var(--surface2);border:1px solid var(--border);border-radius:var(--radius-sm);padding:16px;margin-bottom:16px">
          <div style="display:flex;justify-content:space-between;padding:7px 0;border-bottom:1px solid var(--border);font-size:13px">
            <span class="text-muted">Solicitação Nº</span>
            <span class="mono"><?= htmlspecialchars($num_sol) ?></span>
          </div>
          <div style="display:flex;justify-content:space-between;padding:7px 0;border-bottom:1px solid var(--border);font-size:13px">
            <span class="text-muted">Solicitante</span>
            <span class="mono" style="font-size:12px"><?= htmlspecialchars($_SESSION['nome']) ?></span>
          </div>
          <div style="display:flex;justify-content:space-between;padding:7px 0;border-bottom:1px solid var(--border);font-size:13px">
            <span class="text-muted">Urgência</span>
            <span id="resumoUrgencia" class="mono" style="color:var(--accent)">Média</span>
          </div>
          <div style="display:flex;justify-content:space-between;padding:7px 0;font-size:14px;font-weight:700">
            <span class="text-muted">Total de itens</span>
            <span id="resumoItens" class="mono text-accent">0 itens</span>
          </div>
        </div>

        <p style="font-size:12px;color:var(--text-muted);line-height:1.7">
          Ao enviar, a solicitação será encaminhada ao almoxarife para conferência e separação dos materiais.
        </p>
      </div>

    </div>

    <!-- Actions -->
    <div class="actions fade-up-3">
      <a href="/aluno/dashboard.php" class="btn btn-ghost">Cancelar</a>
      <button type="submit" class="btn btn-primary">Enviar Solicitação →</button>
    </div>

  </form>
  <?php endif; ?>

</div>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
