<?php

function ehRequisicaoAjax(): bool
{
    return strtolower($_SERVER['HTTP_X_REQUESTED_WITH'] ?? '') === 'xmlhttprequest';
}

function csrfToken(): string
{
    if (empty($_SESSION['csrf_token'])) {
        $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
    }
    return $_SESSION['csrf_token'];
}

function responderJson(array $dados): void
{
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode($dados);
    exit;
}

function redirect(string $path): void
{
    header('Location: ' . $path);
    exit;
}

/** URL de um arquivo de public/ com "?v=" da data de modificação (sem cache velho). */
function assetUrl(string $caminho): string
{
    $caminhoFs = BASE_PATH . '/public' . $caminho;
    return $caminho . '?v=' . (is_file($caminhoFs) ? filemtime($caminhoFs) : time());
}

function e(?string $value): string
{
    return htmlspecialchars($value ?? '', ENT_QUOTES, 'UTF-8');
}

function formatMoney($value): string
{
    return 'R$ ' . number_format((float) $value, 2, ',', '.');
}

/** 1.234,56 — para preencher campo de valor. */
function formatDecimal($value, int $casas = 2): string
{
    return number_format((float) $value, $casas, ',', '.');
}

function formatDate(?string $value): string
{
    return $value ? date('d/m/Y', strtotime($value)) : '-';
}

function formatDateTime(?string $value): string
{
    return $value ? date('d/m/Y H:i', strtotime($value)) : '-';
}

function formatPercent($value): string
{
    return rtrim(rtrim(number_format((float) $value, 4, ',', '.'), '0'), ',') . '%';
}

function nomeMes(string $competencia): string
{
    $meses = ['', 'jan', 'fev', 'mar', 'abr', 'mai', 'jun', 'jul', 'ago', 'set', 'out', 'nov', 'dez'];
    return $meses[(int) substr($competencia, 5, 2)] . '/' . substr($competencia, 0, 4);
}

function flash(string $tipo, string $mensagem): void
{
    $_SESSION['flash'][] = ['tipo' => $tipo, 'mensagem' => $mensagem];
}

function getFlashes(): array
{
    $flashes = $_SESSION['flash'] ?? [];
    unset($_SESSION['flash']);
    return $flashes;
}

function input(string $key, $default = ''): string
{
    return trim((string) ($_POST[$key] ?? $default));
}

/** "1.234,56" → 1234.56 (aceita também "1234.56"). */
function paraDecimal($raw): float
{
    $raw = trim((string) $raw);
    if ($raw === '') {
        return 0.0;
    }
    if (str_contains($raw, ',')) {
        $raw = str_replace(',', '.', str_replace('.', '', $raw));
    }
    return (float) $raw;
}

function decimalInput(string $key, $default = 0): float
{
    return paraDecimal($_POST[$key] ?? $default);
}

/** Data AAAA-MM-DD válida, ou null. */
function dataInput(string $key): ?string
{
    $v = input($key);
    $d = DateTimeImmutable::createFromFormat('!Y-m-d', $v);
    return $d && $d->format('Y-m-d') === $v ? $v : null;
}

function idOuNull($valor): ?int
{
    $id = (int) $valor;
    return $id > 0 ? $id : null;
}

function nomeSistema(): string
{
    return APP_NAME;
}

/** Nome com só a primeira letra de cada palavra maiúscula (de/da/do minúsculos). */
function formatarNomePessoa(string $nome): string
{
    $nome = trim(preg_replace('/\s+/u', ' ', $nome));
    if ($nome === '') {
        return '';
    }
    $palavras = explode(' ', mb_strtolower($nome, 'UTF-8'));
    foreach ($palavras as $i => $palavra) {
        if ($i === 0 || !in_array($palavra, ['de', 'da', 'do', 'dos', 'das', 'e'], true)) {
            $palavras[$i] = mb_convert_case($palavra, MB_CASE_TITLE, 'UTF-8');
        }
    }
    return implode(' ', $palavras);
}

function apenasDigitos(string $valor): string
{
    return preg_replace('/\D/', '', $valor) ?? '';
}

function documentoFormatado(?string $doc): string
{
    $d = apenasDigitos((string) $doc);
    return match (strlen($d)) {
        11 => substr($d, 0, 3) . '.' . substr($d, 3, 3) . '.' . substr($d, 6, 3) . '-' . substr($d, 9),
        14 => substr($d, 0, 2) . '.' . substr($d, 2, 3) . '.' . substr($d, 5, 3) . '/' . substr($d, 8, 4) . '-' . substr($d, 12),
        default => (string) $doc,
    };
}

