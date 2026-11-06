<?php
// Renegociar títulos em aberto de um pagador (?ids[]=...): calcula juros e
// multa até a data base, decide para onde vão (novos boletos, cliente ou
// isento) e gera as novas parcelas. ?id= mostra uma renegociação feita e
// permite desfazer.
require_once __DIR__ . '/../../includes/bootstrap.php';
exigirLogin();

$pdo = getDB();

// ---- Ver / desfazer uma renegociação já feita ----
if (isset($_GET['id']) || ($_POST['acao'] ?? '') === 'desfazer') {
    $renegId = (int) ($_GET['id'] ?? $_POST['id'] ?? 0);
    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        $pdo->beginTransaction();
        try {
            desfazerRenegociacao($pdo, $renegId);
            registrarAtividade('contas_receber', 'estorno', 'Renegociação #' . $renegId . ' desfeita: títulos novos excluídos e os antigos voltaram a ficar em aberto');
            $pdo->commit();
            flash('success', 'Renegociação desfeita: os títulos antigos voltaram para o em aberto.');
            redirect('/receber/listar.php');
        } catch (InvalidArgumentException $e) {
            $pdo->rollBack();
            flash('danger', $e->getMessage());
            redirect('/receber/renegociar.php?id=' . $renegId);
        }
    }
    $stmt = $pdo->prepare('SELECT r.*, p.nome AS pagador_nome, c.nome AS cliente_nome, u.nome AS usuario_nome FROM renegociacoes r
        LEFT JOIN pessoas p ON p.id = r.pagador_id LEFT JOIN pessoas c ON c.id = r.cliente_id LEFT JOIN usuarios u ON u.id = r.usuario_id WHERE r.id = ?');
    $stmt->execute([$renegId]);
    $reneg = $stmt->fetch();
    if (!$reneg) {
        flash('danger', 'Renegociação não encontrada.');
        redirect('/receber/listar.php');
    }
    $buscar = fn (string $campo) => $pdo->query("SELECT * FROM contas_receber WHERE {$campo} = " . (int) $renegId . ' ORDER BY data_vencimento, id')->fetchAll();
    $antigos = $buscar('renegociado_em_id');
    $novos = $buscar('renegociacao_id');

    $pageTitle = 'Renegociação #' . $renegId;
    $pageBreadcrumb = 'Financeiro · Contas a receber';
    $activeMenu = 'receber';
    $pageAction = '<a href="/receber/listar.php" class="btn btn-outline-secondary"><i class="bi bi-arrow-left"></i> Contas a receber</a>';
    require __DIR__ . '/../../includes/layout_header.php';
    $tabela = function (array $lista, string $titulo) { ?>
        <div class="secao"><div class="secao-titulo"><?= $titulo ?></div>
        <div class="card"><table class="table table-sm table-hover mb-0 align-middle">
            <thead><tr><th>#</th><th>Vencimento</th><th>Descrição</th><th>Nº / nosso número</th><th class="text-end">Valor</th><th>Situação</th></tr></thead>
            <tbody><?php foreach ($lista as $t): ?>
                <tr data-detalhe="/receber/baixar.php?id=<?= (int) $t['id'] ?>" style="cursor: pointer;"><td><?= (int) $t['id'] ?></td><td class="text-num"><?= formatDate($t['data_vencimento']) ?></td><td><?= e($t['descricao'] ?: '-') ?></td>
                    <td class="small"><?= e(implode(' · ', array_filter([$t['numero_titulo'], $t['boleto_nosso_numero']]))) ?: '-' ?></td>
                    <td class="text-end text-num"><?= formatMoney($t['valor']) ?></td><td><?= badgeStatus(statusTelaReceber($t)) ?></td></tr>
            <?php endforeach; ?></tbody>
        </table></div></div>
    <?php };
    ?>
    <?php if ($reneg['desfeita_em']): ?><div class="alert alert-secondary">Esta renegociação foi desfeita em <?= formatDateTime($reneg['desfeita_em']) ?>.</div><?php endif; ?>
    <div class="secao" style="max-width: 900px;">
        <div class="secao-titulo"><i class="bi bi-arrow-left-right"></i> Resumo</div>
        <div class="row g-3 small">
            <div class="col-md-4"><div class="text-muted">Pagador</div><div class="fw-semibold"><?= e($reneg['pagador_nome'] ?? '-') ?></div></div>
            <div class="col-md-4"><div class="text-muted">Cliente</div><div class="fw-semibold"><?= e($reneg['cliente_nome'] ?? '-') ?></div></div>
            <div class="col-md-4"><div class="text-muted">Feita em</div><div><?= formatDateTime($reneg['criado_em']) ?> · <?= e($reneg['usuario_nome'] ?? '-') ?></div></div>
            <div class="col-md-3"><div class="text-muted">Valor original</div><div class="fw-semibold text-num"><?= formatMoney($reneg['total_original']) ?></div></div>
            <div class="col-md-3"><div class="text-muted">Juros + multa até <?= formatDate($reneg['data']) ?></div><div class="fw-semibold text-num"><?= formatMoney($reneg['encargos']) ?></div></div>
            <div class="col-md-3"><div class="text-muted">Encargos</div><div><?= e(destinosEncargos()[$reneg['destino_encargos']]) ?></div></div>
            <div class="col-md-3"><div class="text-muted">Novo total</div><div class="fw-semibold text-num"><?= formatMoney($reneg['total_novo']) ?> em <?= (int) $reneg['parcelas'] ?>x</div></div>
            <?php if ($reneg['adiantamento_id']): ?><div class="col-12"><a href="/adiantamentos/form.php?id=<?= (int) $reneg['adiantamento_id'] ?>">Repasse do cliente com os encargos (#<?= (int) $reneg['adiantamento_id'] ?>)</a></div><?php endif; ?>
            <?php if ($reneg['observacao']): ?><div class="col-12"><div class="text-muted">Observação</div><?= nl2br(e($reneg['observacao'])) ?></div><?php endif; ?>
        </div>
        <?php if (!$reneg['desfeita_em']): ?>
        <form method="post" class="mt-3" onsubmit="return confirm('Desfazer a renegociação? Os títulos novos são excluídos e os antigos voltam a ficar em aberto.');">
            <input type="hidden" name="acao" value="desfazer"><input type="hidden" name="id" value="<?= (int) $renegId ?>">
            <button class="btn btn-sm btn-outline-danger"><i class="bi bi-arrow-counterclockwise"></i> Desfazer renegociação</button>
            <span class="small text-muted ms-2">Só enquanto nenhum título novo foi recebido nem teve boleto emitido.</span>
        </form>
        <?php endif; ?>
    </div>
    <?php $tabela($antigos, '<i class="bi bi-archive"></i> Títulos renegociados (antigos)'); $tabela($novos, '<i class="bi bi-file-earmark-plus"></i> Novos títulos'); ?>
    <?php require __DIR__ . '/../../includes/layout_footer.php';
    exit;
}

// ---- Nova renegociação ----
$ids = array_map('intval', (array) ($_GET['ids'] ?? $_POST['ids'] ?? []));
$dataBase = dataInput('data_base') ?? (preg_match('/^\d{4}-\d{2}-\d{2}$/', (string) ($_GET['data_base'] ?? '')) ? $_GET['data_base'] : date('Y-m-d'));
$titulos = titulosParaRenegociar($pdo, $ids, $dataBase);
$problemas = problemasRenegociacao($titulos);
$original = round(array_sum(array_map(fn ($t) => (float) $t['valor'], $titulos)), 2);
$encargosCalculados = round(array_sum(array_map(fn ($t) => $t['atualizacao']['juros'] + $t['atualizacao']['multa'], $titulos)), 2);
$clientes = array_values(array_unique(array_filter(array_map(fn ($t) => (int) $t['cliente_id'], $titulos))));
$temClienteUnico = count($clientes) === 1 && !array_filter($titulos, fn ($t) => !$t['cliente_id']);
$erros = [];

if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['acao'] ?? '') === 'renegociar') {
    $parcelas = [];
    foreach ((array) ($_POST['parcelas'] ?? []) as $p) {
        $venc = preg_match('/^\d{4}-\d{2}-\d{2}$/', (string) ($p['vencimento'] ?? '')) ? $p['vencimento'] : '';
        $valor = paraDecimal($p['valor'] ?? '');
        if ($venc || $valor > 0) {
            $parcelas[] = ['vencimento' => $venc, 'valor' => $valor, 'numero' => trim((string) ($p['numero'] ?? ''))];
        }
    }
    $dados = [
        'data_base' => $dataBase, 'destino' => input('destino'), 'encargos' => input('encargos') === '' ? $encargosCalculados : decimalInput('encargos'),
        'parcelas' => $parcelas, 'tipo' => input('tipo'), 'conta_destino_id' => $_POST['conta_destino_id'] ?? null,
        'descricao' => input('descricao'), 'observacao' => input('observacao'),
    ];
    $pdo->beginTransaction();
    try {
        [$renegId, $avisos] = renegociarTitulos($pdo, $ids, $dados);
        $destinoTxt = ['pagador' => 'nos novos títulos', 'cliente' => 'para o cliente (repasse)', 'isento' => 'isentos'][$dados['destino']] ?? $dados['destino'];
        registrarAtividade('contas_receber', 'renegociacao', 'Renegociação #' . $renegId . ' de ' . ($titulos[0]['emitente_nome'] ?? '-') . ': títulos '
            . implode(', ', array_map(fn ($t) => '#' . $t['id'], $titulos)) . ' (' . formatMoney($original) . ') · encargos ' . formatMoney((float) $dados['encargos']) . ' ' . $destinoTxt
            . ' · ' . count($parcelas) . ' nova(s) parcela(s): ' . implode(', ', array_map(fn ($p) => formatMoney($p['valor']) . ' em ' . formatDate($p['vencimento']), $parcelas)));
        $pdo->commit();
        flash('success', 'Renegociação #' . $renegId . ' feita: ' . count($titulos) . ' título(s) substituído(s) por ' . count($parcelas) . ' novo(s).');
        foreach ($avisos as $aviso) {
            flash('warning', $aviso);
        }
        redirect('/receber/renegociar.php?id=' . $renegId);
    } catch (InvalidArgumentException $e) {
        $pdo->rollBack();
        $erros[] = $e->getMessage();
    }
}

