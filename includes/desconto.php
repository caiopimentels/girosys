<?php

// Desconto de títulos — juros simples, mês comercial de 30 dias:
//   juros = valor × taxa% / 30 × dias
// Ex.: cheque de 2.000,00 para 30/12/2026, base 25/10/2026 (66 dias), 2% a.m.
//   2000 × 0,02 / 30 × 66 = 88,00 → vale 1.912,00 em 25/10.
// A mesma conta (no sentido contrário) corrige o adiantamento feito ao
// cliente desde a data dele até a data base. O mesmo cálculo existe em JS
// (assets/js/operacao.js) só para a tela; o que vale é o daqui.

function jurosSimples(float $valor, float $taxa, int $dias): float
{
    return $dias > 0 ? round($valor * $taxa / 100 / 30 * $dias, 2) : 0.0;
}

/**
 * $titulos: [['valor' => float, 'vencimento' => 'Y-m-d', ...], ...]
 * $adiantamentos: [['data' => 'Y-m-d', 'valor_usado' => float, ...], ...]
 * Devolve as mesmas listas com dias/juros calculados e os totais.
 */
function calcularOperacao(string $dataBase, float $taxa, int $diasCompensacao, array $titulos, array $adiantamentos): array
{
    $tot = ['total_bruto' => 0.0, 'total_juros' => 0.0, 'total_liquido' => 0.0,
            'valor_emprestado' => 0.0, 'juros_emprestimo' => 0.0, 'emprestado_atualizado' => 0.0];

    foreach ($titulos as &$t) {
        $t['dias'] = max(0, diasEntre($dataBase, $t['vencimento']) + $diasCompensacao);
        $t['juros'] = jurosSimples($t['valor'], $taxa, $t['dias']);
        $t['valor_liquido'] = round($t['valor'] - $t['juros'], 2);
        $tot['total_bruto'] += $t['valor'];
        $tot['total_juros'] += $t['juros'];
        $tot['total_liquido'] += $t['valor_liquido'];
    }
    unset($t);

    foreach ($adiantamentos as &$a) {
        $a['dias'] = max(0, diasEntre($a['data'], $dataBase));
        $a['juros'] = jurosSimples($a['valor_usado'], $taxa, $a['dias']);
        $a['atualizado'] = round($a['valor_usado'] + $a['juros'], 2);
        $tot['valor_emprestado'] += $a['valor_usado'];
        $tot['juros_emprestimo'] += $a['juros'];
        $tot['emprestado_atualizado'] += $a['atualizado'];
    }
    unset($a);

    $tot = array_map(fn ($v) => round($v, 2), $tot);
    $tot['saldo'] = round($tot['total_liquido'] - $tot['emprestado_atualizado'], 2);

    return ['titulos' => $titulos, 'adiantamentos' => $adiantamentos, 'totais' => $tot];
}

/** Adiantamentos do cliente que ainda têm valor em aberto. */
function adiantamentosEmAberto(PDO $pdo, int $clienteId): array
{
    $stmt = $pdo->prepare(
        'SELECT a.*, a.valor - a.valor_utilizado AS em_aberto, c.nome AS conta_nome, pg.nome AS pagador_nome
         FROM adiantamentos a LEFT JOIN contas_bancarias c ON c.id = a.conta_id LEFT JOIN pessoas pg ON pg.id = a.pagador_id
         WHERE a.cliente_id = ? AND a.valor - a.valor_utilizado > 0.004
         ORDER BY a.data, a.id'
    );
    $stmt->execute([$clienteId]);
    return $stmt->fetchAll();
}

/** Total adiantado ainda em aberto (de um cliente ou de todos). */
function totalAdiantadoEmAberto(PDO $pdo, ?int $clienteId = null): float
{
    $stmt = $pdo->prepare('SELECT COALESCE(SUM(valor - valor_utilizado), 0) FROM adiantamentos' . ($clienteId ? ' WHERE cliente_id = ?' : ''));
    $stmt->execute($clienteId ? [$clienteId] : []);
    return (float) $stmt->fetchColumn();
}

