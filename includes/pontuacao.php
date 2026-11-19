<?php

// Pontuação do pagador (e do cliente, pelos títulos que ele traz). Começa em
// 100, que é também o máximo; cada título, na ordem das datas, mexe nos pontos:
//   atrasou (passou do vencimento + tolerância de compensação):
//       −1 até 15 dias de atraso, −2 acima (em aberto, conta o atraso até hoje)
//   cheque/boleto devolvido: −2 a cada devolução
//   renegociado: conta como atraso até a data da renegociação
//   pagou atrasado: +0,5 · pagou em dia: +1 · pagou adiantado: +2
// Os ganhos nunca passam de 100 e os pontos nunca ficam abaixo de 0.
// Faixas: 95+ bom pagador, 85 a 94,5 atenção, abaixo de 85 risco.

const PONTOS_INICIAIS = 100.0;
const PONTOS_ATRASO_CURTO = -1.0;
const PONTOS_ATRASO_LONGO = -2.0;
const PONTOS_DIAS_ATRASO_LONGO = 15;
const PONTOS_DEVOLUCAO = -2.0;
const PONTOS_PAGOU_ATRASADO = 0.5;
const PONTOS_PAGOU_EM_DIA = 1.0;
const PONTOS_PAGOU_ADIANTADO = 2.0;
const PONTOS_FAIXA_BOM = 95.0;
const PONTOS_FAIXA_ATENCAO = 85.0;

/**
 * Títulos com o que a pontuação precisa (inclui a data da renegociação).
 * $campo = 'emitente_id' (pagador) ou 'cliente_id'; $id = só dessa pessoa.
 */
function titulosParaPontuacao(PDO $pdo, string $campo, ?int $id = null): array
{
    $campo = $campo === 'cliente_id' ? 'cliente_id' : 'emitente_id';
    // O residual da operação (juros NULL com operação) é dívida do próprio
    // cliente: conta para o cliente, não para o pagador.
    $sql = "SELECT r.id, r.{$campo} AS pessoa_id, r.status, r.valor, r.data_vencimento, r.data_recebimento, r.devolvido_em, r.vezes_devolvido,
                r.juros, r.acrescimo, rn.data AS data_renegociacao
            FROM contas_receber r LEFT JOIN renegociacoes rn ON rn.id = r.renegociado_em_id
            WHERE r.{$campo} IS NOT NULL AND r.status <> 'cancelado'"
        . ($campo === 'emitente_id' ? ' AND NOT (r.operacao_id IS NOT NULL AND r.juros IS NULL)' : '')
        . ($id ? " AND r.{$campo} = ?" : '') . ' ORDER BY r.data_vencimento, r.id';
    $stmt = $pdo->prepare($sql);
    $stmt->execute($id ? [$id] : []);
    return $stmt->fetchAll();
}

/** Eventos de pontos de um título: [['data', 'pontos', 'motivo'], ...]. */
function eventosPontuacao(array $t, string $hoje, int $tolerancia): array
{
    $eventos = [];
    $venc = $t['data_vencimento'];
    $limite = date('Y-m-d', strtotime($venc . " +{$tolerancia} days"));
    $atraso = function (string $ate) use ($venc, $limite): ?array {
        if ($ate <= $limite) {
            return null;
        }
        $dias = (int) round((strtotime($ate) - strtotime($venc)) / 86400);
        return [$dias > PONTOS_DIAS_ATRASO_LONGO ? PONTOS_ATRASO_LONGO : PONTOS_ATRASO_CURTO, $dias];
    };

    for ($i = 0; $i < (int) $t['vezes_devolvido']; $i++) {
        $eventos[] = [$t['devolvido_em'] ?: $venc, PONTOS_DEVOLUCAO, 'devolvido'];
    }
    if ($t['status'] === 'recebido' && $t['data_recebimento']) {
        $pago = $t['data_recebimento'];
        if ($a = $atraso($pago)) {
            $eventos[] = [$limite, $a[0], 'atrasou ' . $a[1] . ' dia(s)'];
            $eventos[] = [$pago, PONTOS_PAGOU_ATRASADO, 'pagou atrasado'];
        } elseif ($pago < $venc && !(int) $t['vezes_devolvido']) {
            $eventos[] = [$pago, PONTOS_PAGOU_ADIANTADO, 'pagou adiantado'];
        } else {
            $eventos[] = [$pago, (int) $t['vezes_devolvido'] ? PONTOS_PAGOU_ATRASADO : PONTOS_PAGOU_EM_DIA, (int) $t['vezes_devolvido'] ? 'pagou depois de devolvido' : 'pagou em dia'];
        }
    } elseif ($t['status'] === 'renegociado') {
        if ($a = $atraso($t['data_renegociacao'] ?: $hoje)) {
            $eventos[] = [$limite, $a[0], 'renegociado com ' . $a[1] . ' dia(s) de atraso'];
        }
    } elseif (in_array($t['status'], ['pendente', 'devolvido'], true)) {
        if ($a = $atraso($hoje)) {
            $eventos[] = [$limite, $a[0], 'em aberto, ' . $a[1] . ' dia(s) de atraso'];
        }
    }
    // transferido (voltou para o cliente): a devolução já contou; não há pagamento do pagador.
    return array_map(fn ($e) => $e + [3 => (int) $t['id']], $eventos);
}