function telefoneFormatado(?string $telefone): string
{
    $d = apenasDigitos((string) $telefone);
    return match (strlen($d)) {
        11 => '(' . substr($d, 0, 2) . ') ' . substr($d, 2, 5) . '-' . substr($d, 7),
        10 => '(' . substr($d, 0, 2) . ') ' . substr($d, 2, 4) . '-' . substr($d, 6),
        default => (string) $telefone,
    };
}

/** Dias corridos de $de até $ate (negativo se $ate for antes). */
function diasEntre(string $de, string $ate): int
{
    return (int) (new DateTimeImmutable($de))->diff(new DateTimeImmutable($ate))->format('%r%a');
}

/** Soma N meses preservando o dia quando existe no mês ("31/01 + 1" = 28/02). */
function somarMeses(string $data, int $meses): string
{
    $base = new DateTimeImmutable($data);
    $primeiro = $base->modify('first day of this month')->modify("+{$meses} months");
    $dia = min((int) $base->format('d'), (int) $primeiro->format('t'));
    return $primeiro->setDate((int) $primeiro->format('Y'), (int) $primeiro->format('m'), $dia)->format('Y-m-d');
}

/** <option>s das contas bancárias ativas. */
function opcoesContas(PDO $pdo, ?int $selecionada = null, string $vazio = ''): string
{
    $html = $vazio !== '' ? '<option value="">' . e($vazio) . '</option>' : '';
    foreach ($pdo->query('SELECT id, nome FROM contas_bancarias WHERE ativo = 1 ORDER BY nome')->fetchAll() as $c) {
        $html .= '<option value="' . (int) $c['id'] . '"' . ((int) $c['id'] === $selecionada ? ' selected' : '') . '>' . e($c['nome']) . '</option>';
    }
    return $html;
}

function opcoesCategorias(PDO $pdo, string $tipo, ?int $selecionada = null): string
{
    $stmt = $pdo->prepare('SELECT id, nome FROM categorias WHERE tipo = ? AND (ativo = 1 OR id = ?) ORDER BY nome');
    $stmt->execute([$tipo, (int) $selecionada]);
    $html = '<option value="">— sem categoria —</option>';
    foreach ($stmt->fetchAll() as $c) {
        $html .= '<option value="' . (int) $c['id'] . '"' . ((int) $c['id'] === $selecionada ? ' selected' : '') . '>' . e($c['nome']) . '</option>';
    }
    return $html;
}

function papeisPessoa(): array
{
    return ['cliente' => 'Cliente', 'pagador' => 'Pagador', 'fornecedor' => 'Fornecedor'];
}

/**
 * Cadastros de cada papel + o script da lista suspensa dos campos
 * data-busca-pessoa="cliente|pagador|fornecedor".
 */
function buscaPessoas(PDO $pdo): string
{
    $porPapel = ['cliente' => [], 'pagador' => [], 'fornecedor' => []];
    foreach ($pdo->query('SELECT papel, nome, documento, taxa_padrao, cidade, uf FROM pessoas ORDER BY nome')->fetchAll() as $p) {
        $porPapel[$p['papel']][] = [
            'nome' => $p['nome'],
            'documento' => documentoFormatado($p['documento']),
            'taxa' => $p['taxa_padrao'] !== null ? (float) $p['taxa_padrao'] : null,
            'cidade' => $p['cidade'] ? $p['cidade'] . ($p['uf'] ? '/' . $p['uf'] : '') : '',
        ];
    }
    return '<script>window.PESSOAS = ' . json_encode($porPapel, JSON_HEX_TAG | JSON_HEX_AMP | JSON_UNESCAPED_UNICODE) . ';</script>'
        . '<script src="' . e(assetUrl('/assets/js/busca-pessoa.js')) . '" defer></script>';
}

/**
 * Acha o cadastro daquele papel pelo documento (se veio) ou pelo nome exato;
 * se não existir, cadastra. Devolve o id, ou null se o nome veio vazio.
 */
