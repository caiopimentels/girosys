<?php
require_once __DIR__ . '/../includes/bootstrap.php';
exigirLogin();
// Sem o Início liberado: vai para a primeira tela que o perfil pode ver.
if (!usuarioTemPermissao('ver_dashboard')) {
    foreach (['operacoes' => '/operacoes/listar.php', 'receber' => '/receber/listar.php', 'pagar' => '/pagar/listar.php', 'contas' => '/contas/listar.php',
        'repasses' => '/adiantamentos/listar.php', 'cartoes' => '/cartoes/listar.php', 'pessoas' => '/pessoas/listar.php?papel=cliente', 'movimentacao' => '/financeiro/movimentacao.php'] as $permissao => $url) {
        if (usuarioTemPermissao($permissao)) {
            redirect($url);
        }
    }
    redirect('/sem_acesso.php');
}

$pdo = getDB();
$hoje = date('Y-m-d');
$em7 = date('Y-m-d', strtotime('+7 days'));
$em30 = date('Y-m-d', strtotime('+30 days'));

$contas = contasComSaldo($pdo);
$saldoGlobal = array_sum(array_map(fn ($c) => (float) $c['saldo'], $contas));

$receber = $pdo->query(
    "SELECT
        COALESCE(SUM(CASE WHEN status = 'pendente' THEN valor END), 0) AS pendente,
        COALESCE(SUM(CASE WHEN status = 'pendente' AND data_vencimento < CURDATE() THEN valor END), 0) AS vencido,
        COALESCE(SUM(CASE WHEN status = 'devolvido' THEN valor END), 0) AS devolvido,
        COALESCE(SUM(CASE WHEN status = 'pendente' AND data_vencimento BETWEEN CURDATE() AND CURDATE() + INTERVAL 30 DAY THEN valor END), 0) AS prox30
     FROM contas_receber"
)->fetch();

$stmt = $pdo->prepare("SELECT COALESCE(SUM(valor - COALESCE(valor_pago, 0)), 0) FROM contas_pagar WHERE status = 'pendente' AND data_vencimento <= ?");
$stmt->execute([$em30]);
$pagar30 = (float) $stmt->fetchColumn();
$stmt = $pdo->prepare(
    "SELECT COALESCE(SUM(falta), 0) FROM (SELECT SUM(p.valor) - COALESCE(f.valor_pago, 0) AS falta FROM cartao_faturas f JOIN cartao_parcelas p ON p.fatura_id = f.id
     WHERE f.status = 'aberta' AND f.data_vencimento <= ? GROUP BY f.id) x"
);
$stmt->execute([$em30]);
$faturas30 = (float) $stmt->fetchColumn();

$adiantadoAberto = totalAdiantadoEmAberto($pdo);
$lucroMes = (float) $pdo->query(
    "SELECT COALESCE(SUM(total_juros + juros_emprestimo), 0) FROM operacoes WHERE data_base BETWEEN DATE_FORMAT(CURDATE(), '%Y-%m-01') AND LAST_DAY(CURDATE())"
)->fetchColumn();

$stmt = $pdo->prepare(
    "SELECT r.*, e.nome AS emitente_nome, c.nome AS conta_nome FROM contas_receber r
     LEFT JOIN pessoas e ON e.id = r.emitente_id LEFT JOIN contas_bancarias c ON c.id = r.conta_destino_id
     WHERE (r.status = 'pendente' AND r.data_vencimento <= ?) OR r.status = 'devolvido'
     ORDER BY r.status = 'devolvido' DESC, r.data_vencimento LIMIT 12"
);
$stmt->execute([$em7]);
$proxReceber = $stmt->fetchAll();

$stmt = $pdo->prepare(
    "SELECT 'conta' AS origem, cp.id, cp.descricao, cp.data_vencimento, cp.valor - COALESCE(cp.valor_pago, 0) AS valor FROM contas_pagar cp WHERE cp.status = 'pendente' AND cp.data_vencimento <= ?
     UNION ALL
     SELECT 'fatura', f.id, CONCAT('Fatura ', c.nome), f.data_vencimento, SUM(p.valor) - COALESCE(f.valor_pago, 0) FROM cartao_faturas f JOIN cartoes c ON c.id = f.cartao_id JOIN cartao_parcelas p ON p.fatura_id = f.id
     WHERE f.status = 'aberta' AND f.data_vencimento <= ? GROUP BY f.id
     ORDER BY data_vencimento LIMIT 12"
);
$stmt->execute([$em7, $em7]);
$proxPagar = $stmt->fetchAll();

