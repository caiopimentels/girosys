<?php
require_once __DIR__ . '/../../includes/bootstrap.php';
exigirLogin();

$pdo = getDB();
$erros = [];

// Estado inicial da tela (o JS monta as linhas a partir dele).
$estado = [
    'cliente_nome' => '',
    'cliente_documento' => '',
    'data_base' => date('Y-m-d'),
    'taxa' => '',
    'dias_compensacao' => '0',
    'conta_padrao_id' => '',
    'observacao' => '',
    'pagamento_cliente' => 'pago',
    'data_pagamento_cliente' => date('Y-m-d'),
    'valor_pago_cliente' => '',
    'resto_cliente' => 'desconto',
    'vencimento_resto_cliente' => '',
    'conta_pagamento_id' => '',
    'residual_modo' => 'receber',
    'residual_vencimento' => date('Y-m-d', strtotime('+30 days')),
    'titulos' => [],
    'adiantamentos' => [], // [{id, valor_usado}] marcados (quando volta com erro)
];

if (!empty($_GET['cliente'])) {
    $stmt = $pdo->prepare('SELECT nome, taxa_padrao FROM pessoas WHERE id = ?');
    $stmt->execute([(int) $_GET['cliente']]);
    if ($c = $stmt->fetch()) {
        $estado['cliente_nome'] = $c['nome'];
        $estado['taxa'] = $c['taxa_padrao'] !== null ? rtrim(rtrim(formatDecimal($c['taxa_padrao'], 4), '0'), ',') : '';
    }
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $titulos = [];
    foreach ((array) ($_POST['titulos'] ?? []) as $t) {
        $titulos[] = [
            'tipo' => (string) ($t['tipo'] ?? 'cheque'),
            'emitente_nome' => trim((string) ($t['emitente_nome'] ?? '')),
            'emitente_documento' => trim((string) ($t['emitente_documento'] ?? '')),
            'banco_titulo' => trim((string) ($t['banco_titulo'] ?? '')),
            'numero_titulo' => trim((string) ($t['numero_titulo'] ?? '')),
            'valor' => paraDecimal($t['valor'] ?? ''),
            'vencimento' => preg_match('/^\d{4}-\d{2}-\d{2}$/', (string) ($t['vencimento'] ?? '')) ? $t['vencimento'] : '',
            'conta_destino_id' => (string) ($t['conta_destino_id'] ?? ''),
            'observacao' => trim((string) ($t['observacao'] ?? '')),
        ];
    }
    $adiantamentos = [];
    foreach ((array) ($_POST['adiantamentos'] ?? []) as $a) {
        $adiantamentos[] = ['id' => (int) ($a['id'] ?? 0), 'valor_usado' => paraDecimal($a['valor_usado'] ?? '')];
    }

    $estado = [
        'cliente_nome' => input('cliente_nome'),
        'cliente_documento' => input('cliente_documento'),
        'data_base' => dataInput('data_base') ?? '',
        'taxa' => input('taxa'),
        'dias_compensacao' => input('dias_compensacao', '0'),
        'conta_padrao_id' => input('conta_padrao_id'),
        'observacao' => input('observacao'),
        'pagamento_cliente' => input('pagamento_cliente') === 'agendado' ? 'agendado' : 'pago',
        'data_pagamento_cliente' => dataInput('data_pagamento_cliente') ?? '',
        'valor_pago_cliente' => input('valor_pago_cliente'),
        'resto_cliente' => input('resto_cliente') === 'agendar' ? 'agendar' : 'desconto',
        'vencimento_resto_cliente' => dataInput('vencimento_resto_cliente') ?? '',
        'conta_pagamento_id' => input('conta_pagamento_id'),
        'residual_modo' => input('residual_modo') === 'adiantamento' ? 'adiantamento' : 'receber',
        'residual_vencimento' => dataInput('residual_vencimento') ?? '',
        'titulos' => $titulos,
        'adiantamentos' => $adiantamentos,
    ];

    try {
        if ($estado['cliente_nome'] === '') {
            throw new InvalidArgumentException('Informe o cliente.');
        }
        if ($estado['taxa'] === '') {
            throw new InvalidArgumentException('Informe a taxa.');
        }
        $pdo->beginTransaction();
        $clienteId = obterOuCriarPessoa($pdo, $estado['cliente_nome'], $estado['cliente_documento'], 'cliente');
        // Primeira operação de quem não tinha taxa: guarda essa como a taxa dele.
        $pdo->prepare('UPDATE pessoas SET taxa_padrao = ? WHERE id = ? AND taxa_padrao IS NULL')->execute([paraDecimal($estado['taxa']), $clienteId]);
        $pdo->commit();

        $operacaoId = salvarOperacao($pdo, [
            'cliente_id' => $clienteId,
            'data_base' => $estado['data_base'],
            'taxa' => paraDecimal($estado['taxa']),
            'dias_compensacao' => (int) $estado['dias_compensacao'],
            'observacao' => $estado['observacao'],
            'titulos' => $titulos,
            'adiantamentos' => $adiantamentos,
            'pagamento_cliente' => $estado['pagamento_cliente'],
            'data_pagamento_cliente' => $estado['data_pagamento_cliente'],
            'valor_pago_cliente' => paraDecimal($estado['valor_pago_cliente']),
            'resto_cliente' => $estado['resto_cliente'],
            'vencimento_resto_cliente' => $estado['vencimento_resto_cliente'],
            'conta_pagamento_id' => $estado['conta_pagamento_id'],
            'residual_modo' => $estado['residual_modo'],
            'residual_vencimento' => $estado['residual_vencimento'],
        ]);
        registrarAtividade('operacoes', 'criacao', 'Gravada a ' . resumoOperacaoAuditoria($pdo, $operacaoId) . descricaoPagamentoClienteAuditoria($pdo, $operacaoId));
        flash('success', 'Operação #' . $operacaoId . ' gravada. Os títulos já estão em Contas a receber.');
        redirect('/operacoes/ver.php?id=' . $operacaoId);
    } catch (InvalidArgumentException $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        $erros[] = $e->getMessage();
    }
}