/**
 * Quanto o cliente me deve hoje: títulos que ele passou e ainda não foram
 * pagos (inclui devolvidos e residuais) + adiantamentos em aberto.
 */
function creditoEmUso(PDO $pdo, int $clienteId): float
{
    $stmt = $pdo->prepare("SELECT COALESCE(SUM(valor), 0) FROM contas_receber WHERE cliente_id = ? AND status IN ('pendente', 'devolvido')");
    $stmt->execute([$clienteId]);
    return round((float) $stmt->fetchColumn() + totalAdiantadoEmAberto($pdo, $clienteId), 2);
}

function registrarAdiantamento(PDO $pdo, int $clienteId, string $data, float $valor, ?int $contaId, string $descricao = '', ?int $operacaoOrigemId = null): int
{
    $pdo->prepare(
        'INSERT INTO adiantamentos (cliente_id, data, valor, conta_id, descricao, operacao_origem_id) VALUES (?, ?, ?, ?, ?, ?)'
    )->execute([$clienteId, $data, round($valor, 2), $contaId, $descricao ?: null, $operacaoOrigemId]);
    $id = (int) $pdo->lastInsertId();

    if ($contaId) {
        $stmt = $pdo->prepare('SELECT nome FROM pessoas WHERE id = ?');
        $stmt->execute([$clienteId]);
        lancarMovimentacao($pdo, $contaId, $data, 'saida', $valor, 'Repasse a ' . $stmt->fetchColumn() . ($descricao ? ' · ' . $descricao : ''), 'adiantamento', $id);
    }
    return $id;
}

/**
 * Grava a operação. $dados:
 *   cliente_id, data_base, taxa, dias_compensacao, observacao,
 *   titulos[]: tipo, emitente_nome, emitente_documento, banco_titulo, numero_titulo, valor, vencimento, conta_destino_id, observacao
 *   adiantamentos[]: id, valor_usado
 *   pagamento_cliente ('pago' | 'agendado'), data_pagamento_cliente, conta_pagamento_id
 *   (diferença a favor do cliente: paga agora ou agendada como conta a pagar)
 *   residual_modo ('receber' | 'adiantamento'), residual_vencimento — quando os
 *   títulos não cobrem as despesas: vira conta a receber do cliente ou fica
 *   como saldo devedor para puxar na próxima operação.
 * Lança exceção (InvalidArgumentException) com a mensagem para o usuário.
 */
