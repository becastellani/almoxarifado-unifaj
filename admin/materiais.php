<?php
session_start();
require_once __DIR__ . '/../db.php';
require_once __DIR__ . '/../includes/auth.php';
requer_admin();

$db  = get_db();
$msg  = '';
$erro = '';

// Flash de sucesso após redirect
if (!empty($_SESSION['flash_ok'])) {
    $msg = $_SESSION['flash_ok'];
    unset($_SESSION['flash_ok']);
}

// --- AÇÕES POST ---
$acao = $_POST['acao'] ?? '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verificar_csrf();

    if ($acao === 'criar' || $acao === 'editar') {
        $codigo = strtoupper(trim($_POST['codigo'] ?? ''));
        $nome   = trim($_POST['nome']   ?? '');
        $desc   = trim($_POST['descricao'] ?? '');
        $unid   = trim($_POST['unidade']   ?? 'UN');
        $tipo   = in_array($_POST['tipo'] ?? '', ['material', 'ferramenta']) ? $_POST['tipo'] : 'material';
        $qtd_t  = max(0, (int)($_POST['qtd_total']      ?? 0));
        $qtd_d  = max(0, (int)($_POST['qtd_disponivel'] ?? 0));

        if (!$codigo || !$nome) {
            $erro = 'Código e nome são obrigatórios.';
        } elseif ($qtd_d > $qtd_t) {
            $erro = 'Quantidade disponível não pode ser maior que a total.';
        } else {
            if ($acao === 'criar') {
                $chk = $db->prepare("SELECT id FROM materiais WHERE codigo = ?");
                $chk->execute([$codigo]);
                if ($chk->fetch()) {
                    $erro = "Código \"$codigo\" já existe.";
                } else {
                    $db->prepare("INSERT INTO materiais (codigo, nome, descricao, unidade, tipo, qtd_total, qtd_disponivel) VALUES (?,?,?,?,?,?,?)")
                       ->execute([$codigo, $nome, $desc, $unid, $tipo, $qtd_t, $qtd_d]);
                    $_SESSION['flash_ok'] = "Material \"$nome\" cadastrado com sucesso.";
                    header('Location: /admin/materiais.php');
                    exit;
                }
            } else {
                $id = (int)($_POST['id'] ?? 0);
                $chk = $db->prepare("SELECT id FROM materiais WHERE codigo = ? AND id != ?");
                $chk->execute([$codigo, $id]);
                if ($chk->fetch()) {
                    $erro = "Código \"$codigo\" já existe em outro material.";
                } else {
                    $db->prepare("UPDATE materiais SET codigo=?, nome=?, descricao=?, unidade=?, tipo=?, qtd_total=?, qtd_disponivel=? WHERE id=?")
                       ->execute([$codigo, $nome, $desc, $unid, $tipo, $qtd_t, $qtd_d, $id]);
                    $_SESSION['flash_ok'] = "Material \"$nome\" atualizado com sucesso.";
                    header('Location: /admin/materiais.php');
                    exit;
                }
            }
        }
    }

    if ($acao === 'excluir') {
        $id = (int)($_POST['id'] ?? 0);
        $uso = $db->prepare("SELECT COUNT(*) FROM itens_solicitacao WHERE material_id = ?");
        $uso->execute([$id]);
        if ($uso->fetchColumn() > 0) {
            $erro = 'Este material possui solicitações vinculadas e não pode ser excluído.';
        } else {
            $db->prepare("DELETE FROM materiais WHERE id = ?")->execute([$id]);
            $_SESSION['flash_ok'] = 'Material excluído.';
            header('Location: /admin/materiais.php');
            exit;
        }
    }
}

// --- BUSCA ---
$busca = trim($_GET['q'] ?? '');
$filtro_sql = $busca ? "WHERE nome LIKE ? OR codigo LIKE ?" : "";
$params = $busca ? ["%$busca%", "%$busca%"] : [];

$materiais = $db->prepare("SELECT * FROM materiais $filtro_sql ORDER BY nome ASC");
$materiais->execute($params);
$materiais = $materiais->fetchAll();

// Se há ?editar=ID, carrega o material para o formulário
$editando = null;
if (isset($_GET['editar'])) {
    $stmt = $db->prepare("SELECT * FROM materiais WHERE id = ?");
    $stmt->execute([(int)$_GET['editar']]);
    $editando = $stmt->fetch();
}

$page_title = 'Materiais — ALMOX.SYS';
require_once __DIR__ . '/../includes/header.php';
?>

