<?php
// Ranking por pontuação (includes/pontuacao.php): quem paga bem e quanto
// rende, por pagador ou pelo cliente que traz os títulos. Com ?pessoa=ID,
// o extrato de pontos daquela pessoa.
require_once __DIR__ . '/../../includes/bootstrap.php';
exigirLogin();

$pdo = getDB();
$visao = ($_GET['visao'] ?? '') === 'cliente' ? 'cliente' : 'pagador';
$campo = $visao === 'cliente' ? 'cliente_id' : 'emitente_id';
$ordens = ['pontos' => 'Pontos', 'ganho' => 'Ganho', 'valor' => 'Valor', 'atrasos' => 'Atrasos', 'nome' => 'Nome'];
$ordem = array_key_exists($_GET['ordem'] ?? '', $ordens) ? $_GET['ordem'] : 'pontos';
$pessoaId = (int) ($_GET['pessoa'] ?? 0);
$qs = fn (array $mudar) => '?' . http_build_query(array_merge(['visao' => $visao, 'ordem' => $ordem], $mudar));

$pontuacoes = calcularPontuacoes(titulosParaPontuacao($pdo, $campo, $pessoaId ?: null), (bool) $pessoaId);
$nomes = $pontuacoes ? $pdo->query('SELECT id, nome FROM pessoas WHERE id IN (' . implode(',', array_map('intval', array_keys($pontuacoes))) . ')')->fetchAll(PDO::FETCH_KEY_PAIR) : [];
foreach ($pontuacoes as $id => &$p) {
    $p['nome'] = $nomes[$id] ?? '#' . $id;
    $p['classificacao'] = classificacaoPorPontos($p['pontos'], $p['liquidados']);
}
unset($p);
uasort($pontuacoes, fn ($a, $b) => match ($ordem) {
    'nome' => strcasecmp($a['nome'], $b['nome']),
    'pontos' => [$b['liquidados'] > 0, $b['pontos'], $b['ganho']] <=> [$a['liquidados'] > 0, $a['pontos'], $a['ganho']],
    default => $b[$ordem] <=> $a[$ordem],
});
$faixas = array_count_values(array_column($pontuacoes, 'classificacao'));

$pageTitle = $pessoaId ? 'Extrato de pontos · ' . ($nomes[$pessoaId] ?? '') : 'Pontuação de pagadores e clientes';
$pageBreadcrumb = 'Relatórios';
$activeMenu = 'pontuacao';
$pageAction = $pessoaId ? '<a href="' . e($qs(['pessoa' => null])) . '" class="btn btn-outline-secondary"><i class="bi bi-arrow-left"></i> Ranking</a>'
    : '<button type="button" class="btn btn-outline-secondary" onclick="window.print()"><i class="bi bi-printer"></i> Imprimir</button>';
require __DIR__ . '/../../includes/layout_header.php';
?>

<?php if ($pessoaId): $p = $pontuacoes[$pessoaId] ?? null; ?>
<div class="card mb-3"><div class="card-body d-flex flex-wrap gap-4 align-items-center">
    <div><div class="kpi-rotulo">Pontos hoje</div><div class="kpi-valor"><?= formatarPontos($p['pontos'] ?? PONTOS_INICIAIS) ?></div></div>
    <div><?= badgeClassificacao(classificacaoPorPontos($p['pontos'] ?? PONTOS_INICIAIS, $p['liquidados'] ?? 0), $p['pontos'] ?? null) ?></div>
    <div class="small text-muted">Como <?= $visao === 'cliente' ? 'cliente (títulos que ele trouxe)' : 'pagador' ?> · <?= (int) ($p['titulos'] ?? 0) ?> título(s) · ganho <?= formatMoney($p['ganho'] ?? 0) ?></div>
</div></div>
<div class="card"><div class="table-responsive">
    <table class="table table-sm table-hover mb-0 align-middle">
        <thead><tr><th>Data</th><th>Título</th><th>O que aconteceu</th><th class="text-end">Pontos</th><th class="text-end">Saldo</th></tr></thead>
        <tbody>
        <?php if (empty($p['eventos'])): ?><tr><td colspan="5" class="text-center text-muted py-4">Nenhum título vencido ou pago ainda: continua com 100 pontos.</td></tr><?php endif; ?>
        <?php foreach (array_reverse($p['eventos'] ?? []) as [$data, $pts, $motivo, $tituloId, $saldo]): ?>
            <tr>
                <td class="text-num"><?= formatDate($data) ?></td>
                <td><a href="/receber/baixar.php?id=<?= (int) $tituloId ?>">#<?= (int) $tituloId ?></a></td>
                <td><?= e($motivo) ?></td>
                <td class="text-end text-num <?= $pts < 0 ? 'valor-negativo' : 'valor-positivo' ?>"><?= ($pts > 0 ? '+' : '') . formatarPontos($pts) ?></td>
                <td class="text-end text-num fw-semibold"><?= formatarPontos($saldo) ?></td>
            </tr>
        <?php endforeach; ?>
        </tbody>
    </table>
