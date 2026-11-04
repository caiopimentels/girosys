<?php
// Aba "Webhook de pagamento" da conta: o endereço que o banco chama quando um
// boleto muda de situação, o que está cadastrado hoje no banco e todos os
// avisos que chegaram (aceitos e recusados), com reprocessamento.
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
$noBanco = null;
$erroConsulta = null;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $acao = $_POST['acao'] ?? '';
    try {
        if (!$pronta) {
            throw new InvalidArgumentException('Configure a API do banco desta conta primeiro.');
        }
        if ($acao === 'registrar') {
            $url = registrarWebhookInter($pdo, $conta);
            registrarAtividade('contas_bancarias', 'integracao', 'Webhook do Inter registrado na conta ' . $conta['nome']);
            flash('success', 'Webhook registrado no Inter: ' . $url);
        } elseif ($acao === 'excluir') {
            excluirWebhookInter($pdo, $conta);
            registrarAtividade('contas_bancarias', 'integracao', 'Webhook do Inter excluído na conta ' . $conta['nome']);
            flash('success', 'Webhook excluído no Inter. A baixa automática para até registrar de novo (a conferência por cron continua).');
        } elseif ($acao === 'novo_segredo') {
            $pdo->prepare('UPDATE contas_bancarias SET webhook_segredo = NULL, webhook_registrado_em = NULL WHERE id = ?')->execute([$id]);
            registrarAtividade('contas_bancarias', 'integracao', 'Endereço do webhook trocado (segredo novo) na conta ' . $conta['nome']);
            flash('success', 'Endereço novo gerado. Clique em "Registrar no banco" para o Inter passar a usar ele — o antigo deixa de funcionar.');
        } elseif ($acao === 'reprocessar') {
            $resultado = reprocessarEventoWebhook($pdo, (int) ($_POST['evento'] ?? 0));
            registrarAtividade('boletos', 'consulta_boleto', 'Aviso do webhook #' . (int) $_POST['evento'] . ' reprocessado · ' . $resultado);
            flash('success', $resultado);
        } elseif ($acao === 'consultar') {
            redirect('/contas/webhook.php?id=' . $id . '&consultar=1');
        }
    } catch (InvalidArgumentException | RuntimeException $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        flash('danger', $e->getMessage());
    }
    redirect('/contas/webhook.php?id=' . $id);
}

if ($pronta && isset($_GET['consultar'])) {
    try {
        $noBanco = consultarWebhookInter($pdo, $conta) ?: false;
    } catch (Throwable $e) {
        $erroConsulta = $e->getMessage();
    }
}

$urlWebhook = $pronta ? urlWebhookInter($pdo, $conta) : '';

