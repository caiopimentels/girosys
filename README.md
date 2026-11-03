# Meu Sistema — desconto de títulos e financeiro

Mesmo layout e base do `sistema_keyla` (PHP puro + MySQL, Bootstrap 5, `app.css`).

## Instalar

```bash
cp config/config.example.php config/config.php   # ajuste banco/usuário
mysql -uroot -e "CREATE DATABASE meu_sistema CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci"
mysql -uroot meu_sistema < sql/schema.sql
cd public && php -S 127.0.0.1:8099                # ou aponte o Apache/Nginx para public/
```

No primeiro acesso a tela de login vira "criar acesso".

## Como funciona

- **Operação de desconto** (`/operacoes/form.php`): juros simples, mês de 30 dias —
  `juros = valor × taxa% / 30 × dias`. Os títulos são trazidos para a **data base**; os valores
  adiantados ao cliente ("Adicionar despesa") são corrigidos pela mesma taxa da data de cada um até a data base.
  - Diferença positiva: pode lançar a saída (pagamento ao cliente) na hora.
  - Diferença negativa: vira um adiantamento novo em aberto ("saldo devedor da operação").
- **Taxa por cliente**: fica no cadastro da pessoa e já vem preenchida na operação.
- Cada título vira uma **conta a receber**, com o **emitente** (terceiro dono do cheque) e a **conta bancária** onde vai cair.
  O histórico do emitente (em dia / com atraso / devolvido / vencido) aparece no cadastro dele e na própria operação.
- **Contas bancárias**: saldo = saldo inicial + movimentações. Saldo global no início e em Contas bancárias.
- **Cartões**: compra parcelada vira uma parcela em cada fatura (compra no dia do fechamento ou depois vai para a fatura seguinte).

A conta do desconto está em `includes/desconto.php` (vale ao gravar) e repetida em `public/assets/js/operacao.js` (só para a tela).
