<?php

// Importação da planilha de boletos do banco (relatório do Inter em .xlsx,
// enquanto a API não está liberada): acha o título de cada boleto e dá a
// baixa nos pagos; nos em aberto, guarda o nosso número no título.

/**
 * Linhas da planilha → boletos. Acha o cabeçalho (IDENTIFICADOR, VENCIMENTO,
 * VALOR...) em qualquer uma das primeiras linhas.
 */
function interpretarPlanilhaBoletos(array $linhas): array
{
    $sinonimos = [
        'pagador' => ['cliente', 'pagador', 'sacado', 'nome do pagador'],
        'codigo_cobranca' => ['cod cobranca', 'codigo cobranca', 'codigo da cobranca'],
        'nosso_numero' => ['identificador', 'nosso numero', 'nosso n', 'numero do boleto'],
        'emissao' => ['emissao', 'data emissao', 'data de emissao'],
        'vencimento' => ['vencimento', 'data vencimento', 'data de vencimento'],
        'valor' => ['valor', 'valor nominal', 'valor do boleto'],
        'situacao' => ['status', 'situacao'],
        'data_pagamento' => ['data recebimento', 'data de recebimento', 'data pagamento', 'data de pagamento', 'data do pagamento'],
        'valor_pago' => ['valor recebido', 'valor pago', 'valor do pagamento'],
        'documento' => ['cpf cnpj', 'cpf/cnpj', 'documento', 'cpf', 'cnpj'],
    ];
    $norm = fn ($t) => trim(preg_replace('/\s+/', ' ', preg_replace('/[^a-z0-9\/ ]/', ' ', strtolower((string) iconv('UTF-8', 'ASCII//TRANSLIT', (string) $t)))));

    $mapa = null;
    $inicio = 0;
    foreach (array_slice($linhas, 0, 15) as $i => $linha) {
        $achadas = [];
        foreach ($linha as $col => $titulo) {
            $t = $norm($titulo);
            foreach ($sinonimos as $campo => $nomes) {
                if (!isset($achadas[$campo]) && in_array($t, $nomes, true)) {
                    $achadas[$campo] = $col;
                }
            }
        }
        if (isset($achadas['nosso_numero'], $achadas['vencimento'], $achadas['valor'])) {
            $mapa = $achadas;
            $inicio = $i + 1;
            break;
        }
    }
    if (!$mapa) {
        throw new InvalidArgumentException('Não achei na planilha as colunas IDENTIFICADOR (nosso número), VENCIMENTO e VALOR. Use o relatório de boletos exportado do Inter.');
    }

    $boletos = [];
    foreach (array_slice($linhas, $inicio) as $n => $linha) {
        $pega = fn ($campo) => isset($mapa[$campo]) ? ($linha[$mapa[$campo]] ?? '') : '';
        $nosso = preg_replace('/\D/', '', (string) $pega('nosso_numero'));
        $valor = valorPlanilha($pega('valor'));
        if ($nosso === '' && !$valor) {
            continue; // linha vazia / total
        }
        $boletos[] = [
            'linha' => $inicio + $n + 1,
            'pagador' => trim((string) $pega('pagador')),
            'documento' => preg_replace('/\D/', '', (string) $pega('documento')),
            'codigo_cobranca' => trim((string) $pega('codigo_cobranca')),
            'nosso_numero' => $nosso,
            'emissao' => dataPlanilha($pega('emissao')),
            'vencimento' => dataPlanilha($pega('vencimento')),
            'valor' => (float) $valor,
            'situacao_banco' => trim((string) $pega('situacao')),
            'situacao' => situacaoBoletoPlanilha((string) $pega('situacao'), valorPlanilha($pega('valor_pago'))),
            'data_pagamento' => dataPlanilha($pega('data_pagamento')),
            'valor_pago' => valorPlanilha($pega('valor_pago')),
        ];
    }
    return $boletos;
}

