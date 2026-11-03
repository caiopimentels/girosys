<?php
// Cadastro de cliente, pagador (emitente dos títulos) ou fornecedor — dados
// completos para emissão de boleto.
require_once __DIR__ . '/../../includes/bootstrap.php';
exigirLogin();

$pdo = getDB();
$id = isset($_GET['id']) ? (int) $_GET['id'] : null;
$tabAtiva = $_GET['tab'] ?? 'dados';
$campos = ['tipo_pessoa', 'nome', 'nome_fantasia', 'documento', 'rg_ie', 'data_nascimento', 'telefone', 'celular', 'email',
           'taxa_padrao', 'limite_credito', 'cep', 'logradouro', 'numero', 'complemento', 'bairro', 'cidade', 'uf', 'observacao'];
$pessoa = array_fill_keys($campos, null);
$pessoa['tipo_pessoa'] = 'F';
$pessoa['papel'] = array_key_exists($_GET['papel'] ?? '', papeisPessoa()) ? $_GET['papel'] : 'cliente';

if ($id) {
    $stmt = $pdo->prepare('SELECT * FROM pessoas WHERE id = ?');
    $stmt->execute([$id]);
    $pessoa = $stmt->fetch();
    if (!$pessoa) {
        flash('danger', 'Cadastro não encontrado.');
        redirect('/pessoas/listar.php');
    }
}
$papel = $pessoa['papel'];
$ehCliente = $papel === 'cliente';

$erros = [];
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['acao'] ?? '') === 'excluir' && $id) {
    $stmt = $pdo->prepare(
        'SELECT (SELECT COUNT(*) FROM contas_receber WHERE emitente_id = ? OR cliente_id = ?) + (SELECT COUNT(*) FROM operacoes WHERE cliente_id = ?)
              + (SELECT COUNT(*) FROM adiantamentos WHERE cliente_id = ?) + (SELECT COUNT(*) FROM contas_pagar WHERE pessoa_id = ?)'
    );
    $stmt->execute(array_fill(0, 5, $id));
    if ((int) $stmt->fetchColumn() > 0) {
        flash('danger', 'Este cadastro tem lançamentos (títulos, operações, repasses ou despesas) e não pode ser excluído.');
    } else {
        $pdo->prepare('DELETE FROM pessoas WHERE id = ?')->execute([$id]);
        flash('success', 'Cadastro de ' . $pessoa['nome'] . ' excluído.');
    }
    redirecionarAposAcao('/pessoas/listar.php?papel=' . $pessoa['papel']);
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $novo = ['tipo_pessoa' => input('tipo_pessoa') === 'J' ? 'J' : 'F'];
    foreach (['nome_fantasia', 'rg_ie', 'email', 'logradouro', 'numero', 'complemento', 'bairro', 'cidade', 'observacao'] as $c) {
        $novo[$c] = input($c) ?: null;
    }
    $novo['nome'] = $novo['tipo_pessoa'] === 'F' ? formatarNomePessoa(input('nome')) : trim(input('nome'));
    $novo['documento'] = apenasDigitos(input('documento')) ?: null;
    $novo['data_nascimento'] = dataInput('data_nascimento');
    $novo['telefone'] = apenasDigitos(input('telefone')) ?: null;
    $novo['celular'] = apenasDigitos(input('celular')) ?: null;
    $novo['cep'] = apenasDigitos(input('cep')) ?: null;
    $novo['uf'] = in_array(input('uf'), ufsBrasil(), true) ? input('uf') : null;
    $novo['taxa_padrao'] = $ehCliente && input('taxa_padrao') !== '' ? paraDecimal(input('taxa_padrao')) : ($ehCliente ? null : $pessoa['taxa_padrao']);
    $novo['limite_credito'] = $ehCliente && input('limite_credito') !== '' ? paraDecimal(input('limite_credito')) : ($ehCliente ? null : $pessoa['limite_credito']);

    if ($novo['nome'] === '') {
        $erros[] = $novo['tipo_pessoa'] === 'J' ? 'Informe a razão social.' : 'Informe o nome.';
    }
    if ($novo['documento']) {
        $valido = $novo['tipo_pessoa'] === 'J' ? validarCnpj($novo['documento']) : validarCpf($novo['documento']);
        if (!$valido) {
            $erros[] = ($novo['tipo_pessoa'] === 'J' ? 'CNPJ' : 'CPF') . ' inválido.';
        } else {
            $stmt = $pdo->prepare('SELECT id, nome FROM pessoas WHERE papel = ? AND documento = ? AND id <> ?');
            $stmt->execute([$papel, $novo['documento'], (int) $id]);
            if ($outro = $stmt->fetch()) {
                $erros[] = 'Este documento já está no cadastro de ' . e($outro['nome']) . ' (' . mb_strtolower(papeisPessoa()[$papel]) . ').';
            }
        }
    }
    if ($novo['cep'] && strlen($novo['cep']) !== 8) {
        $erros[] = 'CEP deve ter 8 dígitos.';
    }
    if ($novo['email'] && !filter_var($novo['email'], FILTER_VALIDATE_EMAIL)) {
        $erros[] = 'E-mail inválido.';
    }

    $pessoa = array_merge($pessoa, $novo);
    if (!$erros) {
        $valores = array_map(fn ($c) => $novo[$c], $campos);
        if ($id) {
            $pdo->prepare('UPDATE pessoas SET ' . implode(', ', array_map(fn ($c) => "{$c} = ?", $campos)) . ' WHERE id = ?')->execute([...$valores, $id]);
        } else {
            $pdo->prepare('INSERT INTO pessoas (papel, ' . implode(', ', $campos) . ') VALUES (?' . str_repeat(', ?', count($campos)) . ')')->execute([$papel, ...$valores]);
            $id = (int) $pdo->lastInsertId();
        }
        flash('success', 'Cadastro salvo.');
        redirect('/pessoas/form.php?id=' . $id);
    }
}

