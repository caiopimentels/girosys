<?php

/** Lança entrada/saída no extrato da conta. Devolve o id do lançamento. */
function lancarMovimentacao(PDO $pdo, int $contaId, string $data, string $tipo, float $valor, string $descricao, string $origem, ?int $origemId = null): int
{
    $pdo->prepare(
        'INSERT INTO movimentacoes (conta_id, data, tipo, valor, descricao, origem, origem_id) VALUES (?, ?, ?, ?, ?, ?, ?)'
    )->execute([$contaId, $data, $tipo, round($valor, 2), mb_substr($descricao, 0, 255), $origem, $origemId]);
    return (int) $pdo->lastInsertId();
}

/** Apaga os lançamentos de extrato de uma origem (estorno). */
function removerMovimentacoes(PDO $pdo, string $origem, int $origemId): void
{
    $pdo->prepare('DELETE FROM movimentacoes WHERE origem = ? AND origem_id = ?')->execute([$origem, $origemId]);
}

/**
 * Contas com saldo. $ate = considera só lançamentos até essa data (inclusive);
 * sem ela, o saldo de hoje incluindo lançamentos com data futura.
 */
function contasComSaldo(PDO $pdo, bool $somenteAtivas = true, ?string $ate = null): array
{
    $filtroData = $ate ? 'AND m.data <= ' . $pdo->quote($ate) : '';
    $sql = "SELECT c.*,
                   c.saldo_inicial
                   + COALESCE(SUM(CASE WHEN m.tipo = 'entrada' THEN m.valor END), 0)
                   - COALESCE(SUM(CASE WHEN m.tipo = 'saida' THEN m.valor END), 0) AS saldo
            FROM contas_bancarias c
            LEFT JOIN movimentacoes m ON m.conta_id = c.id {$filtroData}
            " . ($somenteAtivas ? 'WHERE c.ativo = 1' : '') . '
            GROUP BY c.id
            ORDER BY c.ativo DESC, c.nome';
    return $pdo->query($sql)->fetchAll();
}

function saldoGlobal(PDO $pdo): float
{
    return array_sum(array_map(fn ($c) => (float) $c['saldo'], contasComSaldo($pdo)));
}

/** Tipos de recebimento (Cadastros › Tipos de recebimento): chave => nome. Sem $todos, só os ativos. */
function tiposTitulo(bool $todos = false): array
{
    static $cache = null;
    $cache ??= getDB()->query('SELECT tipo, nome, ativo FROM tipos_recebimento ORDER BY ordem, nome')->fetchAll();
    $tipos = [];
    foreach ($cache as $t) {
        if ($todos || $t['ativo']) {
            $tipos[$t['tipo']] = $t['nome'];
        }
    }
    return $tipos;
}

function tipoTituloLabel(string $tipo): string
{
    return tiposTitulo(true)[$tipo] ?? ucfirst($tipo);
}

/** Tipos ativos + o do título (se foi desativado depois), para os campos de edição. */
function tiposParaCampo(?string $atual = null): array
{
    $tipos = tiposTitulo();
    if ($atual !== null && !isset($tipos[$atual])) {
        $tipos[$atual] = tipoTituloLabel($atual) . ' (inativo)';
    }
    return $tipos;
}

/** Status para mostrar: pendente com vencimento passado vira "vencido". */
function statusTelaReceber(array $c): string
{
    if ($c['status'] === 'pendente' && $c['data_vencimento'] < date('Y-m-d')) {
        return 'vencido';
    }
    return $c['status'];
}

function statusTelaPagar(array $c): string
{
    if ($c['status'] === 'pendente' && $c['data_vencimento'] < date('Y-m-d')) {
        return 'vencido';
    }
    if ($c['status'] === 'pendente' && (float) ($c['valor_pago'] ?? 0) > 0) {
        return 'parcial';
    }
    return $c['status'];
}

/** Situação com os dias de atraso: "Vencido há 32 dias", "Devolvido há 32 dias". */
function badgeStatusAtraso(string $status, int $dias): string
{
    if ($dias > 0 && in_array($status, ['vencido', 'devolvido'], true)) {
        return '<span class="badge text-bg-danger">' . ($status === 'vencido' ? 'Vencido' : 'Devolvido') . ' há ' . $dias . ' dia' . ($dias > 1 ? 's' : '') . '</span>';
    }
    return badgeStatus($status);
}

