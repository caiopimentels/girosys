-- Padrão dos sistemas Brimap (UniPHD): configurações no banco, auditoria de
-- tudo que é feito, auditoria das chamadas à API do banco, usuários com
-- perfis de acesso, recuperação de senha e limite de tentativas.
-- Uso: mysql -u USUARIO -p NOME_DO_BANCO < sql/migracao_2026_11_04_padrao_uniphd.sql

SET NAMES utf8mb4;

-- Configurações gerais (linha única, id sempre 1).
CREATE TABLE IF NOT EXISTS configuracoes (
    id INT PRIMARY KEY DEFAULT 1,
    nome_sistema VARCHAR(100) NOT NULL DEFAULT 'GiroSys',

    -- Identidade visual do painel
    logo_sistema VARCHAR(255) DEFAULT NULL,
    cor_primaria VARCHAR(7) NOT NULL DEFAULT '#16a34a',
    cor_secundaria VARCHAR(7) NOT NULL DEFAULT '#15803d',
    logo_pos_x TINYINT UNSIGNED NOT NULL DEFAULT 50,
    logo_pos_y TINYINT UNSIGNED NOT NULL DEFAULT 50,
    logo_zoom DECIMAL(4,2) NOT NULL DEFAULT 1.00,

    -- Dados da empresa (beneficiário dos boletos, cabeçalho dos relatórios)
    empresa_razao_social VARCHAR(150) DEFAULT NULL,
    empresa_nome_fantasia VARCHAR(150) DEFAULT NULL,
    empresa_cnpj VARCHAR(18) DEFAULT NULL,
    empresa_email VARCHAR(150) DEFAULT NULL,
    empresa_telefone VARCHAR(20) DEFAULT NULL,
    empresa_cep VARCHAR(10) DEFAULT NULL,
    empresa_logradouro VARCHAR(150) DEFAULT NULL,
    empresa_numero VARCHAR(20) DEFAULT NULL,
    empresa_complemento VARCHAR(100) DEFAULT NULL,
    empresa_bairro VARCHAR(100) DEFAULT NULL,
    empresa_cidade VARCHAR(100) DEFAULT NULL,
    empresa_uf CHAR(2) DEFAULT NULL,

    -- Operação: título recebido até X dias depois do vencimento ainda conta
    -- como "em dia" no histórico do emitente.
    tolerancia_compensacao_dias TINYINT UNSIGNED NOT NULL DEFAULT 3,

    -- Segurança
    sessao_timeout_minutos INT NOT NULL DEFAULT 60,

    -- E-mail (SMTP): "esqueci minha senha" e avisos de erro.
    smtp_host VARCHAR(150) DEFAULT NULL,
    smtp_porta INT NOT NULL DEFAULT 587,
    smtp_seguranca VARCHAR(10) NOT NULL DEFAULT 'tls',
    smtp_usuario VARCHAR(150) DEFAULT NULL,
    smtp_senha VARCHAR(255) DEFAULT NULL,
    smtp_remetente_nome VARCHAR(150) DEFAULT NULL,
    smtp_remetente_email VARCHAR(150) DEFAULT NULL,
    email_notificacao_erros VARCHAR(150) DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT IGNORE INTO configuracoes (id) VALUES (1);

-- Perfis de acesso e permissões (administrador tem tudo).
CREATE TABLE IF NOT EXISTS perfis (
    id INT AUTO_INCREMENT PRIMARY KEY,
    nome VARCHAR(100) NOT NULL,
    administrador TINYINT(1) NOT NULL DEFAULT 0,
    criado_em TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS permissoes (
    id INT AUTO_INCREMENT PRIMARY KEY,
    chave VARCHAR(50) NOT NULL UNIQUE,
    descricao VARCHAR(150) NOT NULL,
    grupo VARCHAR(40) NOT NULL DEFAULT 'Geral',
    ordem INT NOT NULL DEFAULT 0
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS perfil_permissoes (
    perfil_id INT NOT NULL,
    permissao_id INT NOT NULL,
    PRIMARY KEY (perfil_id, permissao_id),
    CONSTRAINT fk_pp_perfil FOREIGN KEY (perfil_id) REFERENCES perfis(id) ON DELETE CASCADE,
    CONSTRAINT fk_pp_permissao FOREIGN KEY (permissao_id) REFERENCES permissoes(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT IGNORE INTO perfis (id, nome, administrador) VALUES (1, 'Administrador', 1);

INSERT IGNORE INTO permissoes (chave, descricao, grupo, ordem) VALUES
('ver_dashboard', 'Ver o Início (resumo e saldos)', 'Painel', 1),
('operacoes', 'Operações de desconto (lançar, recalcular, excluir)', 'Operações', 10),
('repasses', 'Repasses a clientes', 'Operações', 11),
('receber', 'Contas a receber (lançar, editar, excluir)', 'Financeiro', 20),
('baixar_receber', 'Dar baixa e estornar recebimentos', 'Financeiro', 21),
('emitir_boleto', 'Emitir e cancelar boletos no banco', 'Financeiro', 22),
('pagar', 'Contas a pagar (lançar, pagar, excluir)', 'Financeiro', 23),
('movimentacao', 'Ver recebimentos e pagamentos', 'Financeiro', 24),
('cartoes', 'Cartões de crédito e faturas', 'Financeiro', 25),
('contas', 'Contas bancárias: saldos, extrato, transferências', 'Contas bancárias', 30),
('pessoas', 'Clientes, pagadores e fornecedores', 'Cadastros', 40),
('cadastros_auxiliares', 'Categorias e tipos de recebimento', 'Cadastros', 41);

-- Usuários ganham perfil e o token de sessão única.
ALTER TABLE usuarios
    ADD perfil_id INT NOT NULL DEFAULT 1 AFTER senha,
    ADD token_sessao VARCHAR(64) DEFAULT NULL AFTER ativo,
    ADD CONSTRAINT fk_usuario_perfil FOREIGN KEY (perfil_id) REFERENCES perfis(id);

-- Recuperação de senha por e-mail.
CREATE TABLE IF NOT EXISTS usuarios_reset_senha (
    id INT AUTO_INCREMENT PRIMARY KEY,
    usuario_id INT NOT NULL,
    token VARCHAR(64) NOT NULL UNIQUE,
    criado_em DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    expira_em DATETIME NOT NULL,
    usado_em DATETIME DEFAULT NULL,
    INDEX idx_reset_senha_usuario (usuario_id),
    CONSTRAINT fk_reset_senha_usuario FOREIGN KEY (usuario_id) REFERENCES usuarios(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Limite de tentativas de login e de recuperação de senha (substitui
-- tentativas_login).
CREATE TABLE IF NOT EXISTS tentativas_acesso (
    id INT AUTO_INCREMENT PRIMARY KEY,
    tipo VARCHAR(30) NOT NULL,
    identificador VARCHAR(190) NOT NULL,
    ip VARCHAR(45) NOT NULL,
    criado_em DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_tentativas_tipo_id (tipo, identificador, criado_em),
    INDEX idx_tentativas_tipo_ip (tipo, ip, criado_em)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

DROP TABLE IF EXISTS tentativas_login;

-- Auditoria: quem fez o quê e quando (Configurações › Auditoria).
CREATE TABLE IF NOT EXISTS log_atividades (
    id INT AUTO_INCREMENT PRIMARY KEY,
    usuario_id INT DEFAULT NULL,
    usuario_nome VARCHAR(150) NOT NULL,
    modulo VARCHAR(40) NOT NULL,
    operacao VARCHAR(40) NOT NULL,
    descricao VARCHAR(255) NOT NULL,
    ip VARCHAR(45) DEFAULT NULL,
    user_agent VARCHAR(255) DEFAULT NULL,
    criado_em DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_log_modulo (modulo, criado_em),
    INDEX idx_log_usuario (usuario_id, criado_em),
    INDEX idx_log_criado (criado_em)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Auditoria do banco: cada chamada feita à API do banco (token, emissão,
-- consulta, cancelamento, webhook) com o que foi enviado e a resposta.
CREATE TABLE IF NOT EXISTS banco_chamadas (
    id INT AUTO_INCREMENT PRIMARY KEY,
    conta_bancaria_id INT DEFAULT NULL,
    operacao VARCHAR(40) NOT NULL,
    conta_receber_id INT DEFAULT NULL,
    usuario_id INT DEFAULT NULL,
    origem ENUM('sistema', 'automatico') NOT NULL DEFAULT 'sistema',
    metodo VARCHAR(10) NOT NULL,
    url VARCHAR(255) NOT NULL,
    status_http SMALLINT DEFAULT NULL,
    sucesso TINYINT(1) NOT NULL DEFAULT 0,
    requisicao MEDIUMTEXT DEFAULT NULL,
    resposta MEDIUMTEXT DEFAULT NULL,
    mensagem VARCHAR(255) DEFAULT NULL,
    duracao_ms INT DEFAULT NULL,
    criado_em DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    KEY idx_banco_chamadas_conta (conta_bancaria_id, criado_em),
    KEY idx_banco_chamadas_titulo (conta_receber_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Avisos do webhook: IP de quem mandou e se foi aceito (segredo certo).
ALTER TABLE webhook_eventos
    ADD ip VARCHAR(45) DEFAULT NULL AFTER resultado,
    ADD aceito TINYINT(1) NOT NULL DEFAULT 1 AFTER ip;
