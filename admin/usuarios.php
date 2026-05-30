<?php
require_once __DIR__ . '/../includes/session.php';
require_once __DIR__ . '/../db.php';
require_once __DIR__ . '/../includes/auth.php';
requer_admin();

$db   = get_db();
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

    if ($acao === 'criar') {
        $nome  = trim($_POST['nome']  ?? '');
        $email = trim($_POST['email'] ?? '');
        $senha = $_POST['senha']      ?? '';
        $papel = $_POST['papel']      ?? 'aluno';

        if (!in_array($papel, ['aluno', 'admin'])) $papel = 'aluno';

        if (!$nome || !$email || !$senha) {
            $erro = 'Nome, e-mail e senha são obrigatórios.';
        } elseif (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
            $erro = 'E-mail inválido.';
        } elseif (strlen($senha) < 6) {
            $erro = 'A senha deve ter pelo menos 6 caracteres.';
        } else {
            $chk = $db->prepare("SELECT id FROM usuarios WHERE email = ?");
            $chk->execute([$email]);
            if ($chk->fetch()) {
                $erro = "E-mail \"$email\" já está cadastrado.";
            } else {
                $db->prepare("INSERT INTO usuarios (nome, email, senha_hash, papel, aprovado) VALUES (?, ?, ?, ?, 1)")
                   ->execute([$nome, $email, password_hash($senha, PASSWORD_DEFAULT), $papel]);
                $_SESSION['flash_ok'] = "Usuário \"$nome\" criado com sucesso.";
                header('Location: /admin/usuarios.php');
                exit;
            }
        }
    }

    if ($acao === 'editar') {
        $id    = (int)($_POST['id']    ?? 0);
        $nome  = trim($_POST['nome']   ?? '');
        $email = trim($_POST['email']  ?? '');
        $papel = $_POST['papel']       ?? 'aluno';
        $nova_senha = $_POST['nova_senha'] ?? '';

        if (!in_array($papel, ['aluno', 'admin'])) $papel = 'aluno';

        if ($id === (int)$_SESSION['usuario_id'] && $papel !== 'admin') {
            $erro = 'Você não pode rebaixar sua própria conta.';
        } elseif (!$nome || !$email) {
            $erro = 'Nome e e-mail são obrigatórios.';
        } elseif (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
            $erro = 'E-mail inválido.';
        } else {
            $chk = $db->prepare("SELECT id FROM usuarios WHERE email = ? AND id != ?");
            $chk->execute([$email, $id]);
            if ($chk->fetch()) {
                $erro = "E-mail já usado por outro usuário.";
            } elseif ($nova_senha && strlen($nova_senha) < 6) {
                $erro = 'Nova senha deve ter pelo menos 6 caracteres.';
            } else {
                if ($nova_senha) {
                    $db->prepare("UPDATE usuarios SET nome=?, email=?, papel=?, senha_hash=? WHERE id=?")
                       ->execute([$nome, $email, $papel, password_hash($nova_senha, PASSWORD_DEFAULT), $id]);
                } else {
                    $db->prepare("UPDATE usuarios SET nome=?, email=?, papel=? WHERE id=?")
                       ->execute([$nome, $email, $papel, $id]);
                }
                $_SESSION['flash_ok'] = "Usuário \"$nome\" atualizado com sucesso.";
                header('Location: /admin/usuarios.php');
                exit;
            }
        }
    }

    if ($acao === 'toggle_aprovado') {
        $id = (int)($_POST['id'] ?? 0);
        if ($id === (int)$_SESSION['usuario_id']) {
            $erro = 'Você não pode bloquear sua própria conta.';
        } else {
            $db->prepare("UPDATE usuarios SET aprovado = 1 - aprovado WHERE id = ?")
               ->execute([$id]);
            $_SESSION['flash_ok'] = 'Status do usuário atualizado.';
            header('Location: /admin/usuarios.php');
            exit;
        }
    }

    if ($acao === 'excluir') {
        $id = (int)($_POST['id'] ?? 0);
        if ($id === (int)$_SESSION['usuario_id']) {
            $erro = 'Você não pode excluir sua própria conta.';
        } else {
            $uso = $db->prepare("SELECT COUNT(*) FROM solicitacoes WHERE usuario_id = ?");
            $uso->execute([$id]);
            if ($uso->fetchColumn() > 0) {
                $erro = 'Este usuário possui solicitações e não pode ser excluído.';
            } else {
                $db->prepare("DELETE FROM usuarios WHERE id = ?")->execute([$id]);
                $_SESSION['flash_ok'] = 'Usuário excluído.';
                header('Location: /admin/usuarios.php');
                exit;
            }
        }
    }
}

// --- BUSCA ---
$busca = trim($_GET['q'] ?? '');
$filtro_papel = $_GET['papel'] ?? '';