function salvarOperacao(PDO $pdo, array $dados): int
{
    $clienteId = (int) $dados['cliente_id'];
    $dataBase = $dados['data_base'];
    $taxa = (float) $dados['taxa'];
    $diasComp = max(0, (int) $dados['dias_compensacao']);

    if (!$clienteId) {
        throw new InvalidArgumentException('Informe o cliente.');
    }
    if (!$dataBase) {
        throw new InvalidArgumentException('Informe a data base do cálculo.');
    }
    if ($taxa < 0 || $taxa > 100) {
        throw new InvalidArgumentException('Taxa inválida.');
    }

    $titulos = [];
    foreach ($dados['titulos'] as $i => $t) {
        if ($t['valor'] <= 0 && !$t['vencimento']) {
            continue; // linha em branco
        }
        if ($t['valor'] <= 0 || !$t['vencimento']) {
            throw new InvalidArgumentException('Título ' . ($i + 1) . ': informe valor e vencimento.');
        }
        if (!array_key_exists($t['tipo'], tiposTitulo())) {
            $t['tipo'] = 'outro';
        }
        $titulos[] = $t;
    }
    if (!$titulos) {
        throw new InvalidArgumentException('Inclua pelo menos um título (cheque, boleto, promissória...).');
    }

    $pdo->beginTransaction();
    try {
        // Confere os adiantamentos escolhidos (do mesmo cliente, com saldo).
        $abertos = [];
        foreach (adiantamentosEmAberto($pdo, $clienteId) as $a) {
            $abertos[(int) $a['id']] = $a;
        }
        $adiantamentos = [];
        foreach ($dados['adiantamentos'] as $a) {
            $id = (int) $a['id'];
            $usado = round((float) $a['valor_usado'], 2);
            if ($usado <= 0) {
                continue;
            }
            if (!isset($abertos[$id])) {
                throw new InvalidArgumentException('Um dos repasses escolhidos não é deste cliente ou já foi usado.');
            }
            if ($usado > (float) $abertos[$id]['em_aberto'] + 0.004) {
                throw new InvalidArgumentException('Repasse de ' . formatDate($abertos[$id]['data']) . ': em aberto só ' . formatMoney($abertos[$id]['em_aberto']) . '.');
            }
            $adiantamentos[] = ['id' => $id, 'data' => $abertos[$id]['data'], 'valor_usado' => $usado];
        }

        $calc = calcularOperacao($dataBase, $taxa, $diasComp, $titulos, $adiantamentos);
        $tot = $calc['totais'];

        // Diferença a favor do cliente: paga agora (sai da conta) ou agendada (conta a pagar).
        $modoPagamento = $tot['saldo'] > 0 ? ($dados['pagamento_cliente'] ?? (!empty($dados['pagar_diferenca']) ? 'pago' : '')) : '';
        $contaPagamento = $modoPagamento ? idOuNull($dados['conta_pagamento_id'] ?? null) : null;
        $pagarDiferenca = $modoPagamento === 'pago';
        if ($pagarDiferenca && !$contaPagamento) {
            throw new InvalidArgumentException('Escolha a conta de onde sai o valor pago ao cliente.');
        }
        if ($modoPagamento === 'agendado' && empty($dados['data_pagamento_cliente'])) {
            throw new InvalidArgumentException('Informe a data prevista para pagar o cliente.');
        }

        $pdo->prepare(
            'INSERT INTO operacoes (cliente_id, data_base, taxa, dias_compensacao, valor_emprestado, juros_emprestimo, emprestado_atualizado,
                                    total_bruto, total_juros, total_liquido, saldo, valor_pago_cliente, conta_pagamento_id, observacao, usuario_id)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)'
        )->execute([
            $clienteId, $dataBase, $taxa, $diasComp, $tot['valor_emprestado'], $tot['juros_emprestimo'], $tot['emprestado_atualizado'],
            $tot['total_bruto'], $tot['total_juros'], $tot['total_liquido'], $tot['saldo'],
            0, null, ($dados['observacao'] ?? '') ?: null, usuarioAtualId(),
        ]);
        $operacaoId = (int) $pdo->lastInsertId();

        $stmt = $pdo->prepare('SELECT nome, documento FROM pessoas WHERE id = ?');
        $stmt->execute([$clienteId]);
        $cliente = $stmt->fetch();

        $idsTitulos = [];
        $insTitulo = $pdo->prepare(
            "INSERT INTO contas_receber (operacao_id, tipo, emitente_id, cliente_id, categoria_id, banco_titulo, numero_titulo, valor, data_vencimento,
                                         dias, juros, valor_liquido, conta_destino_id, observacao)
             VALUES (?, ?, ?, ?, (SELECT id FROM categorias WHERE nome = 'Desconto de títulos' LIMIT 1), ?, ?, ?, ?, ?, ?, ?, ?, ?)"
        );
        foreach ($calc['titulos'] as $t) {
            // Pagador em branco = cheque do próprio cliente: usa (ou cria) o
            // cadastro de pagador dele — cliente e pagador são cadastros separados.
            $emitenteId = obterOuCriarPessoa($pdo, $t['emitente_nome'], $t['emitente_documento'], 'pagador')
                ?? obterOuCriarPessoa($pdo, $cliente['nome'], (string) $cliente['documento'], 'pagador');
            $insTitulo->execute([
                $operacaoId, $t['tipo'], $emitenteId, $clienteId, $t['banco_titulo'] ?: null, $t['numero_titulo'] ?: null,
                $t['valor'], $t['vencimento'], $t['dias'], $t['juros'], $t['valor_liquido'], idOuNull($t['conta_destino_id']), $t['observacao'] ?: null,
            ]);
            $idsTitulos[] = (int) $pdo->lastInsertId();
        }
        copiarNossoNumero($pdo, $idsTitulos);

        $insUso = $pdo->prepare('INSERT INTO operacao_adiantamentos (operacao_id, adiantamento_id, valor_usado, dias, juros) VALUES (?, ?, ?, ?, ?)');
        $updUso = $pdo->prepare('UPDATE adiantamentos SET valor_utilizado = valor_utilizado + ? WHERE id = ?');
        foreach ($calc['adiantamentos'] as $a) {
            $insUso->execute([$operacaoId, $a['id'], $a['valor_usado'], $a['dias'], $a['juros']]);
            $updUso->execute([$a['valor_usado'], $a['id']]);
        }

        $stmt = $pdo->prepare('SELECT nome FROM pessoas WHERE id = ?');
        $stmt->execute([$clienteId]);
        $nomeCliente = (string) $stmt->fetchColumn();

        if ($modoPagamento) {
            registrarPagamentoCliente($pdo, $operacaoId, $modoPagamento, ($dados['data_pagamento_cliente'] ?? '') ?: $dataBase, $contaPagamento);
        }

        // Títulos não cobriram o que foi adiantado: o residual vira conta a
        // receber do cliente (sai junto se a operação for excluída) ou um
        // adiantamento em aberto, sem sair dinheiro de conta.
        if ($tot['saldo'] < 0) {
            if (($dados['residual_modo'] ?? 'receber') === 'adiantamento') {
                registrarAdiantamento($pdo, $clienteId, $dataBase, -$tot['saldo'], null, "Saldo devedor da operação #{$operacaoId}", $operacaoId);
            } else {
                $vencResidual = $dados['residual_vencimento'] ?: date('Y-m-d', strtotime($dataBase . ' +30 days'));
                $pdo->prepare(
                    "INSERT INTO contas_receber (operacao_id, tipo, emitente_id, cliente_id, descricao, categoria_id, valor, data_vencimento)
                     VALUES (?, 'outro', ?, ?, ?, (SELECT id FROM categorias WHERE nome = 'Desconto de títulos' LIMIT 1), ?, ?)"
                )->execute([$operacaoId, $clienteId, $clienteId, "Residual da operação #{$operacaoId}", -$tot['saldo'], $vencResidual]);
            }
        }

        $pdo->commit();
        return $operacaoId;
    } catch (Throwable $e) {
        $pdo->rollBack();
        throw $e;
    }
}

