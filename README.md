# ALMOX.SYS — Sistema de Gestão de Almoxarifado

Sistema web para controle de materiais do almoxarifado da UNIFAJ, desenvolvido como solução digital para substituir o processo manual de solicitação, retirada e devolução de materiais de laboratório.

---

## Sumário

- [Tecnologias](#tecnologias)
- [Instalação no Windows](#instalação-no-windows)
- [Como rodar](#como-rodar)
- [Acesso padrão](#acesso-padrão)
- [Módulos do sistema](#módulos-do-sistema)
- [Fluxos operacionais](#fluxos-operacionais)
- [Estrutura de pastas](#estrutura-de-pastas)

---

## Tecnologias

| Camada | Tecnologia |
|---|---|
| Backend | PHP 8+ (sem framework) |
| Banco de dados | SQLite (via PDO) |
| Frontend | HTML5 + CSS3 + JavaScript vanilla |
| Servidor local | PHP Built-in Server |

Não requer instalação de banco de dados externo. O arquivo `.db` é criado automaticamente na primeira execução.

---

## Instalação no Windows

### 1. Instalar o PHP

1. Acesse **https://windows.php.net/download** e baixe a versão mais recente **Thread Safe (x64)** em formato `.zip`
2. Extraia o conteúdo para `C:\php`
3. Adicione o PHP ao PATH do Windows:
   - Abra o menu Iniciar e pesquise **"Variáveis de Ambiente"**
   - Clique em **"Editar as variáveis de ambiente do sistema"**
   - Em **Variáveis do sistema**, selecione `Path` e clique em **Editar**
   - Clique em **Novo** e adicione: `C:\php`
   - Clique em **OK** em todas as janelas
4. Abra o **Prompt de Comando** (`cmd`) e verifique:
   ```
   php --version
   ```
   Deve exibir algo como `PHP 8.x.x`

### 2. Habilitar extensão SQLite no PHP

1. Vá até `C:\php` e abra o arquivo `php.ini` com o Bloco de Notas
   - Se não existir, copie o arquivo `php.ini-development` e renomeie para `php.ini`
2. Encontre a linha:
   ```
   ;extension=pdo_sqlite
   ```
3. Remova o `;` do início para habilitar:
   ```
   extension=pdo_sqlite
   ```
4. Salve o arquivo

### 3. Baixar o projeto

Copie a pasta `management-stock` para o local desejado, por exemplo:
```
C:\projetos\management-stock
```

---

## Como rodar

1. Abra o **Prompt de Comando** (`cmd`) ou **PowerShell**
2. Navegue até a pasta do projeto:
   ```
   cd C:\projetos\management-stock
   ```
3. Inicie o servidor embutido do PHP:
   ```
   php -S localhost:8000
   ```
4. Abra o navegador e acesse:
   ```
   http://localhost:8000
   ```

> O banco de dados `almoxarifado.db` será criado automaticamente dentro da pasta `database/` na primeira execução.

---

## Acesso padrão

| Papel | E-mail | Senha |
|---|---|---|
| Administrador | `admin@almox.local` | `admin123` |

Para criar contas de alunos, acesse **Admin → Usuários** após fazer login como administrador.

Os alunos também podem se auto-cadastrar em `http://localhost:8000/register.php`.

---

## Módulos do sistema

### Módulo 1 — Autenticação

- Cadastro de usuário com senha criptografada (bcrypt)
- Login com sessão PHP
- Dois papéis: **Aluno** e **Administrador**
- Proteção de rotas por papel (guard de sessão)
- Dark mode e Light mode com persistência via localStorage

---

### Módulo 2 — Painel do Aluno

**Dashboard**
- Contadores de solicitações: total, pendentes, em aberto e concluídas
- Alertas de solicitações aprovadas aguardando retirada ou rejeitadas
- Tabela com as 5 solicitações mais recentes

**Nova Solicitação**
- Seleção de materiais disponíveis no estoque
- Adição de múltiplos itens por solicitação
- Definição de urgência (Baixa / Média / Alta)
- Justificativa, local de entrega e data necessária
- Validação de estoque antes de enviar
- Fluxo de aprovação exibido em tempo real

**Minhas Solicitações**
- Listagem completa com filtros por status
- Modal de detalhe com itens, justificativa e datas

---

### Módulo 3 — Gestão de Materiais (Admin)

- Cadastro de materiais com código, nome, descrição, unidade e quantidades
- Edição inline (formulário pré-preenchido)
- Exclusão protegida (não permite excluir materiais com solicitações vinculadas)
- Busca por nome ou código
- Indicador visual de estoque: verde (ok), laranja (crítico ≤ 3), vermelho (zerado)

---

### Módulo 4 — Análise de Solicitações (Admin)

- Listagem de todas as solicitações com filtros por status e busca por aluno
- Cards expansíveis com itens, estoque atual, justificativa e local de entrega
- Alerta visual quando o estoque é insuficiente para algum item
- Ações de **Aprovar** ou **Rejeitar** diretamente na tela

---

### Módulo 5 — Separação e Retirada (Admin)

**Separação**
- Lista solicitações aprovadas ordenadas por urgência
- Confirmação da separação física dos materiais (status: `aprovada → separada`)
- Campo de observação para registro interno

**Retirada**
- Lista materiais separados aguardando retirada do aluno
- Exibe nome e e-mail do aluno para conferência de identidade
- Confirmação da retirada com **baixa automática no estoque** (status: `separada → retirada`)

---

### Módulo 6 — Devolução e Conferência (Admin)

- Lista materiais retirados pendentes de devolução
- Alerta visual para materiais com mais de 3 dias sem devolução
- Campo de observação para registro da conferência (estado dos itens)
- Confirmação da devolução com **reposição automática no estoque** (status: `retirada → devolvida`)
- Histórico das últimas 10 devoluções na mesma tela

---

### Módulo 7 — Relatórios (Admin)

- Filtro por período com atalhos rápidos (Hoje, Esta semana, Este mês, Último mês, Este ano)
- Cards de totais: solicitações, devolvidas, em uso e rejeitadas com percentuais
- Gráfico de barras (sparkline) de solicitações por dia no período
- Ranking de materiais mais solicitados com barra de progresso e estoque atual
- Ranking de alunos mais ativos com solicitações, devoluções e em uso
- Histórico completo de movimentações com datas de solicitação, retirada e devolução
- Função de impressão otimizada (`Ctrl+P` ou botão na tela)

---

### Módulo 8 — Gestão de Usuários (Admin)

- Criação de contas de alunos e administradores pelo painel
- Edição de nome, e-mail, papel e senha
- Bloqueio/desbloqueio de acesso sem excluir histórico
- Exclusão de contas (somente se não houver solicitações vinculadas)
- Filtro por papel e busca por nome ou e-mail

---

## Fluxos operacionais

### Fluxo 1 — Cadastro e Acesso

```
Aluno acessa /register.php
    └── Preenche nome, e-mail e senha
        └── Conta criada → redireciona para login
            └── Login → Dashboard do aluno

Admin cria conta via /admin/usuarios.php
    └── Define nome, e-mail, senha e papel
        └── Conta disponível imediatamente
```

---

### Fluxo 2 — Solicitação de Material (Aluno)

```
Aluno acessa "Nova Solicitação"
    └── Seleciona materiais disponíveis no estoque
        └── Define urgência, justificativa e local de entrega
            └── Envia solicitação → status: PENDENTE
                └── Acompanha em "Minhas Solicitações"
```

---

### Fluxo 3 — Aprovação (Admin)

```
Admin acessa "Solicitações"
    └── Visualiza itens e estoque disponível
        ├── APROVA → status: APROVADA
        │       └── Aluno é notificado na próxima visita ao sistema
        └── REJEITA → status: REJEITADA
                └── Aluno vê status atualizado em "Minhas Solicitações"
```

---

### Fluxo 4 — Separação e Retirada (Admin)

```
Admin acessa "Separação"
    └── Seção "Aguardando Separação" (status: APROVADA)
        └── Separa fisicamente os materiais
            └── Confirma separação → status: SEPARADA
                └── Seção "Aguardando Retirada" (status: SEPARADA)
                    └── Confere identidade do aluno
                        └── Confirma retirada → status: RETIRADA
                                └── Estoque é DECREMENTADO automaticamente
```

---

### Fluxo 5 — Devolução e Conferência (Admin)

```
Admin acessa "Devolução"
    └── Lista de pendentes (status: RETIRADA)
        └── Aluno devolve os materiais fisicamente
            └── Admin confere estado dos itens
                └── Registra observação da conferência
                    └── Confirma devolução → status: DEVOLVIDA
                            └── Estoque é REPOSTO automaticamente
```

---

### Fluxo 6 — Relatório (Admin)

```
Admin acessa "Relatórios"
    └── Seleciona período (ou usa atalho rápido)
        └── Visualiza:
            ├── Totais e percentuais do período
            ├── Gráfico de solicitações por dia
            ├── Materiais mais solicitados (com estoque atual)
            ├── Alunos mais ativos
            └── Histórico completo de movimentações
                └── Imprime relatório com Ctrl+P ou botão na tela
```

---

### Resumo do ciclo de vida de uma solicitação

```
PENDENTE → APROVADA → SEPARADA → RETIRADA → DEVOLVIDA
                  ↘
                REJEITADA
```

| Status | Significado |
|---|---|
| `pendente` | Aguardando análise do almoxarife |
| `aprovada` | Aprovada, aguardando separação |
| `separada` | Materiais separados, aguardando retirada |
| `retirada` | Retirado pelo aluno (estoque decrementado) |
| `devolvida` | Devolvido e conferido (estoque reposto) |
| `rejeitada` | Negada pelo almoxarife |

---

## Estrutura de pastas

```
management-stock/
├── index.php                   # Redireciona pelo papel do usuário
├── login.php                   # Tela de login
├── register.php                # Auto-cadastro de alunos
├── logout.php                  # Encerra sessão
├── db.php                      # Conexão SQLite + criação do schema + seed admin
│
├── includes/
│   ├── auth.php                # Guards de sessão (requer_login, requer_admin, requer_aluno)
│   ├── header.php              # Navbar com dark/light mode
│   └── footer.php              # Rodapé + carrega app.js
│
├── assets/
│   ├── style.css               # Design system completo (dark + light mode)
│   └── app.js                  # Toggle de tema, toast, lógica da tabela de itens
│
├── aluno/
│   ├── dashboard.php           # Painel do aluno com stats e solicitações recentes
│   ├── solicitar.php           # Formulário de nova solicitação
│   └── minhas-solicitacoes.php # Histórico com filtros e modal de detalhe
│
├── admin/
│   ├── dashboard.php           # Painel admin com pendentes e estoque crítico
│   ├── materiais.php           # CRUD de materiais do estoque
│   ├── solicitacoes.php        # Aprovação e rejeição de solicitações
│   ├── separacao.php           # Separação e confirmação de retirada
│   ├── devolucao.php           # Conferência e confirmação de devolução
│   ├── relatorios.php          # Relatórios com filtros por período e impressão
│   └── usuarios.php            # Gestão de contas de alunos e admins
│
└── database/
    └── almoxarifado.db         # Banco SQLite (criado automaticamente)
```
