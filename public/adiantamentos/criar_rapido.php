<?php
// Lança um adiantamento a partir do modal "Adicionar despesa" da operação.
require_once __DIR__ . '/../../includes/bootstrap.php';
exigirLogin();

$pdo = getDB();
$clienteNome = input('cliente_nome');
$data = dataInput('data');
$valor = decimalInput('valor');
$semConta = ($_POST['conta_id'] ?? '') === 'sem'; // lançamento antigo: não mexe em saldo
$contaId = $semConta ? null : idOuNull($_POST['conta_id'] ?? null);

if ($clienteNome === '' || !$data || $valor <= 0 || (!$contaId && !$semConta)) {
    responderJson(['sucesso' => false, 'mensagem' => 'Informe cliente, data, valor e a conta de onde saiu (ou "não movimentar conta").']);
}

$pdo->beginTransaction();
$clienteId = obterOuCriarPessoa($pdo, $clienteNome, input('cliente_documento'), 'cliente');
$id = registrarAdiantamento($pdo, $clienteId, $data, $valor, $contaId, input('descricao'));
registrarAtividade('repasses', 'criacao', 'Repasse a ' . formatarNomePessoa($clienteNome) . ' (pela operação): ' . formatMoney($valor) . ' em ' . formatDate($data) . ($semConta ? ' · sem movimentar conta' : ''));
$pdo->commit();

$conta = $pdo->prepare('SELECT nome FROM contas_bancarias WHERE id = ?');
$conta->execute([(int) $contaId]);

responderJson(['sucesso' => true, 'adiantamento' => [
    'id' => $id,
    'data' => $data,
    'data_br' => formatDate($data),
    'descricao' => input('descricao'),
    'conta' => (string) $conta->fetchColumn(),
    'valor' => round($valor, 2),
    'em_aberto' => round($valor, 2),
]]);