/**
 * Diferença da operação a pagar ao cliente. $modo 'pago' = saiu agora da
 * conta (lança no extrato na $data); 'agendado' = vira conta a pagar ao
 * cliente vencendo em $data (paga depois em Contas a pagar).
 */
function registrarPagamentoCliente(PDO $pdo, int $operacaoId, string $modo, string $data, ?int $contaId): void
{
    $stmt = $pdo->prepare('SELECT o.*, p.nome AS cliente_nome FROM operacoes o JOIN pessoas p ON p.id = o.cliente_id WHERE o.id = ?');
    $stmt->execute([$operacaoId]);
    $op = $stmt->fetch();
    $situacao = pagamentoClienteOperacao($pdo, $op);
    if ($situacao['situacao'] !== 'nao_lancado') {
        throw new InvalidArgumentException('O pagamento ao cliente desta operação já foi lançado ou agendado.');
    }
    $valor = round((float) $op['saldo'], 2);
    if ($modo === 'pago') {
        if (!$contaId) {
            throw new InvalidArgumentException('Escolha a conta de onde sai o valor pago ao cliente.');
        }
        lancarMovimentacao($pdo, $contaId, $data, 'saida', $valor, "Operação #{$operacaoId} · pago a {$op['cliente_nome']}", 'operacao', $operacaoId);
        $pdo->prepare('UPDATE operacoes SET valor_pago_cliente = ?, conta_pagamento_id = ? WHERE id = ?')->execute([$valor, $contaId, $operacaoId]);
    } elseif ($modo === 'agendado') {
        $pdo->prepare('INSERT INTO contas_pagar (descricao, pessoa_id, operacao_id, valor, data_vencimento, conta_id, observacao) VALUES (?, ?, ?, ?, ?, ?, ?)')
            ->execute([mb_substr("Operação #{$operacaoId} · pagar a {$op['cliente_nome']}", 0, 200), $op['cliente_id'], $operacaoId, $valor, $data, $contaId,
                'Diferença da operação #' . $operacaoId . ' de ' . formatDate($op['data_base']) . ' a pagar ao cliente.']);
    } else {
        throw new InvalidArgumentException('Escolha se o cliente já foi pago ou se o pagamento fica agendado.');
    }
}