// Cada aba tem o seu filtro (campos com prefixo rec_, rep_, op_, ext_).
// Situação: pendentes (inclui vencidos e devolvidos em aberto — padrão), já pagos ou todos.
$situacoes = ['pendentes' => 'Somente pendentes', 'pagos' => 'Já pagos', 'todos' => 'Todos'];
$recSit = array_key_exists($_GET['rec_sit'] ?? '', $situacoes) ? $_GET['rec_sit'] : 'pendentes';
$repSit = array_key_exists($_GET['rep_sit'] ?? '', $situacoes) ? $_GET['rep_sit'] : 'pendentes';
[$recDe, $recAte, $recDeSql, $recAteSql] = periodoFiltro('', '', 'rec_de', 'rec_ate');
[$repDe, $repAte, $repDeSql, $repAteSql] = periodoFiltro('', '', 'rep_de', 'rep_ate');
[$opDe, $opAte, $opDeSql, $opAteSql] = periodoFiltro('', '', 'op_de', 'op_ate');
[$extDe, $extAte] = periodoFiltro('', '', 'ext_de', 'ext_ate');
$filtroTitulos = ['pendentes' => " AND r.status IN ('pendente', 'devolvido')", 'pagos' => " AND r.status IN ('recebido', 'transferido', 'cancelado')", 'todos' => ''][$recSit];
$filtroRepasses = ['pendentes' => ' AND a.valor - a.valor_utilizado > 0.004', 'pagos' => ' AND a.valor - a.valor_utilizado <= 0.004', 'todos' => ''][$repSit];
if ($id) {
    $historico = historicoEmitente($pdo, $id);
    $stmt = $pdo->prepare(
        "SELECT r.*, cl.nome AS cliente_nome, e.nome AS emitente_nome FROM contas_receber r
         LEFT JOIN pessoas cl ON cl.id = r.cliente_id LEFT JOIN pessoas e ON e.id = r.emitente_id
         WHERE r." . ($ehCliente ? 'cliente_id' : 'emitente_id') . " = ? AND r.data_vencimento BETWEEN ? AND ?{$filtroTitulos} ORDER BY r.data_vencimento DESC"
    );
    $stmt->execute([$id, $recDeSql, $recAteSql]);
    $titulosPessoa = $stmt->fetchAll();

    if ($ehCliente) {
        $stmt = $pdo->prepare('SELECT * FROM operacoes WHERE cliente_id = ? AND data_base BETWEEN ? AND ? ORDER BY data_base DESC, id DESC');
        $stmt->execute([$id, $opDeSql, $opAteSql]);
        $operacoes = $stmt->fetchAll();
        // Repasses em ordem de data para o saldo acumulado; antes do período, só o que ficou em aberto.
        $stmt = $pdo->prepare('SELECT a.*, c.nome AS conta_nome, pg.nome AS pagador_nome FROM adiantamentos a LEFT JOIN contas_bancarias c ON c.id = a.conta_id LEFT JOIN pessoas pg ON pg.id = a.pagador_id WHERE a.cliente_id = ? AND a.data BETWEEN ? AND ?' . $filtroRepasses . ' ORDER BY a.data, a.id');
        $stmt->execute([$id, $repDeSql, $repAteSql]);
        $adiantamentos = $stmt->fetchAll();
        $stmt = $pdo->prepare('SELECT COALESCE(SUM(valor - valor_utilizado), 0) FROM adiantamentos WHERE cliente_id = ? AND data < ?');
        $stmt->execute([$id, $repDeSql]);
        $saldoAntesRepasses = (float) $stmt->fetchColumn();
        $adiantadoAberto = totalAdiantadoEmAberto($pdo, $id);
        $emUso = creditoEmUso($pdo, $id);
        $extrato = extratoCliente($pdo, $id, $extDe, $extAte);
    }
}
$faltando = faltandoParaBoleto($pessoa);

$rotuloPapel = papeisPessoa()[$papel];
$pageTitle = $id ? $pessoa['nome'] : 'Novo ' . mb_strtolower($rotuloPapel);
$pageBreadcrumb = 'Cadastros · ' . ['cliente' => 'Clientes', 'pagador' => 'Pagadores', 'fornecedor' => 'Fornecedores'][$papel];
$activeMenu = 'pessoas-' . $papel;
$pageAction = ($id && $ehCliente ? '<a href="/adiantamentos/form.php?cliente=' . $id . '" class="btn btn-outline-secondary"><i class="bi bi-cash-coin"></i> Novo repasse</a>'
        . '<a href="/operacoes/form.php?cliente=' . $id . '" class="btn btn-outline-secondary"><i class="bi bi-calculator"></i> Nova operação</a>' : '')
    . '<a href="/pessoas/listar.php?papel=' . $papel . '" class="btn btn-outline-secondary">Voltar</a>'
    . '<button type="submit" form="form-pessoa" class="btn btn-brand"><i class="bi bi-check-lg"></i> Salvar</button>';