/** "Recebido", "Pago", "A receber", "Atrasado", "Cancelado"... → pago | aberto | cancelado. */
function situacaoBoletoPlanilha(string $texto, ?float $valorPago): string
{
    $t = strtolower((string) iconv('UTF-8', 'ASCII//TRANSLIT', $texto));
    return match (true) {
        str_contains($t, 'cancel') || str_contains($t, 'expir') || str_contains($t, 'baixad') => 'cancelado',
        str_contains($t, 'receber') || str_contains($t, 'atras') || str_contains($t, 'vencid') || str_contains($t, 'aberto') || str_contains($t, 'emitid') => 'aberto',
        str_contains($t, 'recebid') || str_contains($t, 'pago') || str_contains($t, 'liquid') || str_contains($t, 'compens') => 'pago',
        default => $valorPago ? 'pago' : 'aberto',
    };
}

/**
 * Para cada boleto, o título do sistema: pelo nosso número (campo próprio ou
 * Nº); se não tiver, pelo valor + vencimento + pagador (título em aberto sem
 * nosso número). E o que fazer: baixar, gravar_numero, ja_baixado, nada, sem_titulo.
 */
function casarBoletosPlanilha(PDO $pdo, array $boletos): array
{
    $porNumero = $pdo->prepare(
        "SELECT r.*, e.nome AS pagador_nome, e.documento AS pagador_documento FROM contas_receber r LEFT JOIN pessoas e ON e.id = r.emitente_id
         WHERE r.status <> 'renegociado' AND (r.boleto_nosso_numero = ? OR (r.boleto_nosso_numero IS NULL AND r.numero_titulo = ?)) ORDER BY r.id DESC LIMIT 1"
    );
    $vencimentos = array_filter(array_column($boletos, 'vencimento'));
    $abertos = [];
    if ($vencimentos) {
        $stmt = $pdo->prepare(
            "SELECT r.*, e.nome AS pagador_nome, e.documento AS pagador_documento FROM contas_receber r LEFT JOIN pessoas e ON e.id = r.emitente_id
             WHERE r.status IN ('pendente', 'devolvido') AND r.data_vencimento BETWEEN ? AND ? ORDER BY r.data_vencimento, r.id"
        );
        $stmt->execute([min($vencimentos), max($vencimentos)]);
        $abertos = $stmt->fetchAll();
    }
    $usados = [];
    foreach ($boletos as &$b) {
        $titulo = null;
        $como = null;
        if ($b['nosso_numero'] !== '') {
            $porNumero->execute([$b['nosso_numero'], $b['nosso_numero']]);
            if ($titulo = $porNumero->fetch() ?: null) {
                $como = 'nosso número';
            }
        }
        if (!$titulo) {
            // Valor e vencimento iguais e o mesmo pagador (nome do Inter pode
            // vir com CPF/CNPJ junto: "67.018.166 VLADER..." ou "DANILO ... 113613...").
            $nomeBanco = normalizarNomeComparacao($b['pagador']);
            $digitosBanco = preg_replace('/\D/', '', $b['pagador'] . ' ' . $b['documento']);
            $candidatos = array_filter($abertos, function ($t) use ($b, $nomeBanco, $digitosBanco, $usados) {
                if (isset($usados[$t['id']]) || $t['boleto_nosso_numero'] || abs((float) $t['valor'] - $b['valor']) > 0.005 || $t['data_vencimento'] !== $b['vencimento']) {
                    return false;
                }
                $nomeTitulo = normalizarNomeComparacao($t['pagador_nome']);
                $doc = (string) $t['pagador_documento'];
                return ($nomeTitulo !== '' && ($nomeTitulo === $nomeBanco || str_starts_with($nomeBanco, $nomeTitulo) || str_starts_with($nomeTitulo, $nomeBanco)))
                    || ($doc !== '' && (str_contains($digitosBanco, $doc) || ($b['documento'] && $b['documento'] === $doc)));
            });
            if (count($candidatos) === 1) {
                $titulo = reset($candidatos);
                $como = 'valor, vencimento e pagador';
            }
        }
        if ($titulo) {
            $usados[$titulo['id']] = true;
        }
        $aberto = $titulo && in_array($titulo['status'], ['pendente', 'devolvido'], true);
        $b['titulo'] = $titulo;
        $b['casou_por'] = $como;
        $b['acao'] = match (true) {
            !$titulo => $b['situacao'] === 'cancelado' ? 'nada' : 'sem_titulo',
            $b['situacao'] === 'pago' && $aberto => 'baixar',
            $b['situacao'] === 'pago' && $titulo['status'] === 'recebido' => 'ja_baixado',
            $b['situacao'] === 'aberto' && $aberto && $titulo['boleto_nosso_numero'] !== $b['nosso_numero'] => 'gravar_numero',
            default => 'nada',
        };
    }
    unset($b);
    return $boletos;
}