function badgeStatus(string $status): string
{
    [$classe, $rotulo] = match ($status) {
        'pendente' => ['text-bg-warning', 'Pendente'],
        'vencido' => ['text-bg-danger', 'Vencido'],
        'recebido' => ['text-bg-success', 'Recebido'],
        'pago', 'paga' => ['text-bg-success', 'Pago'],
        'devolvido' => ['text-bg-danger', 'Devolvido'],
        'cancelado' => ['text-bg-secondary', 'Cancelado'],
        'transferido' => ['text-bg-secondary', 'Devolvido ao cliente'],
        'aberta' => ['text-bg-warning', 'Em aberto'],
        'parcial' => ['text-bg-info', 'Pago em parte'],
        default => ['text-bg-light', ucfirst($status)],
    };
    return '<span class="badge ' . $classe . '">' . $rotulo . '</span>';
}

// ---------------------------------------------------------------
// Baixas de contas a receber e a pagar
// ---------------------------------------------------------------

function baixarReceber(PDO $pdo, array $titulo, string $data, float $valor, int $contaId): void
{
    $pdo->prepare(
        "UPDATE contas_receber SET status = 'recebido', data_recebimento = ?, valor_recebido = ?, acrescimo = ?, conta_recebimento_id = ? WHERE id = ?"
    )->execute([$data, $valor, $valor > $titulo['valor'] ? round($valor - $titulo['valor'], 2) : null, $contaId, $titulo['id']]);
    lancarMovimentacao($pdo, $contaId, $data, 'entrada', $valor, 'Recebimento: ' . descricaoReceber($pdo, $titulo), 'receber', (int) $titulo['id']);
}

/** Volta o título para pendente (ou devolvido, se já tinha voltado antes) e tira do extrato. */
function estornarReceber(PDO $pdo, array $titulo): void
{
    removerMovimentacoes($pdo, 'receber', (int) $titulo['id']);
    $pdo->prepare(
        'UPDATE contas_receber SET status = ?, data_recebimento = NULL, valor_recebido = NULL, acrescimo = NULL, conta_recebimento_id = NULL WHERE id = ?'
    )->execute([$titulo['vezes_devolvido'] > 0 ? 'devolvido' : 'pendente', $titulo['id']]);
}

/**
 * Marca devolvido — de novo também (cheque reapresentado que volta, alínea
 * 12): cada devolução fica no motivo com a data ("11 em 31/12/2026; 12 em ...").
 */
function devolverReceber(PDO $pdo, array $titulo, string $data, string $motivo): void
{
    $registro = trim(($motivo ?: 'sem motivo') . ' em ' . formatDate($data));
    $historico = $titulo['motivo_devolucao'] ? $titulo['motivo_devolucao'] . '; ' . $registro : $registro;
    $pdo->prepare(
        "UPDATE contas_receber SET status = 'devolvido', devolvido_em = ?, motivo_devolucao = ?, vezes_devolvido = vezes_devolvido + 1 WHERE id = ?"
    )->execute([$data, mb_substr($historico, 0, 200), $titulo['id']]);
}

/** Alíneas de devolução de cheque mais comuns (para a lista do campo motivo). */
function alineasDevolucao(): array
{
    return [
        '11' => 'Sem fundos – 1ª apresentação', '12' => 'Sem fundos – 2ª apresentação', '13' => 'Conta encerrada', '14' => 'Prática espúria',
        '20' => 'Folha cancelada por solicitação do correntista', '21' => 'Contra-ordem / oposição ao pagamento', '22' => 'Divergência ou insuficiência de assinatura',
        '28' => 'Contra-ordem por furto ou roubo', '31' => 'Erro formal de preenchimento', '35' => 'Cheque fraudado', '43' => 'Cheque devolvido anteriormente (21, 22, 23...)',
        '44' => 'Cheque prescrito', '48' => 'Cheque acima de R$ 100 sem nominal', '49' => 'Remessa nula',
    ];
}

/**
 * Devolve o título para o cliente: sai do contas a receber (transferido) e
 * vira um repasse em aberto dele, sem dinheiro saindo de conta — como se eu
 * tivesse passado esse valor a ele. Corrige pela taxa e entra na próxima operação.
 */
function transferirParaCliente(PDO $pdo, array $titulo, string $data, float $valor): int
{
    if (empty($titulo['cliente_id'])) {
        throw new InvalidArgumentException('Este título não tem cliente para quem devolver.');
    }
    if ($valor <= 0) {
        throw new InvalidArgumentException('Informe o valor que fica com o cliente.');
    }
    $descricao = tipoTituloLabel($titulo['tipo']) . ' devolvido' . ($titulo['numero_titulo'] ? ' nº ' . $titulo['numero_titulo'] : '')
        . (!empty($titulo['emitente_nome']) ? ' · ' . $titulo['emitente_nome'] : '');
    $repasseId = registrarAdiantamento($pdo, (int) $titulo['cliente_id'], $data, $valor, null, $descricao);
    $pdo->prepare('UPDATE adiantamentos SET titulo_origem_id = ? WHERE id = ?')->execute([$titulo['id'], $repasseId]);
    $pdo->prepare("UPDATE contas_receber SET status = 'transferido', data_recebimento = ?, valor_recebido = ? WHERE id = ?")
        ->execute([$data, round($valor, 2), $titulo['id']]);
    return $repasseId;
}

