-- Meu Sistema — desconto de títulos, contas a receber/pagar, contas bancárias e cartões.
-- Criar do zero:  mysql -uroot -e "CREATE DATABASE meu_sistema CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci"
--                 mysql -uroot meu_sistema < sql/schema.sql

SET NAMES utf8mb4;

CREATE TABLE usuarios (
    id INT AUTO_INCREMENT PRIMARY KEY,
    nome VARCHAR(120) NOT NULL,
    email VARCHAR(160) NOT NULL UNIQUE,
    senha VARCHAR(255) NOT NULL,
    tema ENUM('claro', 'escuro') NOT NULL DEFAULT 'claro',
    ativo TINYINT(1) NOT NULL DEFAULT 1,
    criado_em DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Tentativas de login erradas (bloqueia por alguns minutos depois de várias).
CREATE TABLE tentativas_login (
    id INT AUTO_INCREMENT PRIMARY KEY,
    chave VARCHAR(200) NOT NULL,
    criado_em DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    INDEX (chave, criado_em)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Saldo da conta = saldo_inicial + entradas - saídas (tabela movimentacoes).
CREATE TABLE contas_bancarias (
    id INT AUTO_INCREMENT PRIMARY KEY,
    nome VARCHAR(80) NOT NULL,
    banco VARCHAR(80) NULL,
    saldo_inicial DECIMAL(14,2) NOT NULL DEFAULT 0,
    data_saldo_inicial DATE NOT NULL,
    cor CHAR(7) NOT NULL DEFAULT '#16a34a',
    ativo TINYINT(1) NOT NULL DEFAULT 1,
    criado_em DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Cadastros separados por papel: cliente (para quem empresto), pagador (quem
-- paga o título — o terceiro dono do cheque) e fornecedor. A mesma pessoa
-- que seja cliente e pagador tem um cadastro em cada papel. Dados completos
-- para emissão de boleto.
CREATE TABLE pessoas (
    id INT AUTO_INCREMENT PRIMARY KEY,
    papel ENUM('cliente', 'pagador', 'fornecedor') NOT NULL DEFAULT 'cliente',
    tipo_pessoa ENUM('F', 'J') NOT NULL DEFAULT 'F',
    nome VARCHAR(160) NOT NULL COMMENT 'nome completo ou razão social',
    nome_fantasia VARCHAR(160) NULL,
    documento VARCHAR(20) NULL COMMENT 'CPF/CNPJ só números',
    rg_ie VARCHAR(30) NULL,
    data_nascimento DATE NULL,
    telefone VARCHAR(20) NULL,
    celular VARCHAR(20) NULL,
    email VARCHAR(160) NULL,
    taxa_padrao DECIMAL(7,4) NULL COMMENT 'taxa do cliente nas operações (% ao mês)',
    limite_credito DECIMAL(14,2) NULL COMMENT 'quanto posso ter em aberto com este cliente',
    cep CHAR(8) NULL,
    logradouro VARCHAR(160) NULL,
    numero VARCHAR(20) NULL,
    complemento VARCHAR(80) NULL,
    bairro VARCHAR(80) NULL,
    cidade VARCHAR(80) NULL,
    uf CHAR(2) NULL,
    observacao TEXT NULL,
    criado_em DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    INDEX (papel, nome),
    INDEX (nome),
    INDEX (documento)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE categorias (
    id INT AUTO_INCREMENT PRIMARY KEY,
    nome VARCHAR(80) NOT NULL,
    tipo ENUM('receita', 'despesa') NOT NULL,
    ativo TINYINT(1) NOT NULL DEFAULT 1
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Dinheiro que adiantei ao cliente antes de receber os títulos. Sai da conta
-- na hora; fica "em aberto" até ser usado (todo ou em parte) numa operação.
CREATE TABLE adiantamentos (
    id INT AUTO_INCREMENT PRIMARY KEY,
    cliente_id INT NOT NULL,
    pagador_id INT NULL COMMENT 'pagador ligado ao repasse (opcional)',
    data DATE NOT NULL,
    valor DECIMAL(14,2) NOT NULL,
    valor_utilizado DECIMAL(14,2) NOT NULL DEFAULT 0,
    conta_id INT NULL,
    descricao VARCHAR(200) NULL,
    operacao_origem_id INT NULL COMMENT 'saldo devedor que sobrou desta operação',
    titulo_origem_id INT NULL COMMENT 'título devolvido ao cliente que gerou este repasse',
    criado_em DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    INDEX (cliente_id),
    FOREIGN KEY (cliente_id) REFERENCES pessoas(id),
    FOREIGN KEY (pagador_id) REFERENCES pessoas(id),
    FOREIGN KEY (conta_id) REFERENCES contas_bancarias(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Operação de desconto. Juros simples: valor × taxa/30 × dias.
-- Os adiantamentos usados são corrigidos da data de cada um até a data base,
-- e os títulos são trazidos a valor presente na data base.
CREATE TABLE operacoes (
    id INT AUTO_INCREMENT PRIMARY KEY,
    cliente_id INT NOT NULL,
    data_base DATE NOT NULL,
    taxa DECIMAL(7,4) NOT NULL COMMENT '% ao mês',
    dias_compensacao INT NOT NULL DEFAULT 0,
    valor_emprestado DECIMAL(14,2) NOT NULL DEFAULT 0 COMMENT 'soma dos adiantamentos usados',
    juros_emprestimo DECIMAL(14,2) NOT NULL DEFAULT 0,
    emprestado_atualizado DECIMAL(14,2) NOT NULL DEFAULT 0,
    total_bruto DECIMAL(14,2) NOT NULL,
    total_juros DECIMAL(14,2) NOT NULL,
    total_liquido DECIMAL(14,2) NOT NULL,
    saldo DECIMAL(14,2) NOT NULL COMMENT 'líquido - adiantamentos atualizados (+ a favor do cliente)',
    valor_pago_cliente DECIMAL(14,2) NOT NULL DEFAULT 0 COMMENT 'diferença paga ao cliente na operação',
    conta_pagamento_id INT NULL,
    observacao TEXT NULL,
    usuario_id INT NULL,
    criado_em DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (cliente_id) REFERENCES pessoas(id),
    FOREIGN KEY (conta_pagamento_id) REFERENCES contas_bancarias(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Quais adiantamentos (e quanto de cada) a operação quitou.
CREATE TABLE operacao_adiantamentos (
    id INT AUTO_INCREMENT PRIMARY KEY,
    operacao_id INT NOT NULL,
    adiantamento_id INT NOT NULL,
    valor_usado DECIMAL(14,2) NOT NULL,
    dias INT NOT NULL,
    juros DECIMAL(14,2) NOT NULL,
    FOREIGN KEY (operacao_id) REFERENCES operacoes(id) ON DELETE CASCADE,
    FOREIGN KEY (adiantamento_id) REFERENCES adiantamentos(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Tudo que tenho a receber: os títulos das operações (cheque, boleto...) e
-- lançamentos avulsos. emitente_id = quem vai pagar o título (o terceiro).
CREATE TABLE contas_receber (
    id INT AUTO_INCREMENT PRIMARY KEY,
    operacao_id INT NULL,
    restante_de_id INT NULL COMMENT 'título de onde veio este restante (recebimento a menor)',
    tipo VARCHAR(30) NOT NULL DEFAULT 'outro' COMMENT 'chave de tipos_recebimento',
    emitente_id INT NULL,
    cliente_id INT NULL COMMENT 'cliente da operação (quem me passou o título)',
    descricao VARCHAR(200) NULL,
    categoria_id INT NULL,
    banco_titulo VARCHAR(60) NULL,
    numero_titulo VARCHAR(60) NULL,
    valor DECIMAL(14,2) NOT NULL,
    data_vencimento DATE NOT NULL,
    dias INT NULL,
    juros DECIMAL(14,2) NULL,
    valor_liquido DECIMAL(14,2) NULL,
    conta_destino_id INT NULL,
    status ENUM('pendente', 'recebido', 'devolvido', 'cancelado', 'transferido') NOT NULL DEFAULT 'pendente' COMMENT 'transferido = devolvido para o cliente (virou repasse)',
    data_recebimento DATE NULL,
    valor_recebido DECIMAL(14,2) NULL,
    acrescimo DECIMAL(14,2) NULL COMMENT 'parte do recebido que foi juros/multa por atraso',
    conta_recebimento_id INT NULL,
    devolvido_em DATE NULL,
    motivo_devolucao VARCHAR(200) NULL,
    vezes_devolvido INT NOT NULL DEFAULT 0,
    observacao TEXT NULL,
    criado_em DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    INDEX (status, data_vencimento),
    INDEX (emitente_id),
    FOREIGN KEY (operacao_id) REFERENCES operacoes(id) ON DELETE CASCADE,
    FOREIGN KEY (emitente_id) REFERENCES pessoas(id),
    FOREIGN KEY (cliente_id) REFERENCES pessoas(id),
    FOREIGN KEY (categoria_id) REFERENCES categorias(id),
    FOREIGN KEY (conta_destino_id) REFERENCES contas_bancarias(id),
    FOREIGN KEY (conta_recebimento_id) REFERENCES contas_bancarias(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Tipos de recebimento (cheque, boleto... e os que o usuário criar) e a
-- regra de atualização de título vencido de cada um: pela taxa do cliente
-- (padrão), por juros/multa próprios do tipo, ou nenhuma.
CREATE TABLE tipos_recebimento (
    tipo VARCHAR(30) PRIMARY KEY COMMENT 'chave gravada em contas_receber.tipo',
    nome VARCHAR(60) NOT NULL,
    modo ENUM('cliente', 'proprio', 'nenhum') NOT NULL DEFAULT 'cliente',
    multa_percent DECIMAL(7,4) NULL COMMENT 'cobrada uma vez, sobre o valor',
    juros_mes DECIMAL(7,4) NULL COMMENT '% ao mês, juros simples por dia de atraso (mês de 30 dias)',
    ativo TINYINT(1) NOT NULL DEFAULT 1,
    ordem INT NOT NULL DEFAULT 0
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE contas_pagar (
    id INT AUTO_INCREMENT PRIMARY KEY,
    descricao VARCHAR(200) NOT NULL,
    categoria_id INT NULL,
    pessoa_id INT NULL,
    valor DECIMAL(14,2) NOT NULL,
    data_vencimento DATE NOT NULL,
    status ENUM('pendente', 'pago', 'cancelado') NOT NULL DEFAULT 'pendente',
    data_pagamento DATE NULL,
    valor_pago DECIMAL(14,2) NULL,
    conta_id INT NULL,
    observacao TEXT NULL,
    criado_em DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    INDEX (status, data_vencimento),
    FOREIGN KEY (categoria_id) REFERENCES categorias(id),
    FOREIGN KEY (pessoa_id) REFERENCES pessoas(id),
    FOREIGN KEY (conta_id) REFERENCES contas_bancarias(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE cartoes (
    id INT AUTO_INCREMENT PRIMARY KEY,
    nome VARCHAR(80) NOT NULL,
    bandeira VARCHAR(40) NULL,
    dia_fechamento TINYINT NOT NULL,
    dia_vencimento TINYINT NOT NULL,
    limite DECIMAL(14,2) NULL,
    conta_pagamento_id INT NULL,
    ativo TINYINT(1) NOT NULL DEFAULT 1,
    FOREIGN KEY (conta_pagamento_id) REFERENCES contas_bancarias(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Uma fatura por cartão por mês (competência = mês do vencimento, AAAA-MM).
CREATE TABLE cartao_faturas (
    id INT AUTO_INCREMENT PRIMARY KEY,
    cartao_id INT NOT NULL,
    competencia CHAR(7) NOT NULL,
    data_vencimento DATE NOT NULL,
    status ENUM('aberta', 'paga') NOT NULL DEFAULT 'aberta',
    data_pagamento DATE NULL,
    valor_pago DECIMAL(14,2) NULL,
    conta_id INT NULL,
    UNIQUE KEY (cartao_id, competencia),
    FOREIGN KEY (cartao_id) REFERENCES cartoes(id),
    FOREIGN KEY (conta_id) REFERENCES contas_bancarias(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE cartao_compras (
    id INT AUTO_INCREMENT PRIMARY KEY,
    cartao_id INT NOT NULL,
    descricao VARCHAR(200) NOT NULL,
    categoria_id INT NULL,
    data_compra DATE NOT NULL,
    valor_total DECIMAL(14,2) NOT NULL,
    parcelas INT NOT NULL,
    criado_em DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (cartao_id) REFERENCES cartoes(id),
    FOREIGN KEY (categoria_id) REFERENCES categorias(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE cartao_parcelas (
    id INT AUTO_INCREMENT PRIMARY KEY,
    compra_id INT NOT NULL,
    fatura_id INT NOT NULL,
    numero INT NOT NULL,
    valor DECIMAL(14,2) NOT NULL,
    FOREIGN KEY (compra_id) REFERENCES cartao_compras(id) ON DELETE CASCADE,
    FOREIGN KEY (fatura_id) REFERENCES cartao_faturas(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Extrato de cada conta. origem/origem_id dizem de onde veio o lançamento
-- (adiantamento, operacao, receber, pagar, fatura, transferencia, ajuste) para estornar.
CREATE TABLE movimentacoes (
    id INT AUTO_INCREMENT PRIMARY KEY,
    conta_id INT NOT NULL,
    data DATE NOT NULL,
    tipo ENUM('entrada', 'saida') NOT NULL,
    valor DECIMAL(14,2) NOT NULL,
    descricao VARCHAR(255) NOT NULL,
    origem VARCHAR(20) NOT NULL,
    origem_id INT NULL,
    criado_em DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    INDEX (conta_id, data),
    INDEX (origem, origem_id),
    FOREIGN KEY (conta_id) REFERENCES contas_bancarias(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT INTO categorias (nome, tipo) VALUES
    ('Desconto de títulos', 'receita'),
    ('Outras receitas', 'receita'),
    ('Cartão de crédito', 'despesa'),
    ('Casa', 'despesa'),
    ('Alimentação', 'despesa'),
    ('Transporte', 'despesa'),
    ('Impostos e taxas', 'despesa'),
    ('Outras despesas', 'despesa');

INSERT INTO tipos_recebimento (tipo, nome, ordem) VALUES
    ('cheque', 'Cheque', 1), ('boleto', 'Boleto', 2), ('promissoria', 'Promissória', 3), ('deposito', 'Depósito futuro', 4), ('outro', 'Outro', 99);

-- ---------------------------------------------------------------
-- Boletos pela API do Banco Inter (Cobrança v3, boleto com Pix) e baixa
-- automática por webhook.

-- Credenciais da integração na conta bancária do Inter.
ALTER TABLE contas_bancarias
    ADD integracao ENUM('nenhuma', 'inter') NOT NULL DEFAULT 'nenhuma' AFTER cor,
    ADD inter_ambiente ENUM('producao', 'sandbox') NOT NULL DEFAULT 'producao' AFTER integracao,
    ADD inter_client_id VARCHAR(100) NULL AFTER inter_ambiente,
    ADD inter_client_secret TEXT NULL COMMENT 'criptografado (includes/cripto.php)' AFTER inter_client_id,
    ADD inter_conta_corrente VARCHAR(20) NULL COMMENT 'header x-conta-corrente (quando há mais de uma conta)' AFTER inter_client_secret,
    ADD inter_certificado VARCHAR(255) NULL COMMENT 'arquivo .crt em storage/' AFTER inter_conta_corrente,
    ADD inter_chave VARCHAR(255) NULL COMMENT 'arquivo .key em storage/' AFTER inter_certificado,
    ADD inter_token TEXT NULL COMMENT 'access token (criptografado)' AFTER inter_chave,
    ADD inter_token_expira DATETIME NULL AFTER inter_token,
    ADD webhook_segredo VARCHAR(64) NULL COMMENT 'vai na URL do webhook' AFTER inter_token_expira,
    ADD webhook_registrado_em DATETIME NULL AFTER webhook_segredo;

-- Boleto emitido para o título.
ALTER TABLE contas_receber
    ADD boleto_conta_id INT NULL AFTER observacao,
    ADD boleto_codigo VARCHAR(60) NULL COMMENT 'codigoSolicitacao do Inter' AFTER boleto_conta_id,
    ADD boleto_nosso_numero VARCHAR(30) NULL AFTER boleto_codigo,
    ADD boleto_linha_digitavel VARCHAR(60) NULL AFTER boleto_nosso_numero,
    ADD boleto_codigo_barras VARCHAR(60) NULL AFTER boleto_linha_digitavel,
    ADD boleto_pix TEXT NULL COMMENT 'Pix copia e cola' AFTER boleto_codigo_barras,
    ADD boleto_situacao VARCHAR(30) NULL COMMENT 'A_RECEBER, RECEBIDO, CANCELADO, EXPIRADO...' AFTER boleto_pix,
    ADD boleto_emitido_em DATETIME NULL AFTER boleto_situacao,
    ADD UNIQUE KEY (boleto_codigo);

-- Avisos recebidos do Inter (para conferir e reprocessar).
CREATE TABLE webhook_eventos (
    id INT AUTO_INCREMENT PRIMARY KEY,
    conta_id INT NULL,
    codigo VARCHAR(60) NULL,
    situacao VARCHAR(30) NULL,
    payload MEDIUMTEXT NOT NULL,
    processado TINYINT(1) NOT NULL DEFAULT 0,
    resultado VARCHAR(255) NULL,
    recebido_em DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    INDEX (codigo), INDEX (processado)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
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
-- Renegociação de títulos, importação da planilha de boletos do Inter e o
-- campo "nosso número" separado do Nº (referência livre).
-- Uso: mysql -u USUARIO -p NOME_DO_BANCO < sql/migracao_2026_11_04_renegociacao_importacao.sql

SET NAMES utf8mb4;

-- Título substituído por uma renegociação fica "renegociado" (sai do em aberto,
-- fica no histórico); os novos apontam para a renegociação que os criou.
ALTER TABLE contas_receber
    MODIFY status ENUM('pendente', 'recebido', 'devolvido', 'cancelado', 'transferido', 'renegociado') NOT NULL DEFAULT 'pendente',
    ADD renegociacao_id INT NULL COMMENT 'título novo: renegociação que o criou' AFTER restante_de_id,
    ADD renegociado_em_id INT NULL COMMENT 'título antigo: renegociação que o substituiu' AFTER renegociacao_id,
    ADD INDEX idx_receber_renegociacao (renegociacao_id),
    ADD INDEX idx_receber_renegociado (renegociado_em_id),
    ADD INDEX idx_receber_nosso_numero (boleto_nosso_numero);

CREATE TABLE IF NOT EXISTS renegociacoes (
    id INT AUTO_INCREMENT PRIMARY KEY,
    data DATE NOT NULL COMMENT 'data base do cálculo dos encargos',
    pagador_id INT NULL,
    cliente_id INT NULL,
    total_original DECIMAL(14,2) NOT NULL,
    encargos DECIMAL(14,2) NOT NULL DEFAULT 0 COMMENT 'juros + multa até a data base (o combinado)',
    destino_encargos ENUM('pagador', 'cliente', 'isento') NOT NULL,
    total_novo DECIMAL(14,2) NOT NULL,
    parcelas INT NOT NULL,
    adiantamento_id INT NULL COMMENT 'repasse do cliente com os encargos (destino cliente)',
    observacao TEXT NULL,
    usuario_id INT NULL,
    desfeita_em DATETIME NULL,
    criado_em DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Planilhas de boletos importadas (não deixa o mesmo arquivo baixar duas vezes).
CREATE TABLE IF NOT EXISTS importacoes_banco (
    id INT AUTO_INCREMENT PRIMARY KEY,
    conta_id INT NOT NULL,
    arquivo VARCHAR(255) NOT NULL,
    hash CHAR(64) NOT NULL,
    linhas INT NOT NULL DEFAULT 0,
    baixados INT NOT NULL DEFAULT 0,
    total_baixado DECIMAL(14,2) NOT NULL DEFAULT 0,
    numeros_gravados INT NOT NULL DEFAULT 0,
    usuario_id INT NULL,
    criado_em DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_importacoes_hash (hash)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Boletos em que o nosso número foi digitado no campo Nº: copia para o campo
-- próprio (o Nº continua como referência).
UPDATE contas_receber
SET boleto_nosso_numero = numero_titulo
WHERE tipo = 'boleto' AND boleto_nosso_numero IS NULL AND numero_titulo REGEXP '^[0-9]{6,}$';

-- migracao_2026_11_05_pagamento_cliente_rentabilidade.sql
-- Pagamento da diferença ao cliente na operação: pago na hora (saída no
-- extrato) ou agendado — vira conta a pagar ligada à operação.
ALTER TABLE contas_pagar
    ADD COLUMN operacao_id INT NULL AFTER pessoa_id,
    ADD INDEX (operacao_id),
    ADD FOREIGN KEY (operacao_id) REFERENCES operacoes(id);

-- Relatório de rentabilidade (quanto ganhei: deságio, juros dos repasses, juros/multa por atraso).
INSERT IGNORE INTO permissoes (chave, descricao, grupo, ordem) VALUES
('rentabilidade', 'Relatório de rentabilidade (quanto ganhei)', 'Relatórios', 50);

-- migracao_2026_11_05_arredondamento_cliente.sql
-- Pagamento ao cliente menor que a diferença (ex.: 4.027,05 → paguei 4.000,00):
-- o que faltou fica como arredondamento (ganho meu) ou agendado como conta a pagar.
ALTER TABLE operacoes
    ADD COLUMN desconto_pagamento DECIMAL(14,2) NOT NULL DEFAULT 0 COMMENT 'parte da diferença que não foi paga ao cliente (arredondamento)' AFTER valor_pago_cliente;

-- migracao_2026_11_05_tipo_gera_boleto.sql
-- Tipos de recebimento que são cobrados por boleto registrado no banco
-- (cheque, depósito... não registram). Usado no "Registrar boleto" do contas a receber.
ALTER TABLE tipos_recebimento
    ADD COLUMN gera_boleto TINYINT(1) NOT NULL DEFAULT 0 COMMENT 'registra boleto no banco' AFTER juros_mes;
UPDATE tipos_recebimento SET gera_boleto = 1 WHERE tipo = 'boleto';
