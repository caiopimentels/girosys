<?php
// Dados de uma pessoa para a tela de operação: taxa do cliente, adiantamentos
// em aberto e como ela paga os títulos em que é emitente.
require_once __DIR__ . '/../../includes/bootstrap.php';
exigirLogin();

$pdo = getDB();
$papel = array_key_exists($_GET['papel'] ?? '', papeisPessoa()) ? $_GET['papel'] : 'cliente';
$documento = apenasDigitos($_GET['documento'] ?? '');
$nome = formatarNomePessoa((string) ($_GET['nome'] ?? ''));

$pessoa = null;
if ($documento !== '') {
    $stmt = $pdo->prepare('SELECT * FROM pessoas WHERE papel = ? AND documento = ? LIMIT 1');
    $stmt->execute([$papel, $documento]);
    $pessoa = $stmt->fetch();
}
if (!$pessoa && $nome !== '') {
    $stmt = $pdo->prepare('SELECT * FROM pessoas WHERE papel = ? AND nome = ? ORDER BY id LIMIT 1');
    $stmt->execute([$papel, $nome]);
    $pessoa = $stmt->fetch();
}
if (!$pessoa) {
    responderJson(['encontrado' => false]);
}

$h = historicoEmitente($pdo, (int) $pessoa['id']);
[$rotulo] = classificacaoLabel($h['classificacao']);

responderJson([
    'encontrado' => true,
    'id' => (int) $pessoa['id'],
    'nome' => $pessoa['nome'],
    'documento' => documentoFormatado($pessoa['documento']),
    'taxa_padrao' => $pessoa['taxa_padrao'] !== null ? (float) $pessoa['taxa_padrao'] : null,
    'limite_credito' => $pessoa['limite_credito'] !== null ? (float) $pessoa['limite_credito'] : null,
    'credito_em_uso' => creditoEmUso($pdo, (int) $pessoa['id']),
    'historico' => $h,
    'badge' => badgeClassificacao($h['classificacao']),
    'resumo' => $h['total'] > 0
        ? "{$rotulo}: {$h['total']} título(s), {$h['em_dia']} em dia, {$h['com_atraso']} com atraso, {$h['devolvidos']} devolvido(s), {$h['vencidos']} vencido(s) em aberto"
        : 'Nenhum título deste pagador ainda',
    'adiantamentos' => array_map(fn ($a) => [
        'id' => (int) $a['id'],
        'data' => $a['data'],
        'data_br' => formatDate($a['data']),
        'descricao' => $a['descricao'] ?? '',
        'conta' => $a['conta_nome'] ?? '',
        'valor' => (float) $a['valor'],
        'em_aberto' => round((float) $a['em_aberto'], 2),
    ], adiantamentosEmAberto($pdo, (int) $pessoa['id'])),
]);