/** Desfaz a devolução para o cliente, enquanto o repasse não entrou numa operação. */
function desfazerTransferencia(PDO $pdo, array $titulo): void
{
    $stmt = $pdo->prepare('SELECT * FROM adiantamentos WHERE titulo_origem_id = ?');
    $stmt->execute([$titulo['id']]);
    $repasse = $stmt->fetch();
    if ($repasse && (float) $repasse['valor_utilizado'] > 0) {
        throw new InvalidArgumentException('O repasse gerado já entrou numa operação. Exclua aquela operação primeiro.');
    }
    if ($repasse) {
        $pdo->prepare('DELETE FROM adiantamentos WHERE id = ?')->execute([$repasse['id']]);
    }
    $pdo->prepare('UPDATE contas_receber SET status = ?, data_recebimento = NULL, valor_recebido = NULL WHERE id = ?')
        ->execute([$titulo['vezes_devolvido'] > 0 ? 'devolvido' : 'pendente', $titulo['id']]);
}

function descricaoReceber(PDO $pdo, array $titulo): string
{
    $partes = [tipoTituloLabel($titulo['tipo'])];
    if (!empty($titulo['numero_titulo'])) {
        $partes[] = 'nº ' . $titulo['numero_titulo'];
    }
    if (!empty($titulo['emitente_id'])) {
        $stmt = $pdo->prepare('SELECT nome FROM pessoas WHERE id = ?');
        $stmt->execute([$titulo['emitente_id']]);
        $partes[] = (string) $stmt->fetchColumn();
    }
    if (!empty($titulo['descricao'])) {
        $partes[] = $titulo['descricao'];
    }
    return implode(' · ', $partes);
}

// ---------------------------------------------------------------
// Pagamentos de despesas (origem 'pagar') e faturas de cartão ('fatura').
// Cada parte paga é uma saída no extrato da conta de onde saiu: dá para
// pagar em partes, de contas diferentes, e estornar cada parte sozinha.
// O "pago" é sempre a soma dessas saídas.
// ---------------------------------------------------------------

/** Valor total devido: o valor da despesa ou a soma das parcelas da fatura. */
function totalDevido(PDO $pdo, string $origem, int $id): float
{
    if ($origem === 'fatura') {
        return totalFatura($pdo, $id);
    }
    $stmt = $pdo->prepare('SELECT valor FROM contas_pagar WHERE id = ?');
    $stmt->execute([$id]);
    return (float) $stmt->fetchColumn();
}

/** Partes já pagas (lançamentos no extrato), com o nome da conta. */
function pagamentosFeitos(PDO $pdo, string $origem, int $id): array
{
    $stmt = $pdo->prepare(
        'SELECT m.*, c.nome AS conta_nome FROM movimentacoes m JOIN contas_bancarias c ON c.id = m.conta_id
         WHERE m.origem = ? AND m.origem_id = ? ORDER BY m.data, m.id'
    );
    $stmt->execute([$origem, $id]);
    return $stmt->fetchAll();
}

/**
 * Lança as partes pagas agora. $partes: [['conta_id' => int, 'valor' => float], ...].
 * $quitar = dá como pago mesmo se a soma ficou menor (desconto/negociação).
 */
function registrarPagamentos(PDO $pdo, string $origem, int $id, string $descricao, string $data, array $partes, bool $quitar = false): void
{
    foreach ($partes as $p) {
        if ($p['valor'] > 0 && empty($p['conta_id'])) {
            throw new InvalidArgumentException('Escolha de qual conta sai cada parte do pagamento (' . formatMoney($p['valor']) . ' está sem conta).');
        }
    }
    $partes = array_filter($partes, fn ($p) => !empty($p['conta_id']) && $p['valor'] > 0);
    if (!$partes && !$quitar) {
        throw new InvalidArgumentException('Informe pelo menos uma conta e um valor.');
    }
    foreach ($partes as $p) {
        lancarMovimentacao($pdo, (int) $p['conta_id'], $data, 'saida', round($p['valor'], 2), 'Pagamento: ' . $descricao, $origem, $id);
    }
    atualizarSituacaoPagamento($pdo, $origem, $id, $quitar);
}

