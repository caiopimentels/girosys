<?php
// Recebe vários títulos de uma vez (painel lateral de Contas a receber).
require_once __DIR__ . '/../../includes/bootstrap.php';
exigirLogin();
exigirPermissaoAcao('baixar_receber', 'Seu perfil não pode dar baixa em recebimentos.');

$pdo = getDB();
$data = dataInput('data') ?? date('Y-m-d');
$contaEscolhida = idOuNull($_POST['conta_id'] ?? null);
$atualizado = !empty($_POST['atualizado']);
$ids = array_filter(array_map('intval', (array) ($_POST['ids'] ?? [])));

$stmt = $pdo->prepare(
    "SELECT r.*, cl.taxa_padrao AS cliente_taxa, e.nome AS emitente_nome FROM contas_receber r
     LEFT JOIN pessoas cl ON cl.id = r.cliente_id LEFT JOIN pessoas e ON e.id = r.emitente_id
     WHERE r.id = ? AND r.status IN ('pendente', 'devolvido')"
);
$recebidos = 0;
$total = 0.0;
$semConta = 0;
$baixados = [];
$pdo->beginTransaction();
foreach ($ids as $id) {
    $stmt->execute([$id]);
    if (!$t = $stmt->fetch()) {
        continue; // já recebido/cancelado nesse meio-tempo
    }
    $conta = $contaEscolhida ?? idOuNull($t['conta_destino_id']);
    if (!$conta) {
        $semConta++;
        continue;
    }
    $valor = $atualizado ? atualizacaoReceber($pdo, $t, $data)['total'] : (float) $t['valor'];
    baixarReceber($pdo, $t, $data, $valor, $conta);
    $baixados[] = '#' . $id . ' ' . ($t['emitente_nome'] ?? '') . ' ' . formatMoney($valor);
    $recebidos++;
    $total += $valor;
}
if ($baixados) {
    registrarAtividade('contas_receber', 'baixa', 'Baixa em lote em ' . formatDate($data) . ' (' . $recebidos . ' título(s), total ' . formatMoney($total) . ($atualizado ? ', valor atualizado' : '') . '): ' . implode('; ', $baixados));
}
$pdo->commit();

if ($recebidos) {
    flash('success', "{$recebidos} título(s) recebido(s) em " . formatDate($data) . ' · total ' . formatMoney($total) . '.');
}
if ($semConta) {
    flash('warning', "{$semConta} título(s) ficaram de fora por não terem conta definida — escolha a conta na baixa em lote ou no título.");
}
redirecionarAposAcao('/receber/listar.php');