$contasJs = array_map(fn ($c) => ['id' => (int) $c['id'], 'nome' => $c['nome']], $pdo->query('SELECT id, nome FROM contas_bancarias WHERE ativo = 1 ORDER BY nome')->fetchAll());

$pageTitle = 'Nova operação de desconto';
$pageBreadcrumb = 'Operações';
$activeMenu = 'operacao-nova';
require __DIR__ . '/../../includes/layout_header.php';
?>

<?php if ($erros): ?>
    <div class="alert alert-danger"><ul class="mb-0"><?php foreach ($erros as $erro): ?><li><?= e($erro) ?></li><?php endforeach; ?></ul></div>
<?php endif; ?>
<?php if (!$contasJs): ?>
    <div class="alert alert-warning">Cadastre suas contas bancárias primeiro para escolher onde cada título vai cair. <a href="/contas/form.php">Cadastrar conta</a></div>
<?php endif; ?>

<form method="post" id="form-operacao" autocomplete="off">
<div class="row g-4">
    <div class="col-xl-9">
        <div class="secao">
            <div class="secao-titulo"><span class="secao-numero">1</span> Cliente e condições</div>
            <div class="row g-3">
                <div class="col-md-5">
                    <label class="form-label">Cliente *</label>
                    <input type="text" name="cliente_nome" id="cliente_nome" class="form-control" data-busca-pessoa="cliente" autocomplete="off-busca" required value="<?= e($estado['cliente_nome']) ?>" <?= $estado['cliente_nome'] ? '' : 'autofocus' ?>>
                    <div class="small mt-1" id="cliente-info"></div>
                </div>
                <div class="col-md-3">
                    <label class="form-label">CPF/CNPJ <span class="text-muted small">(se novo)</span></label>
                    <input type="text" name="cliente_documento" class="form-control" inputmode="numeric" autocomplete="off-busca" value="<?= e($estado['cliente_documento']) ?>">
                </div>
                <div class="col-md-4">
                    <label class="form-label">Data base do cálculo *</label>
                    <input type="date" name="data_base" id="data_base" class="form-control" required value="<?= e($estado['data_base']) ?>">
                    <div class="form-text">Títulos são trazidos para esta data; os repasses são corrigidos até ela.</div>
                </div>
                <div class="col-md-3">
                    <label class="form-label">Taxa (% ao mês) *</label>
                    <div class="input-group">
                        <input type="text" name="taxa" id="taxa" class="form-control" inputmode="decimal" required placeholder="2,0" value="<?= e($estado['taxa']) ?>">
                        <span class="input-group-text">%</span>
                    </div>
                    <div class="form-text" id="taxa-origem"></div>
                </div>
                <div class="col-md-3">
                    <label class="form-label">Dias de compensação</label>
                    <input type="number" name="dias_compensacao" id="dias_compensacao" class="form-control" min="0" max="30" value="<?= e($estado['dias_compensacao']) ?>">
                    <div class="form-text">Somados aos dias de cada título.</div>
                </div>
                <div class="col-md-6">
                    <label class="form-label">Conta onde os títulos vão cair</label>
                    <select name="conta_padrao_id" id="conta_padrao_id" class="form-select"><?= opcoesContas($pdo, idOuNull($estado['conta_padrao_id']), '— escolher em cada título —') ?></select>
                    <div class="form-text">Vale para os títulos novos; dá para trocar no detalhe de cada um.</div>
                </div>
            </div>
        </div>

        <div class="secao">
            <div class="secao-titulo">
                <span class="secao-numero">2</span> Títulos recebidos (cheques, boletos, promissórias, depósitos)
                <button type="button" class="btn btn-sm btn-outline-secondary ms-auto" id="btn-parcelas"><i class="bi bi-magic"></i> Calcular parcelas</button>
                <button type="button" class="btn btn-sm btn-outline-secondary" id="btn-add-titulo"><i class="bi bi-plus-lg"></i> Adicionar título</button>
            </div>
            <div class="card">
                <div class="table-responsive">
                    <table class="table mb-0 tabela-titulos" id="tabela-titulos">
                        <thead><tr>
                            <th style="width: 34px;"></th>
                            <th style="min-width: 160px;">Tipo</th>
                            <th style="min-width: 165px;">Vencimento</th>
                            <th style="min-width: 210px;">Valor</th>
                            <th>Pagador · conta</th>
                            <th class="text-end" style="width: 60px;">Dias</th>
                            <th class="text-end" style="width: 105px;">Juros</th>
                            <th class="text-end" style="width: 120px;">Líquido</th>
                            <th style="width: 70px;"></th>
                        </tr></thead>
                        <tfoot><tr class="fw-semibold">
                            <td colspan="3" class="text-end">Totais</td>
                            <td class="calc" id="tot-bruto">R$ 0,00</td>
                            <td colspan="2"></td>
                            <td class="calc text-end" id="tot-juros">R$ 0,00</td>
                            <td class="calc text-end" id="tot-liquido">R$ 0,00</td>
                            <td></td>
                        </tr></tfoot>
                    </table>
                </div>
            </div>
            <div class="form-text">Clique na linha para preencher o pagador do título, CPF, banco, nº e conta. Pagador em branco = cheque do próprio cliente (vira um cadastro de pagador separado). Enter no valor cria o próximo título; <i class="bi bi-copy"></i> repete com vencimento +30 dias.</div>
        </div>

        <div class="secao">
            <div class="secao-titulo">
                <span class="secao-numero">3</span> Repasses ao cliente — marque o que esta operação quita
                <button type="button" class="btn btn-sm btn-outline-secondary ms-auto" id="btn-marcar-todos"><i class="bi bi-check2-all"></i> Marcar todos</button>
            </div>
            <div class="card">
                <div class="table-responsive">
                    <table class="table table-sm mb-0 align-middle tabela-titulos">
                        <thead><tr><th style="width: 34px;"></th><th>Data</th><th>Descrição</th><th>Saiu de</th><th class="text-end">Em aberto</th><th style="width: 150px;">Usar nesta operação</th><th class="text-end">Dias</th><th class="text-end">Juros</th><th class="text-end">Corrigido</th></tr></thead>
                        <tbody id="corpo-adiantamentos">
                            <tr><td colspan="9" class="text-center text-muted py-3">Escolha o cliente para ver os repasses dele em aberto.</td></tr>
                        </tbody>
                        <tfoot><tr class="fw-semibold">
                            <td colspan="5" class="text-end">Marcados</td>
                            <td class="calc" id="tot-adiant">R$ 0,00</td>
                            <td></td>
                            <td class="calc text-end" id="tot-adiant-juros">R$ 0,00</td>
                            <td class="calc text-end" id="tot-adiant-corrigido">R$ 0,00</td>
                        </tr></tfoot>
                    </table>
                </div>
            </div>
            <details class="mt-2" id="novo-adiantamento">
                <summary class="small fw-semibold">+ Lançar um repasse que ainda não está no sistema</summary>
                <div class="row g-2 mt-1 align-items-end">
                    <div class="col-md-2"><label class="form-label small">Data</label><input type="date" id="na-data" class="form-control form-control-sm" value="<?= date('Y-m-d') ?>"></div>
                    <div class="col-md-2"><label class="form-label small">Valor (R$)</label><input type="text" id="na-valor" class="form-control form-control-sm" data-moeda inputmode="decimal"></div>
                    <div class="col-md-3"><label class="form-label small">Saiu da conta</label><select id="na-conta" class="form-select form-select-sm"><?= opcoesContaSaida($pdo) ?></select></div>
                    <div class="col-md-3"><label class="form-label small">Descrição</label><input type="text" id="na-descricao" class="form-control form-control-sm" maxlength="200"></div>
                    <div class="col-md-2"><button type="button" class="btn btn-sm btn-outline-secondary w-100" id="na-salvar"><i class="bi bi-plus-lg"></i> Lançar e marcar</button></div>
                    <div class="col-12 small text-danger" id="na-erro"></div>
                </div>
            </details>
            <div class="form-text">Nada marcado = desconto direto: o líquido dos títulos é o que o cliente recebe agora.</div>
        </div>

        <div class="secao">
            <label class="form-label">Observação</label>
            <textarea name="observacao" class="form-control" rows="2"><?= e($estado['observacao']) ?></textarea>
        </div>
    </div>

    <div class="col-xl-3">
        <div class="card resumo-operacao">
            <div class="card-body">
                <div class="secao-titulo mb-2"><i class="bi bi-calculator"></i> Resumo</div>
                <div class="linha"><span>Valor dos títulos</span><span id="r-bruto">R$ 0,00</span></div>
                <div class="linha"><span>(−) Juros / deságio</span><span id="r-juros" class="valor-positivo">R$ 0,00</span></div>
                <div class="linha total"><span>Líquido dos títulos</span><span id="r-liquido">R$ 0,00</span></div>

                <div class="linha mt-2"><span>Repasses</span><span id="r-adiant">R$ 0,00</span></div>
                <div class="linha"><span>(+) Juros dos repasses</span><span id="r-adiant-juros" class="valor-positivo">R$ 0,00</span></div>
                <div class="linha total"><span>Repasses corrigidos</span><span id="r-adiant-corrigido">R$ 0,00</span></div>

                <div class="mt-3 pt-2 border-top">
                    <div class="kpi-rotulo" id="r-saldo-rotulo">Pagar ao cliente</div>
                    <div class="saldo-final" id="r-saldo">R$ 0,00</div>
                    <div class="small text-muted" id="r-saldo-ajuda"></div>
                </div>

                <div id="bloco-pagar" class="mt-3">
                    <div class="small fw-semibold mb-1">O cliente:</div>
                    <div class="form-check">
                        <input type="radio" class="form-check-input" name="pagamento_cliente" id="pagamento-pago" value="pago" <?= $estado['pagamento_cliente'] === 'pago' ? 'checked' : '' ?>>
                        <label class="form-check-label small" for="pagamento-pago">Já foi pago (sai da conta agora)</label>
                    </div>
                    <div class="form-check">
                        <input type="radio" class="form-check-input" name="pagamento_cliente" id="pagamento-agendado" value="agendado" <?= $estado['pagamento_cliente'] === 'agendado' ? 'checked' : '' ?>>
                        <label class="form-check-label small" for="pagamento-agendado">Agendar para pagar depois (vira conta a pagar)</label>
                    </div>
                    <label class="form-label small mt-2 mb-0" for="data_pagamento_cliente" id="rotulo-data-pagamento">Pago em</label>
                    <input type="date" name="data_pagamento_cliente" id="data_pagamento_cliente" class="form-control form-control-sm" value="<?= e($estado['data_pagamento_cliente']) ?>">
                    <select name="conta_pagamento_id" id="conta_pagamento_id" class="form-select form-select-sm mt-2"><?= opcoesContas($pdo, idOuNull($estado['conta_pagamento_id']), 'Sai de qual conta?') ?></select>
                    <div id="bloco-valor-pago">
                        <label class="form-label small mt-2 mb-0" for="valor_pago_cliente">Valor pago <span class="text-muted">(se pagou diferente)</span></label>
                        <input type="text" name="valor_pago_cliente" id="valor_pago_cliente" class="form-control form-control-sm text-end" data-moeda inputmode="decimal" value="<?= e($estado['valor_pago_cliente']) ?>">
                        <div id="bloco-resto" class="mt-2 p-2 rounded bg-body-tertiary" style="display: none;">
                            <div class="small fw-semibold mb-1">Faltam <span id="r-resto">R$ 0,00</span>:</div>
                            <div class="form-check">
                                <input type="radio" class="form-check-input" name="resto_cliente" id="resto-desconto" value="desconto" <?= $estado['resto_cliente'] !== 'agendar' ? 'checked' : '' ?>>
                                <label class="form-check-label small" for="resto-desconto">Arredondamento: fica para mim (entra no lucro)</label>
                            </div>
                            <div class="form-check">
                                <input type="radio" class="form-check-input" name="resto_cliente" id="resto-agendar" value="agendar" <?= $estado['resto_cliente'] === 'agendar' ? 'checked' : '' ?>>
                                <label class="form-check-label small" for="resto-agendar">Ainda devo ao cliente: pagar em</label>
                            </div>
                            <input type="date" name="vencimento_resto_cliente" id="vencimento_resto_cliente" class="form-control form-control-sm mt-1" value="<?= e($estado['vencimento_resto_cliente']) ?>">
                        </div>
                    </div>
                </div>

                <div id="bloco-residual" class="mt-3">
                    <div class="small fw-semibold mb-1">O residual vira:</div>
                    <div class="form-check">
                        <input type="radio" class="form-check-input" name="residual_modo" id="residual-receber" value="receber" <?= $estado['residual_modo'] === 'receber' ? 'checked' : '' ?>>
                        <label class="form-check-label small" for="residual-receber">Conta a receber do cliente, vencendo em</label>
                    </div>
                    <input type="date" name="residual_vencimento" id="residual_vencimento" class="form-control form-control-sm my-1" value="<?= e($estado['residual_vencimento']) ?>">
                    <div class="form-check">
                        <input type="radio" class="form-check-input" name="residual_modo" id="residual-adiant" value="adiantamento" <?= $estado['residual_modo'] === 'adiantamento' ? 'checked' : '' ?>>
                        <label class="form-check-label small" for="residual-adiant">Saldo devedor para puxar na próxima operação (continua corrigindo pela taxa)</label>
                    </div>
                </div>

                <div class="linha mt-3"><span>Lucro da operação</span><span id="r-lucro" class="fw-semibold valor-positivo">R$ 0,00</span></div>

                <div id="bloco-limite" class="mt-2 small"></div>

                <button type="submit" class="btn btn-brand w-100 mt-3"><i class="bi bi-check-lg"></i> Gravar operação</button>
                <div class="form-text text-center">Até gravar, a tela funciona como simulador.</div>
            </div>
        </div>
    </div>