$where  = "WHERE 1=1";
$params = [];
if ($busca) {
    $where   .= " AND (nome LIKE ? OR email LIKE ?)";
    $params[] = "%$busca%";
    $params[] = "%$busca%";
}
if ($filtro_papel && in_array($filtro_papel, ['aluno','admin'])) {
    $where   .= " AND papel = ?";
    $params[] = $filtro_papel;
}

$usuarios = $db->prepare("
    SELECT u.*,
           COUNT(DISTINCT s.id) AS total_sol,
           SUM(s.status = 'retirada') AS em_uso
    FROM usuarios u
    LEFT JOIN solicitacoes s ON s.usuario_id = u.id
    $where
    GROUP BY u.id
    ORDER BY u.papel ASC, u.nome ASC
");
$usuarios->execute($params);
$usuarios = $usuarios->fetchAll();

// Para edição inline
$editando = null;
if (isset($_GET['editar'])) {
    $stmt = $db->prepare("SELECT * FROM usuarios WHERE id = ?");
    $stmt->execute([(int)$_GET['editar']]);
    $editando = $stmt->fetch();
}

$page_title = 'Usuários — ALMOX.SYS';
require_once __DIR__ . '/../includes/header.php';
?>

<div class="wrapper">

  <div class="page-header fade-up">
    <div class="breadcrumb">Admin <span class="sep">/</span> <a href="/admin/dashboard.php">Dashboard</a> <span class="sep">/</span> Usuários</div>
    <h1 class="page-title">Gestão de <span>Usuários</span></h1>
    <p class="page-subtitle">Crie e gerencie contas de alunos e administradores.</p>
  </div>

  <?php if ($msg):  ?><div class="alert alert-success fade-up"><?= htmlspecialchars($msg) ?></div><?php endif; ?>
  <?php if ($erro): ?><div class="alert alert-error fade-up"><?= htmlspecialchars($erro) ?></div><?php endif; ?>

  <div class="grid-2 fade-up-1" style="align-items:start">

    <!-- Formulário criar/editar -->
    <div class="card">
      <div class="card-title"><?= $editando ? 'Editar Usuário' : 'Novo Usuário' ?></div>

      <form method="POST" action="/admin/usuarios.php">
        <?= csrf_field() ?>
        <input type="hidden" name="acao" value="<?= $editando ? 'editar' : 'criar' ?>" />
        <?php if ($editando): ?>
          <input type="hidden" name="id" value="<?= $editando['id'] ?>" />
        <?php endif; ?>

        <div class="field">
          <label class="field-label">Nome completo <span class="required">*</span></label>
          <input type="text" name="nome" placeholder="Ex: João Pedro Silva"
                 value="<?= htmlspecialchars($editando['nome'] ?? $_POST['nome'] ?? '') ?>" required />
        </div>

        <div class="field">
          <label class="field-label">E-mail <span class="required">*</span></label>
          <input type="email" name="email" placeholder="aluno@email.com"
                 value="<?= htmlspecialchars($editando['email'] ?? $_POST['email'] ?? '') ?>" required />
        </div>

        <div class="field-row">
          <div class="field">
            <label class="field-label"><?= $editando ? 'Nova senha' : 'Senha' ?> <?= !$editando ? '<span class="required">*</span>' : '' ?></label>
            <input type="password" name="<?= $editando ? 'nova_senha' : 'senha' ?>"
                   placeholder="<?= $editando ? 'Deixe em branco para manter' : 'Mín. 6 caracteres' ?>"
                   <?= !$editando ? 'required' : '' ?> />
          </div>
          <div class="field">
            <label class="field-label">Papel <span class="required">*</span></label>
            <select name="papel">
              <option value="aluno"  <?= ($editando['papel'] ?? 'aluno') === 'aluno'  ? 'selected' : '' ?>>Aluno</option>
              <option value="admin"  <?= ($editando['papel'] ?? '') === 'admin' ? 'selected' : '' ?>>Administrador</option>
            </select>
          </div>
        </div>

        <div style="display:flex;gap:10px;margin-top:8px">
          <button type="submit" class="btn btn-primary">
            <?= $editando ? '✓ Salvar alterações' : '+ Criar usuário' ?>
          </button>
          <?php if ($editando): ?>
            <a href="/admin/usuarios.php" class="btn btn-ghost">Cancelar</a>
          <?php endif; ?>
        </div>
      </form>

    </div>

    <!-- Lista de usuários -->
    <div style="display:flex;flex-direction:column;gap:14px">

      <!-- Busca + filtro -->
      <form method="GET" action="/admin/usuarios.php" style="display:flex;gap:8px;flex-wrap:wrap">
        <input type="text" name="q" placeholder="Buscar por nome ou e-mail..."
               value="<?= htmlspecialchars($busca) ?>" style="flex:1;min-width:160px" />
        <select name="papel" style="width:130px">
          <option value="">Todos os papéis</option>
          <option value="aluno" <?= $filtro_papel === 'aluno' ? 'selected' : '' ?>>Alunos</option>
          <option value="admin" <?= $filtro_papel === 'admin' ? 'selected' : '' ?>>Admins</option>
        </select>
        <button type="submit" class="btn btn-secondary">Filtrar</button>
        <?php if ($busca || $filtro_papel): ?>
          <a href="/admin/usuarios.php" class="btn btn-ghost">✕</a>
        <?php endif; ?>
      </form>

      <!-- Tabela -->
      <div class="card" style="padding:0;overflow:hidden">
        <div class="table-wrap" style="border:none;border-radius:0">
          <table>
            <thead>
              <tr>
                <th>Usuário</th>
                <th>Papel</th>
                <th style="text-align:center">Sol.</th>
                <th style="text-align:center">Em uso</th>
                <th style="text-align:center">Status</th>
                <th></th>
              </tr>
            </thead>
            <tbody>
              <?php if (empty($usuarios)): ?>
                <tr class="empty-row">
                  <td colspan="6">Nenhum usuário encontrado.</td>
                </tr>
              <?php else: ?>
                <?php foreach ($usuarios as $u):
                  $eh_eu = $u['id'] === (int)$_SESSION['usuario_id'];
                  $editando_este = $editando && $editando['id'] === $u['id'];
                ?>
                <tr <?= $editando_este ? 'style="background:var(--accent-dim)"' : '' ?>>
                  <td>
                    <span style="font-size:13px;font-weight:600"><?= htmlspecialchars($u['nome']) ?></span>
                    <?php if ($eh_eu): ?><span class="badge" style="background:var(--surface2);color:var(--text-muted);border:1px solid var(--border);font-size:9px;margin-left:4px">você</span><?php endif; ?>
                    <br>
                    <span class="mono" style="font-size:11px;color:var(--text-muted)"><?= htmlspecialchars($u['email']) ?></span>
                  </td>
                  <td>
                    <span class="badge-papel <?= $u['papel'] ?>"><?= $u['papel'] === 'admin' ? 'Admin' : 'Aluno' ?></span>
                  </td>
                  <td class="mono" style="text-align:center"><?= (int)$u['total_sol'] ?></td>
                  <td class="mono" style="text-align:center;color:<?= $u['em_uso'] > 0 ? 'var(--accent)' : 'var(--text-muted)' ?>">
                    <?= (int)$u['em_uso'] ?>
                  </td>
                  <td style="text-align:center">
                    <?php if ($u['aprovado']): ?>
                      <span class="badge" style="background:var(--success-dim);color:var(--success);border:1px solid var(--success)">Ativo</span>
                    <?php else: ?>
                      <span class="badge" style="background:var(--danger-dim);color:var(--danger);border:1px solid var(--danger)">Bloqueado</span>
                    <?php endif; ?>
                  </td>
                  <td>
                    <div style="display:flex;gap:5px;justify-content:flex-end;flex-wrap:wrap">
                      <a href="/admin/usuarios.php?editar=<?= $u['id'] ?>" class="btn btn-secondary btn-xs">Editar</a>

                      <?php if (!$eh_eu): ?>
                        <!-- Bloquear/Desbloquear -->
                        <form method="POST" style="display:inline">
                          <?= csrf_field() ?>
                          <input type="hidden" name="acao" value="toggle_aprovado" />
                          <input type="hidden" name="id"   value="<?= $u['id'] ?>" />
                          <button type="submit" class="btn btn-xs <?= $u['aprovado'] ? 'btn-ghost' : 'btn-success' ?>"
                                  title="<?= $u['aprovado'] ? 'Bloquear acesso' : 'Liberar acesso' ?>">
                            <?= $u['aprovado'] ? '🔒' : '🔓' ?>
                          </button>
                        </form>

                        <!-- Excluir -->
                        <?php if ($u['total_sol'] == 0): ?>
                        <form method="POST" style="display:inline"
                              onsubmit="return confirmarExclusao(<?= json_encode('Excluir usuário "' . $u['nome'] . '"?') ?>)">
                          <?= csrf_field() ?>
                          <input type="hidden" name="acao" value="excluir" />
                          <input type="hidden" name="id"   value="<?= $u['id'] ?>" />
                          <button type="submit" class="btn btn-danger btn-xs">Excluir</button>
                        </form>
                        <?php endif; ?>
                      <?php endif; ?>
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
        <?= count($usuarios) ?> usuário(s) encontrado(s)
      </p>

    </div>
  </div>

</div>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