$contas = $pdo->query('SELECT id, nome FROM contas_bancarias WHERE ativo = 1 ORDER BY nome')->fetchAll();
$destino = $_POST['destino'] ?? 'pagador';
$pageTitle = 'Renegociar títulos';
$pageBreadcrumb = 'Financeiro · Contas a receber';
$activeMenu = 'receber';
$pageAction = '<a href="/receber/listar.php" class="btn btn-outline-secondary">Cancelar</a>'
    . (!$problemas ? '<button type="submit" form="form-reneg" class="btn btn-brand"><i class="bi bi-check-lg"></i> Renegociar</button>' : '');
require __DIR__ . '/../../includes/layout_header.php';
?>

<?php if ($problemas): ?>
    <div class="alert alert-danger"><ul class="mb-0"><?php foreach ($problemas as $p): ?><li><?= e($p) ?></li><?php endforeach; ?></ul></div>
    <a href="/receber/listar.php" class="btn btn-outline-secondary">Voltar para a lista</a>
<?php else: ?>
<?php if ($erros): ?>
    <div class="alert alert-danger"><ul class="mb-0"><?php foreach ($erros as $erro): ?><li><?= e($erro) ?></li><?php endforeach; ?></ul></div>
<?php endif; ?>

<form method="post" id="form-reneg">
    <input type="hidden" name="acao" value="renegociar">
    <?php foreach ($titulos as $t): ?><input type="hidden" name="ids[]" value="<?= (int) $t['id'] ?>"><?php endforeach; ?>

    <div class="secao">
        <div class="secao-titulo"><i class="bi bi-list-check"></i> Títulos de <?= e($titulos[0]['emitente_nome'] ?? 'pagador') ?>
            <span class="ms-auto d-flex align-items-center gap-2 small fw-normal">Juros calculados até
                <input type="date" name="data_base" class="form-control form-control-sm" style="width: 150px;" value="<?= e($dataBase) ?>"
                       onchange="location.href = '?<?= e(http_build_query(['ids' => array_column($titulos, 'id')])) ?>&data_base=' + this.value"></span>
        </div>
        <div class="card"><table class="table table-sm mb-0 align-middle">
            <thead><tr><th>#</th><th>Vencimento</th><th>Tipo</th><th>Cliente</th><th class="text-end">Valor</th><th class="text-end">Atraso</th><th class="text-end">Multa</th><th class="text-end">Juros</th><th class="text-end">Atualizado</th></tr></thead>
            <tbody>
            <?php foreach ($titulos as $t): $a = $t['atualizacao']; ?>
                <tr><td><?= (int) $t['id'] ?></td><td class="text-num"><?= formatDate($t['data_vencimento']) ?></td><td><?= e(tipoTituloLabel($t['tipo'])) ?></td><td class="small"><?= e($t['cliente_nome'] ?? '-') ?></td>
                    <td class="text-end text-num"><?= formatMoney($t['valor']) ?></td><td class="text-end small"><?= $a['dias'] ? $a['dias'] . ' dia(s)' : '-' ?></td>
                    <td class="text-end text-num"><?= $a['multa'] ? formatMoney($a['multa']) : '-' ?></td><td class="text-end text-num"><?= $a['juros'] ? formatMoney($a['juros']) : '-' ?></td>
                    <td class="text-end text-num fw-semibold"><?= formatMoney($a['total']) ?></td></tr>
            <?php endforeach; ?>
            </tbody>
            <tfoot><tr class="fw-semibold"><td colspan="4"><?= count($titulos) ?> título(s)</td><td class="text-end text-num"><?= formatMoney($original) ?></td><td></td>
                <td colspan="2" class="text-end text-num"><?= formatMoney($encargosCalculados) ?></td><td class="text-end text-num"><?= formatMoney($original + $encargosCalculados) ?></td></tr></tfoot>
        </table></div>
    </div>

    <div class="secao">
        <div class="secao-titulo"><i class="bi bi-percent"></i> Juros e multa</div>
        <div class="row g-3 align-items-start">
            <div class="col-md-3"><label class="form-label">Valor dos encargos (R$)</label>
                <input type="text" name="encargos" id="encargos" class="form-control" data-moeda inputmode="decimal" value="<?= e($_POST['encargos'] ?? formatDecimal($encargosCalculados)) ?>">
                <div class="form-text">Calculado: <?= formatMoney($encargosCalculados) ?>. Pode mudar para o valor combinado.</div></div>
            <div class="col-md-9"><label class="form-label">Para onde vão</label>
                <?php foreach (destinosEncargos() as $k => $rotulo): $bloqueado = $k === 'cliente' && !$temClienteUnico; ?>
                    <label class="form-check <?= $bloqueado ? 'text-muted' : '' ?>"><input type="radio" class="form-check-input destino" name="destino" value="<?= $k ?>" <?= $destino === $k ? 'checked' : '' ?> <?= $bloqueado ? 'disabled' : '' ?>>
                        <?= e($rotulo) ?><?= $k === 'cliente' ? ($temClienteUnico ? ' — ' . e($titulos[0]['cliente_nome']) . ' paga e desconta numa próxima operação' : ' (os títulos precisam ser do mesmo cliente)') : '' ?></label>
                <?php endforeach; ?>
            </div>
        </div>
        <div class="alert alert-light border small mt-3 mb-0" id="resumo-reneg"></div>
    </div>

    <div class="secao">
        <div class="secao-titulo"><i class="bi bi-list-ol"></i> Novas parcelas
            <button type="button" class="btn btn-sm btn-outline-secondary ms-auto" data-bs-toggle="collapse" data-bs-target="#dividir-igual"><i class="bi bi-distribute-vertical"></i> Dividir em parcelas iguais</button>
        </div>
        <div class="collapse show mb-2" id="dividir-igual"><div class="card card-body py-2">
            <div class="row g-2 align-items-end">
                <div class="col-md-2"><label class="form-label small">Parcelas</label><input type="number" class="form-control form-control-sm" id="div-qtd" min="1" max="120" value="<?= count($titulos) ?>"></div>
                <div class="col-md-2"><label class="form-label small">1º vencimento</label><input type="date" class="form-control form-control-sm" id="div-venc" value="<?= date('Y-m-d', strtotime('+30 days')) ?>"></div>
                <div class="col-md-2"><label class="form-label small">Intervalo</label><select class="form-select form-select-sm" id="div-intervalo">
                    <option value="mes">Mensal</option><option value="7">Semanal</option><option value="15">Quinzenal (15 dias)</option><option value="30">A cada 30 dias</option><option value="x">A cada X dias...</option></select></div>
                <div class="col-md-1 d-none" id="bloco-div-dias"><label class="form-label small">Dias</label><input type="number" class="form-control form-control-sm" id="div-dias" min="1" max="365" value="10"></div>
                <div class="col-md-auto"><button type="button" class="btn btn-sm btn-brand text-nowrap" id="div-gerar">Preencher com o total</button></div>
            </div>
        </div></div>
        <div class="card">
            <table class="table table-sm mb-0 align-middle tabela-parcelas" id="parcelas-reneg">
                <thead><tr><th style="width: 40px;">#</th><th style="width: 190px;">Vencimento</th><th style="width: 190px;">Valor (R$)</th><th>Nº / referência (nosso número, se já emitiu)</th><th style="width: 50px;"></th></tr></thead>
                <tbody>
                <?php foreach (($_POST['parcelas'] ?? []) as $i => $p): ?>
                    <tr>
                        <td class="text-muted small numero-parcela"></td>
                        <td><input type="date" name="parcelas[<?= (int) $i ?>][vencimento]" class="form-control form-control-sm" data-campo="vencimento" value="<?= e($p['vencimento'] ?? '') ?>"></td>
                        <td><input type="text" name="parcelas[<?= (int) $i ?>][valor]" class="form-control form-control-sm text-end" data-campo="valor" data-parcela-valor data-moeda inputmode="decimal" value="<?= e($p['valor'] ?? '') ?>"></td>
                        <td><input type="text" name="parcelas[<?= (int) $i ?>][numero]" class="form-control form-control-sm" data-campo="numero" maxlength="60" value="<?= e($p['numero'] ?? '') ?>"></td>
                        <td class="text-end"><button type="button" class="btn btn-sm btn-link text-danger remover-parcela" title="Tirar"><i class="bi bi-x-lg"></i></button></td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
                <tfoot><tr>
                    <td colspan="2"><button type="button" class="btn btn-sm btn-outline-secondary" data-add-parcela><i class="bi bi-plus-lg"></i> Adicionar parcela</button></td>
                    <td class="text-end fw-semibold text-num" data-total-parcelas>R$ 0,00</td>
                    <td class="small" id="conferencia-total"></td><td></td>
                </tr></tfoot>
                <template>
                    <tr>
                        <td class="text-muted small numero-parcela"></td>
                        <td><input type="date" name="parcelas[__i][vencimento]" class="form-control form-control-sm" data-campo="vencimento"></td>
                        <td><input type="text" name="parcelas[__i][valor]" class="form-control form-control-sm text-end" data-campo="valor" data-parcela-valor data-moeda inputmode="decimal"></td>
                        <td><input type="text" name="parcelas[__i][numero]" class="form-control form-control-sm" data-campo="numero" maxlength="60"></td>
                        <td class="text-end"><button type="button" class="btn btn-sm btn-link text-danger remover-parcela" title="Tirar"><i class="bi bi-x-lg"></i></button></td>
                    </tr>
                </template>
            </table>
        </div>
    </div>

    <div class="secao">
        <div class="row g-3">
            <div class="col-md-3"><label class="form-label">Tipo dos novos títulos</label>
                <select name="tipo" class="form-select"><?php foreach (tiposParaCampo('boleto') as $k => $v): ?><option value="<?= e($k) ?>" <?= ($_POST['tipo'] ?? 'boleto') === $k ? 'selected' : '' ?>><?= e($v) ?></option><?php endforeach; ?></select></div>
            <div class="col-md-3"><label class="form-label">Cai na conta</label>
                <select name="conta_destino_id" class="form-select"><?php foreach ($contas as $c): ?><option value="<?= (int) $c['id'] ?>" <?= (int) ($_POST['conta_destino_id'] ?? $titulos[0]['conta_destino_id']) === (int) $c['id'] ? 'selected' : '' ?>><?= e($c['nome']) ?></option><?php endforeach; ?></select></div>
            <div class="col-md-6"><label class="form-label">Descrição</label><input type="text" name="descricao" class="form-control" maxlength="150" placeholder="Em branco: Renegociação #nº" value="<?= e($_POST['descricao'] ?? '') ?>"></div>
            <div class="col-12"><label class="form-label">Observação</label><textarea name="observacao" class="form-control" rows="2" placeholder="Ex.: combinado por WhatsApp em ..."><?= e($_POST['observacao'] ?? '') ?></textarea></div>
        </div>
    </div>
