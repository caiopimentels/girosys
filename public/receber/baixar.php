<?php
// Título a receber: ver, receber, marcar devolvido, estornar, editar e excluir.
// Abre também dentro do modal das listas (?modal=1).
require_once __DIR__ . '/../../includes/bootstrap.php';
exigirLogin();

$pdo = getDB();
$id = (int) ($_GET['id'] ?? $_POST['id'] ?? 0);

function carregarTitulo(PDO $pdo, int $id): ?array
{
    $stmt = $pdo->prepare(
        'SELECT r.*, e.nome AS emitente_nome, e.documento AS emitente_documento, cl.nome AS cliente_nome,
                cd.nome AS conta_destino_nome, cr.nome AS conta_recebimento_nome, cat.nome AS categoria_nome
         FROM contas_receber r
         LEFT JOIN pessoas e ON e.id = r.emitente_id
         LEFT JOIN pessoas cl ON cl.id = r.cliente_id
         LEFT JOIN contas_bancarias cd ON cd.id = r.conta_destino_id
         LEFT JOIN contas_bancarias cr ON cr.id = r.conta_recebimento_id
         LEFT JOIN categorias cat ON cat.id = r.categoria_id
         WHERE r.id = ?'
    );
    $stmt->execute([$id]);
    return $stmt->fetch() ?: null;
}

