<?php

// Renegociação: títulos em aberto de um pagador viram novos títulos (outras
// datas/parcelas). Os juros e a multa até a data base vão para os novos
// títulos (o pagador paga), para o cliente (vira repasse em aberto dele) ou
// são isentos. Os antigos ficam "renegociados" no histórico.

function destinosEncargos(): array
{
    return [
        'pagador' => 'Nos novos boletos (o pagador paga)',
        'cliente' => 'Para o cliente (vira repasse em aberto dele)',
        'isento' => 'Isentar',
    ];
}

/** Títulos selecionados para renegociar, com o atualizado na data base. */
function titulosParaRenegociar(PDO $pdo, array $ids, string $dataBase): array
{
    $ids = array_values(array_unique(array_filter(array_map('intval', $ids))));
    if (!$ids) {
        return [];
    }
    $stmt = $pdo->prepare('SELECT r.*, e.nome AS emitente_nome, cl.nome AS cliente_nome, cl.taxa_padrao AS cliente_taxa
        FROM contas_receber r LEFT JOIN pessoas e ON e.id = r.emitente_id LEFT JOIN pessoas cl ON cl.id = r.cliente_id
        WHERE r.id IN (' . implode(',', array_fill(0, count($ids), '?')) . ') ORDER BY r.data_vencimento, r.id');
    $stmt->execute($ids);
    $titulos = $stmt->fetchAll();
    foreach ($titulos as &$t) {
        $t['atualizacao'] = atualizacaoReceber($pdo, $t, $dataBase);
    }
    unset($t);
    return $titulos;
}

/** Por que não dá para renegociar estes títulos (ou [] se dá). */
function problemasRenegociacao(array $titulos): array
{
    $problemas = [];
    if (!$titulos) {
        return ['Selecione pelo menos um título em aberto.'];
    }
    foreach ($titulos as $t) {
        if (!in_array($t['status'], ['pendente', 'devolvido'], true)) {
            $problemas[] = 'Título #' . $t['id'] . ' não está em aberto (' . $t['status'] . ').';
        }
    }
    if (count(array_unique(array_map(fn ($t) => (int) $t['emitente_id'], $titulos))) > 1) {
        $problemas[] = 'Os títulos são de pagadores diferentes — renegocie um pagador por vez.';
    }
    return $problemas;
}

/**
 * Grava a renegociação. $dados: data_base, destino, encargos, parcelas
 * [[vencimento, valor, numero]], tipo, conta_destino_id, descricao, observacao.
 * Devolve [id da renegociação, avisos[]].
 */
function renegociarTitulos(PDO $pdo, array $ids, array $dados): array
{
    $titulos = titulosParaRenegociar($pdo, $ids, $dados['data_base']);
    if ($problemas = problemasRenegociacao($titulos)) {
        throw new InvalidArgumentException(implode(' ', $problemas));
    }
    $destino = array_key_exists($dados['destino'] ?? '', destinosEncargos()) ? $dados['destino'] : 'isento';
    $encargos = round(max(0, (float) $dados['encargos']), 2);
    $original = round(array_sum(array_map(fn ($t) => (float) $t['valor'], $titulos)), 2);
    $totalNovo = round($original + ($destino === 'pagador' ? $encargos : 0), 2);
    $parcelas = array_values(array_filter($dados['parcelas'], fn ($p) => $p['vencimento'] || $p['valor'] > 0));
    if (!$parcelas) {
        throw new InvalidArgumentException('Informe as novas parcelas (vencimento e valor).');
    }
    foreach ($parcelas as $n => $p) {
        if (!$p['vencimento'] || $p['valor'] <= 0) {
            throw new InvalidArgumentException('Nova parcela ' . ($n + 1) . ': informe vencimento e valor.');
        }
    }
    $soma = round(array_sum(array_column($parcelas, 'valor')), 2);
    if (abs($soma - $totalNovo) > 0.009) {
        throw new InvalidArgumentException('As novas parcelas somam ' . formatMoney($soma) . ', mas o total a renegociar é ' . formatMoney($totalNovo)
            . ' (' . formatMoney($original) . ($destino === 'pagador' ? ' + encargos ' . formatMoney($encargos) : '') . '). Ajuste os valores.');
    }
    $clientes = array_values(array_unique(array_filter(array_map(fn ($t) => (int) $t['cliente_id'], $titulos))));
    $clienteId = count($clientes) === 1 ? $clientes[0] : null;
    if ($destino === 'cliente' && $encargos > 0) {
        if (!$clienteId || count(array_filter($titulos, fn ($t) => !$t['cliente_id']))) {
            throw new InvalidArgumentException('Para lançar os encargos para o cliente, todos os títulos precisam ser do mesmo cliente.');
        }
    }
    $primeiro = $titulos[0];
    $pagadorId = (int) $primeiro['emitente_id'] ?: null;

    $pdo->prepare('INSERT INTO renegociacoes (data, pagador_id, cliente_id, total_original, encargos, destino_encargos, total_novo, parcelas, observacao, usuario_id) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)')
        ->execute([$dados['data_base'], $pagadorId, $clienteId, $original, $encargos, $destino, $totalNovo, count($parcelas), $dados['observacao'] ?: null, usuarioAtualId()]);
    $renegId = (int) $pdo->lastInsertId();

    // Antigos: saem do em aberto e apontam para a renegociação.
    $marcar = $pdo->prepare("UPDATE contas_receber SET status = 'renegociado', renegociado_em_id = ? WHERE id = ?");
    $avisos = [];
    foreach ($titulos as $t) {
        $marcar->execute([$renegId, $t['id']]);
        if ($t['boleto_codigo'] && !in_array($t['boleto_situacao'], ['CANCELADO', 'EXPIRADO', 'RECEBIDO'], true) || ($t['boleto_nosso_numero'] && !$t['boleto_codigo'])) {
            $avisos[] = 'O boleto do título #' . $t['id'] . ($t['boleto_nosso_numero'] ? ' (nosso nº ' . $t['boleto_nosso_numero'] . ')' : '') . ' continua valendo no banco — cancele lá para o pagador não pagar o antigo.';
        }
    }

    // Novos títulos.
    $tipo = array_key_exists($dados['tipo'] ?? '', tiposTitulo()) ? $dados['tipo'] : $primeiro['tipo'];
    $descricao = trim((string) ($dados['descricao'] ?? '')) ?: 'Renegociação #' . $renegId;
    $n = count($parcelas);
    $ins = $pdo->prepare('INSERT INTO contas_receber (renegociacao_id, tipo, emitente_id, cliente_id, descricao, categoria_id, numero_titulo, valor, data_vencimento, conta_destino_id, observacao)
        VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)');
    $novos = [];
    foreach ($parcelas as $i => $p) {
        $ins->execute([$renegId, $tipo, $pagadorId, $clienteId, mb_substr($descricao . ($n > 1 ? ' (' . ($i + 1) . "/{$n})" : ''), 0, 200), $primeiro['categoria_id'],
            $p['numero'] ?: null, round($p['valor'], 2), $p['vencimento'], idOuNull($dados['conta_destino_id'] ?? null) ?? $primeiro['conta_destino_id'],
            'Renegociação #' . $renegId . ' dos títulos ' . implode(', ', array_map(fn ($t) => '#' . $t['id'], $titulos))]);
        $novos[] = (int) $pdo->lastInsertId();
    }
    copiarNossoNumero($pdo, $novos);

    // Encargos para o cliente: repasse em aberto (desconta numa próxima operação).
    if ($destino === 'cliente' && $encargos > 0) {
        $repasseId = registrarAdiantamento($pdo, $clienteId, $dados['data_base'], $encargos, null,
            'Juros/multa da renegociação #' . $renegId . ' · ' . ($primeiro['emitente_nome'] ?? 'pagador'));
        $pdo->prepare('UPDATE renegociacoes SET adiantamento_id = ? WHERE id = ?')->execute([$repasseId, $renegId]);
    }
    return [$renegId, $avisos];
}

/** Volta atrás: só enquanto nenhum título novo foi recebido nem teve boleto emitido. */
function desfazerRenegociacao(PDO $pdo, int $renegId): void
{
    $stmt = $pdo->prepare('SELECT * FROM renegociacoes WHERE id = ? AND desfeita_em IS NULL');
    $stmt->execute([$renegId]);
    $r = $stmt->fetch();
    if (!$r) {
        throw new InvalidArgumentException('Renegociação não encontrada (ou já desfeita).');
    }
    $stmt = $pdo->prepare("SELECT COUNT(*) FROM contas_receber WHERE renegociacao_id = ? AND (status NOT IN ('pendente', 'cancelado') OR boleto_codigo IS NOT NULL)");
    $stmt->execute([$renegId]);
    if ((int) $stmt->fetchColumn() > 0) {
        throw new InvalidArgumentException('Algum título novo já foi recebido, devolvido ou teve boleto emitido: estorne/cancele antes de desfazer.');
    }
    if ($r['adiantamento_id']) {
        $stmt = $pdo->prepare('SELECT valor_utilizado FROM adiantamentos WHERE id = ?');
        $stmt->execute([$r['adiantamento_id']]);
        if ((float) $stmt->fetchColumn() > 0) {
            throw new InvalidArgumentException('O repasse dos encargos já foi usado numa operação do cliente: exclua a operação antes.');
        }
        $pdo->prepare('DELETE FROM adiantamentos WHERE id = ?')->execute([$r['adiantamento_id']]);
    }
    $pdo->prepare('DELETE FROM contas_receber WHERE renegociacao_id = ?')->execute([$renegId]);
    $pdo->prepare("UPDATE contas_receber SET status = IF(devolvido_em IS NOT NULL AND vezes_devolvido > 0, 'devolvido', 'pendente'), renegociado_em_id = NULL WHERE renegociado_em_id = ?")
        ->execute([$renegId]);
    $pdo->prepare('UPDATE renegociacoes SET desfeita_em = NOW() WHERE id = ?')->execute([$renegId]);
}
