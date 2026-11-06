<?php

// Rastreabilidade: quem fez o quê e quando, em todos os módulos do sistema.
// Usada tanto para gravar (registrarAtividade) quanto para alimentar a tela
// de Configurações › Auditoria.

function modulosAuditoria(): array
{
    return [
        'login' => 'Login',
        'operacoes' => 'Operações de desconto',
        'repasses' => 'Repasses a clientes',
        'contas_receber' => 'Contas a receber',
        'boletos' => 'Boletos (banco)',
        'contas_pagar' => 'Contas a pagar',
        'cartoes' => 'Cartões de crédito',
        'contas_bancarias' => 'Contas bancárias',
        'pessoas' => 'Clientes, pagadores e fornecedores',
        'categorias' => 'Categorias',
        'tipos_recebimento' => 'Tipos de recebimento',
        'usuarios' => 'Usuários',
        'perfis' => 'Perfis de acesso',
        'configuracoes' => 'Configurações',
    ];
}

function operacoesAuditoria(): array
{
    return [
        'criacao' => 'Criação',
        'edicao' => 'Edição',
        'exclusao' => 'Exclusão',
        'baixa' => 'Baixa',
        'estorno' => 'Estorno',
        'reabertura' => 'Reabertura',
        'recalculo' => 'Recálculo',
        'renegociacao' => 'Renegociação',
        'devolucao' => 'Devolução ao cliente',
        'pagamento' => 'Pagamento',
        'transferencia' => 'Transferência',
        'emissao_boleto' => 'Emissão de boleto',
        'consulta_boleto' => 'Consulta de boleto',
        'cancelamento_boleto' => 'Cancelamento de boleto',
        'vinculo_boleto' => 'Boleto emitido fora ligado ao título',
        'baixa_automatica' => 'Baixa automática (banco)',
        'integracao' => 'Integração com o banco',
        'login' => 'Login',
        'logout' => 'Logout',
        'login_falha' => 'Falha no login',
        'senha' => 'Senha',
    ];
}

// Monta uma descrição tipo 'Taxa: "2" → "2,5"' comparando os valores
// antes/depois de um registro — só entram os campos que realmente mudaram.
// $rotulos mapeia campo => label; $sensiveis lista campos cujo valor não
// deve aparecer (ex.: senha), só que mudou.
function descreverAlteracoes(array $antes, array $depois, array $rotulos, array $sensiveis = []): string
{
    $partes = [];

    foreach ($rotulos as $campo => $rotulo) {
        $valorAntes = $antes[$campo] ?? null;
        $valorDepois = $depois[$campo] ?? null;

        if ((string) $valorAntes === (string) $valorDepois) {
            continue;
        }

        if (in_array($campo, $sensiveis, true)) {
            $partes[] = $rotulo . ' alterado(a)';
            continue;
        }

        $partes[] = $rotulo . ': "' . ($valorAntes !== null && $valorAntes !== '' ? $valorAntes : '-') . '" → "' . ($valorDepois !== null && $valorDepois !== '' ? $valorDepois : '-') . '"';
    }

    return $partes ? implode('; ', $partes) : 'nenhum campo alterado';
}

// "R$ 100,00 (Inter) + R$ 50,00 (Bradesco)" — como foi pago, para a auditoria.
function descreverPartesPagamento(array $partes): string
{
    static $nomes = null;
    $nomes ??= getDB()->query('SELECT id, nome FROM contas_bancarias')->fetchAll(PDO::FETCH_KEY_PAIR);
    $texto = [];
    foreach ($partes as $p) {
        if ((float) ($p['valor'] ?? 0) > 0) {
            $texto[] = formatMoney($p['valor']) . ' (' . ($nomes[$p['conta_id'] ?? 0] ?? 'sem conta') . ')';
        }
    }
    return $texto ? implode(' + ', $texto) : 'sem valor';
}