<div class="wrapper">

  <div class="page-header fade-up">
    <div class="breadcrumb">Admin <span class="sep">/</span> <a href="/admin/dashboard.php">Dashboard</a> <span class="sep">/</span> Materiais</div>
    <h1 class="page-title">Gestão de <span>Materiais</span></h1>
    <p class="page-subtitle">Cadastre, edite e controle o estoque de materiais do almoxarifado.</p>
  </div>

  <?php if ($msg):  ?><div class="alert alert-success fade-up"><?= htmlspecialchars($msg) ?></div><?php endif; ?>
  <?php if ($erro): ?><div class="alert alert-error fade-up"><?= htmlspecialchars($erro) ?></div><?php endif; ?>

  <div class="grid-2 fade-up-1" style="align-items:start">

    <!-- Formulário -->
    <div class="card">
      <div class="card-title"><?= $editando ? 'Editar Material' : 'Novo Material' ?></div>

      <form method="POST" action="/admin/materiais.php">
        <?= csrf_field() ?>
        <input type="hidden" name="acao" value="<?= $editando ? 'editar' : 'criar' ?>" />
        <?php if ($editando): ?>
          <input type="hidden" name="id" value="<?= $editando['id'] ?>" />
        <?php endif; ?>

        <div class="field">
          <label class="field-label">Tipo <span class="required">*</span></label>
          <?php $tipo_atual = $editando['tipo'] ?? $_POST['tipo'] ?? 'material'; ?>
          <div class="radio-group">
            <label class="radio-option" style="<?= $tipo_atual === 'material' ? 'border-color:var(--accent);background:var(--accent-dim)' : '' ?>">
              <input type="radio" name="tipo" value="material" <?= $tipo_atual === 'material' ? 'checked' : '' ?> onchange="atualizarTipo(this)" />
              <span class="radio-label"><span class="dot"></span>Material (consumo)</span>
            </label>
            <label class="radio-option" style="<?= $tipo_atual === 'ferramenta' ? 'border-color:var(--warning);background:rgba(255,170,0,.08)' : '' ?>">
              <input type="radio" name="tipo" value="ferramenta" <?= $tipo_atual === 'ferramenta' ? 'checked' : '' ?> onchange="atualizarTipo(this)" />
              <span class="radio-label"><span class="dot"></span>Ferramentaria (devolução até 22h)</span>
            </label>
          </div>
        </div>

        <div class="field-row">
          <div class="field">
            <label class="field-label">Código <span class="required">*</span></label>
            <input type="text" name="codigo" placeholder="Ex: ELE-001"
                   value="<?= htmlspecialchars($editando['codigo'] ?? strtoupper($_POST['codigo'] ?? '')) ?>"
                   style="text-transform:uppercase" required />
          </div>
          <div class="field">
            <label class="field-label">Unidade <span class="required">*</span></label>
            <select name="unidade">
              <?php foreach (['UN','CX','RL','PC','KG','LT','M','M²','PAR','JG'] as $u):
                $sel = ($editando['unidade'] ?? $_POST['unidade'] ?? 'UN') === $u ? 'selected' : ''; ?>
                <option <?= $sel ?>><?= $u ?></option>
              <?php endforeach; ?>
            </select>
          </div>
        </div>

        <div class="field">
          <label class="field-label">Nome do Material <span class="required">*</span></label>
          <input type="text" name="nome" placeholder="Ex: Papel A4 75g/m²"
                 value="<?= htmlspecialchars($editando['nome'] ?? $_POST['nome'] ?? '') ?>" required />
        </div>

        <div class="field">
          <label class="field-label">Descrição</label>
          <textarea name="descricao" placeholder="Detalhes adicionais (opcional)..."><?= htmlspecialchars($editando['descricao'] ?? $_POST['descricao'] ?? '') ?></textarea>
        </div>

        <div class="field-row">
          <div class="field">
            <label class="field-label">Qtd. Total <span class="required">*</span></label>
            <input type="number" name="qtd_total" min="0" required
                   value="<?= (int)($editando['qtd_total'] ?? $_POST['qtd_total'] ?? 0) ?>" />
          </div>
          <div class="field">
            <label class="field-label">Qtd. Disponível <span class="required">*</span></label>
            <input type="number" name="qtd_disponivel" min="0" required
                   value="<?= (int)($editando['qtd_disponivel'] ?? $_POST['qtd_disponivel'] ?? 0) ?>" />
          </div>
        </div>

        <div style="display:flex;gap:10px;margin-top:8px">
          <button type="submit" class="btn btn-primary">
            <?= $editando ? '✓ Salvar alterações' : '+ Cadastrar material' ?>
          </button>
          <?php if ($editando): ?>
            <a href="/admin/materiais.php" class="btn btn-ghost">Cancelar</a>
          <?php endif; ?>
        </div>
      </form>
    </div>

    <!-- Lista -->
    <div style="display:flex;flex-direction:column;gap:16px">

      <!-- Busca -->
      <form method="GET" action="/admin/materiais.php" style="display:flex;gap:8px">
        <input type="text" name="q" placeholder="Buscar por nome ou código..."
               value="<?= htmlspecialchars($busca) ?>" style="flex:1" />
        <button type="submit" class="btn btn-secondary">Buscar</button>
        <?php if ($busca): ?>
          <a href="/admin/materiais.php" class="btn btn-ghost">✕</a>
        <?php endif; ?>
      </form>

      <!-- Tabela -->
      <div class="card" style="padding:0;overflow:hidden">
        <div class="table-wrap" style="border:none;border-radius:0">
          <table>
            <thead>
              <tr>
                <th>Material</th>
                <th>Tipo</th>
                <th>Cód.</th>
                <th>Un.</th>
                <th style="text-align:center">Disp.</th>
                <th style="text-align:center">Total</th>
                <th></th>
              </tr>
            </thead>
            <tbody>
              <?php if (empty($materiais)): ?>
                <tr class="empty-row">
                  <td colspan="7"><?= $busca ? 'Nenhum material encontrado.' : 'Nenhum material cadastrado ainda.' ?></td>
                </tr>
              <?php else: ?>
                <?php foreach ($materiais as $m):
                  $cor_qtd = $m['qtd_disponivel'] == 0 ? 'var(--danger)' : ($m['qtd_disponivel'] <= 3 ? 'var(--warning)' : 'var(--success)');
                  $e_ferramenta = ($m['tipo'] ?? 'material') === 'ferramenta';
                ?>
                <tr <?= $editando && $editando['id'] == $m['id'] ? 'style="background:var(--accent-dim)"' : '' ?>>
                  <td>
                    <span style="font-size:13px;font-weight:600"><?= htmlspecialchars($m['nome']) ?></span>
                    <?php if ($m['descricao']): ?>
                      <br><span style="font-size:11px;color:var(--text-muted)"><?= htmlspecialchars(mb_substr($m['descricao'], 0, 40)) ?>...</span>
                    <?php endif; ?>
                  </td>
                  <td>
                    <?php if ($e_ferramenta): ?>
                      <span style="font-size:10px;font-weight:700;padding:2px 7px;border-radius:4px;background:rgba(255,170,0,.12);color:var(--warning);border:1px solid var(--warning);white-space:nowrap">Ferramentaria</span>
                    <?php else: ?>
                      <span style="font-size:10px;font-weight:700;padding:2px 7px;border-radius:4px;background:var(--accent-dim);color:var(--accent);border:1px solid var(--accent);white-space:nowrap">Material</span>
                    <?php endif; ?>
                  </td>
                  <td class="mono" style="font-size:11px;color:var(--text-muted)"><?= htmlspecialchars($m['codigo']) ?></td>
                  <td class="mono" style="font-size:12px"><?= htmlspecialchars($m['unidade']) ?></td>
                  <td style="text-align:center;font-weight:700;color:<?= $cor_qtd ?>"><?= (int)$m['qtd_disponivel'] ?></td>
                  <td style="text-align:center;color:var(--text-muted);font-size:12px"><?= (int)$m['qtd_total'] ?></td>
                  <td>
                    <div style="display:flex;gap:6px;justify-content:flex-end">
                      <a href="/admin/materiais.php?editar=<?= $m['id'] ?>" class="btn btn-secondary btn-xs">Editar</a>
                      <form method="POST" action="/admin/materiais.php" style="display:inline"
                            onsubmit="return confirmarExclusao('Excluir \"<?= htmlspecialchars(addslashes($m['nome'])) ?>\"?')">
                        <?= csrf_field() ?>
                        <input type="hidden" name="acao" value="excluir" />
                        <input type="hidden" name="id" value="<?= $m['id'] ?>" />
                        <button type="submit" class="btn btn-danger btn-xs">Excluir</button>
                      </form>
                    </div>
                  </td>
                </tr>
                <?php endforeach; ?>
              <?php endif; ?>
            </tbody>
          </table>
        </div>
      </div>

      <p class="text-muted" style="font-size:12px;text-align:right">
        <?= count($materiais) ?> material(is) encontrado(s)
      </p>

    </div>
  </div>

</div>

<script>
function atualizarTipo(radio) {
  document.querySelectorAll('input[name="tipo"]').forEach(r => {
    const lbl = r.closest('label');
    if (r.value === 'ferramenta') {
      lbl.style.borderColor = r.checked ? 'var(--warning)' : '';
      lbl.style.background  = r.checked ? 'rgba(255,170,0,.08)' : '';
    } else {
      lbl.style.borderColor = r.checked ? 'var(--accent)' : '';
      lbl.style.background  = r.checked ? 'var(--accent-dim)' : '';
    }
  });
}
</script>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