require __DIR__ . '/../../includes/layout_header.php';
?>

<?php if ($erros): ?>
    <div class="alert alert-danger"><ul class="mb-0"><?php foreach ($erros as $erro): ?><li><?= $erro ?></li><?php endforeach; ?></ul></div>
<?php endif; ?>

<?php if ($id): ?>
<div class="row g-3 mb-3">
    <?php if ($ehCliente): ?>
        <div class="col-6 col-lg-3"><div class="card h-100"><div class="card-body">
            <div class="kpi-rotulo">Crédito em uso</div>
            <div class="kpi-valor <?= $pessoa['limite_credito'] !== null && $emUso > $pessoa['limite_credito'] ? 'valor-negativo' : '' ?>"><?= formatMoney($emUso) ?></div>
            <div class="small text-muted"><?php if ($pessoa['limite_credito'] !== null): ?>Limite <?= formatMoney($pessoa['limite_credito']) ?> · disponível <strong><?= formatMoney($pessoa['limite_credito'] - $emUso) ?></strong><?php else: ?>Sem limite definido<?php endif; ?></div>
        </div></div></div>
        <div class="col-6 col-lg-3"><div class="card h-100"><div class="card-body">
            <div class="kpi-rotulo">Repasses em aberto</div>
            <div class="kpi-valor"><?= formatMoney($adiantadoAberto) ?></div>
            <div class="small text-muted"><?= count($operacoes) ?> operação(ões)</div>
        </div></div></div>
        <div class="col-6 col-lg-3"><div class="card h-100"><div class="card-body">
            <div class="kpi-rotulo">Recebimentos em aberto</div>
            <div class="kpi-valor"><?= formatMoney(array_sum(array_map(fn ($t) => in_array($t['status'], ['pendente', 'devolvido'], true) ? $t['valor'] : 0, $titulosPessoa))) ?></div>
            <div class="small text-muted"><?= count(array_filter($titulosPessoa, fn ($t) => $t['status'] === 'devolvido')) ?> devolvido(s)</div>
        </div></div></div>
    <?php elseif ($papel === 'pagador'): ?>
        <div class="col-6 col-lg-3"><div class="card h-100"><div class="card-body">
            <div class="kpi-rotulo">Como pagador</div>
            <div class="mt-2"><?= badgeClassificacao($historico['classificacao']) ?></div>
            <div class="small text-muted mt-1"><?= $historico['total'] ?> título(s) · <?= formatMoney($historico['valor_total']) ?></div>
        </div></div></div>
        <div class="col-6 col-lg-3"><div class="card h-100"><div class="card-body">
            <div class="kpi-rotulo">Pagos em dia / com atraso</div>
            <div class="kpi-valor"><span class="valor-positivo"><?= $historico['em_dia'] ?></span> / <span class="<?= $historico['com_atraso'] ? 'text-warning' : '' ?>"><?= $historico['com_atraso'] ?></span></div>
            <div class="small text-muted">Em dia = até <?= (int) TOLERANCIA_COMPENSACAO_DIAS ?> dias após o vencimento</div>
        </div></div></div>
        <div class="col-6 col-lg-3"><div class="card h-100"><div class="card-body">
            <div class="kpi-rotulo">Devolvidos / vencidos em aberto</div>
            <div class="kpi-valor"><span class="<?= $historico['devolvidos'] ? 'valor-negativo' : '' ?>"><?= $historico['devolvidos'] ?></span> / <span class="<?= $historico['vencidos'] ? 'valor-negativo' : '' ?>"><?= $historico['vencidos'] ?></span></div>
            <div class="small text-muted">Em aberto: <?= formatMoney($historico['valor_em_aberto']) ?></div>
        </div></div></div>
    <?php endif; ?>
    <div class="col-6 col-lg-3"><div class="card h-100"><div class="card-body">
        <div class="kpi-rotulo">Cadastro para boleto</div>
        <?php if (!$faltando): ?>
            <div class="mt-2"><span class="badge text-bg-success"><i class="bi bi-check-circle"></i> Completo</span></div>
        <?php else: ?>
            <div class="mt-2"><span class="badge text-bg-warning">Incompleto</span></div>
            <div class="small text-muted mt-1">Falta: <?= e(implode(', ', $faltando)) ?></div>
        <?php endif; ?>
    </div></div></div>
</div>
<?php endif; ?>

