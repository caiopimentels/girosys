<?php

// Fluxo de caixa projetado: o que entra e o que sai nos próximos dias, com
// as entradas separadas pela reputação de quem paga (includes/pontuacao.php):
//   certas    — títulos a vencer de pagador "bom pagador";
//   prováveis — títulos a vencer de pagador em "atenção" ou sem histórico;
//   incertas  — pagador em "risco", títulos já vencidos e devolvidos: não entram na conta.
// Saídas: contas a pagar em aberto (inclui pagamentos agendados a clientes)
// e faturas de cartão em aberto; as já vencidas contam no primeiro período.
// "Livre para usar" = o menor saldo que a conta chega a ter no período
// contando só as entradas certas — o que dá para gastar hoje sem faltar depois.

function fluxoProjetado(PDO $pdo, int $dias = 90, int $passo = 10): array
{
    $hoje = date('Y-m-d');
    $fim = date('Y-m-d', strtotime("+{$dias} days"));
    $saldo = array_sum(array_map(fn ($c) => (float) $c['saldo'], contasComSaldo($pdo)));

    // Reputação: pagador do título; título sem pagador (ex.: residual) usa o cliente.
    $porPagador = calcularPontuacoes(titulosParaPontuacao($pdo, 'emitente_id'));
    $porCliente = calcularPontuacoes(titulosParaPontuacao($pdo, 'cliente_id'));
    $comAtraso = array_flip(array_map('intval', $pdo->query(
        "SELECT DISTINCT emitente_id FROM contas_receber WHERE emitente_id IS NOT NULL AND (status = 'devolvido' OR (status = 'pendente' AND data_vencimento < CURDATE()))"
    )->fetchAll(PDO::FETCH_COLUMN)));
    $faixa = function (?int $pagador, ?int $cliente) use ($porPagador, $porCliente, $comAtraso): string {
        $p = $pagador ? ($porPagador[$pagador] ?? null) : ($cliente ? ($porCliente[$cliente] ?? null) : null);
        $c = $p ? classificacaoPorPontos($p['pontos'], $p['liquidados']) : 'sem_historico';
        return $c === 'bom' && $pagador && isset($comAtraso[$pagador]) ? 'atencao' : $c;
    };

    $periodos = [];
    for ($ini = 0; $ini < $dias; $ini += $passo) {
        $periodos[] = ['ate' => date('Y-m-d', strtotime('+' . min($dias, $ini + $passo) . ' days')), 'certas' => 0.0, 'provaveis' => 0.0, 'saidas' => 0.0];
    }
    $indice = function (string $data) use ($periodos): int {
        foreach ($periodos as $i => $p) {
            if ($data <= $p['ate']) {
                return $i;
            }
        }
        return count($periodos) - 1;
    };

    $incertas = ['risco' => 0.0, 'vencidos' => 0.0];
    $stmt = $pdo->prepare("SELECT emitente_id, cliente_id, valor, data_vencimento, status FROM contas_receber
        WHERE status IN ('pendente', 'devolvido') AND data_vencimento <= ?");
    $stmt->execute([$fim]);
    foreach ($stmt->fetchAll() as $t) {
        if ($t['status'] === 'devolvido' || $t['data_vencimento'] < $hoje) {
            $incertas['vencidos'] += (float) $t['valor'];
            continue;
        }
        $c = $faixa($t['emitente_id'] ? (int) $t['emitente_id'] : null, $t['cliente_id'] ? (int) $t['cliente_id'] : null);
        if ($c === 'risco') {
            $incertas['risco'] += (float) $t['valor'];
            continue;
        }
        $periodos[$indice($t['data_vencimento'])][$c === 'bom' ? 'certas' : 'provaveis'] += (float) $t['valor'];
    }

    $stmt = $pdo->prepare("SELECT data_vencimento, valor - COALESCE(valor_pago, 0) AS falta FROM contas_pagar WHERE status = 'pendente' AND data_vencimento <= ?
        UNION ALL
        SELECT f.data_vencimento, SUM(p.valor) - COALESCE(f.valor_pago, 0) FROM cartao_faturas f JOIN cartao_parcelas p ON p.fatura_id = f.id
        WHERE f.status = 'aberta' AND f.data_vencimento <= ? GROUP BY f.id");
    $stmt->execute([$fim, $fim]);
    foreach ($stmt->fetchAll() as $s) {
        $periodos[$indice(max($hoje, $s['data_vencimento']))]['saidas'] += max(0.0, (float) $s['falta']);
    }

    // Acumulados e saldo projetado (conservador: só entradas certas).
    $acCertas = $acProvaveis = $acSaidas = 0.0;
    $livre = $saldo;
    foreach ($periodos as &$p) {
        $acCertas += $p['certas'];
        $acProvaveis += $p['provaveis'];
        $acSaidas += $p['saidas'];
        $p['ac_certas'] = round($acCertas, 2);
        $p['ac_com_provaveis'] = round($acCertas + $acProvaveis, 2);
        $p['ac_saidas'] = round($acSaidas, 2);
        $p['saldo_conservador'] = round($saldo + $acCertas - $acSaidas, 2);
        $p['saldo_provavel'] = round($saldo + $acCertas + $acProvaveis - $acSaidas, 2);
        $livre = min($livre, $p['saldo_conservador']);
    }
    unset($p);

    return ['saldo' => round($saldo, 2), 'periodos' => $periodos, 'livre' => round($livre, 2), 'incertas' => $incertas,
        'dias' => $dias, 'passo' => $passo, 'total_certas' => round($acCertas, 2), 'total_provaveis' => round($acProvaveis, 2), 'total_saidas' => round($acSaidas, 2)];
}