</form>

<script>
document.addEventListener('DOMContentLoaded', function () {
    const original = <?= json_encode($original) ?>;
    const tabela = document.getElementById('parcelas-reneg');
    const encargos = document.getElementById('encargos');
    const reais = (v) => v.toLocaleString('pt-BR', { style: 'currency', currency: 'BRL' });
    const destino = () => document.querySelector('.destino:checked')?.value || 'isento';
    const totalNovo = () => Math.round((original + (destino() === 'pagador' ? Moeda.ler(encargos.value) : 0)) * 100) / 100;
    function resumo() {
        const enc = Moeda.ler(encargos.value);
        const txt = { pagador: 'entram nos novos títulos', cliente: 'viram repasse em aberto do cliente', isento: 'são isentos' }[destino()];
        document.getElementById('resumo-reneg').innerHTML = 'Original ' + reais(original) + ' · encargos ' + reais(enc) + ' ' + txt
            + ' → <strong>novos títulos somam ' + reais(totalNovo()) + '</strong>';
        conferir();
    }
    function conferir() {
        const soma = [...tabela.querySelectorAll('[data-parcela-valor]')].reduce((s, c) => s + Moeda.ler(c.value), 0);
        const dif = Math.round((totalNovo() - soma) * 100) / 100;
        const el = document.getElementById('conferencia-total');
        el.className = 'small ' + (Math.abs(dif) < 0.01 ? 'valor-positivo' : 'text-danger');
        el.textContent = Math.abs(dif) < 0.01 ? 'Fecha com o total ✓' : (dif > 0 ? 'Faltam ' : 'Sobram ') + reais(Math.abs(dif)) + ' para fechar ' + reais(totalNovo());
    }
    document.querySelectorAll('.destino').forEach((r) => r.addEventListener('change', resumo));
    encargos.addEventListener('input', resumo);
    document.addEventListener('parcelas-mudou', conferir);
    tabela.addEventListener('input', conferir);

    document.getElementById('div-intervalo').addEventListener('change', function () {
        document.getElementById('bloco-div-dias').classList.toggle('d-none', this.value !== 'x');
    });
    function preencher() {
        const total = totalNovo();
        const n = parseInt(document.getElementById('div-qtd').value, 10) || 0;
        const venc = document.getElementById('div-venc').value;
        if (n < 1 || n > 120 || !venc) { alert('Informe parcelas (1 a 120) e o 1º vencimento.'); return; }
        const escolha = document.getElementById('div-intervalo').value;
        const intervalo = escolha === 'x' ? String(Math.max(1, parseInt(document.getElementById('div-dias').value, 10) || 1)) : escolha;
        const base = Math.floor(total / n * 100) / 100;
        const [a, m, d] = venc.split('-').map(Number);
        tabela.parcelas.limpar();
        for (let i = 0; i < n; i++) {
            const data = intervalo === 'mes'
                ? new Date(Date.UTC(a, m - 1 + i, Math.min(d, new Date(Date.UTC(a, m + i, 0)).getUTCDate())))
                : new Date(Date.UTC(a, m - 1, d + Number(intervalo) * i));
            tabela.parcelas.adicionar({ vencimento: data.toISOString().slice(0, 10), valor: Moeda.formatar(i === 0 ? total - base * (n - 1) : base) });
        }
        conferir();
    }
    document.getElementById('div-gerar').addEventListener('click', preencher);
    resumo();
    if (![...tabela.querySelectorAll('[data-parcela-valor]')].some((c) => Moeda.ler(c.value) > 0)) preencher();
});
</script>
<?php endif; ?>

<?php require __DIR__ . '/../../includes/layout_footer.php'; ?>