<?php
// Barra de filtro de uma aba: mantém os filtros das outras abas e volta para esta.
$filtroAba = function (string $aba, string $prefixo, ?string $situacao, string $de, string $ate, string $rotuloPeriodo) use ($id, $situacoes): void {
    $proprios = [$prefixo . 'sit', $prefixo . 'de', $prefixo . 'ate', 'tab'];
    echo '<div class="filtros"><form method="get">' . camposGetExceto($proprios) . '<input type="hidden" name="tab" value="' . e($aba) . '">';
    if ($situacao !== null) {
        echo '<select name="' . $prefixo . 'sit" class="form-select form-select-sm" onchange="this.form.submit()">';
        foreach ($situacoes as $k => $v) {
            echo '<option value="' . $k . '"' . ($situacao === $k ? ' selected' : '') . '>' . e($v) . '</option>';
        }
        echo '</select>';
    }
    $dataInicio = $de; $dataFim = $ate; $periodoRotulo = $rotuloPeriodo; $periodoCampos = [$prefixo . 'de', $prefixo . 'ate'];
    require __DIR__ . '/../../includes/filtro_periodo.php';
    if ($de || $ate || ($situacao !== null && $situacao !== 'pendentes')) {
        $limpar = array_diff_key($_GET, array_flip($proprios)) + ['tab' => $aba];
        echo '<a href="?' . e(http_build_query($limpar)) . '" class="btn btn-sm btn-link">Limpar</a>';
    }
    echo '</form></div>';
};
?>
<ul class="nav nav-tabs mb-3">
    <li class="nav-item"><a class="nav-link <?= $tabAtiva === 'dados' ? 'active' : '' ?>" href="#" data-tab="dados">Dados</a></li>
    <?php if ($id && $papel !== 'fornecedor'): ?>
        <li class="nav-item"><a class="nav-link <?= in_array($tabAtiva, ['titulos', 'emitente'], true) ? 'active' : '' ?>" href="#" data-tab="titulos"><?= $ehCliente ? 'Recebimentos' : 'Títulos dele' ?> (<?= count($titulosPessoa) ?>)</a></li>
    <?php endif; ?>
    <?php if ($id && $ehCliente): ?>
        <li class="nav-item"><a class="nav-link <?= in_array($tabAtiva, ['repasses', 'cliente'], true) ? 'active' : '' ?>" href="#" data-tab="repasses">Repasses (<?= count($adiantamentos) ?>)</a></li>
        <li class="nav-item"><a class="nav-link <?= $tabAtiva === 'operacoes' ? 'active' : '' ?>" href="#" data-tab="operacoes">Operações (<?= count($operacoes) ?>)</a></li>
        <li class="nav-item"><a class="nav-link <?= $tabAtiva === 'extrato' ? 'active' : '' ?>" href="#" data-tab="extrato"><i class="bi bi-journal-text"></i> Extrato</a></li>
    <?php endif; ?>
</ul>

