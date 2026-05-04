-- ================================================================
--  Sorriso Calories — Schema MySQL
--  Execute UMA VEZ em uma instalação nova.
--  Se já tiver o banco criado use o arquivo de migrações.
-- ================================================================

CREATE TABLE IF NOT EXISTS usuarios (
  id             INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  nome           VARCHAR(100)  NOT NULL,
  email          VARCHAR(150)  NOT NULL UNIQUE,
  senha          VARCHAR(255)  NOT NULL DEFAULT '',
  email_verificado TINYINT(1)  DEFAULT 0,
  token_verificacao VARCHAR(64) NULL,
  token_verificacao_exp DATETIME NULL,
  genero         ENUM('M','F') NOT NULL DEFAULT 'F',
  data_nasc      DATE,
  peso_atual     DECIMAL(5,2),
  peso_alvo      DECIMAL(5,2),
  altura_cm      SMALLINT UNSIGNED,
  nivel_ativ     ENUM('sedentary','light','moderate','active','extra') DEFAULT 'light',
  meta_kcal      SMALLINT UNSIGNED,
  meta_agua_l    DECIMAL(3,1)  DEFAULT 2.5,
  refeicoes_dia  TINYINT       DEFAULT 4,
  objetivo       ENUM('loss','maintain','gain') DEFAULT 'loss',
  criado_em      DATETIME      DEFAULT CURRENT_TIMESTAMP,
  atualizado_em  DATETIME      ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS tokens (
  id         INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  usuario_id INT UNSIGNED NOT NULL,
  token      VARCHAR(64)  NOT NULL UNIQUE,
  expires_at DATETIME     NOT NULL,
  criado_em  DATETIME     DEFAULT CURRENT_TIMESTAMP,
  FOREIGN KEY (usuario_id) REFERENCES usuarios(id) ON DELETE CASCADE,
  INDEX idx_token (token)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS tokens_senha (
  id         INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  usuario_id INT UNSIGNED NOT NULL,
  token      VARCHAR(64)  NOT NULL UNIQUE,
  expires_at DATETIME     NOT NULL,
  usado      TINYINT(1)   DEFAULT 0,
  criado_em  DATETIME     DEFAULT CURRENT_TIMESTAMP,
  FOREIGN KEY (usuario_id) REFERENCES usuarios(id) ON DELETE CASCADE,
  INDEX idx_token_senha (token)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS alimentos (
  id           INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  nome         VARCHAR(200) NOT NULL,
  descricao    VARCHAR(300),
  marca        VARCHAR(100),
  categoria    ENUM('Proteína','Carboidrato','Gordura','Fruta','Vegetal','Laticínio','Bebida','Outro') DEFAULT 'Outro',
  emoji        VARCHAR(10)  DEFAULT '🍽️',
  porcao_g     DECIMAL(8,2) NOT NULL DEFAULT 100,
  unidade      ENUM('g','ml','un','col','xic','fatia') DEFAULT 'g',
  kcal         DECIMAL(8,2) NOT NULL DEFAULT 0,
  proteina_g   DECIMAL(6,2) DEFAULT 0,
  carb_g       DECIMAL(6,2) DEFAULT 0,
  gordura_g    DECIMAL(6,2) DEFAULT 0,
  gord_sat_g   DECIMAL(6,2) DEFAULT 0,
  fibra_g      DECIMAL(6,2) DEFAULT 0,
  acucar_g     DECIMAL(6,2) DEFAULT 0,
  sodio_mg     DECIMAL(7,2) DEFAULT 0,
  calcio_mg    DECIMAL(7,2) DEFAULT 0,
  ferro_mg     DECIMAL(6,2) DEFAULT 0,
  vitamina_c_mg DECIMAL(6,2) DEFAULT 0,
  fonte        VARCHAR(100) DEFAULT 'Manual',
  ativo        TINYINT(1)   DEFAULT 1,
  criado_em    DATETIME     DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS refeicoes (
  id           INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  usuario_id   INT UNSIGNED NOT NULL,
  tipo         ENUM('cafe','lanche_manha','almoco','lanche_tarde','jantar','ceia','outro') DEFAULT 'almoco',
  nome_custom  VARCHAR(100),
  data_ref     DATE         NOT NULL,
  hora_ref     TIME,
  obs          TEXT,
  kcal_total   DECIMAL(8,2) DEFAULT 0,
  proteina_g   DECIMAL(6,2) DEFAULT 0,
  carb_g       DECIMAL(6,2) DEFAULT 0,
  gordura_g    DECIMAL(6,2) DEFAULT 0,
  criado_em    DATETIME     DEFAULT CURRENT_TIMESTAMP,
  FOREIGN KEY (usuario_id) REFERENCES usuarios(id) ON DELETE CASCADE,
  INDEX idx_usuario_data (usuario_id, data_ref)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS refeicao_itens (
  id           INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  refeicao_id  INT UNSIGNED NOT NULL,
  alimento_id  INT UNSIGNED NOT NULL,
  quantidade_g DECIMAL(8,2) NOT NULL DEFAULT 100,
  kcal         DECIMAL(8,2) DEFAULT 0,
  proteina_g   DECIMAL(6,2) DEFAULT 0,
  carb_g       DECIMAL(6,2) DEFAULT 0,
  gordura_g    DECIMAL(6,2) DEFAULT 0,
  fibra_g      DECIMAL(6,2) DEFAULT 0,
  FOREIGN KEY (refeicao_id) REFERENCES refeicoes(id) ON DELETE CASCADE,
  FOREIGN KEY (alimento_id) REFERENCES alimentos(id),
  INDEX idx_refeicao (refeicao_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS historico_diario (
  id           INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  usuario_id   INT UNSIGNED NOT NULL,
  data         DATE         NOT NULL,
  kcal_total   DECIMAL(8,2) DEFAULT 0,
  kcal_meta    SMALLINT UNSIGNED DEFAULT 0,
  proteina_g   DECIMAL(6,2) DEFAULT 0,
  carb_g       DECIMAL(6,2) DEFAULT 0,
  gordura_g    DECIMAL(6,2) DEFAULT 0,
  agua_ml      SMALLINT UNSIGNED DEFAULT 0,
  atualizado_em DATETIME    DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  UNIQUE KEY uk_usuario_data (usuario_id, data),
  FOREIGN KEY (usuario_id) REFERENCES usuarios(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;