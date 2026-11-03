<?php
// Tabela do extrato do cliente (aba Extrato e impressão). Espera $extrato
// (extratoCliente()), $extDe e $extAte. Acessado direto, não mostra nada.
if (!isset($extrato)) {
    http_response_code(404);
    exit;
}
?>
<div class="row g-3 mb-3">
    <div class="col-6 col-md-3"><div class="card h-100"><div class="card-body py-2"><div class="kpi-rotulo">Saldo anterior</div><div class="fw-semibold text-num"><?= $extDe ? formatMoney($extrato['saldo_anterior']) : '-' ?></div></div></div></div>
    <div class="col-6 col-md-3"><div class="card h-100"><div class="card-body py-2"><div class="kpi-rotulo">Débitos no período</div><div class="fw-semibold text-num valor-negativo"><?= formatMoney($extrato['debitos']) ?></div></div></div></div>
    <div class="col-6 col-md-3"><div class="card h-100"><div class="card-body py-2"><div class="kpi-rotulo">Créditos no período</div><div class="fw-semibold text-num valor-positivo"><?= formatMoney($extrato['creditos']) ?></div></div></div></div>
    <div class="col-6 col-md-3"><div class="card h-100"><div class="card-body py-2">
        <div class="kpi-rotulo">Saldo <?= $extAte ? 'em ' . formatDate($extAte) : 'atual' ?></div>
        <div class="fw-bold text-num <?= $extrato['saldo_final'] > 0.004 ? 'valor-negativo' : 'valor-positivo' ?>"><?= formatMoney(abs($extrato['saldo_final'])) ?></div>
        <div class="small text-muted"><?= $extrato['saldo_final'] > 0.004 ? 'a pagar pelo cliente' : ($extrato['saldo_final'] < -0.004 ? 'a favor do cliente' : 'quitado') ?></div>
    </div></div></div>
</div>
<div class="card">
    <div class="table-responsive">
        <table class="table table-sm mb-0 align-middle">
            <thead><tr><th style="width: 110px;">Data</th><th>Histórico</th><th class="text-end">Débito</th><th class="text-end">Crédito</th><th class="text-end">Saldo</th></tr></thead>
            <tbody>
            <?php if ($extDe): ?>
                <tr class="text-muted"><td><?= formatDate(date('Y-m-d', strtotime($extDe . ' -1 day'))) ?></td><td>Saldo anterior</td><td></td><td></td><td class="text-end text-num"><?= formatMoney($extrato['saldo_anterior']) ?></td></tr>
            <?php endif; ?>
            <?php if (!$extrato['linhas']): ?><tr><td colspan="5" class="text-center text-muted py-3">Nenhum lançamento<?= $extDe || $extAte ? ' no período' : '' ?>.</td></tr><?php endif; ?>
            <?php foreach ($extrato['linhas'] as $l): ?>
                <tr>
                    <td class="text-num"><?= formatDate($l['data']) ?></td>
                    <td><?= e($l['historico']) ?></td>
                    <td class="text-end text-num valor-negativo"><?= $l['debito'] > 0 ? formatMoney($l['debito']) : '' ?></td>
                    <td class="text-end text-num valor-positivo"><?= $l['credito'] > 0 ? formatMoney($l['credito']) : '' ?></td>
                    <td class="text-end text-num fw-semibold <?= $l['saldo'] > 0.004 ? 'valor-negativo' : '' ?>"><?= formatMoney($l['saldo']) ?></td>
                </tr>
            <?php endforeach; ?>
            </tbody>
            <?php if ($extrato['linhas']): ?>
            <tfoot><tr class="fw-semibold"><td colspan="2">Totais</td><td class="text-end text-num"><?= formatMoney($extrato['debitos']) ?></td><td class="text-end text-num"><?= formatMoney($extrato['creditos']) ?></td><td class="text-end text-num"><?= formatMoney($extrato['saldo_final']) ?></td></tr></tfoot>
            <?php endif; ?>
        </table>
    </div>
</div>
<div class="small text-muted mt-2">Débito = valores passados ao cliente, correção pela taxa e títulos devolvidos para ele. Crédito = títulos entregues (valor líquido) e pagamentos. Saldo positivo = o cliente deve.</div>