<div class="tab-pane" id="pane-dados" style="<?= $tabAtiva === 'dados' ? '' : 'display:none;' ?>">
    <form method="post" id="form-pessoa" style="max-width: 1000px;" autocomplete="off">
        <div class="secao">
            <div class="secao-titulo"><i class="bi bi-person-vcard"></i> Identificação · <?= e($rotuloPapel) ?></div>
            <div class="row g-3">
                <div class="col-12">
                    <div class="btn-group" role="group">
                        <input type="radio" class="btn-check" name="tipo_pessoa" id="tp-f" value="F" <?= $pessoa['tipo_pessoa'] !== 'J' ? 'checked' : '' ?>>
                        <label class="btn btn-outline-secondary btn-sm" for="tp-f">Pessoa física</label>
                        <input type="radio" class="btn-check" name="tipo_pessoa" id="tp-j" value="J" <?= $pessoa['tipo_pessoa'] === 'J' ? 'checked' : '' ?>>
                        <label class="btn btn-outline-secondary btn-sm" for="tp-j">Pessoa jurídica</label>
                    </div>
                </div>
                <div class="col-md-6"><label class="form-label" data-pf="Nome completo *" data-pj="Razão social *">Nome completo *</label><input type="text" name="nome" class="form-control" required value="<?= e($pessoa['nome']) ?>"></div>
                <div class="col-md-6 so-pj"><label class="form-label">Nome fantasia</label><input type="text" name="nome_fantasia" class="form-control" value="<?= e($pessoa['nome_fantasia']) ?>"></div>
                <div class="col-md-3"><label class="form-label" data-pf="CPF" data-pj="CNPJ">CPF</label><div class="input-group"><input type="text" name="documento" id="documento" class="form-control" inputmode="numeric" value="<?= e(documentoFormatado($pessoa['documento'])) ?>">
                    <button type="button" class="btn btn-outline-secondary so-pj" id="buscar-cnpj" title="Buscar os dados na Receita e preencher (sobrescreve)"><i class="bi bi-search"></i> Buscar</button></div>
                    <div class="form-text" id="cnpj-status"></div></div>
                <div class="col-md-3"><label class="form-label" data-pf="RG" data-pj="Inscrição estadual">RG</label><input type="text" name="rg_ie" class="form-control" value="<?= e($pessoa['rg_ie']) ?>"></div>
                <div class="col-md-3 so-pf"><label class="form-label">Data de nascimento</label><input type="date" name="data_nascimento" class="form-control" value="<?= e($pessoa['data_nascimento']) ?>"></div>
            </div>
        </div>

        <div class="secao">
            <div class="secao-titulo"><i class="bi bi-telephone"></i> Contato</div>
            <div class="row g-3">
                <div class="col-md-3"><label class="form-label">Celular / WhatsApp</label><input type="text" name="celular" class="form-control" data-mascara="telefone" value="<?= e(telefoneFormatado($pessoa['celular'])) ?>"></div>
                <div class="col-md-3"><label class="form-label">Telefone</label><input type="text" name="telefone" class="form-control" data-mascara="telefone" value="<?= e(telefoneFormatado($pessoa['telefone'])) ?>"></div>
                <div class="col-md-6"><label class="form-label">E-mail</label><input type="email" name="email" class="form-control" value="<?= e($pessoa['email']) ?>"></div>
            </div>
        </div>

        <div class="secao">
            <div class="secao-titulo"><i class="bi bi-geo-alt"></i> Endereço</div>
            <div class="row g-3">
                <div class="col-md-2"><label class="form-label">CEP</label><input type="text" name="cep" id="cep" class="form-control" inputmode="numeric" maxlength="9" value="<?= e($pessoa['cep'] ? substr($pessoa['cep'], 0, 5) . '-' . substr($pessoa['cep'], 5) : '') ?>"><div class="form-text" id="cep-status">Preenche o endereço sozinho.</div></div>
                <div class="col-md-6"><label class="form-label">Logradouro</label><input type="text" name="logradouro" id="logradouro" class="form-control" value="<?= e($pessoa['logradouro']) ?>"></div>
                <div class="col-md-2"><label class="form-label">Número</label><input type="text" name="numero" id="numero" class="form-control" value="<?= e($pessoa['numero']) ?>"></div>
                <div class="col-md-2"><label class="form-label">Complemento</label><input type="text" name="complemento" class="form-control" value="<?= e($pessoa['complemento']) ?>"></div>
                <div class="col-md-4"><label class="form-label">Bairro</label><input type="text" name="bairro" id="bairro" class="form-control" value="<?= e($pessoa['bairro']) ?>"></div>
                <div class="col-md-4"><label class="form-label">Cidade</label><input type="text" name="cidade" id="cidade" class="form-control" value="<?= e($pessoa['cidade']) ?>"></div>
                <div class="col-md-2"><label class="form-label">UF</label>
                    <select name="uf" id="uf" class="form-select"><option value=""></option><?php foreach (ufsBrasil() as $uf): ?><option <?= $pessoa['uf'] === $uf ? 'selected' : '' ?>><?= $uf ?></option><?php endforeach; ?></select>
                </div>
            </div>
        </div>

        <?php if ($ehCliente): ?>
        <div class="secao">
            <div class="secao-titulo"><i class="bi bi-percent"></i> Condições do cliente</div>
            <div class="row g-3">
                <div class="col-md-3">
                    <label class="form-label">Taxa (% ao mês)</label>
                    <input type="text" name="taxa_padrao" class="form-control" inputmode="decimal" placeholder="Ex.: 2,0" value="<?= $pessoa['taxa_padrao'] !== null ? e(rtrim(rtrim(formatDecimal($pessoa['taxa_padrao'], 4), '0'), ',')) : '' ?>">
                    <div class="form-text">Já vem preenchida nas operações dele.</div>
                </div>
                <div class="col-md-3">
                    <label class="form-label">Limite de crédito (R$)</label>
                    <input type="text" name="limite_credito" class="form-control" data-moeda inputmode="decimal" placeholder="Sem limite" value="<?= $pessoa['limite_credito'] !== null ? formatDecimal($pessoa['limite_credito']) : '' ?>">
                    <div class="form-text">Máximo em aberto: repasses em aberto + recebimentos (títulos dele) não pagos.</div>
                </div>
            </div>
        </div>
        <?php endif; ?>

        <div class="secao">
            <label class="form-label">Observação</label>
            <textarea name="observacao" class="form-control" rows="2"><?= e($pessoa['observacao']) ?></textarea>
        </div>
    </form>
</div>

<?php if ($id && $papel !== 'fornecedor'): ?>
<div class="tab-pane" id="pane-titulos" style="<?= in_array($tabAtiva, ['titulos', 'emitente'], true) ? '' : 'display:none;' ?>">
    <?php $filtroAba('titulos', 'rec_', $recSit, $recDe, $recAte, 'Vencimento'); ?>
    <div class="card">
        <div class="table-responsive">
            <table class="table table-sm table-hover mb-0 align-middle">
                <thead><tr><th>Tipo</th><th>Nº</th><th>Vencimento</th><th class="text-end">Valor</th><th>Recebido em</th><th><?= $ehCliente ? 'Pagador' : 'Passado pelo cliente' ?></th><th>Situação</th></tr></thead>
                <tbody>
                <?php if (!$titulosPessoa): ?>
                    <tr><td colspan="7" class="text-center text-muted py-3">Nenhum título <?= ['pendentes' => 'pendente', 'pagos' => 'pago', 'todos' => ''][$recSit] ?><?= $recDe || $recAte ? ' no período' : '' ?>.</td></tr>
                <?php endif; ?>
                <?php foreach ($titulosPessoa as $r): ?>
                    <?php $atraso = $r['data_recebimento'] ? diasEntre($r['data_vencimento'], $r['data_recebimento']) : null; ?>
                    <tr class="cursor-pointer" onclick="window.location='/receber/baixar.php?id=<?= (int) $r['id'] ?>'">
                        <td><?= $r['juros'] === null && $r['operacao_id'] ? 'Residual' : tipoTituloLabel($r['tipo']) ?></td>
                        <td><?= e($r['numero_titulo']) ?: '-' ?></td>
                        <td class="text-num"><?= formatDate($r['data_vencimento']) ?></td>
                        <td class="text-end text-num"><?= formatMoney($r['valor']) ?></td>
                        <td class="text-num"><?= formatDate($r['data_recebimento']) ?><?php if ($atraso !== null && $atraso > TOLERANCIA_COMPENSACAO_DIAS): ?> <span class="small text-warning">(+<?= $atraso ?>d)</span><?php endif; ?></td>
                        <td><?= e(($ehCliente ? $r['emitente_nome'] : $r['cliente_nome']) ?? '-') ?></td>
                        <td><?= badgeStatus(statusTelaReceber($r)) ?><?php if ($r['vezes_devolvido'] > 0 && $r['status'] !== 'devolvido'): ?> <span class="badge text-bg-light">já devolvido <?= (int) $r['vezes_devolvido'] ?>×</span><?php endif; ?></td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </div>
