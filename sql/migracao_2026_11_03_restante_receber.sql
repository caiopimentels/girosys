-- Recebimento a menor: o que faltou pode virar um título novo (em aberto ou
-- cobrado do pagador) ligado ao título original.
ALTER TABLE contas_receber ADD restante_de_id INT NULL COMMENT 'título de onde veio este restante (recebimento a menor)' AFTER operacao_id;