$compromissos = compromissosCartao($pdo, 6);

$pageTitle = 'Olá, ' . explode(' ', trim(usuarioAtualNome()))[0];
$pageBreadcrumb = 'Início';
$activeMenu = 'dashboard';
require __DIR__ . '/../includes/layout_header.php';
?>

<div class="atalhos">
    <a href="/operacoes/form.php" class="atalho"><i class="bi bi-calculator"></i> Nova operação de desconto</a>
    <a href="/adiantamentos/form.php" class="atalho"><i class="bi bi-cash-coin"></i> Repasse a cliente</a>
    <a href="/pagar/form.php" class="atalho"><i class="bi bi-arrow-up-circle"></i> Lançar despesa</a>
    <a href="/cartoes/compra.php" class="atalho"><i class="bi bi-credit-card"></i> Compra no cartão</a>
    <a href="/contas/transferir.php" class="atalho"><i class="bi bi-arrow-left-right"></i> Transferir</a>
</div>

<div class="secao">
    <div class="secao-titulo"><i class="bi bi-graph-up"></i> Resumo</div>
    <div class="row g-3">
        <div class="col-6 col-lg-3"><div class="card h-100"><div class="card-body">
            <div class="kpi-rotulo">Saldo global</div>
            <div class="kpi-valor <?= $saldoGlobal < 0 ? 'valor-negativo' : '' ?>"><?= formatMoney($saldoGlobal) ?></div>
            <div class="small text-muted"><?= count($contas) ?> conta(s) · <a href="/contas/listar.php">ver contas</a></div>
        </div></div></div>
        <div class="col-6 col-lg-3"><div class="card h-100"><div class="card-body">
            <div class="kpi-rotulo">A receber (títulos)</div>
            <div class="kpi-valor valor-positivo"><?= formatMoney($receber['pendente']) ?></div>
            <div class="small text-muted">Próx. 30 dias: <?= formatMoney($receber['prox30']) ?></div>
        </div></div></div>
        <div class="col-6 col-lg-3"><div class="card h-100"><div class="card-body">
            <div class="kpi-rotulo">Vencidos + devolvidos</div>
            <div class="kpi-valor <?= $receber['vencido'] + $receber['devolvido'] > 0 ? 'valor-negativo' : '' ?>"><?= formatMoney($receber['vencido'] + $receber['devolvido']) ?></div>
            <div class="small text-muted">Devolvidos: <?= formatMoney($receber['devolvido']) ?></div>
        </div></div></div>
        <div class="col-6 col-lg-3"><div class="card h-100"><div class="card-body">
            <div class="kpi-rotulo">A pagar em 30 dias</div>
            <div class="kpi-valor valor-negativo"><?= formatMoney($pagar30 + $faturas30) ?></div>
            <div class="small text-muted">Contas <?= formatMoney($pagar30) ?> · cartões <?= formatMoney($faturas30) ?></div>
        </div></div></div>
        <div class="col-6 col-lg-3"><div class="card h-100"><div class="card-body">
            <div class="kpi-rotulo">Repasses a clientes (em aberto)</div>
            <div class="kpi-valor"><?= formatMoney($adiantadoAberto) ?></div>
            <div class="small text-muted"><a href="/adiantamentos/listar.php">ver por cliente</a></div>
        </div></div></div>
        <div class="col-6 col-lg-3"><div class="card h-100"><div class="card-body">
            <div class="kpi-rotulo">Lucro das operações no mês</div>
            <div class="kpi-valor valor-positivo"><?= formatMoney($lucroMes) ?></div>
            <div class="small text-muted"><a href="/operacoes/listar.php">ver operações</a></div>
        </div></div></div>
        <div class="col-12 col-lg-6"><div class="card h-100"><div class="card-body">
            <div class="kpi-rotulo mb-2">Saldo por conta</div>
            <div class="d-flex flex-wrap gap-3">
                <?php if (!$contas): ?><a href="/contas/form.php">Cadastrar a primeira conta</a><?php endif; ?>
                <?php foreach ($contas as $c): ?>
                    <a href="/contas/extrato.php?conta=<?= (int) $c['id'] ?>" class="text-reset text-decoration-none">
                        <div class="small text-muted"><span class="ponto-status" style="background: <?= e($c['cor']) ?>"></span> <?= e($c['nome']) ?></div>
                        <div class="fw-semibold text-num <?= $c['saldo'] < 0 ? 'valor-negativo' : '' ?>"><?= formatMoney($c['saldo']) ?></div>
                    </a>
                <?php endforeach; ?>
            </div>
        </div></div></div>
    </div>
