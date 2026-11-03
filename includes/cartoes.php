<?php

// Cartão de crédito: a compra parcelada vira uma parcela em cada fatura.
// Fatura = cartão + competência (mês do vencimento, AAAA-MM).

/**
 * Competência da fatura em que cai uma compra feita em $dataCompra.
 * Compra no dia do fechamento ou depois já vai para a fatura seguinte.
 */
function competenciaDaCompra(array $cartao, string $dataCompra): string
{
    $d = new DateTimeImmutable($dataCompra);
    $mesFechamento = $d->modify('first day of this month');
    if ((int) $d->format('d') >= (int) $cartao['dia_fechamento']) {
        $mesFechamento = $mesFechamento->modify('+1 month');
    }
    // Vencimento antes do dia de fechamento = vence no mês seguinte ao fechamento.
    if ((int) $cartao['dia_vencimento'] <= (int) $cartao['dia_fechamento']) {
        $mesFechamento = $mesFechamento->modify('+1 month');
    }
    return $mesFechamento->format('Y-m');
}

function somarMesesCompetencia(string $competencia, int $meses): string
{
    return (new DateTimeImmutable($competencia . '-01'))->modify("+{$meses} months")->format('Y-m');
}

function vencimentoDaFatura(array $cartao, string $competencia): string
{
    $primeiro = new DateTimeImmutable($competencia . '-01');
    $dia = min((int) $cartao['dia_vencimento'], (int) $primeiro->format('t'));
    return $primeiro->format('Y-m-') . str_pad((string) $dia, 2, '0', STR_PAD_LEFT);
}

/** Id da fatura do cartão naquela competência (cria se ainda não existe). */
function obterFatura(PDO $pdo, array $cartao, string $competencia): int
{
    $stmt = $pdo->prepare('SELECT id FROM cartao_faturas WHERE cartao_id = ? AND competencia = ?');
    $stmt->execute([$cartao['id'], $competencia]);
    if ($id = $stmt->fetchColumn()) {
        return (int) $id;
    }
    $pdo->prepare('INSERT INTO cartao_faturas (cartao_id, competencia, data_vencimento) VALUES (?, ?, ?)')
        ->execute([$cartao['id'], $competencia, vencimentoDaFatura($cartao, $competencia)]);
    return (int) $pdo->lastInsertId();
}

/** Divide o total em N parcelas; os centavos que sobram vão na primeira. */
function dividirParcelas(float $total, int $parcelas): array
{
    $base = floor($total / $parcelas * 100) / 100;
    $valores = array_fill(0, $parcelas, $base);
    $valores[0] = round($total - $base * ($parcelas - 1), 2);
    return $valores;
}

/** Grava a compra e suas parcelas. $competenciaInicial vazio = calcula pela data. */
function lancarCompraCartao(PDO $pdo, array $cartao, string $descricao, ?int $categoriaId, string $dataCompra, float $total, int $parcelas, string $competenciaInicial = ''): int
{
    $competencia = preg_match('/^\d{4}-\d{2}$/', $competenciaInicial) ? $competenciaInicial : competenciaDaCompra($cartao, $dataCompra);

    $pdo->beginTransaction();
    try {
        $pdo->prepare('INSERT INTO cartao_compras (cartao_id, descricao, categoria_id, data_compra, valor_total, parcelas) VALUES (?, ?, ?, ?, ?, ?)')
            ->execute([$cartao['id'], $descricao, $categoriaId, $dataCompra, $total, $parcelas]);
        $compraId = (int) $pdo->lastInsertId();

        $ins = $pdo->prepare('INSERT INTO cartao_parcelas (compra_id, fatura_id, numero, valor) VALUES (?, ?, ?, ?)');
        foreach (dividirParcelas($total, $parcelas) as $i => $valor) {
            $ins->execute([$compraId, obterFatura($pdo, $cartao, somarMesesCompetencia($competencia, $i)), $i + 1, $valor]);
        }
        $pdo->commit();
        return $compraId;
    } catch (Throwable $e) {
        $pdo->rollBack();
        throw $e;
    }
}

function excluirCompraCartao(PDO $pdo, int $compraId): void
{
    $stmt = $pdo->prepare("SELECT COUNT(*) FROM cartao_parcelas p JOIN cartao_faturas f ON f.id = p.fatura_id WHERE p.compra_id = ? AND f.status = 'paga'");
    $stmt->execute([$compraId]);
    if ((int) $stmt->fetchColumn() > 0) {
        throw new InvalidArgumentException('Esta compra tem parcela em fatura já paga. Estorne a fatura antes de excluir.');
    }
    $pdo->prepare('DELETE FROM cartao_compras WHERE id = ?')->execute([$compraId]);
    // Faturas que ficaram vazias e em aberto saem também.
    $pdo->exec("DELETE f FROM cartao_faturas f LEFT JOIN cartao_parcelas p ON p.fatura_id = f.id WHERE p.id IS NULL AND f.status = 'aberta'");
}

function totalFatura(PDO $pdo, int $faturaId): float
{
    $stmt = $pdo->prepare('SELECT COALESCE(SUM(valor), 0) FROM cartao_parcelas WHERE fatura_id = ?');
    $stmt->execute([$faturaId]);
    return (float) $stmt->fetchColumn();
}

/** Total por mês das faturas em aberto (todos os cartões) até N meses à frente — atrasadas incluídas. */
function compromissosCartao(PDO $pdo, int $meses = 12): array
{
    $stmt = $pdo->prepare(
        "SELECT competencia, SUM(total) FROM (
            SELECT f.competencia, SUM(p.valor) - COALESCE(f.valor_pago, 0) AS total
            FROM cartao_faturas f JOIN cartao_parcelas p ON p.fatura_id = f.id
            WHERE f.status = 'aberta' AND f.competencia < ?
            GROUP BY f.id
         ) x GROUP BY competencia ORDER BY competencia"
    );
    $stmt->execute([somarMesesCompetencia(date('Y-m'), $meses)]);
    return $stmt->fetchAll(PDO::FETCH_KEY_PAIR);
}