</div>
<?php endif; ?>

<?php if ($id && $ehCliente): ?>
<div class="tab-pane" id="pane-repasses" style="<?= in_array($tabAtiva, ['repasses', 'cliente'], true) ? '' : 'display:none;' ?>">
    <?php $filtroAba('repasses', 'rep_', $repSit, $repDe, $repAte, 'Data'); ?>
    <div class="secao">
        <div class="secao-titulo"><i class="bi bi-cash-coin"></i> Repasses</div>
        <div class="card">
            <div class="table-responsive">
                <table class="table table-sm mb-0 align-middle">
                    <thead><tr><th>Data</th><th>Descrição</th><th>Pagador</th><th>Saiu de</th><th class="text-end">Valor</th><th class="text-end">Corrigido hoje<?= $pessoa['taxa_padrao'] !== null ? ' (' . e(formatPercent($pessoa['taxa_padrao'])) . ' a.m.)' : '' ?></th><th class="text-end">Saldo corrigido</th></tr></thead>
                    <tbody>
                    <?php $saldoRep = $saldoAntesRepasses; $totValor = $totUsado = $totAberto = $totCorrigido = 0.0; $hojeRep = date('Y-m-d'); ?>
                    <?php if ($repDe && $saldoAntesRepasses > 0.004): ?>
                        <tr class="text-muted"><td colspan="6">Em aberto antes de <?= formatDate($repDe) ?></td><td class="text-end text-num valor-negativo"><?= formatMoney($saldoAntesRepasses) ?></td></tr>
                    <?php endif; ?>
                    <?php if (!$adiantamentos): ?><tr><td colspan="7" class="text-center text-muted py-3">Nenhum repasse<?= $repDe || $repAte ? ' no período' : '' ?>.</td></tr><?php endif; ?>
                    <?php foreach ($adiantamentos as $a):
                        $aberto = round($a['valor'] - $a['valor_utilizado'], 2);
                        $corrigido = $aberto > 0.004 ? $aberto + jurosSimples($aberto, (float) $pessoa['taxa_padrao'], max(0, diasEntre($a['data'], $hojeRep))) : 0;
                        $saldoRep += $corrigido; $totValor += $a['valor']; $totUsado += $a['valor_utilizado']; $totAberto += $aberto; $totCorrigido += $corrigido; ?>
                        <tr>
                            <td class="text-num"><?= formatDate($a['data']) ?></td>
                            <td><?= e($a['descricao']) ?: '-' ?></td>
                            <td><?= e($a['pagador_nome'] ?? '') ?: '<span class="text-muted">-</span>' ?></td>
                            <td><?= e($a['conta_nome'] ?? '—') ?></td>
                            <?php // Usado só em parte numa operação: o valor que conta é o que ainda falta. ?>
                            <td class="text-end text-num"><?= formatMoney($aberto > 0.004 ? $aberto : $a['valor']) ?></td>
                            <td class="text-end text-num"><?= $aberto > 0.004 ? formatMoney($corrigido) : '<span class="text-muted">quitado</span>' ?></td>
                            <td class="text-end text-num fw-semibold <?= $saldoRep > 0.004 ? 'valor-negativo' : '' ?>"><?= formatMoney($saldoRep) ?></td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                    <?php if ($adiantamentos): ?>
                    <tfoot><tr class="fw-semibold">
                        <td colspan="4">Total<?= $repDe || $repAte ? ' do período' : '' ?></td>
                        <td class="text-end text-num"><?= formatMoney($repSit === 'pendentes' ? $totAberto : $totValor) ?></td>
                        <td class="text-end text-num"><?= formatMoney($totCorrigido) ?></td>
                        <td class="text-end text-num <?= $saldoRep > 0.004 ? 'valor-negativo' : '' ?>"><?= formatMoney($saldoRep) ?></td>
                    </tr></tfoot>
                    <?php endif; ?>
                </table>
            </div>
        </div>
    </div>