</div>

<div class="row g-4">
    <div class="col-lg-6">
        <div class="secao">
            <div class="secao-titulo"><i class="bi bi-arrow-down-circle"></i> A receber (até 7 dias, vencidos e devolvidos)
                <a href="/receber/listar.php" class="btn btn-sm btn-outline-secondary ms-auto">Ver todos</a></div>
            <div class="card"><div class="table-responsive">
                <table class="table table-sm table-hover mb-0">
                    <thead><tr><th>Vencimento</th><th>Pagador</th><th>Conta</th><th class="text-end">Valor</th></tr></thead>
                    <tbody>
                    <?php if (!$proxReceber): ?><tr><td colspan="4" class="text-center text-muted py-3">Nada para os próximos dias.</td></tr><?php endif; ?>
                    <?php foreach ($proxReceber as $r): $st = statusTelaReceber($r); ?>
                        <tr class="cursor-pointer" data-detalhe="/receber/baixar.php?id=<?= (int) $r['id'] ?>">
                            <td class="text-num <?= $st !== 'pendente' ? 'valor-negativo' : '' ?>"><?= formatDate($r['data_vencimento']) ?><?= $st === 'devolvido' ? ' ' . badgeStatus('devolvido') : '' ?></td>
                            <td><?= tipoTituloLabel($r['tipo']) ?> · <?= e($r['emitente_nome'] ?? $r['descricao']) ?></td>
                            <td><?= e($r['conta_nome'] ?? '—') ?></td>
                            <td class="text-end text-num"><?= formatMoney($r['valor']) ?></td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
            </div></div>
        </div>
    </div>
    <div class="col-lg-6">
        <div class="secao">
            <div class="secao-titulo"><i class="bi bi-arrow-up-circle"></i> A pagar (até 7 dias e vencidas)
                <a href="/pagar/listar.php" class="btn btn-sm btn-outline-secondary ms-auto">Ver todas</a></div>
            <div class="card"><div class="table-responsive">
                <table class="table table-sm table-hover mb-0">
                    <thead><tr><th>Vencimento</th><th>Descrição</th><th class="text-end">Valor</th></tr></thead>
                    <tbody>
                    <?php if (!$proxPagar): ?><tr><td colspan="3" class="text-center text-muted py-3">Nada a pagar nos próximos dias.</td></tr><?php endif; ?>
                    <?php foreach ($proxPagar as $p): ?>
                        <tr class="cursor-pointer" data-detalhe="<?= $p['origem'] === 'fatura' ? '/cartoes/fatura.php?id=' : '/pagar/baixar.php?id=' ?><?= (int) $p['id'] ?>">
                            <td class="text-num <?= $p['data_vencimento'] < $hoje ? 'valor-negativo fw-semibold' : '' ?>"><?= formatDate($p['data_vencimento']) ?></td>
                            <td><?= $p['origem'] === 'fatura' ? '<i class="bi bi-credit-card"></i> ' : '' ?><?= e($p['descricao']) ?></td>
                            <td class="text-end text-num"><?= formatMoney($p['valor']) ?></td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
            </div></div>
        </div>
        <?php if ($compromissos): ?>
        <div class="secao">
            <div class="secao-titulo"><i class="bi bi-credit-card"></i> Cartões: faturas em aberto
                <a href="/cartoes/listar.php" class="btn btn-sm btn-outline-secondary ms-auto">Detalhar</a></div>
            <div class="card"><div class="card-body d-flex flex-wrap gap-4">
                <?php foreach ($compromissos as $mes => $valor): ?>
                    <div><div class="small text-muted <?= $mes < date('Y-m') ? 'valor-negativo' : '' ?>"><?= nomeMes($mes) ?></div><div class="fw-semibold text-num"><?= formatMoney($valor) ?></div></div>
                <?php endforeach; ?>
            </div></div>
        </div>
        <?php endif; ?>
    </div>
</div>

<?php require __DIR__ . '/../includes/layout_footer.php'; ?>
