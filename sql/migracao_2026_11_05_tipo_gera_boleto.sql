-- Tipos de recebimento que são cobrados por boleto registrado no banco
-- (cheque, depósito... não registram). Usado no "Registrar boleto" do contas a receber.
ALTER TABLE tipos_recebimento
    ADD COLUMN gera_boleto TINYINT(1) NOT NULL DEFAULT 0 COMMENT 'registra boleto no banco' AFTER juros_mes;
UPDATE tipos_recebimento SET gera_boleto = 1 WHERE tipo = 'boleto';
