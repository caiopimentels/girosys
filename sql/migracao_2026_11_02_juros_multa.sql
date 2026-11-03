-- Atualização de título vencido por tipo de recebimento (cheque, boleto...):
-- pela taxa do cliente (padrão), por juros/multa próprios do tipo, ou nenhuma.
CREATE TABLE config_recebimento (
    tipo VARCHAR(20) PRIMARY KEY,
    modo ENUM('cliente', 'proprio', 'nenhum') NOT NULL DEFAULT 'cliente',
    multa_percent DECIMAL(7,4) NULL COMMENT 'cobrada uma vez, sobre o valor',
    juros_mes DECIMAL(7,4) NULL COMMENT '% ao mês, juros simples por dia de atraso (mês de 30 dias)'
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT INTO config_recebimento (tipo) VALUES ('cheque'), ('boleto'), ('promissoria'), ('deposito'), ('outro');

-- Quanto do valor recebido foi juros/multa por atraso.
ALTER TABLE contas_receber ADD acrescimo DECIMAL(14,2) NULL AFTER valor_recebido;