/**
 * Aplica o que foi marcado: $escolhas[i] = ['titulo_id' => .., 'acao' => baixar|gravar_numero].
 * Baixa com o valor e a data que vieram do banco, na conta escolhida.
 * Devolve [baixados, total, numeros, avisos[]].
 */
function aplicarImportacaoBoletos(PDO $pdo, array $boletos, array $escolhas, int $contaId): array
{
    $baixados = 0;
    $total = 0.0;
    $numeros = 0;
    $avisos = [];
    $detalhes = [];
    $buscar = $pdo->prepare('SELECT r.*, e.nome AS emitente_nome, cl.taxa_padrao AS cliente_taxa FROM contas_receber r
        LEFT JOIN pessoas e ON e.id = r.emitente_id LEFT JOIN pessoas cl ON cl.id = r.cliente_id WHERE r.id = ?');
    foreach ($escolhas as $i => $escolha) {
        $b = $boletos[$i] ?? null;
        $tituloId = (int) ($escolha['titulo_id'] ?? 0);
        if (!$b || !$tituloId) {
            continue;
        }
        $buscar->execute([$tituloId]);
        $t = $buscar->fetch();
        if (!$t) {
            continue;
        }
        $outro = $pdo->prepare("SELECT id FROM contas_receber WHERE boleto_nosso_numero = ? AND id <> ? AND status <> 'renegociado' LIMIT 1");
        $outro->execute([$b['nosso_numero'], $tituloId]);
        if ($b['nosso_numero'] !== '' && ($idOutro = $outro->fetchColumn())) {
            $avisos[] = 'Boleto ' . $b['nosso_numero'] . ': o nosso número já está no título #' . $idOutro . ' (não mexi no #' . $tituloId . ').';
            continue;
        }
        $abertoNoSistema = in_array($t['status'], ['pendente', 'devolvido'], true);
        if ($b['nosso_numero'] !== '' && $abertoNoSistema && !$t['boleto_nosso_numero']) {
            $pdo->prepare('UPDATE contas_receber SET boleto_nosso_numero = ? WHERE id = ?')->execute([$b['nosso_numero'], $tituloId]);
            $numeros++;
        }
        if ($b['situacao'] === 'pago' && ($escolha['acao'] ?? '') === 'baixar') {
            if (!$abertoNoSistema) {
                $avisos[] = 'Título #' . $tituloId . ' não está em aberto (já recebido?) — não baixei de novo.';
                continue;
            }
            $valor = $b['valor_pago'] ?: $b['valor'];
            $data = $b['data_pagamento'] ?: date('Y-m-d');
            baixarReceber($pdo, $t, $data, $valor, $contaId);
            $pdo->prepare("UPDATE contas_receber SET boleto_situacao = 'RECEBIDO' WHERE id = ?")->execute([$tituloId]);
            $baixados++;
            $total += $valor;
            $detalhes[] = '#' . $tituloId . ' ' . ($t['emitente_nome'] ?? '') . ' ' . formatMoney($valor) . ' em ' . formatDate($data);
        }
    }
    return [$baixados, round($total, 2), $numeros, $avisos, $detalhes];
}

// ---------------------------------------------------------------------------
// Planilha de contas a receber devolvida preenchida: a mesma que sai no botão
// "Planilha" da listagem, com o nosso número digitado. Acha as colunas ID e
// Nosso número pelo cabeçalho; as outras colunas são ignoradas.

/** [id => texto do nosso número como veio] e as linhas que não deu para ler. */
function lerPlanilhaNossoNumero(array $linhas): array
{
    $colId = $colNosso = null;
    $inicio = 0;
    foreach ($linhas as $i => $linha) {
        foreach ($linha as $c => $v) {
            $n = preg_replace('/[^a-z]+/', '', strtr(mb_strtolower(trim((string) $v)), ['ú' => 'u', 'º' => 'o', '°' => 'o']));
            if ($n === 'id') {
                $colId = $c;
            } elseif (in_array($n, ['nossonumero', 'nosson', 'nossono'], true)) {
                $colNosso = $c;
            }
        }
        if ($colId !== null && $colNosso !== null) {
            $inicio = $i + 1;
            break;
        }
        $colId = $colNosso = null;
    }
    if ($colId === null) {
        throw new InvalidArgumentException('Não achei as colunas "ID" e "Nosso número" no cabeçalho. Use a planilha baixada no botão Planilha de Contas a receber.');
    }

    $valores = [];
    $erros = [];
    foreach (array_slice($linhas, $inicio, null, true) as $i => $linha) {
        $id = (int) apenasDigitos((string) ($linha[$colId] ?? ''));
        if (!$id) {
            continue;
        }
        $bruto = $linha[$colNosso] ?? '';
        if (is_float($bruto)) {
            $bruto = sprintf('%.0f', $bruto);
        }
        $bruto = trim((string) $bruto);
        // Excel salvando CSV com número grande: "1,23457E+11" — os dígitos já se perderam.
        if (preg_match('/e\+?\d+$/i', $bruto)) {
            $erros[] = 'Linha ' . ($i + 1) . ' (#' . $id . '): o Excel converteu o nosso número em notação científica (' . $bruto . '). Formate a coluna como Texto e digite de novo, ou salve em .xlsx.';
            continue;
        }
        $valores[$id] = apenasDigitos($bruto);
    }
    return [$valores, $erros];
}

/**
 * O que fazer com cada linha: gravar, igual, vazio (planilha em branco: não
 * apaga), sem_titulo, registrado (boleto emitido pelo sistema: o número veio
 * do banco), duplicado ou invalido.
 */
function analisarNossoNumeroPlanilha(PDO $pdo, array $valores): array
{
    if (!$valores) {
        return [];
    }
    $ids = array_map('intval', array_keys($valores));
    $stmt = $pdo->query('SELECT r.id, r.tipo, r.status, r.valor, r.data_vencimento, r.boleto_codigo, r.boleto_nosso_numero, e.nome AS pagador_nome
        FROM contas_receber r LEFT JOIN pessoas e ON e.id = r.emitente_id WHERE r.id IN (' . implode(',', $ids) . ')');
    $titulos = array_column($stmt->fetchAll(), null, 'id');
    $repetidos = array_filter(array_count_values(array_filter($valores, fn ($v) => $v !== '')), fn ($n) => $n > 1);
    $outro = $pdo->prepare("SELECT id FROM contas_receber WHERE boleto_nosso_numero = ? AND id <> ? AND status <> 'renegociado' LIMIT 1");

    $linhas = [];
    foreach ($valores as $id => $novo) {
        $t = $titulos[$id] ?? null;
        $atual = (string) ($t['boleto_nosso_numero'] ?? '');
        $acao = 'gravar';
        $motivo = '';
        if (!$t) {
            $acao = 'sem_titulo';
        } elseif ($novo === '') {
            $acao = 'vazio';
        } elseif ($novo === $atual) {
            $acao = 'igual';
        } elseif (strlen($novo) > 30) {
            [$acao, $motivo] = ['invalido', 'mais de 30 dígitos'];
        } elseif ($t['boleto_codigo']) {
            [$acao, $motivo] = ['registrado', 'boleto emitido pelo sistema: o nosso número veio do banco'];
        } else {
            $outro->execute([$novo, $id]);
            $dono = $outro->fetchColumn();
            // Se o dono atual também está trocando de número nesta planilha, libera.
            if ($dono && !(isset($valores[$dono]) && $valores[$dono] !== '' && $valores[$dono] !== $novo)) {
                [$acao, $motivo] = ['duplicado', 'já está no título #' . $dono];
            } elseif (isset($repetidos[$novo])) {
                [$acao, $motivo] = ['duplicado', 'o mesmo número aparece em mais de uma linha da planilha'];
            }
        }
        $linhas[] = ['id' => $id, 'novo' => $novo, 'atual' => $atual, 'acao' => $acao, 'motivo' => $motivo, 'titulo' => $t];
    }
    return $linhas;
}
