<?php

date_default_timezone_set('America/Sao_Paulo');

define('DB_PATH', getenv('STORAGE_PATH') ? getenv('STORAGE_PATH') . '/almoxarifado.db' : __DIR__ . '/database/almoxarifado.db');

function get_db(): PDO {
    static $pdo = null;
    if ($pdo === null) {
        $pdo = new PDO('sqlite:' . DB_PATH);
        $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $pdo->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
        $pdo->exec('PRAGMA foreign_keys = ON;');
        criar_schema($pdo);
    }
    return $pdo;
}

function criar_schema(PDO $pdo): void {
    $pdo->exec("
        CREATE TABLE IF NOT EXISTS usuarios (
            id          INTEGER PRIMARY KEY AUTOINCREMENT,
            nome        TEXT    NOT NULL,
            email       TEXT    UNIQUE NOT NULL,
            senha_hash  TEXT    NOT NULL,
            papel       TEXT    NOT NULL DEFAULT 'aluno',
            aprovado    INTEGER NOT NULL DEFAULT 1,
            criado_em   DATETIME DEFAULT CURRENT_TIMESTAMP
        );

        CREATE TABLE IF NOT EXISTS materiais (
            id              INTEGER PRIMARY KEY AUTOINCREMENT,
            codigo          TEXT    UNIQUE NOT NULL,
            nome            TEXT    NOT NULL,
            descricao       TEXT,
            unidade         TEXT    NOT NULL DEFAULT 'UN',
            tipo            TEXT    NOT NULL DEFAULT 'material',
            qtd_disponivel  INTEGER NOT NULL DEFAULT 0,
            qtd_total       INTEGER NOT NULL DEFAULT 0,
            criado_em       DATETIME DEFAULT CURRENT_TIMESTAMP
        );

        CREATE TABLE IF NOT EXISTS solicitacoes (
            id              INTEGER PRIMARY KEY AUTOINCREMENT,
            usuario_id      INTEGER NOT NULL,
            status          TEXT    NOT NULL DEFAULT 'pendente',
            urgencia        TEXT    NOT NULL DEFAULT 'media',
            justificativa   TEXT,
            local_entrega   TEXT,
            data_necessaria DATE,
            criado_em       DATETIME DEFAULT CURRENT_TIMESTAMP,
            FOREIGN KEY (usuario_id) REFERENCES usuarios(id)
        );

        CREATE TABLE IF NOT EXISTS itens_solicitacao (
            id              INTEGER PRIMARY KEY AUTOINCREMENT,
            solicitacao_id  INTEGER NOT NULL,
            material_id     INTEGER NOT NULL,
            qtd_solicitada  INTEGER NOT NULL DEFAULT 1,
            FOREIGN KEY (solicitacao_id) REFERENCES solicitacoes(id),
            FOREIGN KEY (material_id)   REFERENCES materiais(id)
        );

        CREATE TABLE IF NOT EXISTS movimentacoes (
            id              INTEGER PRIMARY KEY AUTOINCREMENT,
            solicitacao_id  INTEGER NOT NULL,
            tipo            TEXT    NOT NULL,
            usuario_id      INTEGER NOT NULL,
            observacao      TEXT,
            criado_em       DATETIME DEFAULT CURRENT_TIMESTAMP,
            FOREIGN KEY (solicitacao_id) REFERENCES solicitacoes(id),
            FOREIGN KEY (usuario_id)     REFERENCES usuarios(id)
        );

        CREATE TABLE IF NOT EXISTS cobrancas (
            id              INTEGER PRIMARY KEY AUTOINCREMENT,
            solicitacao_id  INTEGER NOT NULL UNIQUE,
            usuario_id      INTEGER NOT NULL,
            gerada_em       DATETIME DEFAULT CURRENT_TIMESTAMP,
            status          TEXT    NOT NULL DEFAULT 'pendente',
            observacao      TEXT,
            FOREIGN KEY (solicitacao_id) REFERENCES solicitacoes(id),
            FOREIGN KEY (usuario_id)     REFERENCES usuarios(id)
        );
    ");

    // Migrações: colunas adicionadas em versões posteriores
    foreach ([
        "ALTER TABLE materiais      ADD COLUMN tipo                   TEXT    NOT NULL DEFAULT 'material'",
        "ALTER TABLE materiais      ADD COLUMN consumivel              INTEGER NOT NULL DEFAULT 1",
        "ALTER TABLE movimentacoes  ADD COLUMN foto_path              TEXT",
        "ALTER TABLE solicitacoes   ADD COLUMN observacao_requisitante TEXT",
        "ALTER TABLE itens_solicitacao ADD COLUMN qtd_devolvida       INTEGER NOT NULL DEFAULT 0",
        "ALTER TABLE itens_solicitacao ADD COLUMN item_fechado        INTEGER NOT NULL DEFAULT 0",
        "ALTER TABLE itens_solicitacao ADD COLUMN status_item         TEXT",
        "ALTER TABLE itens_solicitacao ADD COLUMN motivo_recusa       TEXT",
    ] as $migration) {
        try { $pdo->exec($migration); } catch (PDOException) { /* já existe */ }
    }

    // Inicializa consumivel=0 para ferramentas (única vez)
    $tem_ferr0 = $pdo->query("SELECT COUNT(*) FROM materiais WHERE tipo='ferramenta' AND consumivel=0")->fetchColumn();
    if (!$tem_ferr0) {
        try { $pdo->exec("UPDATE materiais SET consumivel=0 WHERE tipo='ferramenta'"); } catch (PDOException) {}
    }

    // Seed: admin padrão se não existir
    $stmt = $pdo->prepare("SELECT id FROM usuarios WHERE email = ?");
    $stmt->execute(['admin@almox.local']);
    if (!$stmt->fetch()) {
        $pdo->prepare("INSERT INTO usuarios (nome, email, senha_hash, papel) VALUES (?, ?, ?, 'admin')")
            ->execute(['Administrador', 'admin@almox.local', password_hash('admin123', PASSWORD_DEFAULT)]);
    }
}
