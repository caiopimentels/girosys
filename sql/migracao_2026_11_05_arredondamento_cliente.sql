-- Pagamento ao cliente menor que a diferença (ex.: 4.027,05 → paguei 4.000,00):
-- o que faltou fica como arredondamento (ganho meu) ou agendado como conta a pagar.
ALTER TABLE operacoes
    ADD COLUMN desconto_pagamento DECIMAL(14,2) NOT NULL DEFAULT 0 COMMENT 'parte da diferença que não foi paga ao cliente (arredondamento)' AFTER valor_pago_cliente;