</div>
<div class="tab-pane" id="pane-operacoes" style="<?= $tabAtiva === 'operacoes' ? '' : 'display:none;' ?>">
    <?php $filtroAba('operacoes', 'op_', null, $opDe, $opAte, 'Data base'); ?>
    <div class="secao">
        <div class="secao-titulo"><i class="bi bi-calculator"></i> Operações de desconto</div>
        <div class="card">
            <div class="table-responsive">
                <table class="table table-sm table-hover mb-0 align-middle">
                    <thead><tr><th>#</th><th>Data base</th><th class="text-end">Taxa</th><th class="text-end">Bruto</th><th class="text-end">Juros</th><th class="text-end">Líquido</th><th class="text-end">Passado (corrigido)</th><th class="text-end">Diferença</th></tr></thead>
                    <tbody>
                    <?php if (!$operacoes): ?><tr><td colspan="8" class="text-center text-muted py-3">Nenhuma operação.</td></tr><?php endif; ?>
                    <?php foreach ($operacoes as $o): ?>
                        <tr class="cursor-pointer" onclick="window.location='/operacoes/ver.php?id=<?= (int) $o['id'] ?>'">
                            <td><?= (int) $o['id'] ?></td>
                            <td class="text-num"><?= formatDate($o['data_base']) ?></td>
                            <td class="text-end"><?= formatPercent($o['taxa']) ?></td>
                            <td class="text-end text-num"><?= formatMoney($o['total_bruto']) ?></td>
                            <td class="text-end text-num"><?= formatMoney($o['total_juros']) ?></td>
                            <td class="text-end text-num"><?= formatMoney($o['total_liquido']) ?></td>
                            <td class="text-end text-num"><?= formatMoney($o['emprestado_atualizado']) ?></td>
                            <td class="text-end text-num <?= $o['saldo'] < 0 ? 'valor-negativo' : '' ?>"><?= formatMoney($o['saldo']) ?></td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </div>
</div>
<div class="tab-pane" id="pane-extrato" style="<?= $tabAtiva === 'extrato' ? '' : 'display:none;' ?>">
    <div class="d-flex align-items-start gap-2">
        <div class="flex-grow-1"><?php $filtroAba('extrato', 'ext_', null, $extDe, $extAte, 'Período'); ?></div>
        <a href="/pessoas/extrato_imprimir.php?<?= e(http_build_query(['id' => $id, 'de' => $extDe, 'ate' => $extAte])) ?>" target="_blank" class="btn btn-sm btn-outline-secondary mt-2"><i class="bi bi-printer"></i> Imprimir extrato</a>
    </div>
    <?php include __DIR__ . '/_extrato_tabela.php'; ?>
</div>
<?php endif; ?>

<script>
document.querySelectorAll('[data-tab]').forEach(function (link) {
    link.addEventListener('click', function (ev) {
        ev.preventDefault();
        document.querySelectorAll('[data-tab]').forEach((l) => l.classList.remove('active'));
        document.querySelectorAll('.tab-pane').forEach((p) => (p.style.display = 'none'));
        link.classList.add('active');
        document.getElementById('pane-' + link.dataset.tab).style.display = '';
    });
});

