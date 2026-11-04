<?php
require_once __DIR__ . '/../../includes/bootstrap.php';
exigirAdmin();

$pdo = getDB();

[$dataInicio, $dataFim] = periodoFiltro(date('Y-m-d', strtotime('-30 days')), date('Y-m-d'));
$modulo = trim($_GET['modulo'] ?? '');
$usuarioId = (int) ($_GET['usuario_id'] ?? 0);
$operacao = trim($_GET['operacao'] ?? '');
$busca = trim($_GET['busca'] ?? '');
$pagina = max(1, (int) ($_GET['pagina'] ?? 1));

$resultado = buscarLogAtividades($pdo, [
    'data_inicio' => $dataInicio,
    'data_fim' => $dataFim,
    'modulo' => $modulo,
    'usuario_id' => $usuarioId ?: null,
    'operacao' => $operacao,
    'busca' => $busca,
], $pagina, 50);

$usuarios = $pdo->query('SELECT id, nome FROM usuarios ORDER BY nome')->fetchAll();

// Operações: as conhecidas e as que já apareceram no registro, com o nome
// amigável quando houver.
$operacoes = operacoesAuditoria();
foreach ($pdo->query('SELECT DISTINCT operacao FROM log_atividades')->fetchAll(PDO::FETCH_COLUMN) as $op) {
    $operacoes[$op] ??= ucfirst(str_replace('_', ' ', $op));
}
asort($operacoes);

$queryBase = [
    'de' => $dataInicio,
    'ate' => $dataFim,
    'modulo' => $modulo,
    'usuario_id' => $usuarioId ?: '',
    'operacao' => $operacao,
    'busca' => $busca,
];

$pageTitle = 'Auditoria';
$pageBreadcrumb = 'Administração · Configurações';
$activeMenu = 'configuracoes';
require __DIR__ . '/../../includes/layout_header.php';
$abaAtiva = 'auditoria';
require __DIR__ . '/../../includes/abas_configuracoes.php';
?>

<div class="filtros">
    <form method="get">
        <?php require __DIR__ . '/../../includes/filtro_periodo.php'; ?>
        <select name="modulo" class="form-select form-select-sm" style="width: 200px;">
            <option value="">Todos os módulos</option>
            <?php foreach (modulosAuditoria() as $chave => $nome): ?>
                <option value="<?= e($chave) ?>" <?= $modulo === $chave ? 'selected' : '' ?>><?= e($nome) ?></option>
            <?php endforeach; ?>
        </select>
        <select name="usuario_id" class="form-select form-select-sm" style="width: 170px;">
            <option value="">Todos os usuários</option>
            <?php foreach ($usuarios as $u): ?>
                <option value="<?= (int) $u['id'] ?>" <?= $usuarioId === (int) $u['id'] ? 'selected' : '' ?>><?= e($u['nome']) ?></option>
            <?php endforeach; ?>
        </select>
        <select name="operacao" class="form-select form-select-sm" style="width: 190px;">
            <option value="">Todas as operações</option>
            <?php foreach ($operacoes as $chave => $nome): ?>
                <option value="<?= e($chave) ?>" <?= $operacao === $chave ? 'selected' : '' ?>><?= e($nome) ?></option>
            <?php endforeach; ?>
        </select>
        <input type="search" name="busca" class="form-control form-control-sm" style="width: 240px;" placeholder="Cliente, nº do título, valor, IP..." value="<?= e($busca) ?>">
        <button class="btn btn-sm btn-outline-secondary" type="submit"><i class="bi bi-search"></i> Filtrar</button>
        <a href="/configuracoes/auditoria.php" class="btn btn-sm btn-outline-secondary"><i class="bi bi-x-lg"></i> Limpar</a>
    </form>
    <span class="ms-auto text-muted small"><?= $resultado['total'] ?> registro(s)</span>
</div>

<div class="card">
    <div class="table-responsive">
        <table class="table table-sm mb-0 align-middle">
            <thead>
            <tr>
                <th>Data/hora</th>
                <th>Usuário</th>
                <th>Módulo</th>
                <th>Operação</th>
                <th>Descrição</th>
                <th>IP</th>
                <th>Dispositivo</th>
            </tr>
            </thead>
            <tbody>
            <?php if (!$resultado['itens']): ?>
                <tr><td colspan="7" class="text-center text-muted py-4">Nenhum registro encontrado.</td></tr>
            <?php endif; ?>
            <?php foreach ($resultado['itens'] as $log): ?>
                <tr class="<?= $log['operacao'] === 'login_falha' ? 'table-danger' : '' ?>">
                    <td class="text-nowrap text-num"><?= formatDateTime($log['criado_em']) ?></td>
                    <td><?= e($log['usuario_nome']) ?></td>
                    <td><?= e(modulosAuditoria()[$log['modulo']] ?? $log['modulo']) ?></td>
                    <td><?= e(operacoesAuditoria()[$log['operacao']] ?? ucfirst(str_replace('_', ' ', $log['operacao']))) ?></td>
                    <td><?= e($log['descricao']) ?></td>
                    <td class="text-nowrap"><?= e($log['ip'] ?: '-') ?></td>
                    <td class="text-nowrap"><?= e(resumirUserAgent($log['user_agent'])) ?></td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    </div>
    <?php if ($resultado['total_paginas'] > 1): ?>
    <div class="card-footer bg-transparent">
        <nav>
            <ul class="pagination pagination-sm mb-0 flex-wrap">
                <?php for ($i = 1; $i <= $resultado['total_paginas']; $i++): ?>
                    <li class="page-item <?= $i === $resultado['pagina'] ? 'active' : '' ?>">
                        <a class="page-link" href="?<?= e(http_build_query(array_merge($queryBase, ['pagina' => $i]))) ?>"><?= $i ?></a>
                    </li>
                <?php endfor; ?>
            </ul>
        </nav>
    </div>
    <?php endif; ?>
</div>

<?php require __DIR__ . '/../../includes/layout_footer.php'; ?>
