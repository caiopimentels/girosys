<?php
require_once __DIR__ . '/../../includes/bootstrap.php';
exigirLogin();

$pdo = getDB();
$contas = contasComSaldo($pdo, false);
$ativas = array_filter($contas, fn ($c) => $c['ativo']);
$global = array_sum(array_map(fn ($c) => (float) $c['saldo'], $ativas));
$hoje = date('Y-m-d');

// O que já está previsto para entrar/sair em cada conta (para o saldo projetado).
$previstoEntrar = $pdo->query(
    "SELECT conta_destino_id, SUM(valor) FROM contas_receber WHERE status = 'pendente' AND conta_destino_id IS NOT NULL GROUP BY conta_destino_id"
)->fetchAll(PDO::FETCH_KEY_PAIR);
$previstoSair = $pdo->query(
    "SELECT conta_id, SUM(valor - COALESCE(valor_pago, 0)) FROM contas_pagar WHERE status = 'pendente' AND conta_id IS NOT NULL GROUP BY conta_id"
)->fetchAll(PDO::FETCH_KEY_PAIR);
$receberSemConta = (float) $pdo->query("SELECT COALESCE(SUM(valor), 0) FROM contas_receber WHERE status = 'pendente' AND conta_destino_id IS NULL")->fetchColumn();

$pageTitle = 'Contas bancárias';
$pageBreadcrumb = 'Saldos';
$activeMenu = 'contas';
$pageAction = '<a href="/contas/transferir.php" class="btn btn-outline-secondary"><i class="bi bi-arrow-left-right"></i> Transferir</a>'
    . '<a href="/contas/form.php" class="btn btn-brand"><i class="bi bi-plus-lg"></i> Nova conta</a>';
require __DIR__ . '/../../includes/layout_header.php';
?>

<div class="secao">
    <div class="row g-3">
        <div class="col-md-4">
            <div class="card h-100"><div class="card-body">
                <div class="kpi-rotulo">Saldo global (todas as contas)</div>
                <div class="kpi-valor <?= $global < 0 ? 'valor-negativo' : '' ?>"><?= formatMoney($global) ?></div>
                <div class="small text-muted"><?= count($ativas) ?> conta(s) ativa(s)</div>
            </div></div>
        </div>
        <div class="col-md-4">
            <div class="card h-100"><div class="card-body">
                <div class="kpi-rotulo">A receber (pendente)</div>
                <div class="kpi-valor valor-positivo"><?= formatMoney(array_sum($previstoEntrar) + $receberSemConta) ?></div>
                <?php if ($receberSemConta > 0): ?><div class="small text-muted"><?= formatMoney($receberSemConta) ?> sem conta definida</div><?php endif; ?>
            </div></div>
        </div>
        <div class="col-md-4">
            <div class="card h-100"><div class="card-body">
                <div class="kpi-rotulo">A pagar com conta definida</div>
                <div class="kpi-valor valor-negativo"><?= formatMoney(array_sum($previstoSair)) ?></div>
                <div class="small text-muted">Faturas de cartão em Cartões de crédito</div>
            </div></div>
        </div>
    </div>
</div>

<div class="secao">
    <div class="secao-titulo"><i class="bi bi-bank"></i> Saldo por conta</div>
    <div class="row g-3">
        <?php if (!$contas): ?>
            <div class="col-12"><div class="card"><div class="card-body text-center text-muted py-4">
                Nenhuma conta cadastrada. <a href="/contas/form.php">Cadastre a primeira</a> (ex.: Banco Inter, Mercado Pago, Bradesco).
            </div></div></div>
        <?php endif; ?>
        <?php foreach ($contas as $c): ?>
            <?php $entra = (float) ($previstoEntrar[$c['id']] ?? 0); $sai = (float) ($previstoSair[$c['id']] ?? 0); ?>
            <div class="col-md-6 col-xl-4">
                <div class="card h-100 conta-saldo <?= $c['ativo'] ? '' : 'opacity-50' ?>" style="--cor-conta: <?= e($c['cor']) ?>">
                    <div class="card-body">
                        <div class="d-flex align-items-start gap-2">
                            <div>
                                <div class="fw-semibold"><?= e($c['nome']) ?> <?= $c['ativo'] ? '' : '<span class="badge text-bg-secondary">Inativa</span>' ?></div>
                                <?php if ($c['banco']): ?><div class="small text-muted"><?= e($c['banco']) ?></div><?php endif; ?>
                            </div>
                            <a href="/contas/form.php?id=<?= (int) $c['id'] ?>" class="btn btn-sm btn-outline-secondary ms-auto" title="Editar"><i class="bi bi-pencil"></i></a>
                        </div>
                        <div class="kpi-valor mt-2 <?= $c['saldo'] < 0 ? 'valor-negativo' : '' ?>"><?= formatMoney($c['saldo']) ?></div>
                        <div class="small text-muted mt-1">
                            Previsto: <span class="valor-positivo">+<?= formatMoney($entra) ?></span> · <span class="valor-negativo">−<?= formatMoney($sai) ?></span>
                            → <strong><?= formatMoney($c['saldo'] + $entra - $sai) ?></strong>
                        </div>
                        <a href="/contas/extrato.php?conta=<?= (int) $c['id'] ?>" class="btn btn-sm btn-outline-secondary mt-3"><i class="bi bi-list-ul"></i> Extrato</a>
                        <?php if (usuarioEhAdmin()): ?><a href="/contas/inter.php?id=<?= (int) $c['id'] ?>" class="btn btn-sm btn-outline-secondary mt-3"><i class="bi bi-shield-lock"></i> <?= ($c['integracao'] ?? '') === 'inter' ? 'Banco (API)' : 'Integrar com o banco' ?></a><?php endif; ?>
                    </div>
                </div>
            </div>
        <?php endforeach; ?>
    </div>
</div>

<?php require __DIR__ . '/../../includes/layout_footer.php'; ?>
