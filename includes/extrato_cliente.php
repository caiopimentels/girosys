<?php

// Extrato do cliente: a conta corrente dele comigo, em ordem de data.
// Débito = ele passa a me dever; crédito = ele quitou. Saldo > 0 = ele deve.
//   + repasse (valor passado a ele)                        débito
//   + título dele devolvido para ele (virou repasse)        débito
//   + correção dos repasses usados numa operação            débito
//   + diferença paga a ele na operação                      débito
//   − títulos entregues na operação (valor líquido)         crédito
//   − residual da operação recebido                         crédito
// O "saldo devedor" de operação (repasse sem saída de conta) e o residual
// lançado como conta a receber não entram: já estão no saldo corrido.

/** Lançamentos do extrato até $ate (todos, para o saldo anterior sair certo). */
function lancamentosExtratoCliente(PDO $pdo, int $clienteId, string $ate): array
{
    $l = [];
    $stmt = $pdo->prepare(
        "SELECT a.*, c.nome AS conta_nome, r.juros AS titulo_juros, r.operacao_id AS titulo_operacao
         FROM adiantamentos a LEFT JOIN contas_bancarias c ON c.id = a.conta_id LEFT JOIN contas_receber r ON r.id = a.titulo_origem_id
         WHERE a.cliente_id = ? AND a.operacao_origem_id IS NULL AND a.data <= ?"
    );
    $stmt->execute([$clienteId, $ate]);
    foreach ($stmt->fetchAll() as $a) {
        if ($a['titulo_origem_id'] && $a['titulo_operacao'] && $a['titulo_juros'] === null) {
            continue; // residual devolvido para ele: já estava no saldo
        }
        // No mesmo dia: repasse antes da operação; título devolvido depois dela.
        $l[] = ['data' => $a['data'], 'ordem' => $a['titulo_origem_id'] ? 6 : 1, 'debito' => (float) $a['valor'], 'credito' => 0.0,
            'historico' => $a['titulo_origem_id'] ? 'Título devolvido para você · ' . ($a['descricao'] ?: '') : 'Valor passado a você' . ($a['descricao'] ? ' · ' . $a['descricao'] : '') . ($a['conta_nome'] ? ' (' . $a['conta_nome'] . ')' : '')];
    }

    $stmt = $pdo->prepare('SELECT * FROM operacoes WHERE cliente_id = ? AND data_base <= ? ORDER BY data_base, id');
    $stmt->execute([$clienteId, $ate]);
    $qtdTitulos = $pdo->prepare('SELECT COUNT(*) FROM contas_receber WHERE operacao_id = ? AND juros IS NOT NULL');
    foreach ($stmt->fetchAll() as $o) {
        $qtdTitulos->execute([$o['id']]);
        $n = (int) $qtdTitulos->fetchColumn();
        if ($o['juros_emprestimo'] > 0) {
            $l[] = ['data' => $o['data_base'], 'ordem' => 2, 'debito' => (float) $o['juros_emprestimo'], 'credito' => 0.0,
                'historico' => 'Operação #' . $o['id'] . ' · correção dos repasses (' . formatPercent($o['taxa']) . ' a.m.)'];
        }
        $l[] = ['data' => $o['data_base'], 'ordem' => 3, 'debito' => 0.0, 'credito' => (float) $o['total_liquido'],
            'historico' => 'Operação #' . $o['id'] . ' · ' . $n . ' título(s) entregue(s): ' . formatMoney($o['total_bruto']) . ' − juros ' . formatMoney($o['total_juros'])];
        if ($o['valor_pago_cliente'] > 0) {
            $l[] = ['data' => $o['data_base'], 'ordem' => 4, 'debito' => (float) $o['valor_pago_cliente'], 'credito' => 0.0,
                'historico' => 'Operação #' . $o['id'] . ' · diferença paga a você'];
        }
    }

    // Residual (conta a receber da operação) que ele pagou.
    $stmt = $pdo->prepare(
        "SELECT * FROM contas_receber WHERE cliente_id = ? AND operacao_id IS NOT NULL AND juros IS NULL AND status = 'recebido' AND data_recebimento <= ?"
    );
    $stmt->execute([$clienteId, $ate]);
    foreach ($stmt->fetchAll() as $r) {
        $l[] = ['data' => $r['data_recebimento'], 'ordem' => 5, 'debito' => 0.0, 'credito' => (float) $r['valor_recebido'],
            'historico' => 'Pagamento do residual da operação #' . $r['operacao_id']];
    }

    usort($l, fn ($a, $b) => [$a['data'], $a['ordem']] <=> [$b['data'], $b['ordem']]);
    return $l;
}

/**
 * Extrato no período: saldo anterior, lançamentos com saldo corrido e totais.
 * $de/$ate vazios = sem limite.
 */
function extratoCliente(PDO $pdo, int $clienteId, string $de, string $ate): array
{
    $todos = lancamentosExtratoCliente($pdo, $clienteId, $ate ?: '2999-12-31');
    $saldoAnterior = 0.0;
    $linhas = [];
    foreach ($todos as $l) {
        if ($de && $l['data'] < $de) {
            $saldoAnterior += $l['debito'] - $l['credito'];
            continue;
        }
        $linhas[] = $l;
    }
    $saldo = $saldoAnterior;
    foreach ($linhas as &$l) {
        $saldo += $l['debito'] - $l['credito'];
        $l['saldo'] = round($saldo, 2);
    }
    unset($l);
    return [
        'saldo_anterior' => round($saldoAnterior, 2),
        'linhas' => $linhas,
        'debitos' => round(array_sum(array_column($linhas, 'debito')), 2),
        'creditos' => round(array_sum(array_column($linhas, 'credito')), 2),
        'saldo_final' => round($saldo, 2),
    ];
}