/**
 * Pontos de cada pessoa a partir dos títulos dela, aplicando os eventos na
 * ordem das datas: [pessoa_id => ['pontos', 'titulos', 'em_dia', 'adiantados',
 * 'atrasos', 'devolucoes', 'valor', 'ganho', 'eventos' (se pedido)]].
 */
function calcularPontuacoes(array $titulos, bool $comEventos = false, ?string $hoje = null): array
{
    $hoje ??= date('Y-m-d');
    $tolerancia = toleranciaCompensacaoDias();
    $porPessoa = [];
    foreach ($titulos as $t) {
        $p = &$porPessoa[(int) $t['pessoa_id']];
        $p ??= ['eventos' => [], 'titulos' => 0, 'valor' => 0.0, 'ganho' => 0.0, 'em_dia' => 0, 'adiantados' => 0, 'atrasos' => 0, 'devolucoes' => 0, 'em_aberto' => 0.0];
        $p['titulos']++;
        $p['valor'] += (float) $t['valor'];
        $p['ganho'] += (float) $t['juros'] + (float) $t['acrescimo'];
        if (in_array($t['status'], ['pendente', 'devolvido'], true)) {
            $p['em_aberto'] += (float) $t['valor'];
        }
        array_push($p['eventos'], ...eventosPontuacao($t, $hoje, $tolerancia));
        unset($p);
    }
    foreach ($porPessoa as &$p) {
        usort($p['eventos'], fn ($a, $b) => [$a[0], $a[3]] <=> [$b[0], $b[3]]);
        $pontos = PONTOS_INICIAIS;
        foreach ($p['eventos'] as &$e) {
            $pontos = max(0.0, min(PONTOS_INICIAIS, $pontos + $e[1]));
            $e[4] = $pontos; // saldo depois do evento
            match (true) {
                str_starts_with($e[2], 'pagou em dia') => $p['em_dia']++,
                str_starts_with($e[2], 'pagou adiantado') => $p['adiantados']++,
                $e[2] === 'devolvido' => $p['devolucoes']++,
                $e[1] < 0 => $p['atrasos']++,
                default => null,
            };
        }
        unset($e);
        $p['pontos'] = $pontos;
        $p['liquidados'] = count($p['eventos']);
        if (!$comEventos) {
            unset($p['eventos']);
        }
    }
    unset($p);
    return $porPessoa;
}

/** Pontos de uma pessoa (sem títulos: 100, sem histórico). */
function pontuacaoPessoa(PDO $pdo, int $id, string $campo = 'emitente_id', bool $comEventos = false): array
{
    return calcularPontuacoes(titulosParaPontuacao($pdo, $campo, $id), $comEventos)[$id]
        ?? ['pontos' => PONTOS_INICIAIS, 'titulos' => 0, 'liquidados' => 0, 'eventos' => [], 'em_dia' => 0, 'adiantados' => 0, 'atrasos' => 0, 'devolucoes' => 0, 'valor' => 0.0, 'ganho' => 0.0, 'em_aberto' => 0.0];
}

/** Classificação pela faixa de pontos; sem nenhum evento ainda = sem histórico. */
function classificacaoPorPontos(float $pontos, int $eventos): string
{
    return match (true) {
        $eventos === 0 => 'sem_historico',
        $pontos >= PONTOS_FAIXA_BOM => 'bom',
        $pontos >= PONTOS_FAIXA_ATENCAO => 'atencao',
        default => 'risco',
    };
}

function formatarPontos(float $pontos): string
{
    return rtrim(rtrim(number_format($pontos, 1, ',', '.'), '0'), ',');
}
