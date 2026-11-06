-- Pagamento da diferença ao cliente na operação: pago na hora (saída no
-- extrato) ou agendado — vira conta a pagar ligada à operação.
ALTER TABLE contas_pagar
    ADD COLUMN operacao_id INT NULL AFTER pessoa_id,
    ADD INDEX (operacao_id),
    ADD FOREIGN KEY (operacao_id) REFERENCES operacoes(id);

-- Relatório de rentabilidade (quanto ganhei: deságio, juros dos repasses, juros/multa por atraso).
INSERT IGNORE INTO permissoes (chave, descricao, grupo, ordem) VALUES
('rentabilidade', 'Relatório de rentabilidade (quanto ganhei)', 'Relatórios', 50);
