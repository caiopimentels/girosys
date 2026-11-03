-- Tipos de recebimento viram cadastro (criar, ativar/desativar, excluir):
-- a regra de juros/multa de cada tipo fica na mesma tabela.
ALTER TABLE contas_receber MODIFY tipo VARCHAR(30) NOT NULL DEFAULT 'outro';
RENAME TABLE config_recebimento TO tipos_recebimento;
ALTER TABLE tipos_recebimento
    MODIFY tipo VARCHAR(30) NOT NULL COMMENT 'chave gravada em contas_receber.tipo',
    ADD nome VARCHAR(60) NOT NULL DEFAULT '' AFTER tipo,
    ADD ativo TINYINT(1) NOT NULL DEFAULT 1,
    ADD ordem INT NOT NULL DEFAULT 0;
UPDATE tipos_recebimento SET nome = 'Cheque', ordem = 1 WHERE tipo = 'cheque';
UPDATE tipos_recebimento SET nome = 'Boleto', ordem = 2 WHERE tipo = 'boleto';
UPDATE tipos_recebimento SET nome = 'Promissória', ordem = 3 WHERE tipo = 'promissoria';
UPDATE tipos_recebimento SET nome = 'Depósito futuro', ordem = 4 WHERE tipo = 'deposito';
UPDATE tipos_recebimento SET nome = 'Outro', ordem = 99 WHERE tipo = 'outro';
