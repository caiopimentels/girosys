<?php

// Bloco de pagamento usado em despesa (pagar/baixar.php) e fatura de cartão
// (cartoes/fatura.php): várias contas no mesmo pagamento, pagamento parcial
// e a lista das partes já pagas com estorno de cada uma.

/** Formulário "Pagar": data + uma linha (conta, valor) por conta usada. */
function formPagamento(PDO $pdo, float $falta, ?int $contaPadrao): string
{
    $contas = $pdo->query('SELECT id, nome FROM contas_bancarias WHERE ativo = 1 ORDER BY nome')->fetchAll();
    $saldos = array_column(contasComSaldo($pdo), 'saldo', 'id');
    $opcoes = '<option value="">Conta...</option>';
    foreach ($contas as $c) {
        $opcoes .= '<option value="' . (int) $c['id'] . '"' . ((int) $c['id'] === $contaPadrao ? ' selected' : '') . '>'
            . e($c['nome']) . ' (' . e(formatMoney($saldos[$c['id']] ?? 0)) . ')</option>';
    }
    ob_start(); ?>
    <form method="post" class="form-pagamento" data-falta="<?= e((string) round($falta, 2)) ?>">
        <input type="hidden" name="acao" value="pagar">
        <div class="mb-2"><label class="form-label small">Data do pagamento</label><input type="date" name="data" class="form-control" required value="<?= date('Y-m-d') ?>"></div>
        <label class="form-label small mb-1">De quais contas sai</label>
        <div class="partes">
            <div class="input-group mb-2 parte">
                <select name="partes[0][conta_id]" class="form-select" style="max-width: 60%;"><?= $opcoes ?></select>
                <input type="text" name="partes[0][valor]" class="form-control text-end" data-moeda inputmode="decimal" value="<?= formatDecimal($falta) ?>">
                <button type="button" class="btn btn-outline-secondary remover-parte" title="Tirar"><i class="bi bi-x"></i></button>
            </div>
        </div>
        <button type="button" class="btn btn-sm btn-link px-0 add-parte"><i class="bi bi-plus-lg"></i> Pagar parte por outra conta</button>
        <div class="d-flex justify-content-between small mt-2 p-2 rounded bg-body-tertiary">
            <span>Pagando agora: <strong class="pagando">-</strong></span>
            <span class="restante"></span>
        </div>
        <label class="form-check mt-2 small">
            <input type="checkbox" class="form-check-input" name="quitar" value="1"> Dar como quitado mesmo pagando menos (desconto)
        </label>
        <button class="btn btn-brand w-100 mt-2"><i class="bi bi-check-lg"></i> Confirmar pagamento</button>
        <template class="modelo-parte">
            <div class="input-group mb-2 parte">
                <select name="partes[__i][conta_id]" class="form-select" style="max-width: 60%;"><?= str_replace(' selected', '', $opcoes) ?></select>
                <input type="text" name="partes[__i][valor]" class="form-control text-end" data-moeda inputmode="decimal" value="">
                <button type="button" class="btn btn-outline-secondary remover-parte" title="Tirar"><i class="bi bi-x"></i></button>
            </div>
        </template>
    </form>
    <script>
    // moeda.js carrega no rodapé: só começa depois da página montada (ou já,
    // quando o bloco chega depois, dentro do modal de detalhe).
    (function (form) { const iniciar = function () {
        const falta = Number(form.dataset.falta);
        const partes = form.querySelector('.partes');
        let seq = 1;
        function atualizar() {
            const soma = [...partes.querySelectorAll('input[name$="[valor]"]')].reduce((s, c) => s + Moeda.ler(c.value), 0);
            const resto = Math.round((falta - soma) * 100) / 100;
            form.querySelector('.pagando').textContent = Moeda.reais(soma);
            form.querySelector('.restante').innerHTML = resto > 0 ? 'Fica faltando <strong class="valor-negativo">' + Moeda.reais(resto) + '</strong>'
                : resto < 0 ? 'Pagando <strong>' + Moeda.reais(-resto) + '</strong> a mais (juros)' : '<span class="valor-positivo fw-semibold">Quita tudo</span>';
        }
        form.querySelector('.add-parte').addEventListener('click', () => {
            const resto = falta - [...partes.querySelectorAll('input[name$="[valor]"]')].reduce((s, c) => s + Moeda.ler(c.value), 0);
            partes.insertAdjacentHTML('beforeend', form.querySelector('.modelo-parte').innerHTML.replaceAll('__i', seq++));
            const novo = partes.lastElementChild;
            if (resto > 0) novo.querySelector('input').value = Moeda.formatar(resto);
            novo.querySelector('select').focus();
            atualizar();
        });
        partes.addEventListener('click', (ev) => {
            if (ev.target.closest('.remover-parte') && partes.children.length > 1) { ev.target.closest('.parte').remove(); atualizar(); }
        });
        form.addEventListener('input', atualizar);
        form.addEventListener('focusout', () => setTimeout(atualizar));
        atualizar();
    }; document.readyState === 'loading' ? document.addEventListener('DOMContentLoaded', iniciar) : iniciar(); })(document.currentScript.previousElementSibling);
    </script>
    <?php
    return ob_get_clean();
}

/** Tabela das partes já pagas, com estorno de cada uma. */
function listaPagamentosFeitos(array $pagamentos): string
{
    if (!$pagamentos) {
        return '';
    }
    ob_start(); ?>
    <div class="secao">
        <div class="secao-titulo"><i class="bi bi-cash-stack"></i> Pagamentos feitos</div>
        <div class="card">
            <table class="table table-sm mb-0 align-middle">
                <thead><tr><th>Data</th><th>Conta</th><th class="text-end">Valor</th><th></th></tr></thead>
                <tbody>
                <?php foreach ($pagamentos as $p): ?>
                    <tr>
                        <td class="text-num"><?= formatDate($p['data']) ?></td>
                        <td><?= e($p['conta_nome']) ?></td>
                        <td class="text-end text-num"><?= formatMoney($p['valor']) ?></td>
                        <td class="text-end">
                            <form method="post" class="d-inline" onsubmit="return confirm('Estornar este pagamento? O valor volta para a conta <?= e($p['conta_nome']) ?>.');">
                                <input type="hidden" name="acao" value="estornar_parte"><input type="hidden" name="movimentacao_id" value="<?= (int) $p['id'] ?>">
                                <button class="btn btn-sm btn-outline-danger" title="Estornar"><i class="bi bi-arrow-counterclockwise"></i></button>
                            </form>
                        </td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
                <tfoot><tr class="fw-semibold"><td colspan="2">Total pago</td><td class="text-end text-num"><?= formatMoney(array_sum(array_column($pagamentos, 'valor'))) ?></td><td></td></tr></tfoot>
            </table>
        </div>
    </div>
    <?php
    return ob_get_clean();
}