[$de, $ate, $deSql, $ateSql] = periodoFiltro(date('Y-m-d', strtotime('-30 days')), date('Y-m-d'));
$resultadoFiltro = in_array($_GET['resultado'] ?? '', ['processado', 'nao', 'recusado'], true) ? $_GET['resultado'] : '';
$where = ['conta_id = ?', 'recebido_em BETWEEN ? AND ?'];
$params = [$id, $deSql . ' 00:00:00', $ateSql . ' 23:59:59'];
if ($resultadoFiltro === 'processado') {
    $where[] = 'processado = 1';
} elseif ($resultadoFiltro === 'nao') {
    $where[] = 'processado = 0 AND aceito = 1';
} elseif ($resultadoFiltro === 'recusado') {
    $where[] = 'aceito = 0';
}
$stmt = $pdo->prepare('SELECT w.*, r.id AS titulo_id, r.descricao AS titulo_descricao, e.nome AS pagador_nome
    FROM webhook_eventos w
    LEFT JOIN contas_receber r ON r.boleto_codigo = w.codigo AND r.boleto_conta_id = w.conta_id
    LEFT JOIN pessoas e ON e.id = r.emitente_id
    WHERE ' . implode(' AND ', $where) . ' ORDER BY w.id DESC LIMIT 200');
$stmt->execute($params);
$eventos = $stmt->fetchAll();

$pageTitle = $conta['nome'];
$pageBreadcrumb = 'Administração · Contas bancárias · Webhook de pagamento';
$activeMenu = 'configuracoes';
$pageAction = '<a href="/configuracoes/contas.php" class="btn btn-outline-secondary"><i class="bi bi-arrow-left"></i> Contas bancárias</a>';
require __DIR__ . '/../../includes/layout_header.php';
$abaAtiva = 'contas';
require __DIR__ . '/../../includes/abas_configuracoes.php';
$contaAbaId = $id;
$contaAba = 'webhook';
require __DIR__ . '/../../includes/abas_conta_bancaria.php';
?>

<?php if (!$pronta): ?>
    <div class="alert alert-light border small" style="max-width: 900px;">Esta conta ainda não tem a API do banco configurada. Configure em <a href="/contas/inter.php?id=<?= (int) $id ?>">API do banco</a> para emitir boletos e receber os avisos de pagamento.</div>
<?php else: ?>
<div class="secao" style="max-width: 900px;">
    <div class="secao-titulo"><i class="bi bi-broadcast"></i> Endereço do webhook</div>
    <p class="small text-muted mb-2">Quando um boleto é pago (ou cancelado/expira), o Inter chama este endereço e o sistema dá a baixa sozinho — sempre conferindo a cobrança na API antes, não só confiando no aviso.</p>
    <div class="input-group mb-2">
        <input type="text" class="form-control form-control-sm font-monospace" id="url-webhook" value="<?= e($urlWebhook) ?>" readonly>
        <button type="button" class="btn btn-sm btn-outline-secondary" onclick="navigator.clipboard.writeText(document.getElementById('url-webhook').value); this.innerHTML='<i class=&quot;bi bi-check2&quot;></i> Copiado';"><i class="bi bi-clipboard"></i> Copiar</button>
    </div>
    <div class="small mb-3">
        <?php if ($conta['webhook_registrado_em']): ?>
            <i class="bi bi-check-circle valor-positivo"></i> Registrado no banco em <?= formatDateTime($conta['webhook_registrado_em']) ?>.
        <?php else: ?>
            <i class="bi bi-exclamation-circle text-warning"></i> Ainda não registrado no banco — os pagamentos só baixam pela conferência (cron) ou na mão.
        <?php endif; ?>
        <?php if (!str_starts_with($urlWebhook, 'https://')): ?>
            <div class="text-danger mt-1">O Inter só aceita endereço <strong>https://</strong>. Acesse o sistema pelo endereço com https (ou defina URL_SISTEMA no config.php).</div>
        <?php endif; ?>
    </div>
    <div class="d-flex flex-wrap gap-2">
        <form method="post"><input type="hidden" name="acao" value="registrar"><button class="btn btn-sm btn-brand"><i class="bi bi-broadcast"></i> <?= $conta['webhook_registrado_em'] ? 'Registrar no banco de novo' : 'Registrar no banco' ?></button></form>
        <a href="?id=<?= (int) $id ?>&consultar=1" class="btn btn-sm btn-outline-secondary"><i class="bi bi-search"></i> Ver o que está no banco</a>
        <form method="post" onsubmit="return confirm('Gerar um endereço novo? O antigo para de funcionar e é preciso registrar o novo no banco.');"><input type="hidden" name="acao" value="novo_segredo"><button class="btn btn-sm btn-outline-secondary"><i class="bi bi-arrow-repeat"></i> Gerar endereço novo</button></form>
        <form method="post" onsubmit="return confirm('Excluir o webhook no Inter? A baixa automática para.');"><input type="hidden" name="acao" value="excluir"><button class="btn btn-sm btn-outline-danger"><i class="bi bi-trash"></i> Excluir no banco</button></form>
    </div>
    <?php if ($noBanco !== null || $erroConsulta): ?>
        <div class="alert <?= $erroConsulta ? 'alert-danger' : 'alert-light border' ?> small mt-3 mb-0">
            <?php if ($erroConsulta): ?>
                Não foi possível consultar: <?= e($erroConsulta) ?>
            <?php elseif ($noBanco === false): ?>
                Nenhum webhook cadastrado no Inter para esta integração.
            <?php else: ?>
                <strong>No Inter hoje:</strong> <code><?= e($noBanco['webhookUrl']) ?></code>
                <?php if (!empty($noBanco['criacao'])): ?> · criado em <?= e(formatDateTime(str_replace('T', ' ', substr($noBanco['criacao'], 0, 19)))) ?><?php endif; ?>
                <?php if ($noBanco['webhookUrl'] === $urlWebhook): ?>
                    <div class="valor-positivo mt-1"><i class="bi bi-check-circle"></i> É o mesmo endereço do sistema.</div>
                <?php else: ?>
                    <div class="text-danger mt-1"><i class="bi bi-exclamation-triangle"></i> É diferente do endereço do sistema — clique em "Registrar no banco".</div>
                <?php endif; ?>
            <?php endif; ?>
        </div>
    <?php endif; ?>
</div>
<?php endif; ?>

<div class="filtros">
    <form method="get">
        <input type="hidden" name="id" value="<?= (int) $id ?>">
        <?php $dataInicio = $de; $dataFim = $ate; $periodoRotulo = 'Recebido'; require __DIR__ . '/../../includes/filtro_periodo.php'; ?>
        <select name="resultado" class="form-select form-select-sm" style="width: 200px;">
            <option value="">Todos os avisos</option>
            <option value="processado" <?= $resultadoFiltro === 'processado' ? 'selected' : '' ?>>Processados</option>
            <option value="nao" <?= $resultadoFiltro === 'nao' ? 'selected' : '' ?>>Não processados</option>
            <option value="recusado" <?= $resultadoFiltro === 'recusado' ? 'selected' : '' ?>>Recusados</option>
        </select>
        <button class="btn btn-sm btn-outline-secondary" type="submit"><i class="bi bi-search"></i> Filtrar</button>
    </form>
    <span class="ms-auto text-muted small"><?= count($eventos) ?> aviso(s)<?= count($eventos) === 200 ? ' (os 200 mais recentes)' : '' ?></span>
</div>

<div class="card">
    <div class="table-responsive">
        <table class="table table-sm mb-0 align-middle">
            <thead><tr><th>Recebido em</th><th>Cobrança</th><th>Título</th><th>Situação</th><th>Resultado</th><th>IP</th><th></th></tr></thead>
            <tbody>
            <?php if (!$eventos): ?>
                <tr><td colspan="7" class="text-center text-muted py-4">Nenhum aviso recebido no período.</td></tr>
            <?php endif; ?>
            <?php foreach ($eventos as $ev): ?>
                <tr class="<?= !$ev['aceito'] ? 'table-danger' : '' ?>">
                    <td class="text-nowrap text-num"><?= formatDateTime($ev['recebido_em']) ?></td>
                    <td class="small font-monospace"><?= e($ev['codigo'] ?? '-') ?></td>
                    <td class="small">
                        <?php if ($ev['titulo_id']): ?>
                            <a href="/receber/baixar.php?id=<?= (int) $ev['titulo_id'] ?>">#<?= (int) $ev['titulo_id'] ?></a> <?= e($ev['pagador_nome'] ?? '') ?>
                        <?php else: ?><span class="text-muted">-</span><?php endif; ?>
                    </td>
                    <td><?= e($ev['situacao'] ? rotuloSituacaoBoleto($ev['situacao']) : '-') ?></td>
                    <td class="small <?= $ev['processado'] ? 'valor-positivo' : (!$ev['aceito'] ? 'text-danger' : 'text-muted') ?>"><?= e($ev['resultado'] ?? 'Não processado') ?></td>
                    <td class="small text-nowrap"><?= e($ev['ip'] ?? '-') ?></td>
                    <td class="text-end text-nowrap">
                        <?php if ($ev['aceito'] && $ev['codigo'] && $pronta): ?>
                            <form method="post" class="d-inline"><input type="hidden" name="acao" value="reprocessar"><input type="hidden" name="evento" value="<?= (int) $ev['id'] ?>">
                                <button class="btn btn-sm btn-outline-secondary" title="Consultar a cobrança no banco e dar a baixa, se paga"><i class="bi bi-arrow-repeat"></i></button></form>
                        <?php endif; ?>
                        <button type="button" class="btn btn-sm btn-outline-secondary" data-bs-toggle="collapse" data-bs-target="#evento-<?= (int) $ev['id'] ?>"><i class="bi bi-code-slash"></i> Ver</button>
                    </td>
                </tr>
                <tr class="collapse" id="evento-<?= (int) $ev['id'] ?>">
                    <td colspan="7" class="bg-body-tertiary">
                        <div class="small fw-semibold mb-1">Conteúdo recebido</div>
                        <pre class="small mb-0" style="white-space: pre-wrap; word-break: break-all; max-height: 360px; overflow: auto;"><?= e($ev['payload'] !== '' ? $ev['payload'] : '(vazio)') ?></pre>
                    </td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    </div>
</div>

<?php require __DIR__ . '/../../includes/layout_footer.php'; ?>
