<?php

// Rentabilidade: quanto ganhei no período. Fontes de ganho:
//   deságio        — juros descontados dos títulos nas operações (pela data base);
//   repasses       — juros cobrados sobre os repasses quitados nas operações;
//   atraso         — juros e multa por atraso recebidos (acréscimo na baixa, pela data do recebimento);
//   renegociação   — juros/multa levados para os novos títulos ou para o cliente (pela data da renegociação);
//   arredondamento — parte da diferença da operação que não paguei ao cliente (pela data base).

/** Linhas de ganho no período, cada uma com data, cliente e componente. */
function ganhosPeriodo(PDO $pdo, string $de, string $ate): array
{
    $linhas = [];

    $stmt = $pdo->prepare('SELECT o.id, o.data_base, o.cliente_id, p.nome AS cliente_nome, o.total_bruto, o.total_liquido, o.total_juros, o.juros_emprestimo, o.desconto_pagamento,
            (SELECT COALESCE(SUM(r.juros), 0) FROM contas_receber r WHERE r.operacao_id = o.id AND r.status = \'recebido\') AS desagio_realizado
        FROM operacoes o JOIN pessoas p ON p.id = o.cliente_id WHERE o.data_base BETWEEN ? AND ?');
    $stmt->execute([$de, $ate]);
    foreach ($stmt->fetchAll() as $o) {
        $linhas[] = ['data' => $o['data_base'], 'cliente_id' => (int) $o['cliente_id'], 'cliente_nome' => $o['cliente_nome'], 'componente' => 'desagio',
            'valor' => (float) $o['total_juros'], 'realizado' => (float) $o['desagio_realizado'], 'operacao' => $o];
        if ($o['desconto_pagamento'] > 0) {
            $linhas[] = ['data' => $o['data_base'], 'cliente_id' => (int) $o['cliente_id'], 'cliente_nome' => $o['cliente_nome'], 'componente' => 'arredondamento',
                'valor' => (float) $o['desconto_pagamento']];
        }
        if ($o['juros_emprestimo'] > 0) {
            $linhas[] = ['data' => $o['data_base'], 'cliente_id' => (int) $o['cliente_id'], 'cliente_nome' => $o['cliente_nome'], 'componente' => 'repasses',
                'valor' => (float) $o['juros_emprestimo']];
        }
    }

    foreach (jurosMultaRecebidos($pdo, $de, $ate) as $t) {
        $linhas[] = ['data' => $t['data_recebimento'], 'cliente_id' => (int) $t['cliente_id'], 'cliente_nome' => $t['cliente_nome'], 'componente' => 'atraso',
            'valor' => (float) $t['acrescimo'], 'multa' => $t['multa_parte'], 'juros' => $t['juros_parte']];
    }

    $stmt = $pdo->prepare("SELECT rn.*, cl.nome AS cliente_nome FROM renegociacoes rn LEFT JOIN pessoas cl ON cl.id = rn.cliente_id
        WHERE rn.desfeita_em IS NULL AND rn.data BETWEEN ? AND ? AND rn.encargos > 0");
    $stmt->execute([$de, $ate]);
    foreach ($stmt->fetchAll() as $r) {
        $linhas[] = ['data' => $r['data'], 'cliente_id' => (int) $r['cliente_id'], 'cliente_nome' => $r['cliente_nome'],
            'componente' => $r['destino_encargos'] === 'isento' ? 'isento' : 'renegociacao', 'valor' => (float) $r['encargos']];
    }
    return $linhas;
}

/**
 * Títulos recebidos no período com juros/multa por atraso. O acréscimo é
 * guardado junto; a multa sai pela regra do tipo (% sobre o valor, até o
 * acréscimo) e o resto é juros.
 */
function jurosMultaRecebidos(PDO $pdo, string $de, string $ate): array
{
    $stmt = $pdo->prepare(
        "SELECT r.*, e.nome AS emitente_nome, cl.nome AS cliente_nome, cl.taxa_padrao AS cliente_taxa
         FROM contas_receber r LEFT JOIN pessoas e ON e.id = r.emitente_id LEFT JOIN pessoas cl ON cl.id = r.cliente_id
         WHERE r.status = 'recebido' AND r.acrescimo > 0 AND r.data_recebimento BETWEEN ? AND ?
         ORDER BY r.data_recebimento DESC, r.id DESC"
    );
    $stmt->execute([$de, $ate]);
    $titulos = $stmt->fetchAll();
    foreach ($titulos as &$t) {
        [$multaPct] = regraAtualizacao($pdo, $t);
        $multa = $multaPct > 0 ? min((float) $t['acrescimo'], round((float) $t['valor'] * $multaPct / 100, 2)) : 0.0;
        $t['multa_parte'] = $multa;
        $t['juros_parte'] = round((float) $t['acrescimo'] - $multa, 2);
        $t['dias_atraso'] = max(0, diasEntre($t['data_vencimento'], $t['data_recebimento']));
    }
    unset($t);
    return $titulos;
}

function componentesGanho(): array
{
    return [
        'desagio' => 'Deságio das operações',
        'repasses' => 'Juros dos repasses',
        'atraso' => 'Juros e multa por atraso',
        'renegociacao' => 'Juros/multa de renegociação',
        'arredondamento' => 'Arredondamentos',
    ];
}

/** Soma por chave (mês ou cliente) e componente. */
function agruparGanhos(array $linhas, callable $chave): array
{
    $grupos = [];
    foreach ($linhas as $l) {
        $k = $chave($l);
        $grupos[$k] ??= array_fill_keys([...array_keys(componentesGanho()), 'isento', 'total', 'bruto', 'liquido', 'operacoes'], 0.0);
        $grupos[$k][$l['componente']] += $l['valor'];
        if ($l['componente'] !== 'isento') {
            $grupos[$k]['total'] += $l['valor'];
        }
        if ($l['componente'] === 'desagio') {
            $grupos[$k]['bruto'] += (float) $l['operacao']['total_bruto'];
            $grupos[$k]['liquido'] += (float) $l['operacao']['total_liquido'];
            $grupos[$k]['operacoes']++;
        }
    }
    return $grupos;
}
