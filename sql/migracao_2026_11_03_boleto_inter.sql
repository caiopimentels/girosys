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
