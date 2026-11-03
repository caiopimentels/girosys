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
