<?php
require_once __DIR__ . '/../../includes/bootstrap.php';
exigirLogin();

$pdo = getDB();
$papel = array_key_exists($_GET['papel'] ?? '', papeisPessoa()) ? $_GET['papel'] : 'cliente';
$busca = trim($_GET['busca'] ?? '');

$where = ['p.papel = ?'];
$params = [$papel];
if ($busca !== '') {
    $where[] = '(p.nome LIKE ? OR p.nome_fantasia LIKE ? OR p.documento LIKE ? OR p.cidade LIKE ?)';
    array_push($params, "%{$busca}%", "%{$busca}%", '%' . (apenasDigitos($busca) ?: $busca) . '%', "%{$busca}%");
}

$stmt = $pdo->prepare(
    "SELECT p.*,
        (SELECT COALESCE(SUM(a.valor - a.valor_utilizado), 0) FROM adiantamentos a WHERE a.cliente_id = p.id)
          + (SELECT COALESCE(SUM(r.valor), 0) FROM contas_receber r WHERE r.cliente_id = p.id AND r.status IN ('pendente', 'devolvido')) AS em_uso,
        (SELECT COUNT(*) FROM contas_receber r WHERE r.emitente_id = p.id AND r.status <> 'cancelado') AS titulos,
        (SELECT COUNT(*) FROM contas_receber r WHERE r.emitente_id = p.id OR r.cliente_id = p.id)
          + (SELECT COUNT(*) FROM operacoes o WHERE o.cliente_id = p.id) + (SELECT COUNT(*) FROM adiantamentos a WHERE a.cliente_id = p.id)
          + (SELECT COUNT(*) FROM contas_pagar cp WHERE cp.pessoa_id = p.id) AS vinculos,
        (SELECT COALESCE(SUM(r.valor), 0) FROM contas_receber r WHERE r.emitente_id = p.id AND r.status IN ('pendente', 'devolvido')) AS a_receber
     FROM pessoas p WHERE " . implode(' AND ', $where) . ' ORDER BY p.nome LIMIT 1000'
);
$stmt->execute($params);
$pessoas = $stmt->fetchAll();
$contagem = $pdo->query('SELECT papel, COUNT(*) FROM pessoas GROUP BY papel')->fetchAll(PDO::FETCH_KEY_PAIR);

$titulos = ['cliente' => 'Clientes', 'pagador' => 'Pagadores (emitentes dos títulos)', 'fornecedor' => 'Fornecedores'];
$pageTitle = $titulos[$papel];
$pageBreadcrumb = 'Cadastros';
$activeMenu = 'pessoas-' . $papel;
$pageAction = '<a href="/pessoas/form.php?papel=' . $papel . '" class="btn btn-brand"><i class="bi bi-plus-lg"></i> Novo ' . mb_strtolower(papeisPessoa()[$papel]) . '</a>';
require __DIR__ . '/../../includes/layout_header.php';
?>

<ul class="nav nav-tabs mb-3">
    <?php foreach ($titulos as $k => $v): ?>
        <li class="nav-item"><a class="nav-link <?= $papel === $k ? 'active' : '' ?>" href="?papel=<?= $k ?>"><?= e(explode(' (', $v)[0]) ?> <span class="badge text-bg-light"><?= (int) ($contagem[$k] ?? 0) ?></span></a></li>
    <?php endforeach; ?>
</ul>

<div class="filtros">
    <form method="get">
        <input type="hidden" name="papel" value="<?= e($papel) ?>">
        <input type="text" name="busca" class="form-control form-control-sm" placeholder="Nome, CPF/CNPJ ou cidade" value="<?= e($busca) ?>" style="width: 300px;">
        <button class="btn btn-sm btn-outline-secondary" type="submit"><i class="bi bi-search"></i> Buscar</button>
    </form>
    <span class="ms-auto text-muted small"><?= count($pessoas) ?> registro(s)</span>
</div>

<div class="card">
    <div class="table-responsive">
        <table class="table table-hover mb-0 align-middle">
            <thead><tr>
                <th>Nome</th><th>CPF/CNPJ</th><th>Celular / telefone</th><th>Cidade</th>
                <?php if ($papel === 'cliente'): ?><th class="text-end">Taxa</th><th class="text-end">Limite</th><th class="text-end">Crédito em uso</th><?php endif; ?>
                <?php if ($papel === 'pagador'): ?><th class="text-center">Títulos</th><th class="text-end">A receber</th><th>Como pagador</th><?php endif; ?>
                <th></th><th></th>
            </tr></thead>
            <tbody>
            <?php if (!$pessoas): ?>
                <tr><td colspan="10" class="text-center text-muted py-4">Ninguém encontrado.</td></tr>
            <?php endif; ?>
            <?php foreach ($pessoas as $p): ?>
                <tr class="cursor-pointer" onclick="window.location='/pessoas/form.php?id=<?= (int) $p['id'] ?>'">
                    <td><?= e($p['nome']) ?><?= $p['nome_fantasia'] ? '<div class="small text-muted">' . e($p['nome_fantasia']) . '</div>' : '' ?></td>
                    <td class="text-num"><?= e(documentoFormatado($p['documento'])) ?: '-' ?></td>
                    <td class="text-num"><?= e(telefoneFormatado($p['celular'] ?: $p['telefone'])) ?: '-' ?></td>
                    <td><?= $p['cidade'] ? e($p['cidade'] . ($p['uf'] ? '/' . $p['uf'] : '')) : '-' ?></td>
                    <?php if ($papel === 'cliente'): ?>
                        <td class="text-end"><?= $p['taxa_padrao'] !== null ? formatPercent($p['taxa_padrao']) : '-' ?></td>
                        <td class="text-end text-num"><?= $p['limite_credito'] !== null ? formatMoney($p['limite_credito']) : '-' ?></td>
                        <td class="text-end text-num <?= $p['limite_credito'] !== null && $p['em_uso'] > $p['limite_credito'] ? 'valor-negativo fw-semibold' : '' ?>"><?= formatMoney($p['em_uso']) ?></td>
                    <?php endif; ?>
                    <?php if ($papel === 'pagador'): ?>
                        <td class="text-center"><?= (int) $p['titulos'] ?></td>
                        <td class="text-end text-num"><?= formatMoney($p['a_receber']) ?></td>
                        <td><?= $p['titulos'] > 0 ? badgeClassificacao(historicoEmitente($pdo, (int) $p['id'])['classificacao']) : '<span class="text-muted small">-</span>' ?></td>
                    <?php endif; ?>
                    <td class="text-end"><?= cadastroCompletoParaBoleto($p) ? '' : '<span class="badge text-bg-light" title="Faltam dados para emitir boleto">incompleto</span>' ?></td>
                    <?= acoesLinha('/pessoas/form.php?id=' . (int) $p['id'], $p['vinculos'] ? null : '/pessoas/form.php?id=' . (int) $p['id'],
                        'Excluir o cadastro de ' . $p['nome'] . '?', false, 'Tem lançamentos (títulos, operações, repasses ou despesas): não pode ser excluído') ?>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    </div>
</div>

<?php require __DIR__ . '/../../includes/layout_footer.php'; ?>
