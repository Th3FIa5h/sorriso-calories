# 🥗 Sorriso Calories

Sistema web de controle alimentar e acompanhamento calórico diário.

---

## 📋 O que o sistema faz

- Cadastro de perfil com peso, altura, idade e objetivo (perda, manutenção ou ganho)
- Cálculo automático da meta calórica diária pela fórmula **Mifflin-St Jeor**
- Registro de refeições do dia com busca de alimentos em tempo real
- Banco com ~80 alimentos reais da tabela **TACO/IBGE**
- Dashboard com resumo calórico, gráfico de macros e sugestões do dia
- CRUD completo de alimentos: criar, buscar, editar e excluir
- Histórico calórico diário com streak de dias consecutivos

---

## 🗂️ Estrutura do Projeto

```
sorriso-calories/
│
├── api/                          ← Backend (PHP puro, API REST)
│   ├── .htaccess                 ← Redireciona todas as rotas para index.php
│   ├── index.php                 ← Roteador central da API
│   ├── helpers.php               ← Funções auxiliares compartilhadas
│   ├── config/
│   │   └── database.php          ← Configuração e conexão com o MySQL
│   └── routes/
│       ├── alimentos.php         ← CRUD de alimentos
│       ├── refeicoes.php         ← CRUD de refeições e seus itens
│       ├── usuarios.php          ← CRUD de usuários
│       ├── dashboard.php         ← Resumo do dia para o dashboard
│       └── historico.php         ← Histórico calórico por período
│
├── public/                       ← Frontend (HTML + CSS + JavaScript puro)
│   ├── index.html                ← Dashboard principal
│   ├── css/
│   │   └── shared.css            ← Estilos globais de todas as páginas
│   ├── js/
│   │   └── api.js                ← Cliente HTTP, sidebar, onboarding, toasts
│   └── pages/
│       ├── refeicoes.html        ← Visualização das refeições do dia
│       ├── adicionar-refeicao.html ← Formulário para registrar refeição
│       ├── alimentos.html        ← Tabela de alimentos com busca e filtros
│       ├── cadastro-alimento.html  ← Formulário para cadastrar alimento
│       ├── calculo.html          ← Calculadora de calorias (TMB/TDEE/IMC)
│       └── perfil.html           ← Edição de perfil e objetivos
│
└── sql/
    ├── 01_schema.sql             ← Criação das tabelas do banco
    └── 02_seed_alimentos.sql     ← Carga inicial de ~80 alimentos
```

---

## ⚙️ Tecnologias Utilizadas

| Camada   | Tecnologia                        |
|----------|-----------------------------------|
| Frontend | HTML5, CSS3, JavaScript (vanilla) |
| Backend  | PHP 8.1+                          |
| Banco    | MySQL / MariaDB                   |
| Servidor | Apache via Laragon ou XAMPP       |

---

## 🚀 Como Instalar e Rodar

### Pré-requisitos
- [Laragon](https://laragon.org/download/) instalado (recomendado) **ou** XAMPP

### Passo 1 — Copiar o projeto
Coloque a pasta `sorriso-calories` dentro de:
- **Laragon:** `C:\laragon\www\`
- **XAMPP:** `C:\xampp\htdocs\`

### Passo 2 — Iniciar o servidor
- Abra o Laragon e clique em **Start All**
- Apache e MySQL devem ficar verdes

### Passo 3 — Criar o banco de dados
1. Abra o **HeidiSQL** (Menu do Laragon → Database)
2. Execute o arquivo `sql/01_schema.sql`
3. Execute o arquivo `sql/02_seed_alimentos.sql`

### Passo 4 — Verificar a senha
Abra `api/config/database.php` e confirme:
```php
define('DB_PASS', 'root'); // Laragon = 'root' | XAMPP = ''
```

### Passo 5 — Acessar o sistema
```
http://localhost/sorriso-calories/public/index.html
```
Na primeira visita o sistema abre automaticamente o formulário de cadastro.

---

## 🔌 Endpoints da API

Base URL: `http://localhost/sorriso-calories/api`

| Método | Rota | Descrição |
|--------|------|-----------|
| GET | `/alimentos?q=frango` | Busca com filtro e paginação |
| GET | `/alimentos/{id}` | Detalhe de um alimento |
| POST | `/alimentos` | Cadastrar alimento |
| PUT | `/alimentos/{id}` | Editar alimento |
| DELETE | `/alimentos/{id}` | Remover alimento (soft delete) |
| GET | `/refeicoes?usuario_id=1&data=2026-04-15` | Refeições do dia |
| POST | `/refeicoes` | Criar refeição |
| DELETE | `/refeicoes/{id}` | Excluir refeição |
| POST | `/refeicoes/{id}/itens` | Adicionar alimento à refeição |
| DELETE | `/refeicoes/{id}/itens/{itemId}` | Remover alimento da refeição |
| GET | `/usuarios/{id}` | Dados do usuário |
| POST | `/usuarios` | Criar usuário |
| PUT | `/usuarios/{id}` | Atualizar perfil |
| GET | `/dashboard?usuario_id=1` | Resumo do dia |
| GET | `/historico?usuario_id=1&dias=30` | Histórico calórico |

---

## 🗃️ Banco de Dados

| Tabela | Descrição |
|--------|-----------|
| `usuarios` | Dados do usuário, metas e objetivos |
| `alimentos` | Tabela nutricional de alimentos |
| `refeicoes` | Registro de refeições por usuário e data |
| `refeicao_itens` | Alimentos dentro de cada refeição |
| `historico_diario` | Totais calóricos por dia por usuário |

---

## ⚠️ Problemas Comuns

**Pop-up de cadastro não aparece**
Abra o console do navegador (F12) e execute:
```javascript
localStorage.clear()
```
Recarregue a página.

**Erro 404 na API**
Verifique se o Apache está rodando e se o `mod_rewrite` está ativo.

**Erro de conexão com o banco**
Confira a senha em `api/config/database.php`.
Laragon usa `'root'`, XAMPP usa `''` (vazio).