/**
 * Como está o pagamento da diferença ao cliente:
 * situacao: sem_diferenca | nao_lancado | pago | agendado | parcial;
 * conta_pagar (a conta a pagar agendada, se houver), pago (valor), data.
 */
function pagamentoClienteOperacao(PDO $pdo, array $op): array
{
    if ((float) $op['saldo'] <= 0) {
        return ['situacao' => 'sem_diferenca', 'conta_pagar' => null, 'pago' => 0.0, 'data' => null];
    }
    if ((float) $op['valor_pago_cliente'] > 0) {
        $stmt = $pdo->prepare("SELECT MAX(data) FROM movimentacoes WHERE origem = 'operacao' AND origem_id = ?");
        $stmt->execute([$op['id']]);
        return ['situacao' => 'pago', 'conta_pagar' => null, 'pago' => (float) $op['valor_pago_cliente'], 'data' => $stmt->fetchColumn() ?: $op['data_base']];
    }
    $stmt = $pdo->prepare('SELECT cp.*, c.nome AS conta_nome FROM contas_pagar cp LEFT JOIN contas_bancarias c ON c.id = cp.conta_id WHERE cp.operacao_id = ? ORDER BY cp.id DESC LIMIT 1');
    $stmt->execute([$op['id']]);
    $cp = $stmt->fetch() ?: null;
    if (!$cp) {
        return ['situacao' => 'nao_lancado', 'conta_pagar' => null, 'pago' => 0.0, 'data' => null];
    }
    $pago = (float) $cp['valor_pago'];
    $situacao = $cp['status'] === 'pago' ? 'pago' : ($pago > 0 ? 'parcial' : 'agendado');
    return ['situacao' => $situacao, 'conta_pagar' => $cp, 'pago' => $pago, 'data' => $cp['status'] === 'pago' ? $cp['data_pagamento'] : $cp['data_vencimento']];
}

/** Volta o pagamento ao cliente para "não lançado" (foi marcado como pago/agendado por engano). */
function desfazerPagamentoCliente(PDO $pdo, int $operacaoId): void
{
    $stmt = $pdo->prepare('SELECT id FROM contas_pagar WHERE operacao_id = ? AND (valor_pago > 0 OR status = \'pago\')');
    $stmt->execute([$operacaoId]);
    if ($cpId = $stmt->fetchColumn()) {
        throw new InvalidArgumentException('O pagamento agendado (conta a pagar #' . $cpId . ') já tem valor pago: estorne lá em Contas a pagar antes.');
    }
    $pdo->prepare('DELETE FROM contas_pagar WHERE operacao_id = ?')->execute([$operacaoId]);
    removerMovimentacoes($pdo, 'operacao', $operacaoId);
    $pdo->prepare('UPDATE operacoes SET valor_pago_cliente = 0, conta_pagamento_id = NULL WHERE id = ?')->execute([$operacaoId]);
}