</div>
</form>

<?php // Calcular parcelas: acha o valor de cada título para os líquidos somarem o valor desejado (assets/js/operacao.js). ?>
<div class="modal fade" id="modal-parcelas" tabindex="-1">
    <div class="modal-dialog modal-lg">
        <div class="modal-content">
            <div class="modal-header"><h5 class="modal-title"><i class="bi bi-magic"></i> Calcular parcelas</h5><button type="button" class="btn-close" data-bs-dismiss="modal"></button></div>
            <div class="modal-body" id="painel-parcelas">
                <p class="small text-muted">Quanto o cliente precisa cobrir (líquido) e em quantas parcelas: o sistema acha o valor de cada título, já descontados os juros de cada prazo pela taxa da operação.</p>
                <div class="row g-3">
                    <div class="col-md-5"><label class="form-label">Líquido desejado (R$)</label><input type="text" class="form-control" id="parc-alvo" data-moeda inputmode="decimal"><div class="form-text" id="parc-alvo-ajuda"></div></div>
                    <div class="col-md-3"><label class="form-label">Parcelas</label><input type="number" class="form-control" id="parc-qtd" min="1" max="360" value="3"></div>
                    <div class="col-md-4"><label class="form-label">1º vencimento</label><input type="date" class="form-control" id="parc-venc"></div>
                    <div class="col-md-5"><label class="form-label">Intervalo entre as parcelas</label>
                        <select class="form-select" id="parc-intervalo"><option value="mes">Mensal (mesmo dia do mês)</option><option value="dias">A cada X dias</option></select></div>
                    <div class="col-md-3" id="bloco-parc-dias"><label class="form-label">Dias</label><input type="number" class="form-control" id="parc-dias" min="1" max="365" value="30"></div>
                    <div class="col-md-4"><label class="form-label">Tipo</label>
                        <select class="form-select" id="parc-tipo"><?php foreach (tiposTitulo() as $k => $v): ?><option value="<?= e($k) ?>"><?= e($v) ?></option><?php endforeach; ?></select></div>
                    <div class="col-md-5"><label class="form-label">Arredondar para cima</label>
                        <select class="form-select" id="parc-arred"><option value="0.01">Centavos</option><option value="1">R$ 1</option><option value="10">R$ 10</option></select></div>
                </div>
                <div class="mt-3 p-2 rounded bg-body-tertiary small" id="parc-previa"></div>
                <div class="small text-muted mt-2" id="parc-vencs"></div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">Cancelar</button>
                <button type="button" class="btn btn-brand" id="parc-gerar"><i class="bi bi-check-lg"></i> Gerar títulos</button>
            </div>
        </div>
    </div>
</div>

<?= buscaPessoas($pdo) ?>

<script>
window.OPERACAO = <?= json_encode([
    'estado' => $estado,
    'contas' => $contasJs,
    'tipos' => tiposTitulo(),
], JSON_HEX_TAG | JSON_HEX_AMP | JSON_UNESCAPED_UNICODE) ?>;
</script>
<script src="<?= e(assetUrl('/assets/js/operacao.js')) ?>" defer></script>

<?php require __DIR__ . '/../../includes/layout_footer.php'; ?>