// Resumo da operação de desconto para a auditoria (lido do banco).
function resumoOperacaoAuditoria(PDO $pdo, int $id): string
{
    $stmt = $pdo->prepare('SELECT o.*, p.nome AS cliente_nome, (SELECT COUNT(*) FROM contas_receber r WHERE r.operacao_id = o.id AND r.juros IS NOT NULL) AS qtd
        FROM operacoes o JOIN pessoas p ON p.id = o.cliente_id WHERE o.id = ?');
    $stmt->execute([$id]);
    $o = $stmt->fetch();
    if (!$o) {
        return 'operação #' . $id;
    }
    return 'operação #' . $id . ' de ' . $o['cliente_nome'] . ' (data base ' . formatDate($o['data_base']) . ', taxa ' . formatDecimal($o['taxa'], 2) . '%, '
        . (int) $o['qtd'] . ' título(s), bruto ' . formatMoney($o['total_bruto']) . ', juros ' . formatMoney($o['total_juros']) . ', líquido ' . formatMoney($o['total_liquido']) . ')';
}

// " · diferença paga ao cliente em ..." / " · pagamento ao cliente agendado para ..." (auditoria).
function descricaoPagamentoClienteAuditoria(PDO $pdo, int $id): string
{
    $stmt = $pdo->prepare('SELECT * FROM operacoes WHERE id = ?');
    $stmt->execute([$id]);
    $o = $stmt->fetch();
    $p = $o ? pagamentoClienteOperacao($pdo, $o) : ['situacao' => 'sem_diferenca'];
    return match ($p['situacao']) {
        'pago' => ' · diferença ' . formatMoney($o['saldo']) . ' paga ao cliente em ' . formatDate($p['data']),
        'agendado', 'parcial' => ' · pagamento de ' . formatMoney($o['saldo']) . ' ao cliente agendado para ' . formatDate($p['data']) . ' (conta a pagar #' . $p['conta_pagar']['id'] . ')',
        'nao_lancado' => ' · diferença ' . formatMoney($o['saldo']) . ' ao cliente não lançada',
        default => '',
    };
}

function registrarAtividade(string $modulo, string $operacao, string $descricao): void
{
    registrarAtividadeUsuario(usuarioAtualId(), usuarioAtualNome(), $modulo, $operacao, $descricao);
}

// Registra a atividade de um usuário específico — usado no logout (a sessão
// já vai ser limpa), em tentativas de login que falharam (ninguém logado) e
// no que o próprio sistema faz sozinho (webhook, cron: usuário null = "Sistema").
function registrarAtividadeUsuario(?int $usuarioId, string $usuarioNome, string $modulo, string $operacao, string $descricao): void
{
    // descricao é varchar(255) e o MySQL roda em modo estrito: maior que
    // isso estoura e derrubaria a operação que está só sendo registrada.
    // Registro de auditoria nunca pode quebrar o que ele está auditando.
    if (mb_strlen($descricao) > 255) {
        $descricao = mb_substr($descricao, 0, 252) . '...';
    }

    try {
        getDB()->prepare(
            'INSERT INTO log_atividades (usuario_id, usuario_nome, modulo, operacao, descricao, ip, user_agent, criado_em)
             VALUES (?, ?, ?, ?, ?, ?, ?, NOW())'
        )->execute([
            $usuarioId,
            $usuarioNome !== '' ? $usuarioNome : 'Sistema',
            $modulo,
            $operacao,
            $descricao,
            $_SERVER['REMOTE_ADDR'] ?? null,
            substr((string) ($_SERVER['HTTP_USER_AGENT'] ?? ''), 0, 255) ?: null,
        ]);
    } catch (Throwable $e) {
        error_log('Falha ao registrar auditoria: ' . $e->getMessage());
    }
}

// Tentativa de login que falhou: não existe usuário autenticado, então
// guarda o e-mail digitado no lugar do nome, pra dar pra ver quem tentou.
function registrarTentativaLoginFalha(string $emailTentado): void
{
    registrarAtividadeUsuario(null, $emailTentado !== '' ? $emailTentado : 'desconhecido', 'login', 'login_falha', 'Tentativa de login falhou para "' . $emailTentado . '"');
}

// Resumo curto e legível do User-Agent (navegador + sistema) para a tela de
// auditoria — não precisa ser perfeito, só ajudar a reconhecer o aparelho.
function resumirUserAgent(?string $userAgent): string
{
    if (!$userAgent) {
        return '-';
    }

    $sistema = match (true) {
        (bool) preg_match('/iPhone/i', $userAgent) => 'iPhone',
        (bool) preg_match('/iPad/i', $userAgent) => 'iPad',
        (bool) preg_match('/Android/i', $userAgent) => 'Android',
        (bool) preg_match('/Windows/i', $userAgent) => 'Windows',
        (bool) preg_match('/Mac OS X/i', $userAgent) => 'Mac',
        (bool) preg_match('/Linux/i', $userAgent) => 'Linux',
        default => 'Dispositivo desconhecido',
    };

    $navegador = match (true) {
        (bool) preg_match('/Edg\//i', $userAgent) => 'Edge',
        (bool) preg_match('/OPR\//i', $userAgent) => 'Opera',
        (bool) preg_match('/Chrome\/|CriOS/i', $userAgent) => 'Chrome',
        (bool) preg_match('/FxiOS|Firefox\//i', $userAgent) => 'Firefox',
        (bool) preg_match('/Safari\//i', $userAgent) => 'Safari',
        default => '',
    };

    return trim($sistema . ($navegador ? ' · ' . $navegador : ''));
}

function buscarLogAtividades(PDO $pdo, array $filtros, int $pagina = 1, int $porPagina = 50): array
{
    $condicoes = [];
    $parametros = [];

    if (!empty($filtros['data_inicio'])) {
        $condicoes[] = 'criado_em >= ?';
        $parametros[] = $filtros['data_inicio'] . ' 00:00:00';
    }

    if (!empty($filtros['data_fim'])) {
        $condicoes[] = 'criado_em <= ?';
        $parametros[] = $filtros['data_fim'] . ' 23:59:59';
    }

    foreach (['modulo', 'operacao', 'usuario_id'] as $campo) {
        if (!empty($filtros[$campo])) {
            $condicoes[] = "$campo = ?";
            $parametros[] = $filtros[$campo];
        }
    }

    // % e _ escapados: número como "2759_1" não pode virar curinga.
    if (($filtros['busca'] ?? '') !== '') {
        $termo = '%' . addcslashes($filtros['busca'], '\\%_') . '%';
        $condicoes[] = '(descricao LIKE ? OR usuario_nome LIKE ? OR ip LIKE ?)';
        array_push($parametros, $termo, $termo, $termo);
    }

    $where = $condicoes ? 'WHERE ' . implode(' AND ', $condicoes) : '';

    $stmtTotal = $pdo->prepare("SELECT COUNT(*) FROM log_atividades $where");
    $stmtTotal->execute($parametros);
    $total = (int) $stmtTotal->fetchColumn();

    $pagina = max(1, $pagina);
    $offset = ($pagina - 1) * $porPagina;

    $stmt = $pdo->prepare("SELECT * FROM log_atividades $where ORDER BY criado_em DESC, id DESC LIMIT $porPagina OFFSET $offset");
    $stmt->execute($parametros);

    return [
        'itens' => $stmt->fetchAll(),
        'total' => $total,
        'pagina' => $pagina,
        'por_pagina' => $porPagina,
        'total_paginas' => max(1, (int) ceil($total / $porPagina)),
    ];
}