</div></div>

<?php else: ?>
<ul class="nav nav-tabs mb-3 nao-imprimir">
    <li class="nav-item"><a class="nav-link <?= $visao === 'pagador' ? 'active' : '' ?>" href="<?= e($qs(['visao' => 'pagador'])) ?>">Por pagador</a></li>
    <li class="nav-item"><a class="nav-link <?= $visao === 'cliente' ? 'active' : '' ?>" href="<?= e($qs(['visao' => 'cliente'])) ?>">Por cliente (títulos que ele traz)</a></li>
</ul>
<div class="d-flex flex-wrap gap-2 mb-3 small">
    <?php foreach (['bom', 'atencao', 'risco', 'sem_historico'] as $c): if (empty($faixas[$c])) continue; ?>
        <span><?= badgeClassificacao($c) ?> <?= (int) $faixas[$c] ?></span>
    <?php endforeach; ?>
    <span class="ms-auto text-muted nao-imprimir">Ordenar por:
        <?php foreach ($ordens as $k => $rotulo): ?><a href="<?= e($qs(['ordem' => $k])) ?>" class="ms-2 <?= $ordem === $k ? 'fw-semibold text-reset' : '' ?>"><?= $rotulo ?></a><?php endforeach; ?></span>
</div>
<div class="card"><div class="table-responsive">
    <table class="table table-sm table-hover mb-0 align-middle">
        <thead><tr><th><?= $visao === 'cliente' ? 'Cliente' : 'Pagador' ?></th><th>Pontuação</th><th class="text-center">Títulos</th><th class="text-center">Adiantados</th><th class="text-center">Em dia</th>
            <th class="text-center">Atrasos</th><th class="text-center">Devoluções</th><th class="text-end">Valor total</th><th class="text-end">Em aberto</th><th class="text-end" title="Deságio dos títulos + juros/multa por atraso recebidos">Ganho</th></tr></thead>
        <tbody>
        <?php if (!$pontuacoes): ?><tr><td colspan="10" class="text-center text-muted py-4">Nenhum título lançado ainda.</td></tr><?php endif; ?>
        <?php foreach ($pontuacoes as $id => $p): ?>
            <tr class="cursor-pointer" onclick="location.href='<?= e($qs(['pessoa' => $id])) ?>'">
                <td><a href="<?= e($qs(['pessoa' => $id])) ?>" class="text-reset"><?= e($p['nome']) ?></a></td>
                <td><?= badgeClassificacao($p['classificacao'], $p['pontos']) ?></td>
                <td class="text-center"><?= $p['titulos'] ?></td>
                <td class="text-center"><?= $p['adiantados'] ?: '-' ?></td>
                <td class="text-center"><?= $p['em_dia'] ?: '-' ?></td>
                <td class="text-center <?= $p['atrasos'] ? 'valor-negativo' : '' ?>"><?= $p['atrasos'] ?: '-' ?></td>
                <td class="text-center <?= $p['devolucoes'] ? 'valor-negativo' : '' ?>"><?= $p['devolucoes'] ?: '-' ?></td>
                <td class="text-end text-num"><?= formatMoney($p['valor']) ?></td>
                <td class="text-end text-num"><?= $p['em_aberto'] > 0 ? formatMoney($p['em_aberto']) : '-' ?></td>
                <td class="text-end text-num valor-positivo"><?= formatMoney($p['ganho']) ?></td>
            </tr>
        <?php endforeach; ?>
        </tbody>
    </table>
</div></div>
<?php endif; ?>
<div class="form-text mt-2">
    Todos começam com 100 pontos (o máximo). Atraso além da tolerância de <?= toleranciaCompensacaoDias() ?> dia(s): −1 (até <?= PONTOS_DIAS_ATRASO_LONGO ?> dias) ou −2 (mais que isso; em aberto conta até hoje).
    Devolução: −2. Renegociação conta como atraso. Pagou atrasado: +0,5 · em dia: +1 · adiantado: +2 (nunca passa de 100).
    Faixas: <?= formatarPontos(PONTOS_FAIXA_BOM) ?>+ bom pagador · <?= formatarPontos(PONTOS_FAIXA_ATENCAO) ?> a <?= formatarPontos(PONTOS_FAIXA_BOM - 0.5) ?> atenção · abaixo de <?= formatarPontos(PONTOS_FAIXA_ATENCAO) ?> risco.
</div>

<?php require __DIR__ . '/../../includes/layout_footer.php'; ?>