/** Recalcula pago/pendente a partir das partes lançadas no extrato. */
function atualizarSituacaoPagamento(PDO $pdo, string $origem, int $id, bool $quitar = false): void
{
    $stmt = $pdo->prepare(
        "SELECT COALESCE(SUM(valor), 0) AS pago, MAX(data) AS ultima,
                (SELECT conta_id FROM movimentacoes WHERE origem = ? AND origem_id = ? ORDER BY data DESC, id DESC LIMIT 1) AS ultima_conta
         FROM movimentacoes WHERE origem = ? AND origem_id = ?"
    );
    $stmt->execute([$origem, $id, $origem, $id]);
    $r = $stmt->fetch();
    $pago = round((float) $r['pago'], 2);
    $quitado = $quitar || ($pago > 0 && $pago >= totalDevido($pdo, $origem, $id) - 0.004);

    if ($origem === 'fatura') {
        $pdo->prepare('UPDATE cartao_faturas SET status = ?, valor_pago = ?, data_pagamento = ?, conta_id = ? WHERE id = ?')
            ->execute([$quitado ? 'paga' : 'aberta', $pago ?: null, $quitado ? ($r['ultima'] ?? date('Y-m-d')) : null, $r['ultima_conta'], $id]);
    } else {
        $pdo->prepare('UPDATE contas_pagar SET status = ?, valor_pago = ?, data_pagamento = ? WHERE id = ?')
            ->execute([$quitado ? 'pago' : 'pendente', $pago ?: null, $quitado ? ($r['ultima'] ?? date('Y-m-d')) : null, $id]);
    }
}

/** Estorna uma parte paga (um lançamento) de uma despesa/fatura. */
function estornarParte(PDO $pdo, string $origem, int $id, int $movimentacaoId): void
{
    $pdo->prepare('DELETE FROM movimentacoes WHERE id = ? AND origem = ? AND origem_id = ?')->execute([$movimentacaoId, $origem, $id]);
    atualizarSituacaoPagamento($pdo, $origem, $id);
}

/** Despesa já paga de uma vez (ex.: "já está paga" no lançamento). */
function pagarConta(PDO $pdo, array $conta, string $data, float $valor, int $contaBancariaId): void
{
    registrarPagamentos($pdo, 'pagar', (int) $conta['id'], $conta['descricao'], $data, [['conta_id' => $contaBancariaId, 'valor' => $valor]]);
}

/** Partes de pagamento enviadas pelo formulário (campos partes[i][conta_id] / partes[i][valor]). */
function partesPagamentoInput(): array
{
    $partes = [];
    foreach ((array) ($_POST['partes'] ?? []) as $p) {
        $partes[] = ['conta_id' => idOuNull($p['conta_id'] ?? null), 'valor' => paraDecimal($p['valor'] ?? '')];
    }
    return $partes;
}

// ---------------------------------------------------------------
// Histórico do emitente (o terceiro dono do cheque/título)
// ---------------------------------------------------------------

/**
 * Resumo de como a pessoa paga os títulos em que ela é a emitente.
 * classificacao: sem_historico | bom | atencao | risco
 */
function historicoEmitente(PDO $pdo, int $pessoaId): array
{
    $tolerancia = (int) TOLERANCIA_COMPENSACAO_DIAS;
    $stmt = $pdo->prepare(
        "SELECT
            COUNT(*) AS total,
            COALESCE(SUM(valor), 0) AS valor_total,
            SUM(status = 'recebido' AND vezes_devolvido = 0 AND data_recebimento <= data_vencimento + INTERVAL {$tolerancia} DAY) AS em_dia,
            SUM(status = 'recebido' AND vezes_devolvido = 0 AND data_recebimento > data_vencimento + INTERVAL {$tolerancia} DAY) AS com_atraso,
            SUM(vezes_devolvido > 0) AS devolvidos,
            SUM(status = 'devolvido') AS devolvidos_em_aberto,
            SUM(status = 'pendente' AND data_vencimento < CURDATE()) AS vencidos,
            SUM(status = 'pendente' AND data_vencimento >= CURDATE()) AS a_vencer,
            COALESCE(SUM(CASE WHEN status IN ('pendente', 'devolvido') THEN valor END), 0) AS valor_em_aberto
         FROM contas_receber
         WHERE emitente_id = ? AND status <> 'cancelado'"
    );
    $stmt->execute([$pessoaId]);
    $h = [];
    foreach ($stmt->fetch() as $k => $v) {
        $h[$k] = in_array($k, ['valor_total', 'valor_em_aberto'], true) ? (float) $v : (int) $v;
    }

    $liquidados = $h['em_dia'] + $h['com_atraso'] + $h['devolvidos'];
    if ($h['total'] === 0) {
        $h['classificacao'] = 'sem_historico';
    } elseif ($h['devolvidos_em_aberto'] > 0 || $h['vencidos'] > 0 || ($liquidados > 0 && $h['devolvidos'] / $liquidados > 0.2)) {
        $h['classificacao'] = 'risco';
    } elseif ($h['devolvidos'] > 0 || $h['com_atraso'] > 0) {
        $h['classificacao'] = 'atencao';
    } elseif ($liquidados === 0) {
        $h['classificacao'] = 'sem_historico';
    } else {
        $h['classificacao'] = 'bom';
    }
    return $h;
}

