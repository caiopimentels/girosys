<?php
// Paga várias contas/faturas de uma vez (painel lateral de Contas a pagar).
// O dinheiro pode ter saído de várias contas (partes[i][conta_id|valor]): as
// contas marcadas são quitadas em ordem de vencimento, consumindo as partes em
// sequência — cada uma fica com o pagamento da(s) conta(s) que a cobriu.
// "Não movimentar conta" (sozinha) dá todas como pagas sem mexer em saldo.
require_once __DIR__ . '/../../includes/bootstrap.php';
exigirLogin();

$pdo = getDB();
$data = dataInput('data') ?? date('Y-m-d');

$partes = [];
$semConta = false;
foreach ((array) ($_POST['partes'] ?? []) as $p) {
    $valor = paraDecimal($p['valor'] ?? '');
    if (($p['conta_id'] ?? '') === 'sem') {
        $semConta = true;
    } elseif (idOuNull($p['conta_id'] ?? null) && $valor > 0) {
        $partes[] = ['conta_id' => (int) $p['conta_id'], 'valor' => round($valor, 2)];
    } elseif ($valor > 0) {
        flash('danger', 'Escolha de qual conta saiu cada valor.');
        redirecionarAposAcao('/pagar/listar.php');
    }
}
if ($semConta && $partes) {
    flash('danger', '"Não movimentar conta" vale sozinha: não misture com contas no mesmo pagamento.');
    redirecionarAposAcao('/pagar/listar.php');
}
if (!$semConta && !$partes) {
    flash('danger', 'Informe de qual conta saiu o pagamento e o valor.');
    redirecionarAposAcao('/pagar/listar.php');
}

// Itens marcados, ainda em aberto, em ordem de vencimento.
$itens = [];
foreach ((array) ($_POST['ids'] ?? []) as $chave) {
    [$tipo, $id] = array_pad(explode(':', (string) $chave), 2, 0);
    $id = (int) $id;
    if ($tipo === 'fatura') {
        $stmt = $pdo->prepare("SELECT f.*, c.nome AS cartao_nome FROM cartao_faturas f JOIN cartoes c ON c.id = f.cartao_id WHERE f.id = ? AND f.status = 'aberta'");
        $stmt->execute([$id]);
        if ($f = $stmt->fetch()) {
            $itens[] = ['origem' => 'fatura', 'id' => $id, 'vencimento' => $f['data_vencimento'], 'descricao' => 'Fatura ' . $f['cartao_nome'] . ' ' . nomeMes($f['competencia']), 'pago' => (float) $f['valor_pago']];
        }
    } else {
        $stmt = $pdo->prepare("SELECT * FROM contas_pagar WHERE id = ? AND status = 'pendente'");
        $stmt->execute([$id]);
        if ($c = $stmt->fetch()) {
            $itens[] = ['origem' => 'pagar', 'id' => $id, 'vencimento' => $c['data_vencimento'], 'descricao' => $c['descricao'], 'pago' => (float) $c['valor_pago']];
        }
    }
}
usort($itens, fn ($a, $b) => $a['vencimento'] <=> $b['vencimento']);

$quitadas = $parciais = 0;
$total = 0.0;
$partesInformadas = $partes;
$pagos = [];
$pdo->beginTransaction();
foreach ($itens as $item) {
    $falta = round(totalDevido($pdo, $item['origem'], $item['id']) - $item['pago'], 2);
    if ($falta <= 0.004) {
        continue;
    }
    if ($semConta) {
        quitarSemMovimentar($pdo, $item['origem'], $item['id'], $data);
        $pagos[] = $item['descricao'] . ' ' . formatMoney($falta);
        $quitadas++;
        $total += $falta;
        continue;
    }
    // Tira das partes (na ordem) o que esta conta precisa.
    $usar = [];
    $precisa = $falta;
    while ($precisa > 0.004 && $partes) {
        $tira = min($precisa, $partes[0]['valor']);
        $usar[] = ['conta_id' => $partes[0]['conta_id'], 'valor' => round($tira, 2)];
        $partes[0]['valor'] = round($partes[0]['valor'] - $tira, 2);
        $precisa = round($precisa - $tira, 2);
        if ($partes[0]['valor'] <= 0.004) {
            array_shift($partes);
        }
    }
    if (!$usar) {
        break; // acabou o dinheiro informado
    }
    registrarPagamentos($pdo, $item['origem'], $item['id'], $item['descricao'], $data, $usar);
    $pagos[] = $item['descricao'] . ' ' . formatMoney($falta - $precisa) . ($precisa > 0.004 ? ' (em parte)' : '');
    $precisa > 0.004 ? $parciais++ : $quitadas++;
    $total += $falta - $precisa;
}
if ($pagos) {
    registrarAtividade('contas_pagar', 'pagamento', 'Pagamento em lote em ' . formatDate($data) . ' · ' . ($semConta ? 'sem movimentar conta' : descreverPartesPagamento($partesInformadas)) . ': ' . implode('; ', $pagos));
}
$pdo->commit();

$sobra = array_sum(array_column($partes, 'valor'));
$naoPagas = count($itens) - $quitadas - $parciais;
$msg = "{$quitadas} conta(s) quitada(s)" . ($parciais ? ", {$parciais} paga(s) em parte" : '') . ' em ' . formatDate($data) . ' · total ' . formatMoney($total)
    . ($semConta ? ' (sem movimentar conta).' : '.')
    . ($naoPagas > 0 ? " {$naoPagas} ficaram em aberto (o valor informado acabou)." : '')
    . ($sobra > 0.004 ? ' Sobrou ' . formatMoney($sobra) . ' que não foi lançado.' : '');
flash($naoPagas > 0 || $parciais || $sobra > 0.004 ? 'warning' : 'success', $msg);
redirecionarAposAcao('/pagar/listar.php');
