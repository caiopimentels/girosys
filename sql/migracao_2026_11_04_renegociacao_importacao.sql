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