function obterOuCriarPessoa(PDO $pdo, string $nome, string $documento, string $papel): ?int
{
    $nome = formatarNomePessoa($nome);
    $documento = apenasDigitos($documento);
    if ($nome === '') {
        return null;
    }
    if ($documento !== '') {
        $stmt = $pdo->prepare('SELECT id FROM pessoas WHERE papel = ? AND documento = ? LIMIT 1');
        $stmt->execute([$papel, $documento]);
        if ($id = $stmt->fetchColumn()) {
            return (int) $id;
        }
    }
    $stmt = $pdo->prepare('SELECT id, documento FROM pessoas WHERE papel = ? AND nome = ? ORDER BY id LIMIT 1');
    $stmt->execute([$papel, $nome]);
    if ($p = $stmt->fetch()) {
        if ($documento !== '' && !$p['documento']) {
            $pdo->prepare('UPDATE pessoas SET documento = ? WHERE id = ?')->execute([$documento, $p['id']]);
        }
        return (int) $p['id'];
    }
    $pdo->prepare('INSERT INTO pessoas (papel, tipo_pessoa, nome, documento) VALUES (?, ?, ?, ?)')
        ->execute([$papel, strlen($documento) === 14 ? 'J' : 'F', $nome, $documento ?: null]);
    return (int) $pdo->lastInsertId();
}

function validarCpf(string $cpf): bool
{
    $cpf = apenasDigitos($cpf);
    if (strlen($cpf) !== 11 || preg_match('/^(\d)\1{10}$/', $cpf)) {
        return false;
    }
    for ($posicao = 9; $posicao <= 10; $posicao++) {
        $soma = 0;
        for ($i = 0; $i < $posicao; $i++) {
            $soma += ((int) $cpf[$i]) * (($posicao + 1) - $i);
        }
        $digito = ($soma * 10) % 11;
        if ($digito === 10) {
            $digito = 0;
        }
        if ($digito !== (int) $cpf[$posicao]) {
            return false;
        }
    }
    return true;
}

function validarCnpj(string $cnpj): bool
{
    $cnpj = apenasDigitos($cnpj);
    if (strlen($cnpj) !== 14 || preg_match('/^(\d)\1{13}$/', $cnpj)) {
        return false;
    }
    $digito = function (string $base, array $pesos) {
        $soma = 0;
        foreach ($pesos as $i => $peso) {
            $soma += ((int) $base[$i]) * $peso;
        }
        $resto = $soma % 11;
        return $resto < 2 ? 0 : 11 - $resto;
    };
    return $digito(substr($cnpj, 0, 12), [5, 4, 3, 2, 9, 8, 7, 6, 5, 4, 3, 2]) === (int) $cnpj[12]
        && $digito(substr($cnpj, 0, 13), [6, 5, 4, 3, 2, 9, 8, 7, 6, 5, 4, 3, 2]) === (int) $cnpj[13];
}

function ufsBrasil(): array
{
    return ['AC', 'AL', 'AP', 'AM', 'BA', 'CE', 'DF', 'ES', 'GO', 'MA', 'MT', 'MS', 'MG', 'PA', 'PB', 'PR', 'PE', 'PI', 'RJ', 'RN', 'RS', 'RO', 'RR', 'SC', 'SP', 'SE', 'TO'];
}

/** O que falta no cadastro para emitir boleto contra esta pessoa (vazio = completo). */
function faltandoParaBoleto(array $p): array
{
    $doc = apenasDigitos((string) ($p['documento'] ?? ''));
    $falta = [];
    if (!(strlen($doc) === 11 ? validarCpf($doc) : (strlen($doc) === 14 && validarCnpj($doc)))) {
        $falta[] = 'CPF/CNPJ';
    }
    foreach (['cep' => 'CEP', 'logradouro' => 'endereço', 'numero' => 'número', 'bairro' => 'bairro', 'cidade' => 'cidade', 'uf' => 'UF'] as $campo => $rotulo) {
        if (trim((string) ($p[$campo] ?? '')) === '') {
            $falta[] = $rotulo;
        }
    }
    return $falta;
}

function cadastroCompletoParaBoleto(array $p): bool
{
    return !faltandoParaBoleto($p);
}

/**
 * Período dos filtros (campos "de"/"ate" do filtro_periodo.php). Sem nada na
 * URL usa o padrão da tela; vazio de propósito ("Tudo") = sem limite.
 * Devolve [de, ate, deSql, ateSql] — as versões Sql nunca são vazias.
 */
function periodoFiltro(string $padraoDe = '', string $padraoAte = '', string $campoDe = 'de', string $campoAte = 'ate'): array
{
    $veio = array_key_exists($campoDe, $_GET) || array_key_exists($campoAte, $_GET);
    $valida = fn ($v) => preg_match('/^\d{4}-\d{2}-\d{2}$/', (string) $v) ? $v : '';
    $de = $veio ? $valida($_GET[$campoDe] ?? '') : $padraoDe;
    $ate = $veio ? $valida($_GET[$campoAte] ?? '') : $padraoAte;
    return [$de, $ate, $de ?: '1900-01-01', $ate ?: '2999-12-31'];
}