$t = carregarTitulo($pdo, $id);
if (!$t) {
    flash('danger', 'Título não encontrado.');
    redirecionarAposAcao('/receber/listar.php');
}
$ehResidual = $t['operacao_id'] && $t['juros'] === null;
$podeMexer = in_array($t['status'], ['pendente', 'devolvido', 'cancelado'], true);
// Recebido ou que já voltou devolvido fica no histórico do pagador: não sai.
$podeExcluir = $podeMexer && (!$t['vezes_devolvido'] || $t['status'] === 'cancelado');

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $acao = $_POST['acao'] ?? '';
    $pdo->beginTransaction();
    try {
        if ($acao === 'receber' && in_array($t['status'], ['pendente', 'devolvido'], true)) {
            $data = dataInput('data') ?? throw new InvalidArgumentException('Informe a data do recebimento.');
            $valor = decimalInput('valor');
            $conta = idOuNull($_POST['conta_id'] ?? null) ?? throw new InvalidArgumentException('Escolha a conta onde o dinheiro entrou.');
            if ($valor <= 0) {
                throw new InvalidArgumentException('Informe o valor recebido.');
            }
            // Recebeu menos que o atualizado na data: a diferença segue a escolha da tela.
            $diferenca = round(atualizacaoReceber($pdo, $t, $data)['total'] - $valor, 2);
            $modo = in_array($_POST['diferenca_modo'] ?? '', ['baixar', 'aberto', 'pagador', 'cliente'], true) ? $_POST['diferenca_modo'] : 'baixar';
            $nota = tratarDiferencaRecebimento($pdo, $t, $data, $diferenca, $modo, dataInput('diferenca_vencimento'));
            baixarReceber($pdo, $t, $data, $valor, $conta);
            flash('success', 'Recebimento de ' . formatMoney($valor) . ' lançado na conta.' . $nota);
        } elseif ($acao === 'devolver' && in_array($t['status'], ['pendente', 'devolvido'], true)) {
            devolverReceber($pdo, $t, dataInput('data') ?? date('Y-m-d'), input('motivo'));
            flash('warning', 'Título marcado como devolvido. Fica no histórico do pagador.');
        } elseif ($acao === 'estornar' && $t['status'] === 'recebido') {
            estornarReceber($pdo, $t);
            flash('success', 'Recebimento estornado (saiu do extrato da conta).');
        } elseif ($acao === 'emitir_boleto') {
            $contaBoleto = contaBancaria($pdo, (int) ($_POST['boleto_conta_id'] ?? 0));
            if (!$contaBoleto || pendenciasInter($contaBoleto)) {
                throw new InvalidArgumentException('Escolha uma conta com a integração do Inter configurada.');
            }
            $pdo->commit(); // a emissão grava por conta própria (a chamada ao banco não pode ser desfeita)
            emitirBoletoInter($pdo, $t, $contaBoleto);
            flash('success', 'Boleto emitido no Banco Inter.');
            redirecionarAposAcao('/receber/baixar.php?id=' . $id);
        } elseif ($acao === 'consultar_boleto') {
            $pdo->commit();
            $situacao = consultarBoletoInter($pdo, $id);
            flash('success', 'Situação no Inter: ' . rotuloSituacaoBoleto($situacao) . ($situacao === 'RECEBIDO' ? ' — baixa lançada.' : '.'));
            redirecionarAposAcao('/receber/baixar.php?id=' . $id);
        } elseif ($acao === 'cancelar_boleto') {
            $pdo->commit();
            cancelarBoletoInter($pdo, $t, input('motivo'));
            flash('success', 'Boleto cancelado no Inter.');
            redirecionarAposAcao('/receber/baixar.php?id=' . $id);
        } elseif ($acao === 'devolver_cliente' && in_array($t['status'], ['pendente', 'devolvido'], true)) {
            transferirParaCliente($pdo, $t, dataInput('data') ?? date('Y-m-d'), decimalInput('valor'));
            flash('success', 'Devolvido para ' . $t['cliente_nome'] . ': saiu do contas a receber e virou um repasse em aberto dele.');
        } elseif ($acao === 'desfazer_cliente' && $t['status'] === 'transferido') {
            desfazerTransferencia($pdo, $t);
            flash('success', 'Desfeito: o título voltou para o contas a receber e o repasse saiu.');
        } elseif ($acao === 'reabrir' && $t['status'] === 'cancelado') {
            $pdo->prepare("UPDATE contas_receber SET status = 'pendente' WHERE id = ?")->execute([$id]);
            flash('success', 'Lançamento reaberto.');
        } elseif ($acao === 'alterar' && $podeMexer) {
            $venc = dataInput('data_vencimento') ?? throw new InvalidArgumentException('Vencimento inválido.');
            $valor = decimalInput('valor');
            if ($valor <= 0) {
                throw new InvalidArgumentException('Informe o valor.');
            }
            $tipo = array_key_exists(input('tipo'), tiposParaCampo($t['tipo'])) ? input('tipo') : $t['tipo'];
            $pagadorId = input('pagador_nome') !== '' ? obterOuCriarPessoa($pdo, input('pagador_nome'), input('pagador_documento'), 'pagador') : $t['emitente_id'];
            $pdo->prepare(
                'UPDATE contas_receber SET tipo = ?, emitente_id = ?, valor = ?, data_vencimento = ?, conta_destino_id = ?, banco_titulo = ?, numero_titulo = ?, descricao = ?, observacao = ? WHERE id = ?'
            )->execute([$tipo, $pagadorId, $valor, $venc, idOuNull($_POST['conta_destino_id'] ?? null), input('banco_titulo') ?: null,
                input('numero_titulo') ?: null, input('descricao') ?: null, input('observacao') ?: null, $id]);
            $mudouCalculo = $t['operacao_id'] && !$ehResidual && ((float) $valor !== (float) $t['valor'] || $venc !== $t['data_vencimento']);
            if ($mudouCalculo) {
                recalcularTituloOperacao($pdo, $id);
                recalcularTotaisOperacao($pdo, (int) $t['operacao_id']);
            }
            flash('success', 'Título alterado.' . ($mudouCalculo ? ' Juros do título e totais da operação #' . $t['operacao_id'] . ' recalculados — a diferença já paga ao cliente não muda.' : ''));
        } elseif ($acao === 'excluir' && $podeExcluir) {
            if ($t['operacao_id'] && !$ehResidual) {
                $stmt = $pdo->prepare('SELECT COUNT(*) FROM contas_receber WHERE operacao_id = ? AND juros IS NOT NULL');
                $stmt->execute([$t['operacao_id']]);
                if ((int) $stmt->fetchColumn() <= 1) {
                    throw new InvalidArgumentException('Este é o único título da operação #' . $t['operacao_id'] . '. Para tirar, exclua a operação inteira.');
                }
            }
            $pdo->prepare('DELETE FROM contas_receber WHERE id = ?')->execute([$id]);
            if ($t['operacao_id'] && !$ehResidual) {
                recalcularTotaisOperacao($pdo, (int) $t['operacao_id']);
            }
            $pdo->commit();
            flash('success', 'Título excluído.' . ($t['operacao_id'] && !$ehResidual ? ' Totais da operação #' . $t['operacao_id'] . ' recalculados.' : ''));
            redirecionarAposAcao('/receber/listar.php');
        } elseif ($acao === 'excluir') {
            throw new InvalidArgumentException('Título já recebido ou que já voltou devolvido não pode ser excluído (fica no histórico). Estorne o recebimento antes, se for o caso.');
        }
        $pdo->commit();
    } catch (InvalidArgumentException | RuntimeException $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        flash('danger', $e->getMessage());
        redirecionarAposAcao('/receber/baixar.php?id=' . $id, true);
    }
    redirecionarAposAcao('/receber/baixar.php?id=' . $id);
}