/** Desfaz a operação inteira — só enquanto nenhum título foi recebido/devolvido. */
function excluirOperacao(PDO $pdo, int $operacaoId): void
{
    $stmt = $pdo->prepare("SELECT COUNT(*) FROM contas_receber WHERE operacao_id = ? AND (status IN ('recebido', 'devolvido') OR vezes_devolvido > 0)");
    $stmt->execute([$operacaoId]);
    if ((int) $stmt->fetchColumn() > 0) {
        throw new InvalidArgumentException('Esta operação já tem título recebido ou devolvido. Estorne esses títulos antes de excluir.');
    }
    $stmt = $pdo->prepare('SELECT id, valor_utilizado FROM adiantamentos WHERE operacao_origem_id = ?');
    $stmt->execute([$operacaoId]);
    $sobra = $stmt->fetch();
    if ($sobra && (float) $sobra['valor_utilizado'] > 0) {
        throw new InvalidArgumentException('O saldo devedor desta operação já foi usado em outra operação. Exclua aquela primeiro.');
    }
    $stmt = $pdo->prepare("SELECT id FROM contas_pagar WHERE operacao_id = ? AND (valor_pago > 0 OR status = 'pago')");
    $stmt->execute([$operacaoId]);
    if ($cpId = $stmt->fetchColumn()) {
        throw new InvalidArgumentException('O pagamento ao cliente desta operação (conta a pagar #' . $cpId . ') já foi pago. Estorne em Contas a pagar antes de excluir.');
    }

    $pdo->beginTransaction();
    try {
        $stmt = $pdo->prepare('SELECT adiantamento_id, valor_usado FROM operacao_adiantamentos WHERE operacao_id = ?');
        $stmt->execute([$operacaoId]);
        $upd = $pdo->prepare('UPDATE adiantamentos SET valor_utilizado = GREATEST(0, valor_utilizado - ?) WHERE id = ?');
        foreach ($stmt->fetchAll() as $u) {
            $upd->execute([$u['valor_usado'], $u['adiantamento_id']]);
        }
        if ($sobra) {
            $pdo->prepare('DELETE FROM adiantamentos WHERE id = ?')->execute([$sobra['id']]);
        }
        removerMovimentacoes($pdo, 'operacao', $operacaoId);
        $pdo->prepare('DELETE FROM contas_pagar WHERE operacao_id = ?')->execute([$operacaoId]);
        $pdo->prepare('DELETE FROM operacoes WHERE id = ?')->execute([$operacaoId]);
        $pdo->commit();
    } catch (Throwable $e) {
        $pdo->rollBack();
        throw $e;
    }
}

/** Refaz dias/juros/líquido de um título da operação (depois de mudar valor ou vencimento). */
function recalcularTituloOperacao(PDO $pdo, int $tituloId): void
{
    $stmt = $pdo->prepare(
        'SELECT r.id, r.valor, r.data_vencimento, o.data_base, o.taxa, o.dias_compensacao
         FROM contas_receber r JOIN operacoes o ON o.id = r.operacao_id WHERE r.id = ? AND r.juros IS NOT NULL'
    );
    $stmt->execute([$tituloId]);
    if (!$t = $stmt->fetch()) {
        return;
    }
    $dias = max(0, diasEntre($t['data_base'], $t['data_vencimento']) + (int) $t['dias_compensacao']);
    $juros = jurosSimples((float) $t['valor'], (float) $t['taxa'], $dias);
    $pdo->prepare('UPDATE contas_receber SET dias = ?, juros = ?, valor_liquido = ? WHERE id = ?')
        ->execute([$dias, $juros, round($t['valor'] - $juros, 2), $tituloId]);
}

/**
 * Refaz os totais da operação a partir dos títulos que ela tem hoje. Não
 * mexe no que já saiu de conta (diferença paga) nem no residual lançado.
 */
function recalcularTotaisOperacao(PDO $pdo, int $operacaoId): void
{
    $pdo->prepare(
        'UPDATE operacoes o JOIN (
            SELECT operacao_id, SUM(valor) AS bruto, SUM(juros) AS juros, SUM(valor_liquido) AS liquido
            FROM contas_receber WHERE operacao_id = ? AND juros IS NOT NULL GROUP BY operacao_id
         ) t ON t.operacao_id = o.id
         SET o.total_bruto = t.bruto, o.total_juros = t.juros, o.total_liquido = t.liquido, o.saldo = t.liquido - o.emprestado_atualizado'
    )->execute([$operacaoId]);
    ajustarResidualOperacao($pdo, $operacaoId);
}

/**
 * Depois de recalcular a operação, o que o cliente ficou devendo (saldo
 * devedor ou residual a receber) acompanha o novo saldo — enquanto não foi
 * usado numa operação nem recebido. Assim o extrato do cliente fecha.
 */