/**
 * Para onde voltar depois de uma ação. Quem age pelo modal de uma lista
 * manda "voltar" (a lista) — volta para ela já atualizada; se deu erro, a
 * lista reabre o modal ("reabrir") para corrigir sem perder o lugar.
 */
function redirecionarAposAcao(string $padrao, bool $erro = false): void
{
    $voltar = (string) ($_POST['voltar'] ?? '');
    if (!str_starts_with($voltar, '/') || str_starts_with($voltar, '//')) {
        redirect($padrao);
    }
    if ($erro && str_starts_with((string) ($_POST['reabrir'] ?? ''), '/')) {
        $voltar .= (str_contains($voltar, '?') ? '&' : '?') . 'reabrir=' . rawurlencode($_POST['reabrir']);
    }
    redirect($voltar);
}

/**
 * Botões editar/excluir da última coluna das listas. $editar abre no modal de
 * detalhe ($noModal) ou navega; $excluir é a página que recebe o POST
 * acao=excluir e volta para esta lista. null = botão não aparece; string em
 * $motivoSemExcluir = botão desabilitado com a explicação no title.
 */
function acoesLinha(?string $editar, ?string $excluir, string $confirmacao = 'Excluir este registro?', bool $noModal = false, string $motivoSemExcluir = '', string $antes = ''): string
{
    // $antes: botões extras antes do editar (ex.: "Receber").
    $html = '<td class="text-end text-nowrap acoes-linha" onclick="event.stopPropagation()">' . $antes;
    if ($editar) {
        $html .= $noModal
            ? '<button type="button" class="btn btn-sm btn-outline-secondary" title="Editar" onclick="abrirDetalhe(' . e(json_encode($editar)) . ')"><i class="bi bi-pencil"></i></button> '
            : '<a href="' . e($editar) . '" class="btn btn-sm btn-outline-secondary" title="Editar"><i class="bi bi-pencil"></i></a> ';
    }
    if ($excluir) {
        $html .= '<form method="post" action="' . e($excluir) . '" class="d-inline" onsubmit="return confirm(' . e(json_encode($confirmacao)) . ');">'
            . '<input type="hidden" name="acao" value="excluir"><input type="hidden" name="voltar" value="' . e($_SERVER['REQUEST_URI'] ?? '') . '">'
            . '<button class="btn btn-sm btn-outline-danger" title="Excluir"><i class="bi bi-trash"></i></button></form>';
    } elseif ($motivoSemExcluir !== '') {
        $html .= '<span class="d-inline-block" tabindex="0" title="' . e($motivoSemExcluir) . '"><button class="btn btn-sm btn-outline-danger" disabled><i class="bi bi-trash"></i></button></span>';
    }
    return $html . '</td>';
}

/** Campos ocultos com os parâmetros atuais da URL, menos os de $exceto (filtros independentes na mesma página). */
function camposGetExceto(array $exceto): string
{
    $html = '';
    foreach ($_GET as $k => $v) {
        if (!in_array($k, $exceto, true) && is_scalar($v)) {
            $html .= '<input type="hidden" name="' . e($k) . '" value="' . e((string) $v) . '">';
        }
    }
    return $html;
}

/**
 * <option>s de "de qual conta saiu", com a escolha "não movimentar conta"
 * (valor "sem") para lançar coisa antiga que o saldo inicial da conta já
 * considera. data-inicio = data do saldo inicial (para avisar na tela).
 */
function opcoesContaSaida(PDO $pdo, string $selecionada = ''): string
{
    $html = '<option value="">Escolha...</option>';
    foreach ($pdo->query('SELECT id, nome, data_saldo_inicial FROM contas_bancarias WHERE ativo = 1 ORDER BY nome')->fetchAll() as $c) {
        $html .= '<option value="' . (int) $c['id'] . '" data-inicio="' . e($c['data_saldo_inicial']) . '"' . ((string) $c['id'] === $selecionada ? ' selected' : '') . '>' . e($c['nome']) . '</option>';
    }
    return $html . '<option value="sem"' . ($selecionada === 'sem' ? ' selected' : '') . '>Não movimentar conta (lançamento antigo)</option>';
}
