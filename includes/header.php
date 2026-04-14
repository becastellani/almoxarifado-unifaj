<?php
if (session_status() === PHP_SESSION_NONE) session_start();
$usuario = usuario_logado();
$papel   = $usuario['papel'] ?? '';
$nome    = $usuario['nome']  ?? '';
$iniciais = $nome ? mb_strtoupper(mb_substr(explode(' ', trim($nome))[0], 0, 1) . (mb_substr(explode(' ', trim($nome))[1] ?? '', 0, 1))) : '?';

// URL base para links (funciona com php -S)
$base = '';
?>
<!DOCTYPE html>
<html lang="pt-BR" data-theme="dark">
<head>
  <meta charset="UTF-8" />
  <meta name="viewport" content="width=device-width, initial-scale=1.0" />
  <title><?= $page_title ?? 'ALMOX.SYS' ?></title>
  <link href="https://fonts.googleapis.com/css2?family=IBM+Plex+Mono:wght@400;600&family=IBM+Plex+Sans:wght@300;400;600;700&display=swap" rel="stylesheet" />
  <link rel="stylesheet" href="<?= $base ?>/assets/style.css" />
</head>
<body>

<header class="topbar">
  <div class="topbar-left">
    <a href="<?= $papel === 'admin' ? '/admin/dashboard.php' : '/aluno/dashboard.php' ?>" class="logo">
      <div class="logo-icon"></div>
      <span class="logo-text">ALMOX<span class="logo-dot">.</span>SYS</span>
    </a>

    <?php if ($usuario): ?>
    <nav class="nav-links">
      <?php if ($papel === 'admin'): ?>
        <a href="/admin/dashboard.php"    class="nav-link <?= str_contains($_SERVER['REQUEST_URI'], 'dashboard') ? 'active' : '' ?>">Dashboard</a>
        <a href="/admin/materiais.php"    class="nav-link <?= str_contains($_SERVER['REQUEST_URI'], 'materiais') ? 'active' : '' ?>">Materiais</a>
        <a href="/admin/solicitacoes.php" class="nav-link <?= str_contains($_SERVER['REQUEST_URI'], 'solicitacoes') ? 'active' : '' ?>">Solicitações</a>
        <a href="/admin/separacao.php"    class="nav-link <?= str_contains($_SERVER['REQUEST_URI'], 'separacao') ? 'active' : '' ?>">Separação</a>
        <a href="/admin/devolucao.php"    class="nav-link <?= str_contains($_SERVER['REQUEST_URI'], 'devolucao') ? 'active' : '' ?>">Devolução</a>
        <a href="/admin/relatorios.php"   class="nav-link <?= str_contains($_SERVER['REQUEST_URI'], 'relatorios') ? 'active' : '' ?>">Relatórios</a>
        <a href="/admin/usuarios.php"    class="nav-link <?= str_contains($_SERVER['REQUEST_URI'], 'usuarios') ? 'active' : '' ?>">Usuários</a>
      <?php else: ?>
        <a href="/aluno/dashboard.php"           class="nav-link <?= str_contains($_SERVER['REQUEST_URI'], 'dashboard') ? 'active' : '' ?>">Início</a>
        <a href="/aluno/solicitar.php"            class="nav-link <?= str_contains($_SERVER['REQUEST_URI'], 'solicitar') ? 'active' : '' ?>">Nova Solicitação</a>
        <a href="/aluno/minhas-solicitacoes.php"  class="nav-link <?= str_contains($_SERVER['REQUEST_URI'], 'minhas') ? 'active' : '' ?>">Minhas Solicitações</a>
      <?php endif; ?>
    </nav>
    <?php endif; ?>
  </div>

  <div class="topbar-right">
    <button class="theme-toggle" id="themeToggle" title="Alternar tema">
      <span class="theme-icon">🌙</span>
    </button>

    <?php if ($usuario): ?>
      <div class="user-chip">
        <div class="avatar"><?= htmlspecialchars($iniciais) ?></div>
        <span class="user-name"><?= htmlspecialchars(explode(' ', $nome)[0]) ?></span>
        <span class="badge-papel <?= $papel ?>"><?= $papel === 'admin' ? 'Admin' : 'Aluno' ?></span>
      </div>
      <a href="/logout.php" class="btn-logout">Sair</a>
    <?php endif; ?>
  </div>
</header>

<main class="main-content">
