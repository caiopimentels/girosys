-- "Devolver para o cliente": o título sai do contas a receber (status
-- transferido) e vira um repasse em aberto do cliente (adiantamento sem
-- saída de conta), ligado ao título de origem.
ALTER TABLE contas_receber MODIFY status ENUM('pendente', 'recebido', 'devolvido', 'cancelado', 'transferido') NOT NULL DEFAULT 'pendente';
ALTER TABLE adiantamentos ADD titulo_origem_id INT NULL COMMENT 'título devolvido ao cliente que gerou este repasse' AFTER operacao_origem_id;
