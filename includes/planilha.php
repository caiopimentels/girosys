<?php

// Leitura de planilha .xlsx sem biblioteca (o arquivo é um zip com XML) e de
// .csv. Devolve as linhas da primeira aba como listas de valores (texto ou
// número, como estão na célula); datas do Excel vêm como número de série.

function lerPlanilha(string $caminho, string $nomeOriginal): array
{
    $extensao = strtolower(pathinfo($nomeOriginal, PATHINFO_EXTENSION));
    if ($extensao === 'csv' || $extensao === 'txt') {
        return lerCsv($caminho);
    }
    if ($extensao !== 'xlsx') {
        throw new InvalidArgumentException('Envie a planilha em .xlsx (ou .csv). Arquivo .xls antigo: abra no Excel e salve como .xlsx.');
    }
    return lerXlsx($caminho);
}

function lerCsv(string $caminho): array
{
    $texto = (string) file_get_contents($caminho);
    if (!mb_check_encoding($texto, 'UTF-8')) {
        $texto = mb_convert_encoding($texto, 'UTF-8', 'ISO-8859-1');
    }
    $texto = preg_replace('/^\xEF\xBB\xBF/', '', $texto);
    $primeira = strtok($texto, "\n");
    $separador = substr_count($primeira, ';') >= substr_count($primeira, ',') ? ';' : ',';
    $linhas = [];
    foreach (preg_split('/\r\n|\n|\r/', $texto) as $linha) {
        if (trim($linha) !== '') {
            $linhas[] = str_getcsv($linha, $separador, '"', '');
        }
    }
    return $linhas;
}

function lerXlsx(string $caminho): array
{
    if (!class_exists('ZipArchive')) {
        throw new RuntimeException('O PHP do servidor está sem a extensão zip (necessária para ler .xlsx). Instale php-zip ou envie em .csv.');
    }
    $zip = new ZipArchive();
    if ($zip->open($caminho) !== true) {
        throw new InvalidArgumentException('Não consegui abrir a planilha (o arquivo não parece um .xlsx válido).');
    }

    // Textos repetidos ficam numa tabela à parte (sharedStrings).
    $textos = [];
    if (($xml = $zip->getFromName('xl/sharedStrings.xml')) !== false) {
        $sst = simplexml_load_string($xml);
        foreach ($sst->si as $si) {
            if (isset($si->t)) {
                $textos[] = (string) $si->t;
            } else {
                $partes = '';
                foreach ($si->r as $r) {
                    $partes .= (string) $r->t;
                }
                $textos[] = $partes;
            }
        }
    }

    // Primeira aba (pela ordem do workbook).
    $aba = 'xl/worksheets/sheet1.xml';
    if (($wb = $zip->getFromName('xl/workbook.xml')) !== false && ($rels = $zip->getFromName('xl/_rels/workbook.xml.rels')) !== false) {
        $wbXml = simplexml_load_string($wb);
        $primeira = $wbXml->sheets->sheet[0] ?? null;
        $rid = $primeira ? (string) $primeira->attributes('r', true)->id : '';
        foreach (simplexml_load_string($rels)->Relationship as $rel) {
            if ((string) $rel['Id'] === $rid) {
                $alvo = ltrim((string) $rel['Target'], '/');
                $aba = str_starts_with($alvo, 'xl/') ? $alvo : 'xl/' . $alvo;
            }
        }
    }
    $xml = $zip->getFromName($aba);
    $zip->close();
    if ($xml === false) {
        throw new InvalidArgumentException('A planilha não tem nenhuma aba com dados.');
    }

    $linhas = [];
    $folha = simplexml_load_string($xml);
    foreach ($folha->sheetData->row as $row) {
        $linha = [];
        foreach ($row->c as $c) {
            $ref = (string) $c['r'];
            $coluna = indiceColunaXlsx(preg_replace('/\d+/', '', $ref));
            $tipo = (string) $c['t'];
            if ($tipo === 's') {
                $valor = $textos[(int) $c->v] ?? '';
            } elseif ($tipo === 'inlineStr') {
                $valor = (string) $c->is->t;
            } elseif ($tipo === 'b') {
                $valor = (string) $c->v === '1';
            } else {
                $v = (string) $c->v;
                $valor = $v === '' ? '' : (is_numeric($v) ? $v + 0 : $v);
            }
            $linha[$coluna] = $valor;
        }
        if ($linha) {
            $max = max(array_keys($linha));
            $cheia = array_fill(0, $max + 1, '');
            foreach ($linha as $i => $v) {
                $cheia[$i] = $v;
            }
            $linhas[] = $cheia;
        }
    }
    return $linhas;
}

/** "A" → 0, "Z" → 25, "AA" → 26. */
function indiceColunaXlsx(string $letras): int
{
    $n = 0;
    foreach (str_split(strtoupper($letras)) as $l) {
        $n = $n * 26 + (ord($l) - 64);
    }
    return $n - 1;
}

/** Data da célula: "13/10/2026", "2026-10-13" ou número de série do Excel. */
function dataPlanilha($valor): ?string
{
    if (is_int($valor) || is_float($valor)) {
        return $valor > 20000 && $valor < 80000 ? gmdate('Y-m-d', (int) round(($valor - 25569) * 86400)) : null;
    }
    $v = trim((string) $valor);
    if (preg_match('#^(\d{1,2})/(\d{1,2})/(\d{4})#', $v, $m)) {
        return checkdate((int) $m[2], (int) $m[1], (int) $m[3]) ? sprintf('%04d-%02d-%02d', $m[3], $m[2], $m[1]) : null;
    }
    if (preg_match('#^(\d{4})-(\d{2})-(\d{2})#', $v, $m)) {
        return checkdate((int) $m[2], (int) $m[3], (int) $m[1]) ? "{$m[1]}-{$m[2]}-{$m[3]}" : null;
    }
    return is_numeric($v) ? dataPlanilha((float) $v) : null;
}

/** Valor da célula: "R$ 1.311,27", "1311.27" ou número. */
function valorPlanilha($valor): ?float
{
    if (is_int($valor) || is_float($valor)) {
        return round((float) $valor, 2);
    }
    $v = trim(str_replace(['R$', ' ', "\u{a0}"], '', (string) $valor));
    if ($v === '' || $v === '-') {
        return null;
    }
    if (str_contains($v, ',')) {
        $v = str_replace(['.', ','], ['', '.'], $v);
    }
    return is_numeric($v) ? round((float) $v, 2) : null;
}

/** Texto para comparar nomes: sem acento, minúsculo, sem números e pontuação. */
function normalizarNomeComparacao(?string $nome): string
{
    $t = iconv('UTF-8', 'ASCII//TRANSLIT', (string) $nome);
    $t = strtolower(preg_replace('/[^a-zA-Z ]+/', ' ', (string) $t));
    $t = preg_replace('/\b(me|mei|eireli|ltda|epp|sa|s a)\b/', ' ', $t);
    return trim(preg_replace('/\s+/', ' ', $t));
}
