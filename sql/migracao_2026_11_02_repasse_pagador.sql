-- Repasse com cliente (quem deve) e, opcionalmente, o pagador.
ALTER TABLE adiantamentos ADD pagador_id INT NULL COMMENT 'pagador ligado ao repasse (opcional)' AFTER cliente_id,
    ADD FOREIGN KEY (pagador_id) REFERENCES pessoas(id);