// Pessoa física × jurídica: troca rótulos e campos que só valem para uma delas.
(function () {
    const doc = document.getElementById('documento');
    function tipo() { return document.querySelector('[name="tipo_pessoa"]:checked').value; }
    function aplicar() {
        const pj = tipo() === 'J';
        document.querySelectorAll('[data-pf]').forEach((l) => (l.textContent = pj ? l.dataset.pj : l.dataset.pf));
        document.querySelectorAll('.so-pj').forEach((el) => (el.style.display = pj ? '' : 'none'));
        document.querySelectorAll('.so-pf').forEach((el) => (el.style.display = pj ? 'none' : ''));
        formatarDoc();
    }
    function formatarDoc() {
        let d = doc.value.replace(/\D/g, '');
        // Mais de 11 dígitos com "pessoa física" marcada: é CNPJ — troca para jurídica.
        if (tipo() === 'F' && d.length > 11) {
            document.getElementById('tp-j').checked = true;
            aplicar();
            return;
        }
        if (tipo() === 'J') {
            d = d.slice(0, 14);
            doc.value = d.replace(/^(\d{2})(\d)/, '$1.$2').replace(/^(\d{2})\.(\d{3})(\d)/, '$1.$2.$3').replace(/\.(\d{3})(\d)/, '.$1/$2').replace(/(\d{4})(\d)/, '$1-$2');
        } else {
            d = d.slice(0, 11);
            doc.value = d.replace(/^(\d{3})(\d)/, '$1.$2').replace(/^(\d{3})\.(\d{3})(\d)/, '$1.$2.$3').replace(/\.(\d{3})(\d{1,2})$/, '.$1-$2');
        }
    }
    document.querySelectorAll('[name="tipo_pessoa"]').forEach((r) => r.addEventListener('change', aplicar));
    doc.addEventListener('input', () => { formatarDoc(); buscarSeCompleto(); });
    aplicar();

    // ---- Busca de CNPJ (BrasilAPI, dados públicos da Receita) ----
    // CNPJ completo e válido: busca sozinho e preenche só o que está em branco;
    // o botão "Buscar" busca de novo e sobrescreve com os dados da Receita.
    const status = document.getElementById('cnpj-status');
    let ultimoBuscado = '';
    function cnpjValido(c) {
        if (c.length !== 14 || /^(\d)\1{13}$/.test(c)) return false;
        const dig = (base, pesos) => { const r = pesos.reduce((s, p, i) => s + Number(base[i]) * p, 0) % 11; return r < 2 ? 0 : 11 - r; };
        return dig(c, [5, 4, 3, 2, 9, 8, 7, 6, 5, 4, 3, 2]) === Number(c[12]) && dig(c, [6, 5, 4, 3, 2, 9, 8, 7, 6, 5, 4, 3, 2]) === Number(c[13]);
    }
    const titulo = (t) => String(t || '').toLowerCase().replace(/(^|\s)(\S)/g, (m, e, l) => e + l.toUpperCase())
        .replace(/ (De|Da|Do|Dos|Das|E) /g, (m) => m.toLowerCase());
    function buscarSeCompleto() {
        const c = doc.value.replace(/\D/g, '');
        if (tipo() === 'J' && c.length === 14 && c !== ultimoBuscado) {
            if (cnpjValido(c)) buscarCnpj(false);
            else { status.className = 'form-text text-danger'; status.textContent = 'CNPJ inválido (confira os dígitos).'; }
        }
    }
    function buscarCnpj(sobrescrever) {
        const c = doc.value.replace(/\D/g, '');
        if (!cnpjValido(c)) { status.className = 'form-text text-danger'; status.textContent = 'Digite um CNPJ válido para buscar.'; return; }
        ultimoBuscado = c;
        status.className = 'form-text'; status.textContent = 'Buscando na Receita...';
        fetch('https://brasilapi.com.br/api/cnpj/v1/' + c)
            .then((r) => { if (r.status === 404) throw new Error('nao'); if (!r.ok) throw new Error('erro'); return r.json(); })
            .then((d) => {
                const form = document.getElementById('form-pessoa');
                const por = (nome, valor) => {
                    const campo = form.querySelector('[name="' + nome + '"]');
                    if (!campo || !valor || (!sobrescrever && campo.value.trim() !== '')) return;
                    campo.value = valor;
                    campo.dispatchEvent(new Event('input', { bubbles: true })); // máscaras (telefone)
                };
                por('nome', d.razao_social);
                por('nome_fantasia', titulo(d.nome_fantasia));
                const cep = String(d.cep || '').replace(/\D/g, '');
                const campoCep = form.querySelector('[name="cep"]');
                if (cep.length === 8 && (sobrescrever || !campoCep.value.trim())) campoCep.value = cep.slice(0, 5) + '-' + cep.slice(5);
                por('logradouro', titulo([d.descricao_tipo_de_logradouro, d.logradouro].filter(Boolean).join(' ')));
                por('numero', d.numero === 'SN' ? 'S/N' : d.numero);
                por('complemento', titulo(d.complemento));
                por('bairro', titulo(d.bairro));
                por('cidade', titulo(d.municipio));
                por('uf', d.uf);
                por('telefone', d.ddd_telefone_1);
                por('email', d.email ? d.email.toLowerCase() : '');
                const ativa = String(d.descricao_situacao_cadastral || '').toUpperCase() === 'ATIVA';
                const desde = d.data_inicio_atividade ? ' · aberta em ' + d.data_inicio_atividade.split('-').reverse().join('/') : '';
                status.className = 'form-text ' + (ativa ? 'valor-positivo' : 'text-danger fw-semibold');
                status.textContent = 'Receita: ' + (d.descricao_situacao_cadastral || 'situação não informada') + desde + (sobrescrever ? ' · dados preenchidos' : ' · campos vazios preenchidos');
            })
            .catch((e) => {
                status.className = 'form-text text-danger';
                status.textContent = e.message === 'nao' ? 'CNPJ não encontrado na Receita.' : 'Não foi possível consultar agora; preencha à mão.';
                ultimoBuscado = '';
            });
    }
    document.getElementById('buscar-cnpj').addEventListener('click', () => buscarCnpj(true));
})();

// CEP: busca o endereço no ViaCEP e preenche (só o que vier).
(function () {
    const cep = document.getElementById('cep');
    const status = document.getElementById('cep-status');
    cep.addEventListener('input', () => {
        const d = cep.value.replace(/\D/g, '').slice(0, 8);
        cep.value = d.length > 5 ? d.slice(0, 5) + '-' + d.slice(5) : d;
        if (d.length !== 8) return;
        status.textContent = 'Buscando...';
        fetch('https://viacep.com.br/ws/' + d + '/json/')
            .then((r) => r.json())
            .then((r) => {
                if (r.erro) { status.textContent = 'CEP não encontrado.'; return; }
                const set = (id, v) => { if (v) document.getElementById(id).value = v; };
                set('logradouro', r.logradouro); set('bairro', r.bairro); set('cidade', r.localidade); set('uf', r.uf);
                status.textContent = 'Endereço preenchido.';
                document.getElementById('numero').focus();
            })
            .catch(() => (status.textContent = 'Não foi possível buscar o CEP; preencha à mão.'));
    });
})();
</script>

<?php require __DIR__ . '/../../includes/layout_footer.php'; ?>