function ajustarResidualOperacao(PDO $pdo, int $operacaoId): void
{
    $stmt = $pdo->prepare('SELECT saldo FROM operacoes WHERE id = ?');
    $stmt->execute([$operacaoId]);
    $deve = round(-(float) $stmt->fetchColumn(), 2);

    $stmt = $pdo->prepare('SELECT id, valor_utilizado FROM adiantamentos WHERE operacao_origem_id = ?');
    $stmt->execute([$operacaoId]);
    if (($sobra = $stmt->fetch()) && (float) $sobra['valor_utilizado'] == 0) {
        $deve > 0.004
            ? $pdo->prepare('UPDATE adiantamentos SET valor = ? WHERE id = ?')->execute([$deve, $sobra['id']])
            : $pdo->prepare('DELETE FROM adiantamentos WHERE id = ?')->execute([$sobra['id']]);
    }

    $stmt = $pdo->prepare("SELECT id FROM contas_receber WHERE operacao_id = ? AND juros IS NULL AND status = 'pendente'");
    $stmt->execute([$operacaoId]);
    if ($residualId = $stmt->fetchColumn()) {
        $deve > 0.004
            ? $pdo->prepare('UPDATE contas_receber SET valor = ? WHERE id = ?')->execute([$deve, $residualId])
            : $pdo->prepare('DELETE FROM contas_receber WHERE id = ?')->execute([$residualId]);
    }
}

/**
 * Muda data base / taxa / dias de compensação da operação e refaz a conta de
 * tudo: juros de cada título, correção de cada repasse usado e os totais.
 * O que já saiu de conta (diferença paga) e o residual lançado não mudam.
 */
function editarOperacao(PDO $pdo, int $operacaoId, string $dataBase, float $taxa, int $diasCompensacao, string $observacao): void
{
    $pdo->prepare('UPDATE operacoes SET data_base = ?, taxa = ?, dias_compensacao = ?, observacao = ? WHERE id = ?')
        ->execute([$dataBase, $taxa, max(0, $diasCompensacao), $observacao ?: null, $operacaoId]);

    $stmt = $pdo->prepare('SELECT id FROM contas_receber WHERE operacao_id = ? AND juros IS NOT NULL');
    $stmt->execute([$operacaoId]);
    foreach ($stmt->fetchAll(PDO::FETCH_COLUMN) as $tituloId) {
        recalcularTituloOperacao($pdo, (int) $tituloId);
    }

    $stmt = $pdo->prepare('SELECT oa.id, oa.valor_usado, a.data FROM operacao_adiantamentos oa JOIN adiantamentos a ON a.id = oa.adiantamento_id WHERE oa.operacao_id = ?');
    $stmt->execute([$operacaoId]);
    $upd = $pdo->prepare('UPDATE operacao_adiantamentos SET dias = ?, juros = ? WHERE id = ?');
    $usado = $juros = 0.0;
    foreach ($stmt->fetchAll() as $u) {
        $dias = max(0, diasEntre($u['data'], $dataBase));
        $j = jurosSimples((float) $u['valor_usado'], $taxa, $dias);
        $upd->execute([$dias, $j, $u['id']]);
        $usado += (float) $u['valor_usado'];
        $juros += $j;
    }
    $pdo->prepare('UPDATE operacoes SET valor_emprestado = ?, juros_emprestimo = ?, emprestado_atualizado = ? WHERE id = ?')
        ->execute([round($usado, 2), round($juros, 2), round($usado + $juros, 2), $operacaoId]);

    recalcularTotaisOperacao($pdo, $operacaoId);

    // Pagamento ao cliente ainda só agendado (nada pago): acompanha a nova diferença.
    $stmt = $pdo->prepare('SELECT saldo FROM operacoes WHERE id = ?');
    $stmt->execute([$operacaoId]);
    $saldo = round((float) $stmt->fetchColumn(), 2);
    if ($saldo > 0) {
        $pdo->prepare("UPDATE contas_pagar SET valor = ? WHERE operacao_id = ? AND status = 'pendente' AND COALESCE(valor_pago, 0) = 0")->execute([$saldo, $operacaoId]);
    } else {
        $pdo->prepare("DELETE FROM contas_pagar WHERE operacao_id = ? AND status = 'pendente' AND COALESCE(valor_pago, 0) = 0")->execute([$operacaoId]);
    }
}
