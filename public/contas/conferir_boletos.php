<?php
// Aba "Conferir boletos" da conta: os boletos em aberto no banco e a
// conferência na hora (a mesma que o cron faz), para quando um aviso do
// webhook não chegou.
require_once __DIR__ . '/../../includes/bootstrap.php';
exigirAdmin();

$pdo = getDB();
$id = (int) ($_GET['id'] ?? 0);
$conta = contaBancaria($pdo, $id);
if (!$conta) {
    flash('danger', 'Conta não encontrada.');
    redirect('/configuracoes/contas.php');
}
$pronta = contaTemInter($conta) && !pendenciasInter($conta);

if ($_SERVER['REQUEST_METHOD'] === 'POST' && $pronta) {
    $tituloId = (int) ($_POST['titulo'] ?? 0);
    try {
        if ($tituloId) {
            $situacao = consultarBoletoInter($pdo, $tituloId);
            registrarAtividade('boletos', 'consulta_boleto', 'Boleto do título #' . $tituloId . ' conferido no ' . $conta['nome'] . ': ' . rotuloSituacaoBoleto($situacao));
            flash('success', 'Título #' . $tituloId . ': ' . rotuloSituacaoBoleto($situacao) . ($situacao === 'RECEBIDO' ? ' — baixa lançada.' : '.'));
        } else {
            [$conferidos, $pagos, $erros] = conferirBoletosInter($pdo, $id);
            registrarAtividade('boletos', 'consulta_boleto', "Conferência dos boletos do {$conta['nome']}: {$conferidos} conferido(s), {$pagos} pago(s) baixado(s)" . ($erros ? ', ' . count($erros) . ' com erro' : ''));
            flash($erros ? 'warning' : 'success', "{$conferidos} boleto(s) conferido(s), {$pagos} pago(s) baixado(s)."
                . ($erros ? ' Com erro: ' . implode(' · ', array_map(fn ($t, $m) => "#{$t} {$m}", array_keys($erros), $erros)) : ''));
        }
    } catch (InvalidArgumentException | RuntimeException $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        flash('danger', $e->getMessage());
    }
    redirect('/contas/conferir_boletos.php?id=' . $id);
}

$boletos = boletosEmAberto($pdo, $id);
$totalAberto = array_sum(array_column($boletos, 'valor'));
$ultimaAutomatica = $pdo->prepare("SELECT MAX(criado_em) FROM banco_chamadas WHERE conta_bancaria_id = ? AND operacao = 'consultar_boleto' AND origem = 'automatico'");
$ultimaAutomatica->execute([$id]);
$ultimaAutomatica = $ultimaAutomatica->fetchColumn();

$pageTitle = $conta['nome'];
$pageBreadcrumb = 'Administração · Contas bancárias · Conferir boletos';
$activeMenu = 'configuracoes';
$pageAction = '<a href="/configuracoes/contas.php" class="btn btn-outline-secondary"><i class="bi bi-arrow-left"></i> Contas bancárias</a>';
require __DIR__ . '/../../includes/layout_header.php';
$abaAtiva = 'contas';
require __DIR__ . '/../../includes/abas_configuracoes.php';
$contaAbaId = $id;
$contaAba = 'conferir';
require __DIR__ . '/../../includes/abas_conta_bancaria.php';
?>

<?php if (!$pronta): ?>
    <div class="alert alert-light border small" style="max-width: 900px;">Esta conta ainda não tem a API do banco configurada. Configure em <a href="/contas/inter.php?id=<?= (int) $id ?>">API do banco</a>.</div>
<?php else: ?>
<div class="secao" style="max-width: 900px;">
    <div class="secao-titulo"><i class="bi bi-arrow-repeat"></i> Conferência</div>
    <p class="small text-muted mb-2">
        Consulta no banco cada boleto em aberto e dá a baixa nos que já foram pagos — a mesma rotina que o cron
        (<code>scripts/conferir_boletos.php</code>) roda sozinha.
        <?= $ultimaAutomatica ? 'Última conferência automática: ' . formatDateTime($ultimaAutomatica) . '.' : 'Nenhuma conferência automática registrada ainda (confira se o cron está ligado).' ?>
    </p>
    <form method="post" onsubmit="this.querySelector('button').disabled = true; this.querySelector('button').innerHTML = '<span class=&quot;spinner-border spinner-border-sm&quot;></span> Conferindo...';">
        <button class="btn btn-brand" <?= $boletos ? '' : 'disabled' ?>><i class="bi bi-arrow-repeat"></i> Conferir todos agora (<?= count($boletos) ?>)</button>
        <a href="/contas/vincular_boletos.php?id=<?= (int) $id ?>" class="btn btn-outline-secondary ms-2"><i class="bi bi-link-45deg"></i> Vincular boletos emitidos fora do sistema</a>
    </form>
</div>
<?php endif; ?>

<div class="card">
    <div class="table-responsive">
        <table class="table table-sm table-hover mb-0 align-middle">
            <thead><tr><th>Vencimento</th><th>Pagador</th><th>Título</th><th>Nosso número</th><th>Situação no banco</th><th>Emitido em</th><th class="text-end">Valor</th><th></th></tr></thead>
            <tbody>
            <?php if (!$boletos): ?>
                <tr><td colspan="8" class="text-center text-muted py-4">Nenhum boleto em aberto nesta conta.</td></tr>
            <?php endif; ?>
            <?php foreach ($boletos as $b): ?>
                <tr>
                    <td class="text-num"><?= formatDate($b['data_vencimento']) ?><?php if ($b['data_vencimento'] < date('Y-m-d')): ?> <span class="small text-danger">vencido</span><?php endif; ?></td>
                    <td><?= e($b['pagador_nome'] ?? '-') ?></td>
                    <td class="small"><a href="/receber/baixar.php?id=<?= (int) $b['id'] ?>">#<?= (int) $b['id'] ?></a> <?= e($b['descricao']) ?></td>
                    <td class="small font-monospace"><?= e($b['boleto_nosso_numero'] ?? '-') ?></td>
                    <td><?= e(rotuloSituacaoBoleto($b['boleto_situacao'])) ?></td>
                    <td class="small text-num"><?= $b['boleto_emitido_em'] ? formatDateTime($b['boleto_emitido_em']) : '-' ?></td>
                    <td class="text-end text-num"><?= formatMoney($b['valor']) ?></td>
                    <td class="text-end text-nowrap">
                        <?php if ($pronta): ?>
                            <form method="post" class="d-inline"><input type="hidden" name="titulo" value="<?= (int) $b['id'] ?>"><button class="btn btn-sm btn-outline-secondary" title="Consultar este boleto no banco"><i class="bi bi-search"></i></button></form>
                        <?php endif; ?>
                        <a href="/contas/auditoria.php?id=<?= (int) $id ?>&titulo=<?= (int) $b['id'] ?>" class="btn btn-sm btn-outline-secondary" title="Chamadas ao banco deste título"><i class="bi bi-journal-text"></i></a>
                    </td>
                </tr>
            <?php endforeach; ?>
            </tbody>
            <?php if ($boletos): ?>
            <tfoot><tr class="fw-semibold"><td colspan="6"><?= count($boletos) ?> boleto(s) em aberto</td><td class="text-end text-num"><?= formatMoney($totalAberto) ?></td><td></td></tr></tfoot>
            <?php endif; ?>
        </table>
    </div>
</div>

<?php require __DIR__ . '/../../includes/layout_footer.php'; ?>
