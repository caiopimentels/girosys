<?php
// Aba "Auditoria do banco" da conta: tudo o que o sistema enviou ao banco
// (token, emissão, consulta, PDF, cancelamento, webhook) e o que o banco
// respondeu. Nada aqui é apagado; segredos e tokens aparecem mascarados.
require_once __DIR__ . '/../../includes/bootstrap.php';
exigirAdmin();

$pdo = getDB();
$id = (int) ($_GET['id'] ?? 0);
$tituloFiltro = (int) ($_GET['titulo'] ?? 0);
if (!$id && $tituloFiltro) {
    // Vindo do título: acha a conta pela última chamada dele.
    $stmt = $pdo->prepare('SELECT conta_bancaria_id FROM banco_chamadas WHERE conta_receber_id = ? ORDER BY id DESC LIMIT 1');
    $stmt->execute([$tituloFiltro]);
    $id = (int) $stmt->fetchColumn();
}
$conta = contaBancaria($pdo, $id);
if (!$conta) {
    flash('danger', 'Conta não encontrada.');
    redirect('/configuracoes/contas.php');
}

$operacoes = rotulosOperacaoBanco();
[$de, $ate, $deSql, $ateSql] = periodoFiltro(date('Y-m-d', strtotime('-30 days')), date('Y-m-d'));
$operacao = array_key_exists($_GET['operacao'] ?? '', $operacoes) ? $_GET['operacao'] : '';
$resultadoFiltro = in_array($_GET['resultado'] ?? '', ['ok', 'erro'], true) ? $_GET['resultado'] : '';
$quem = trim($_GET['quem'] ?? '');
$busca = trim($_GET['busca'] ?? '');
$pagina = max(1, (int) ($_GET['pagina'] ?? 1));
$porPagina = 50;

$where = ['c.conta_bancaria_id = ?', 'c.criado_em BETWEEN ? AND ?'];
$params = [$id, $deSql . ' 00:00:00', $ateSql . ' 23:59:59'];
if ($operacao) {
    $where[] = 'c.operacao = ?';
    $params[] = $operacao;
}
if ($resultadoFiltro) {
    $where[] = 'c.sucesso = ' . ($resultadoFiltro === 'ok' ? 1 : 0);
}
if ($quem === 'automatico') {
    $where[] = "c.origem = 'automatico'";
} elseif (str_starts_with($quem, 'u:')) {
    $where[] = 'c.usuario_id = ?';
    $params[] = (int) substr($quem, 2);
}
if ($tituloFiltro) {
    $where[] = 'c.conta_receber_id = ?';
    $params[] = $tituloFiltro;
}
if ($busca !== '') {
    $termo = '%' . addcslashes($busca, '\\%_') . '%';
    $where[] = '(e.nome LIKE ? OR r.boleto_nosso_numero LIKE ? OR r.boleto_codigo LIKE ? OR c.requisicao LIKE ? OR c.resposta LIKE ?)';
    array_push($params, $termo, $termo, $termo, $termo, $termo);
}
$from = 'FROM banco_chamadas c
    LEFT JOIN usuarios u ON u.id = c.usuario_id
    LEFT JOIN contas_receber r ON r.id = c.conta_receber_id
    LEFT JOIN pessoas e ON e.id = r.emitente_id
    WHERE ' . implode(' AND ', $where);