function classificacaoLabel(string $c): array
{
    return match ($c) {
        'bom' => ['Bom pagador', 'text-bg-success', 'bi-hand-thumbs-up'],
        'atencao' => ['Atenção', 'text-bg-warning', 'bi-exclamation-circle'],
        'risco' => ['Risco', 'text-bg-danger', 'bi-exclamation-octagon'],
        default => ['Sem histórico', 'text-bg-secondary', 'bi-dash-circle'],
    };
}

function badgeClassificacao(string $c): string
{
    [$rotulo, $classe, $icone] = classificacaoLabel($c);
    return '<span class="badge ' . $classe . '"><i class="bi ' . $icone . '"></i> ' . $rotulo . '</span>';
}

// ---------------------------------------------------------------
// Atualização de título vencido (juros/multa por atraso)
// ---------------------------------------------------------------

/** Regra de cada tipo de recebimento: tipo => [modo, multa_percent, juros_mes]. */
function configRecebimento(PDO $pdo): array
{
    static $cache = null;
    if ($cache === null) {
        $cache = [];
        foreach ($pdo->query('SELECT * FROM tipos_recebimento')->fetchAll() as $c) {
            $cache[$c['tipo']] = $c;
        }
    }
    return $cache;
}

function modosAtualizacao(): array
{
    return ['cliente' => 'Taxa do cliente', 'proprio' => 'Juros e multa próprios', 'nenhum' => 'Não atualizar'];
}

/**
 * Parâmetros que valem para este título: [multa %, juros % ao mês, de onde veio].
 * Modo "cliente" (ou tipo sem regra) usa a taxa do cliente da operação; sem
 * taxa no cliente, não atualiza.
 */
function regraAtualizacao(PDO $pdo, array $t): array
{
    $c = configRecebimento($pdo)[$t['tipo']] ?? ['modo' => 'cliente', 'multa_percent' => null, 'juros_mes' => null];
    if ($c['modo'] === 'nenhum') {
        return [0.0, 0.0, 'não atualiza'];
    }
    if ($c['modo'] === 'proprio') {
        return [(float) $c['multa_percent'], (float) $c['juros_mes'], 'regra do ' . mb_strtolower(tipoTituloLabel($t['tipo']))];
    }
    $taxa = $t['cliente_taxa'] ?? null;
    if (!array_key_exists('cliente_taxa', $t) && !empty($t['cliente_id'])) {
        $stmt = $pdo->prepare('SELECT taxa_padrao FROM pessoas WHERE id = ?');
        $stmt->execute([$t['cliente_id']]);
        $taxa = $stmt->fetchColumn();
    }
    return $taxa !== null && $taxa !== false ? [0.0, (float) $taxa, 'taxa do cliente'] : [0.0, 0.0, 'cliente sem taxa'];
}

/**
 * Valor do título atualizado até $data (hoje, se vazio): juros simples por
 * dia de atraso (mês de 30 dias, como no desconto) + multa uma vez.
 * Só para pendente/devolvido vencido; senão devolve o valor como está.
 */
function atualizacaoReceber(PDO $pdo, array $t, ?string $data = null): array
{
    $data ??= date('Y-m-d');
    $valor = (float) $t['valor'];
    $dias = in_array($t['status'], ['pendente', 'devolvido'], true) ? max(0, diasEntre($t['data_vencimento'], $data)) : 0;
    [$multaPct, $jurosMes, $origem] = regraAtualizacao($pdo, $t);
    $juros = jurosSimples($valor, $jurosMes, $dias);
    $multa = $dias > 0 ? round($valor * $multaPct / 100, 2) : 0.0;
    return [
        'dias' => $dias, 'juros' => $juros, 'multa' => $multa, 'total' => round($valor + $juros + $multa, 2),
        'multa_percent' => $multaPct, 'juros_mes' => $jurosMes, 'origem' => $origem,
    ];
}
