-- Clientes, pagadores (emitentes dos títulos) e fornecedores viram cadastros
-- separados (coluna papel) e ganham os dados completos exigidos para emitir
-- boleto (tipo de pessoa, endereço completo...).

ALTER TABLE pessoas
    ADD papel ENUM('cliente', 'pagador', 'fornecedor') NOT NULL DEFAULT 'cliente' AFTER id,
    ADD tipo_pessoa ENUM('F', 'J') NOT NULL DEFAULT 'F' AFTER papel,
    ADD nome_fantasia VARCHAR(160) NULL AFTER nome,
    ADD rg_ie VARCHAR(30) NULL AFTER documento,
    ADD data_nascimento DATE NULL AFTER rg_ie,
    ADD celular VARCHAR(20) NULL AFTER telefone,
    ADD cep CHAR(8) NULL AFTER limite_credito,
    ADD logradouro VARCHAR(160) NULL AFTER cep,
    ADD numero VARCHAR(20) NULL AFTER logradouro,
    ADD complemento VARCHAR(80) NULL AFTER numero,
    ADD bairro VARCHAR(80) NULL AFTER complemento,
    ADD cidade VARCHAR(80) NULL AFTER bairro,
    ADD uf CHAR(2) NULL AFTER cidade,
    ADD migrado_de INT NULL,
    ADD INDEX (papel, nome);

UPDATE pessoas SET tipo_pessoa = 'J' WHERE CHAR_LENGTH(documento) = 14;

-- Quem é cliente: tem operação, adiantamento, título passado, taxa ou limite.
CREATE TEMPORARY TABLE eh_cliente AS
    SELECT id FROM pessoas WHERE taxa_padrao IS NOT NULL OR limite_credito IS NOT NULL
    UNION SELECT cliente_id FROM operacoes
    UNION SELECT cliente_id FROM adiantamentos
    UNION SELECT cliente_id FROM contas_receber WHERE cliente_id IS NOT NULL;

-- Emitentes que não são clientes viram pagadores.
UPDATE pessoas SET papel = 'pagador'
WHERE id IN (SELECT emitente_id FROM contas_receber WHERE emitente_id IS NOT NULL)
  AND id NOT IN (SELECT id FROM eh_cliente);

-- Só aparecem em contas a pagar: fornecedores.
UPDATE pessoas SET papel = 'fornecedor'
WHERE id IN (SELECT pessoa_id FROM contas_pagar WHERE pessoa_id IS NOT NULL)
  AND id NOT IN (SELECT id FROM eh_cliente)
  AND id NOT IN (SELECT emitente_id FROM contas_receber WHERE emitente_id IS NOT NULL);

-- Cliente que também é emitente: ganha um cadastro separado de pagador,
-- e os títulos em que ele é emitente passam para esse cadastro.
INSERT INTO pessoas (papel, tipo_pessoa, nome, documento, telefone, email, migrado_de)
SELECT 'pagador', p.tipo_pessoa, p.nome, p.documento, p.telefone, p.email, p.id
FROM pessoas p
WHERE p.papel = 'cliente' AND p.id IN (SELECT emitente_id FROM contas_receber WHERE emitente_id IS NOT NULL);

UPDATE contas_receber r JOIN pessoas n ON n.migrado_de = r.emitente_id SET r.emitente_id = n.id;

ALTER TABLE pessoas DROP COLUMN migrado_de;
DROP TEMPORARY TABLE eh_cliente;