$stmt = $pdo->prepare("SELECT COUNT(*) $from");
$stmt->execute($params);
$total = (int) $stmt->fetchColumn();
$totalPaginas = max(1, (int) ceil($total / $porPagina));
$pagina = min($pagina, $totalPaginas);
$stmt = $pdo->prepare("SELECT c.*, u.nome AS usuario_nome, r.descricao AS titulo_descricao, r.valor AS titulo_valor, r.data_vencimento AS titulo_vencimento,
    r.boleto_nosso_numero, r.boleto_situacao, r.status AS titulo_status, e.nome AS pagador_nome
    $from ORDER BY c.id DESC LIMIT $porPagina OFFSET " . (($pagina - 1) * $porPagina));
$stmt->execute($params);
$chamadas = $stmt->fetchAll();
$usuarios = $pdo->query('SELECT id, nome FROM usuarios ORDER BY nome')->fetchAll();

// JSON guardado com quebras; o que não for JSON aparece como veio.
$leitura = function (?string $texto): string {
    $dados = json_decode((string) $texto, true);
    return is_array($dados) ? json_encode($dados, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) : (string) $texto;
};
$filtrosAtuais = array_filter(['id' => $id, 'de' => $de, 'ate' => $ate, 'operacao' => $operacao, 'resultado' => $resultadoFiltro, 'quem' => $quem, 'titulo' => $tituloFiltro ?: '', 'busca' => $busca]);

$pageTitle = $conta['nome'];
$pageBreadcrumb = 'Administração · Contas bancárias · Auditoria do banco';
$activeMenu = 'configuracoes';
$pageAction = '<a href="/configuracoes/contas.php" class="btn btn-outline-secondary"><i class="bi bi-arrow-left"></i> Contas bancárias</a>';
require __DIR__ . '/../../includes/layout_header.php';
$abaAtiva = 'contas';
require __DIR__ . '/../../includes/abas_configuracoes.php';
$contaAbaId = $id;
$contaAba = 'auditoria';
require __DIR__ . '/../../includes/abas_conta_bancaria.php';
?>

<p class="text-muted small" style="max-width: 900px;">
    Tudo o que o sistema enviou ao banco e o que o banco respondeu. Os avisos que o banco manda ficam na aba
    <a href="/contas/webhook.php?id=<?= (int) $id ?>">Webhook de pagamento</a>. Nada aqui é apagado; segredos e tokens aparecem como <code>••••••</code>.
</p>

<div class="filtros">
    <form method="get">
        <input type="hidden" name="id" value="<?= (int) $id ?>">
        <?php if ($tituloFiltro): ?><input type="hidden" name="titulo" value="<?= $tituloFiltro ?>"><?php endif; ?>
        <?php $dataInicio = $de; $dataFim = $ate; require __DIR__ . '/../../includes/filtro_periodo.php'; ?>
        <input type="text" name="busca" class="form-control form-control-sm" style="width: 220px;" placeholder="Pagador, nosso número, cobrança..." value="<?= e($busca) ?>">
        <select name="operacao" class="form-select form-select-sm" style="width: 190px;">
            <option value="">Todas as operações</option>
            <?php foreach ($operacoes as $chave => $rotulo): ?>
                <option value="<?= e($chave) ?>" <?= $operacao === $chave ? 'selected' : '' ?>><?= e($rotulo) ?></option>
            <?php endforeach; ?>
        </select>
        <select name="quem" class="form-select form-select-sm" style="width: 170px;">
            <option value="">Quem pediu: todos</option>
            <?php foreach ($usuarios as $u): ?>
                <option value="u:<?= (int) $u['id'] ?>" <?= $quem === 'u:' . $u['id'] ? 'selected' : '' ?>><?= e($u['nome']) ?></option>
            <?php endforeach; ?>
            <option value="automatico" <?= $quem === 'automatico' ? 'selected' : '' ?>>Automático (webhook/cron)</option>
        </select>
        <select name="resultado" class="form-select form-select-sm" style="width: 150px;">
            <option value="">Todos os resultados</option>
            <option value="ok" <?= $resultadoFiltro === 'ok' ? 'selected' : '' ?>>Deu certo</option>
            <option value="erro" <?= $resultadoFiltro === 'erro' ? 'selected' : '' ?>>Com erro</option>
        </select>
        <button class="btn btn-sm btn-outline-secondary" type="submit"><i class="bi bi-search"></i> Filtrar</button>
        <a href="?id=<?= (int) $id ?>" class="btn btn-sm btn-outline-secondary"><i class="bi bi-x-lg"></i> Limpar</a>
    </form>
    <span class="ms-auto text-muted small"><?= $total ?> chamada(s)<?= $tituloFiltro ? ' · só o título #' . $tituloFiltro : '' ?></span>
</div>

<div class="card">
    <div class="table-responsive">
        <table class="table table-sm mb-0 align-middle">
            <thead><tr><th>Data/hora</th><th>Operação</th><th>Quem pediu</th><th>Título</th><th>Boleto</th><th>Resposta</th><th></th></tr></thead>
            <tbody>
            <?php if (!$chamadas): ?>
                <tr><td colspan="7" class="text-center text-muted py-4">Nenhuma chamada registrada com esses filtros.</td></tr>
            <?php endif; ?>
            <?php foreach ($chamadas as $c): ?>
                <tr>
                    <td class="text-nowrap text-num"><?= formatDateTime($c['criado_em']) ?></td>
                    <td><?= e($operacoes[$c['operacao']] ?? $c['operacao']) ?> <span class="text-muted small"><?= e($c['metodo']) ?></span></td>
                    <td><?= $c['origem'] === 'automatico' ? '<span class="text-muted">Automático</span>' : e($c['usuario_nome'] ?: '-') ?></td>
                    <td class="small">
                        <?php if ($c['conta_receber_id']): ?>
                            <div class="fw-semibold"><?= e($c['pagador_nome'] ?: '-') ?></div>
                            <a href="?<?= e(http_build_query(['id' => $id, 'titulo' => $c['conta_receber_id']])) ?>" title="Ver só este título">#<?= (int) $c['conta_receber_id'] ?></a>
                            <?php if ($c['titulo_valor'] !== null): ?><span class="text-muted">· <?= formatMoney($c['titulo_valor']) ?> · vence <?= formatDate($c['titulo_vencimento']) ?></span><?php endif; ?>
                        <?php else: ?><span class="text-muted">-</span><?php endif; ?>
                    </td>
                    <td class="small">
                        <?php if ($c['boleto_nosso_numero'] || $c['boleto_situacao']): ?>
                            <?php if ($c['boleto_nosso_numero']): ?><div>Nosso nº <strong><?= e($c['boleto_nosso_numero']) ?></strong></div><?php endif; ?>
                            <span class="text-muted">hoje: <?= e(rotuloSituacaoBoleto($c['boleto_situacao'])) ?></span>
                        <?php else: ?><span class="text-muted">-</span><?php endif; ?>
                    </td>
                    <td>
                        <span class="badge text-bg-<?= $c['status_http'] === null ? 'dark' : ($c['sucesso'] ? 'success' : 'danger') ?>"><?= $c['status_http'] === null ? 'sem resposta' : (int) $c['status_http'] ?></span>
                        <?php if ($c['duracao_ms'] !== null): ?><span class="text-muted small"><?= (int) $c['duracao_ms'] ?> ms</span><?php endif; ?>
                        <?php if ($c['mensagem']): ?><div class="small text-danger"><?= e($c['mensagem']) ?></div><?php endif; ?>
                    </td>
                    <td class="text-end">
                        <button type="button" class="btn btn-sm btn-outline-secondary" data-bs-toggle="collapse" data-bs-target="#chamada-<?= (int) $c['id'] ?>"><i class="bi bi-code-slash"></i> Ver</button>
                    </td>
                </tr>
                <tr class="collapse" id="chamada-<?= (int) $c['id'] ?>">
                    <td colspan="7" class="bg-body-tertiary">
                        <div class="small text-muted mb-2 font-monospace"><?= e($c['metodo'] . ' ' . $c['url']) ?></div>
                        <div class="row g-3">
                            <div class="col-lg-6">
                                <div class="small fw-semibold mb-1">Enviado ao banco</div>
                                <pre class="small mb-0" style="white-space: pre-wrap; word-break: break-all; max-height: 420px; overflow: auto;"><?= $c['requisicao'] !== null ? e($leitura($c['requisicao'])) : '<span class="text-muted">(sem corpo)</span>' ?></pre>
                            </div>
                            <div class="col-lg-6">
                                <div class="small fw-semibold mb-1">Resposta do banco</div>
                                <pre class="small mb-0" style="white-space: pre-wrap; word-break: break-all; max-height: 420px; overflow: auto;"><?= $c['resposta'] !== null ? e($leitura($c['resposta'])) : '<span class="text-muted">(sem resposta)</span>' ?></pre>
                            </div>
                        </div>
                    </td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    </div>
    <?php if ($totalPaginas > 1): ?>
    <div class="card-footer bg-transparent d-flex align-items-center gap-2">
        <?php $query = fn (int $p) => '?' . http_build_query($filtrosAtuais + ['pagina' => $p]); ?>
        <a class="btn btn-sm btn-outline-secondary <?= $pagina <= 1 ? 'disabled' : '' ?>" href="<?= e($query($pagina - 1)) ?>">‹ Mais recentes</a>
        <span class="small text-muted">Página <?= $pagina ?> de <?= $totalPaginas ?></span>
        <a class="btn btn-sm btn-outline-secondary <?= $pagina >= $totalPaginas ? 'disabled' : '' ?>" href="<?= e($query($pagina + 1)) ?>">Mais antigas ›</a>
    </div>
    <?php endif; ?>
</div>

<?php require __DIR__ . '/../../includes/layout_footer.php'; ?>
