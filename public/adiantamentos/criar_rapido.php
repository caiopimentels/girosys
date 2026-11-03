<?php
// Lança um adiantamento a partir do modal "Adicionar despesa" da operação.
require_once __DIR__ . '/../../includes/bootstrap.php';
exigirLogin();

$pdo = getDB();
$clienteNome = input('cliente_nome');
$data = dataInput('data');
$valor = decimalInput('valor');
$contaId = idOuNull($_POST['conta_id'] ?? null);

if ($clienteNome === '' || !$data || $valor <= 0 || !$contaId) {
    responderJson(['sucesso' => false, 'mensagem' => 'Informe cliente, data, valor e a conta de onde saiu.']);
}

$pdo->beginTransaction();
$clienteId = obterOuCriarPessoa($pdo, $clienteNome, input('cliente_documento'), 'cliente');
$id = registrarAdiantamento($pdo, $clienteId, $data, $valor, $contaId, input('descricao'));
$pdo->commit();

$conta = $pdo->prepare('SELECT nome FROM contas_bancarias WHERE id = ?');
$conta->execute([$contaId]);

responderJson(['sucesso' => true, 'adiantamento' => [
    'id' => $id,
    'data' => $data,
    'data_br' => formatDate($data),
    'descricao' => input('descricao'),
    'conta' => (string) $conta->fetchColumn(),
    'valor' => round($valor, 2),
    'em_aberto' => round($valor, 2),
]]);