$historico = $t['emitente_id'] ? historicoEmitente($pdo, (int) $t['emitente_id']) : null;
$atualizacao = atualizacaoReceber($pdo, $t);
$stmt = $pdo->prepare('SELECT * FROM contas_receber WHERE restante_de_id = ?');
$stmt->execute([$id]);
$restantes = $stmt->fetchAll();
$status = statusTelaReceber($t);

$pageTitle = ($ehResidual ? 'Residual' : tipoTituloLabel($t['tipo'])) . ' · ' . formatMoney($t['valor']);
$pageBreadcrumb = 'Contas a receber' . ($t['operacao_id'] ? ' · operação #' . $t['operacao_id'] : '');
$activeMenu = 'receber';
$pageAction = '<a href="/receber/listar.php" class="btn btn-outline-secondary"><i class="bi bi-arrow-left"></i> Voltar</a>'
    . ($t['operacao_id'] ? '<a href="/operacoes/ver.php?id=' . (int) $t['operacao_id'] . '" class="btn btn-outline-secondary">Operação #' . (int) $t['operacao_id'] . '</a>' : '');
require __DIR__ . '/../../includes/layout_header.php';
?>

<div style="max-width: 900px;">
    <?php // Em cima: o que é o título. ?>
    <div class="d-flex flex-wrap align-items-center gap-2 mb-2">
        <?= badgeStatusAtraso($status, $atualizacao['dias']) ?>
        <?php if ($t['vezes_devolvido'] > 0): ?><span class="badge text-bg-light">devolvido <?= (int) $t['vezes_devolvido'] ?>×</span><?php endif; ?>
        <?php if ($t['operacao_id']): ?><a href="/operacoes/ver.php?id=<?= (int) $t['operacao_id'] ?>" class="small">operação #<?= (int) $t['operacao_id'] ?></a><?php endif; ?>
        <span class="ms-auto d-flex gap-2">
            <?php if ($podeMexer): ?>
                <button type="button" class="btn btn-sm btn-outline-secondary" onclick="const d = document.getElementById('editar-titulo'); d.classList.toggle('d-none'); if (!d.classList.contains('d-none')) { d.scrollIntoView({block: 'nearest'}); d.querySelector('[name=descricao]').focus(); }"><i class="bi bi-pencil"></i> Editar</button>
            <?php endif; ?>
            <?php if ($podeExcluir): ?>
                <form method="post" class="d-inline" onsubmit="return confirm('Excluir este título?<?= $t['operacao_id'] && !$ehResidual ? ' Os totais da operação serão recalculados.' : '' ?>');"><input type="hidden" name="acao" value="excluir"><button class="btn btn-sm btn-outline-danger"><i class="bi bi-trash"></i> Excluir</button></form>
            <?php endif; ?>
        </span>
    </div>
    <div class="row g-2 mb-2">
        <div class="col-6 col-md-3"><div class="kpi-rotulo">Valor</div><div class="fw-semibold text-num"><?= formatMoney($t['valor']) ?></div></div>
        <div class="col-6 col-md-3"><div class="kpi-rotulo">Vencimento</div><div class="fw-semibold <?= $status === 'vencido' ? 'valor-negativo' : '' ?>"><?= formatDate($t['data_vencimento']) ?></div></div>
        <div class="col-6 col-md-3"><div class="kpi-rotulo">Pagador</div><div class="fw-semibold"><?php if ($t['emitente_id']): ?><a href="/pessoas/form.php?id=<?= (int) $t['emitente_id'] ?>&tab=titulos" class="text-reset"><?= e($t['emitente_nome']) ?></a><?php else: ?>-<?php endif; ?></div></div>
        <div class="col-6 col-md-3"><div class="kpi-rotulo">Cliente</div><div class="fw-semibold"><?php if ($t['cliente_nome']): ?><a href="/pessoas/form.php?id=<?= (int) $t['cliente_id'] ?>" class="text-reset"><?= e($t['cliente_nome']) ?></a><?php else: ?>-<?php endif; ?></div></div>
    </div>
    <div class="small text-muted mb-3">
        <?= e(implode(' · ', array_filter([
            $t['descricao'],
            trim($t['banco_titulo'] . ' ' . ($t['numero_titulo'] ? 'nº ' . $t['numero_titulo'] : '')),
            'cai em ' . ($t['conta_destino_nome'] ?? '— conta não definida'),
        ]))) ?>
        <?php if ($t['status'] === 'recebido'): ?><div class="valor-positivo fw-semibold mt-1">Recebido <?= formatMoney($t['valor_recebido']) ?> em <?= formatDate($t['data_recebimento']) ?> · <?= e($t['conta_recebimento_nome']) ?><?= $t['acrescimo'] > 0 ? ' (inclui ' . formatMoney($t['acrescimo']) . ' de juros/multa)' : '' ?></div><?php endif; ?>
        <?php if ($t['devolvido_em']): ?><div class="valor-negativo mt-1">Devolução: <?= e($t['motivo_devolucao'] ?: formatDate($t['devolvido_em'])) ?></div><?php endif; ?>
        <?php if ($t['status'] === 'transferido'): ?><div class="fw-semibold mt-1">Devolvido para o cliente em <?= formatDate($t['data_recebimento']) ?> · virou repasse de <?= formatMoney($t['valor_recebido']) ?> em aberto de <?= e($t['cliente_nome']) ?></div><?php endif; ?>
        <?php if ($t['observacao']): ?><div class="mt-1"><?= nl2br(e($t['observacao'])) ?></div><?php endif; ?>
        <?php if ($t['restante_de_id']): ?><div class="mt-1"><i class="bi bi-link-45deg"></i> Restante do <a href="#" onclick="event.preventDefault(); abrirDetalhe('/receber/baixar.php?id=<?= (int) $t['restante_de_id'] ?>')">título #<?= (int) $t['restante_de_id'] ?></a></div><?php endif; ?>
        <?php foreach ($restantes as $rest): ?><div class="mt-1"><i class="bi bi-link-45deg"></i> Restante de <?= formatMoney($rest['valor']) ?> no <a href="#" onclick="event.preventDefault(); abrirDetalhe('/receber/baixar.php?id=<?= (int) $rest['id'] ?>')">título #<?= (int) $rest['id'] ?></a> · <?= e(statusTelaReceber($rest) === 'recebido' ? 'recebido' : 'em aberto') ?></div><?php endforeach; ?>
    </div>

    <?php if ($atualizacao['dias'] > 0 && $atualizacao['total'] > $t['valor']): ?>
    <div class="alert alert-danger py-2 small" id="memorial-calculo" data-origem="<?= e($atualizacao['origem']) ?>"><?= memorialAtualizacao($t, $atualizacao, 'hoje') ?></div>
    <?php endif; ?>

    <?php if ($historico && $historico['total'] > 0): ?>
    <div class="card mb-3"><div class="card-body py-2 d-flex flex-wrap gap-3 align-items-center small">
        <span class="text-muted">Como <?= e($t['emitente_nome']) ?> paga:</span> <?= badgeClassificacao($historico['classificacao']) ?>
        <span><strong><?= $historico['total'] ?></strong> título(s)</span>
        <span class="valor-positivo"><strong><?= $historico['em_dia'] ?></strong> em dia</span>
        <span><strong><?= $historico['com_atraso'] ?></strong> com atraso</span>
        <span class="valor-negativo"><strong><?= $historico['devolvidos'] ?></strong> devolvido(s)</span>
        <span><strong><?= $historico['vencidos'] ?></strong> vencido(s) em aberto</span>
    </div></div>
    <?php endif; ?>

    <?php // Boleto pelo Banco Inter (com Pix): emitir, linha digitável, PDF, consultar, cancelar. ?>
    <?php $contasBoleto = contasInter($pdo); $temBoleto = $t['boleto_codigo'] && !in_array($t['boleto_situacao'], ['CANCELADO', 'EXPIRADO'], true); ?>
    <?php if ($temBoleto || ($contasBoleto && in_array($t['status'], ['pendente', 'devolvido'], true))): ?>
    <div class="card mb-3"><div class="card-body">
        <div class="secao-titulo mb-2"><i class="bi bi-upc-scan"></i> Boleto
            <?php if ($t['boleto_codigo']): ?><span class="badge <?= $t['boleto_situacao'] === 'RECEBIDO' ? 'text-bg-success' : (in_array($t['boleto_situacao'], ['CANCELADO', 'EXPIRADO'], true) ? 'text-bg-secondary' : 'text-bg-info') ?> ms-2"><?= e(rotuloSituacaoBoleto($t['boleto_situacao'])) ?></span><?php endif; ?>
        </div>
        <?php if ($temBoleto): ?>
            <?php if ($t['boleto_linha_digitavel']): ?>
                <div class="small text-muted">Linha digitável</div>
                <div class="input-group input-group-sm mb-2"><input type="text" class="form-control text-num" readonly value="<?= e($t['boleto_linha_digitavel']) ?>" id="linha-digitavel">
                    <button type="button" class="btn btn-outline-secondary" onclick="navigator.clipboard.writeText(document.getElementById('linha-digitavel').value); this.textContent = 'Copiado';">Copiar</button></div>
            <?php else: ?>
                <div class="small text-muted mb-2">O Inter ainda está registrando o boleto — clique em "Consultar no Inter" em instantes para ver a linha digitável.</div>
            <?php endif; ?>
            <?php if ($t['boleto_pix']): ?>
                <div class="small text-muted">Pix copia e cola</div>
                <div class="input-group input-group-sm mb-2"><input type="text" class="form-control" readonly value="<?= e($t['boleto_pix']) ?>" id="pix-copia">
                    <button type="button" class="btn btn-outline-secondary" onclick="navigator.clipboard.writeText(document.getElementById('pix-copia').value); this.textContent = 'Copiado';">Copiar</button></div>
            <?php endif; ?>
            <div class="d-flex flex-wrap gap-2 align-items-center">
                <?php if ($t['boleto_linha_digitavel']): ?><a href="/receber/boleto_pdf.php?id=<?= (int) $t['id'] ?>" target="_blank" class="btn btn-sm btn-outline-secondary"><i class="bi bi-file-earmark-pdf"></i> PDF do boleto</a><?php endif; ?>
                <form method="post" class="d-inline"><input type="hidden" name="acao" value="consultar_boleto"><button class="btn btn-sm btn-outline-secondary"><i class="bi bi-arrow-repeat"></i> Consultar no Inter</button></form>
                <?php if ($t['boleto_situacao'] !== 'RECEBIDO'): ?>
                <form method="post" class="d-inline" onsubmit="return confirm('Cancelar o boleto no Banco Inter?');"><input type="hidden" name="acao" value="cancelar_boleto"><input type="hidden" name="motivo" value="Cancelado pelo beneficiário"><button class="btn btn-sm btn-outline-danger"><i class="bi bi-x-circle"></i> Cancelar boleto</button></form>
                <?php endif; ?>
                <span class="small text-muted ms-auto">nº <?= e($t['boleto_nosso_numero'] ?: '-') ?> · emitido <?= formatDateTime($t['boleto_emitido_em']) ?></span>
            </div>
        <?php else: ?>
            <?php $pagadorBoleto = null; if ($t['emitente_id']) { $st = $pdo->prepare('SELECT * FROM pessoas WHERE id = ?'); $st->execute([$t['emitente_id']]); $pagadorBoleto = $st->fetch(); } ?>
            <?php $faltaBoleto = $pagadorBoleto ? faltandoParaBoleto($pagadorBoleto) : ['pagador']; ?>
            <?php if ($t['boleto_codigo']): ?><div class="small text-muted mb-2">O boleto anterior está <?= e(mb_strtolower(rotuloSituacaoBoleto($t['boleto_situacao']))) ?>; dá para emitir outro.</div><?php endif; ?>
            <?php if ($faltaBoleto): ?>
                <div class="small">Para emitir, complete o cadastro do pagador: falta <strong><?= e(implode(', ', $faltaBoleto)) ?></strong>.
                    <?php if ($t['emitente_id']): ?><a href="/pessoas/form.php?id=<?= (int) $t['emitente_id'] ?>" target="_blank">abrir cadastro</a><?php endif; ?></div>
            <?php else: ?>
                <form method="post" class="d-flex flex-wrap gap-2 align-items-end" onsubmit="this.querySelector('button').disabled = true; this.querySelector('button').innerHTML = 'Emitindo...';">
                    <input type="hidden" name="acao" value="emitir_boleto">
                    <div><label class="form-label small mb-1">Conta do Inter</label><select name="boleto_conta_id" class="form-select form-select-sm">
                        <?php foreach ($contasBoleto as $cb): ?><option value="<?= (int) $cb['id'] ?>" <?= (int) $cb['id'] === (int) $t['conta_destino_id'] ? 'selected' : '' ?>><?= e($cb['nome']) ?></option><?php endforeach; ?></select></div>
                    <button class="btn btn-sm btn-brand"><i class="bi bi-upc-scan"></i> Emitir boleto (<?= formatMoney($t['valor']) ?> · vence <?= formatDate($t['data_vencimento']) ?>)</button>
                    <?php [$mBol, $jBol] = regraAtualizacao($pdo, $t); ?>
                    <span class="small text-muted">Multa <?= $mBol > 0 ? e(rtrim(rtrim(formatDecimal($mBol, 2), '0'), ',')) . '%' : 'não' ?> · juros <?= $jBol > 0 ? e(rtrim(rtrim(formatDecimal($jBol, 2), '0'), ',')) . '% a.m.' : 'não' ?> (regra do tipo)</span>
                </form>
            <?php endif; ?>
        <?php endif; ?>
    </div></div>
    <?php endif; ?>

    <?php if ($podeMexer): ?>
    <div class="card mb-3 <?= !empty($_GET['editar']) ? '' : 'd-none' ?>" id="editar-titulo">
        <div class="card-body">
            <div class="secao-titulo mb-2"><i class="bi bi-pencil"></i> Editar título</div>
            <form method="post">
                <input type="hidden" name="acao" value="alterar">
                <div class="row g-2">
                    <div class="col-12"><label class="form-label small">Descrição</label><input type="text" name="descricao" class="form-control" maxlength="200" value="<?= e($t['descricao']) ?>"></div>
                    <div class="col-md-3"><label class="form-label small">Tipo</label><select name="tipo" class="form-select"><?php foreach (tiposParaCampo($t['tipo']) as $k => $v): ?><option value="<?= $k ?>" <?= $t['tipo'] === $k ? 'selected' : '' ?>><?= $v ?></option><?php endforeach; ?></select></div>
                    <div class="col-md-3"><label class="form-label small">Valor (R$)</label><input type="text" name="valor" class="form-control" data-moeda inputmode="decimal" value="<?= formatDecimal($t['valor']) ?>"></div>
                    <div class="col-md-3"><label class="form-label small">Vencimento</label><input type="date" name="data_vencimento" class="form-control" value="<?= e($t['data_vencimento']) ?>"></div>
                    <div class="col-md-3"><label class="form-label small">Cai na conta</label><select name="conta_destino_id" class="form-select"><?= opcoesContas($pdo, idOuNull($t['conta_destino_id']), '—') ?></select></div>
                    <div class="col-md-6"><label class="form-label small">Pagador</label><input type="text" name="pagador_nome" class="form-control" data-busca-pessoa="pagador" autocomplete="off-busca" value="<?= e($t['emitente_nome']) ?>"></div>
                    <div class="col-md-6"><label class="form-label small">CPF/CNPJ do pagador <span class="text-muted">(se novo)</span></label><input type="text" name="pagador_documento" class="form-control" inputmode="numeric"></div>
                    <div class="col-md-3"><label class="form-label small">Banco</label><input type="text" name="banco_titulo" class="form-control" value="<?= e($t['banco_titulo']) ?>"></div>
                    <div class="col-md-3"><label class="form-label small">Nº</label><input type="text" name="numero_titulo" class="form-control" value="<?= e($t['numero_titulo']) ?>"></div>
                    <div class="col-md-6"></div>
                    <div class="col-12"><label class="form-label small">Observação</label><textarea name="observacao" class="form-control" rows="2"><?= e($t['observacao']) ?></textarea></div>
                    <?php if ($t['operacao_id'] && !$ehResidual): ?><div class="col-12 form-text">Mudando valor ou vencimento, os juros deste título e os totais da operação são recalculados.</div><?php endif; ?>
                    <div class="col-12 text-end"><button class="btn btn-sm btn-brand"><i class="bi bi-check-lg"></i> Salvar alterações</button></div>
                </div>
            </form>
        </div>
    </div>
    <?php endif; ?>

    <?php // Embaixo: o recebimento. ?>
    <?php if (in_array($t['status'], ['pendente', 'devolvido'], true)): ?>
    <div class="secao">
        <div class="secao-titulo"><i class="bi bi-wallet2"></i> Recebimento</div>
        <div class="card"><div class="card-body">
            <?php // Valor sugerido = atualizado até a data do recebimento (muda junto com a data). ?>
            <form method="post" id="form-receber" data-hoje="<?= date('Y-m-d') ?>" data-valor="<?= e((string) $t['valor']) ?>" data-vencimento="<?= e($t['data_vencimento']) ?>"
                  data-multa="<?= e((string) $atualizacao['multa_percent']) ?>" data-juros="<?= e((string) $atualizacao['juros_mes']) ?>">
                <input type="hidden" name="acao" value="receber">
                <div class="row g-2 align-items-end">
                    <div class="col-md-3"><label class="form-label small">Data</label><input type="date" name="data" class="form-control" required value="<?= date('Y-m-d') ?>"></div>
                    <div class="col-md-3"><label class="form-label small">Valor (R$)</label><input type="text" name="valor" class="form-control" data-moeda inputmode="decimal" required value="<?= formatDecimal($atualizacao['total']) ?>"></div>
                    <div class="col-md-4"><label class="form-label small">Entrou na conta</label><select name="conta_id" class="form-select" required><?= opcoesContas($pdo, idOuNull($t['conta_destino_id']), 'Escolha...') ?></select></div>
                    <div class="col-md-2"><button class="btn btn-brand w-100"><i class="bi bi-check-lg"></i> Receber</button></div>
                </div>
                <?php // Recebendo menos que o atualizado: o que fazer com a diferença. ?>
                <div class="mt-3 p-2 rounded border d-none" id="bloco-diferenca">
                    <div class="small fw-semibold mb-1">Faltam <span id="diferenca-valor"></span> para o atualizado. O que fazer com a diferença?</div>
                    <label class="form-check small"><input type="radio" class="form-check-input" name="diferenca_modo" value="baixar" checked> Baixar assim mesmo (a diferença fica de desconto)</label>
                    <label class="form-check small"><input type="radio" class="form-check-input" name="diferenca_modo" value="aberto"> Deixar o restante em aberto (novo título do mesmo pagador, vencendo na data do recebimento)</label>
                    <label class="form-check small d-flex align-items-center gap-2 flex-wrap"><input type="radio" class="form-check-input" name="diferenca_modo" value="pagador"> Lançar a diferença para o pagador, vencendo em
                        <input type="date" name="diferenca_vencimento" class="form-control form-control-sm" style="width: 160px;" value="<?= date('Y-m-d', strtotime('+30 days')) ?>"></label>
                    <?php if ($t['cliente_id']): ?>
                    <label class="form-check small"><input type="radio" class="form-check-input" name="diferenca_modo" value="cliente"> Lançar a diferença para o cliente <?= e($t['cliente_nome']) ?> (vira repasse em aberto)</label>
                    <?php endif; ?>
                </div>
            </form>
            <script>
            (function () {
                const form = document.getElementById('form-receber');
                const data = form.querySelector('[name="data"]');
                const campo = form.querySelector('[name="valor"]');
                const d = form.dataset;
                function dias(de, ate) {
                    const [a1, m1, d1] = de.split('-').map(Number), [a2, m2, d2] = ate.split('-').map(Number);
                    return Math.round((Date.UTC(a2, m2 - 1, d2) - Date.UTC(a1, m1 - 1, d1)) / 86400000);
                }
                // Mesma conta de atualizacaoReceber() no PHP.
                function atualizar() {
                    const valor = Number(d.valor), n = Math.max(0, dias(d.vencimento, data.value || d.vencimento));
                    const juros = n > 0 ? Math.round(valor * Number(d.juros) / 100 / 30 * n * 100) / 100 : 0;
                    const multa = n > 0 ? Math.round(valor * Number(d.multa) / 100 * 100) / 100 : 0;
                    const fmt = (v) => v.toLocaleString('pt-BR', { minimumFractionDigits: 2, maximumFractionDigits: 2 });
                    devido = Math.round((valor + juros + multa) * 100) / 100;
                    campo.value = fmt(devido);
                    // Memorial de cálculo na data escolhida.
                    const memorial = document.getElementById('memorial-calculo');
                    if (memorial && n > 0) {
                        const pct = (v) => String(Math.round(v * 10000) / 10000).replace('.', ',');
                        const hoje = data.value === d.hoje; // data do servidor (horário do Brasil)
                        memorial.innerHTML = 'Atualizado ' + (hoje ? 'até hoje' : 'em ' + data.value.split('-').reverse().join('/')) + ': R$ ' + fmt(valor)
                            + (multa ? ' + multa R$ ' + fmt(multa) + ' (' + pct(Number(d.multa)) + '%)' : '')
                            + (juros ? ' + juros R$ ' + fmt(juros) + ' (' + pct(Number(d.juros)) + '% a.m. · ' + n + ' dia' + (n > 1 ? 's' : '') + ')' : '')
                            + ' = <strong>R$ ' + fmt(devido) + '</strong> <span class="text-muted">(' + memorial.dataset.origem + ')</span>';
                    }
                    mostrarDiferenca();
                }
                // Valor menor que o atualizado na data: mostra as opções para a diferença.
                let devido = 0;
                function mostrarDiferenca() {
                    // Leitura local de "1.000,00" (o moeda.js só carrega no rodapé da página).
                    let t = String(campo.value || '').replace(/[^\d,.-]/g, '');
                    if (t.includes(',')) t = t.replace(/\./g, '').replace(',', '.');
                    const falta = Math.round((devido - (parseFloat(t) || 0)) * 100) / 100;
                    document.getElementById('bloco-diferenca').classList.toggle('d-none', !(falta > 0.004));
                    document.getElementById('diferenca-valor').textContent = 'R$ ' + falta.toLocaleString('pt-BR', { minimumFractionDigits: 2, maximumFractionDigits: 2 });
                }
                campo.addEventListener('input', mostrarDiferenca);
                data.addEventListener('change', atualizar);
                atualizar();
            })();
            </script>
            <?php // Não pagou: marcar devolvido (de novo, se reapresentado) ou devolver para o cliente. ?>
            <div class="d-flex flex-wrap gap-3 mt-3 small">
                <a href="#" class="text-danger" onclick="event.preventDefault(); this.closest('.card-body').querySelector('.bloco-devolver').classList.toggle('d-none');">
                    <i class="bi bi-x-octagon"></i> <?= $t['status'] === 'devolvido' ? 'Voltou de novo (reapresentado)' : 'Voltou / não pagou' ?></a>
                <?php if ($t['cliente_id']): ?>
                <a href="#" onclick="event.preventDefault(); this.closest('.card-body').querySelector('.bloco-cliente').classList.toggle('d-none');">
                    <i class="bi bi-arrow-return-left"></i> Devolver para o cliente</a>
                <?php endif; ?>
            </div>
            <form method="post" class="mt-2 bloco-devolver d-none" onsubmit="return confirm('Marcar como devolvido?');">
                <input type="hidden" name="acao" value="devolver">
                <div class="row g-2 align-items-end">
                    <div class="col-md-3"><label class="form-label small">Data</label><input type="date" name="data" class="form-control" value="<?= date('Y-m-d') ?>"></div>
                    <div class="col-md-6"><label class="form-label small">Motivo (alínea)</label>
                        <input type="text" name="motivo" class="form-control" list="lista-alineas" maxlength="120" value="<?= $t['vezes_devolvido'] > 0 ? '12 - Sem fundos – 2ª apresentação' : '' ?>" placeholder="Ex.: 11 - Sem fundos">
                        <datalist id="lista-alineas"><?php foreach (alineasDevolucao() as $n => $texto): ?><option value="<?= e($n . ' - ' . $texto) ?>"></option><?php endforeach; ?></datalist>
                    </div>
                    <div class="col-md-3"><button class="btn btn-outline-danger w-100">Marcar devolvido</button></div>
                </div>
            </form>
            <?php if ($t['cliente_id']): ?>
            <form method="post" class="mt-2 bloco-cliente d-none" onsubmit="return confirm('Devolver para <?= e($t['cliente_nome']) ?>? Sai do contas a receber e vira repasse em aberto dele.');">
                <input type="hidden" name="acao" value="devolver_cliente">
                <div class="small text-muted mb-2">Sai do seu contas a receber e vira um <strong>repasse em aberto</strong> de <?= e($t['cliente_nome']) ?> (sem sair dinheiro de conta) — corrige pela taxa dele e entra na próxima operação.</div>
                <div class="row g-2 align-items-end">
                    <div class="col-md-3"><label class="form-label small">Data</label><input type="date" name="data" class="form-control" value="<?= date('Y-m-d') ?>"></div>
                    <div class="col-md-4"><label class="form-label small">Valor que fica com ele (R$)</label><input type="text" name="valor" class="form-control" data-moeda inputmode="decimal" value="<?= formatDecimal($atualizacao['total']) ?>"></div>
                    <div class="col-md-5"><button class="btn btn-outline-secondary w-100"><i class="bi bi-arrow-return-left"></i> Devolver para o cliente</button></div>
                </div>
            </form>
            <?php endif; ?>
        </div></div>
    </div>
    <?php elseif ($t['status'] === 'recebido'): ?>
        <form method="post" onsubmit="return confirm('Estornar o recebimento? O valor sai do extrato da conta.');">
            <input type="hidden" name="acao" value="estornar">
            <button class="btn btn-outline-danger"><i class="bi bi-arrow-counterclockwise"></i> Estornar recebimento</button>
        </form>
    <?php elseif ($t['status'] === 'transferido'): ?>
        <form method="post" onsubmit="return confirm('Desfazer? O título volta para o contas a receber e o repasse do cliente sai.');">
            <input type="hidden" name="acao" value="desfazer_cliente">
            <button class="btn btn-outline-secondary"><i class="bi bi-arrow-counterclockwise"></i> Desfazer devolução para o cliente</button>
        </form>
    <?php elseif ($t['status'] === 'cancelado'): ?>
        <form method="post"><input type="hidden" name="acao" value="reabrir"><button class="btn btn-outline-secondary">Reabrir lançamento</button></form>
    <?php endif; ?>
</div>
<?php if ($podeMexer): ?><?= buscaPessoas($pdo) ?><?php endif; ?>

<?php require __DIR__ . '/../../includes/layout_footer.php'; ?>
