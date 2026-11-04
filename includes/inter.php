<?php

// Banco Inter — API de Cobrança v3 (boleto com Pix) e webhook de pagamento.
// Autenticação: OAuth2 client_credentials com certificado do Inter (mTLS):
// o .crt e o .key gerados em Internet Banking › Soluções para sua empresa ›
// Nova integração ficam em storage/inter/<conta>/, fora do public/.
// Documentação: https://developers.inter.co/references/cobranca-bolepix

const INTER_ESCOPOS = 'boleto-cobranca.read boleto-cobranca.write';

/** Endereço base da API (INTER_URL_BASE no config.php sobrepõe — só para testes). */
function interUrlBase(array $conta): string
{
    if (defined('INTER_URL_BASE') && INTER_URL_BASE) {
        return rtrim(INTER_URL_BASE, '/');
    }
    return $conta['inter_ambiente'] === 'sandbox'
        ? 'https://cdpj-sandbox.partners.uatinter.co'
        : 'https://cdpj.partners.bancointer.com.br';
}

function contaTemInter(array $conta): bool
{
    return ($conta['integracao'] ?? '') === 'inter';
}

/** O que falta configurar para a conta emitir boleto (vazio = pronta). */
function pendenciasInter(array $conta): array
{
    $falta = [];
    if (!contaTemInter($conta)) {
        return ['integração com o Inter não está ativada nesta conta'];
    }
    if (empty($conta['inter_client_id'])) {
        $falta[] = 'Client ID';
    }
    if (!descriptografar($conta['inter_client_secret'] ?? null)) {
        $falta[] = 'Client Secret';
    }
    if (empty($conta['inter_certificado']) || !is_file($conta['inter_certificado'])) {
        $falta[] = 'certificado (.crt)';
    }
    if (empty($conta['inter_chave']) || !is_file($conta['inter_chave'])) {
        $falta[] = 'chave do certificado (.key)';
    }
    return $falta;
}

/** Contas ativas com o Inter configurado (para escolher onde emitir). */
function contasInter(PDO $pdo): array
{
    return array_values(array_filter(
        $pdo->query("SELECT * FROM contas_bancarias WHERE ativo = 1 AND integracao = 'inter' ORDER BY nome")->fetchAll(),
        fn ($c) => !pendenciasInter($c)
    ));
}

function contaBancaria(PDO $pdo, int $id): ?array
{
    $stmt = $pdo->prepare('SELECT * FROM contas_bancarias WHERE id = ?');
    $stmt->execute([$id]);
    return $stmt->fetch() ?: null;
}

/** Guarda o .crt e o .key enviados em storage/inter/<conta>/. */
function salvarCertificadoInter(PDO $pdo, int $contaId, array $arquivoCrt, array $arquivoKey): void
{
    $pasta = caminhoStorage('inter/' . $contaId);
    if (!is_dir($pasta) && !mkdir($pasta, 0700, true) && !is_dir($pasta)) {
        throw new RuntimeException('Não foi possível criar ' . $pasta . ' (permissão da pasta storage/).');
    }
    $destinos = [];
    foreach (['crt' => $arquivoCrt, 'key' => $arquivoKey] as $ext => $arq) {
        if (($arq['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) {
            continue;
        }
        $conteudo = (string) file_get_contents($arq['tmp_name']);
        $esperado = $ext === 'crt' ? 'CERTIFICATE' : 'PRIVATE KEY';
        if (!str_contains($conteudo, $esperado)) {
            throw new InvalidArgumentException('O arquivo .' . $ext . ' não parece ' . ($ext === 'crt' ? 'um certificado' : 'uma chave privada') . ' (formato PEM).');
        }
        $destino = $pasta . '/inter.' . $ext;
        file_put_contents($destino, $conteudo);
        chmod($destino, 0600);
        $destinos[$ext] = $destino;
    }
    if (isset($destinos['crt'])) {
        $pdo->prepare('UPDATE contas_bancarias SET inter_certificado = ?, inter_token = NULL WHERE id = ?')->execute([$destinos['crt'], $contaId]);
    }
    if (isset($destinos['key'])) {
        $pdo->prepare('UPDATE contas_bancarias SET inter_chave = ?, inter_token = NULL WHERE id = ?')->execute([$destinos['key'], $contaId]);
    }
}

/**
 * Título a que as próximas chamadas ao banco se referem (vai para a
 * auditoria do banco). null = chamada da conta (token, webhook...).
 */
function interContextoTitulo(?int $tituloId = null, bool $definir = false): ?int
{
    static $atual = null;
    if ($definir) {
        $atual = $tituloId;
    }
    return $atual;
}

/** Nome da operação para a auditoria do banco, pelo método e caminho. */
function interOperacaoChamada(string $metodo, string $caminho): string
{
    return match (true) {
        str_contains($caminho, '/oauth/') => 'token',
        str_ends_with($caminho, '/webhook') => match ($metodo) { 'PUT' => 'registrar_webhook', 'DELETE' => 'excluir_webhook', default => 'consultar_webhook' },
        str_contains($caminho, '/webhook/callbacks') => 'reenviar_webhook',
        str_ends_with($caminho, '/pdf') => 'pdf_boleto',
        str_ends_with($caminho, '/cancelar') => 'cancelar_boleto',
        $metodo === 'POST' => 'emitir_boleto',
        default => 'consultar_boleto',
    };
}

function rotulosOperacaoBanco(): array
{
    return [
        'token' => 'Autenticação (token)',
        'emitir_boleto' => 'Emissão de boleto',
        'consultar_boleto' => 'Consulta de boleto',
        'pdf_boleto' => 'PDF do boleto',
        'cancelar_boleto' => 'Cancelamento de boleto',
        'registrar_webhook' => 'Registro do webhook',
        'consultar_webhook' => 'Consulta do webhook',
        'excluir_webhook' => 'Exclusão do webhook',
        'reenviar_webhook' => 'Avisos enviados pelo banco',
    ];
}

/**
 * Guarda a chamada na auditoria do banco (banco_chamadas). Segredos saem
 * mascarados e o PDF não é guardado. Nunca derruba a chamada.
 */
function registrarChamadaBanco(array $conta, string $metodo, string $caminho, $corpo, int $status, $resposta, ?string $erro, int $duracaoMs): void
{
    try {
        $operacao = interOperacaoChamada($metodo, $caminho);
        if (is_array($corpo) && isset($corpo['client_secret'])) {
            $corpo['client_secret'] = '••••••';
        }
        if (is_array($resposta) && isset($resposta['access_token'])) {
            $resposta['access_token'] = '••••••';
        }
        if (is_array($resposta) && isset($resposta['pdf'])) {
            $resposta['pdf'] = '[PDF em base64, ' . strlen((string) $resposta['pdf']) . ' caracteres — não guardado]';
        }
        $texto = fn ($v) => $v === null ? null : mb_substr(is_string($v) ? $v : json_encode($v, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT), 0, 60000);
        $sucesso = $erro === null && $status >= 200 && $status < 300;
        $mensagem = $erro ?? ($sucesso ? null : interMensagemErro($status, $resposta));
        getDB()->prepare(
            'INSERT INTO banco_chamadas (conta_bancaria_id, operacao, conta_receber_id, usuario_id, origem, metodo, url, status_http, sucesso, requisicao, resposta, mensagem, duracao_ms)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)'
        )->execute([
            $conta['id'] ?? null, $operacao, interContextoTitulo(), usuarioAtualId(), usuarioAtualId() ? 'sistema' : 'automatico',
            $metodo, mb_substr(interUrlBase($conta) . $caminho, 0, 255), $status ?: null, $sucesso ? 1 : 0,
            $texto($corpo), $texto($resposta), $mensagem !== null ? mb_substr($mensagem, 0, 255) : null, $duracaoMs,
        ]);
    } catch (Throwable $e) {
        error_log('Falha ao registrar chamada ao banco: ' . $e->getMessage());
    }
}

/**
 * Chamada HTTP à API com o certificado da conta. Devolve [status, corpo decodificado].
 * $form = true manda application/x-www-form-urlencoded (só o token).
 * Toda chamada fica na auditoria do banco.
 */
function interHttp(array $conta, string $metodo, string $caminho, $corpo = null, ?string $token = null, bool $form = false): array
{
    $ch = curl_init(interUrlBase($conta) . $caminho);
    $headers = ['Accept: application/json'];
    if ($token) {
        $headers[] = 'Authorization: Bearer ' . $token;
    }
    if (!empty($conta['inter_conta_corrente'])) {
        $headers[] = 'x-conta-corrente: ' . preg_replace('/\D/', '', $conta['inter_conta_corrente']);
    }
    if ($corpo !== null) {
        $headers[] = 'Content-Type: ' . ($form ? 'application/x-www-form-urlencoded' : 'application/json');
        curl_setopt($ch, CURLOPT_POSTFIELDS, $form ? http_build_query($corpo) : json_encode($corpo, JSON_UNESCAPED_UNICODE));
    }
    curl_setopt_array($ch, [
        CURLOPT_CUSTOMREQUEST => $metodo,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_HTTPHEADER => $headers,
        CURLOPT_TIMEOUT => 30,
        CURLOPT_CONNECTTIMEOUT => 10,
        CURLOPT_SSLCERT => $conta['inter_certificado'],
        CURLOPT_SSLKEY => $conta['inter_chave'],
    ]);
    $inicio = microtime(true);
    $resposta = curl_exec($ch);
    $duracao = (int) round((microtime(true) - $inicio) * 1000);
    $status = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $erroCurl = curl_error($ch);
    curl_close($ch);
    if ($resposta === false) {
        $erro = 'Sem resposta do Banco Inter: ' . $erroCurl;
        registrarChamadaBanco($conta, $metodo, $caminho, $corpo, $status, null, $erro, $duracao);
        throw new RuntimeException($erro);
    }
    $decodificada = json_decode((string) $resposta, true) ?? $resposta;
    registrarChamadaBanco($conta, $metodo, $caminho, $corpo, $status, $decodificada, null, $duracao);
    return [$status, $decodificada];
}

/** Mensagem legível de um erro da API ("title", "detail", "violacoes"...). */
function interMensagemErro(int $status, $corpo): string
{
    if (is_array($corpo)) {
        $partes = array_filter([$corpo['title'] ?? null, $corpo['detail'] ?? null, $corpo['message'] ?? null, $corpo['error_description'] ?? null]);
        foreach ((array) ($corpo['violacoes'] ?? []) as $v) {
            $partes[] = trim(($v['propriedade'] ?? '') . ': ' . ($v['razao'] ?? ''), ': ');
        }
        if ($partes) {
            return 'Inter (' . $status . '): ' . implode(' · ', $partes);
        }
    }
    return 'Inter respondeu ' . $status . (is_string($corpo) && $corpo !== '' ? ': ' . mb_substr(strip_tags($corpo), 0, 200) : '');
}

/** Access token (guardado criptografado até perto de vencer). */
function interToken(PDO $pdo, array $conta, bool $renovar = false): string
{
    if (!$renovar && $conta['inter_token'] && $conta['inter_token_expira'] && strtotime($conta['inter_token_expira']) > time() + 60) {
        $token = descriptografar($conta['inter_token']);
        if ($token) {
            return $token;
        }
    }
    if ($falta = pendenciasInter($conta)) {
        throw new InvalidArgumentException('Configure a integração com o Inter: falta ' . implode(', ', $falta) . '.');
    }
    [$status, $corpo] = interHttp($conta, 'POST', '/oauth/v2/token', [
        'client_id' => $conta['inter_client_id'],
        'client_secret' => descriptografar($conta['inter_client_secret']),
        'grant_type' => 'client_credentials',
        'scope' => INTER_ESCOPOS,
    ], null, true);
    if ($status !== 200 || empty($corpo['access_token'])) {
        throw new RuntimeException(interMensagemErro($status, $corpo));
    }
    $expira = date('Y-m-d H:i:s', time() + (int) ($corpo['expires_in'] ?? 3600));
    $pdo->prepare('UPDATE contas_bancarias SET inter_token = ?, inter_token_expira = ? WHERE id = ?')
        ->execute([criptografar($corpo['access_token']), $expira, $conta['id']]);
    return $corpo['access_token'];
}

/** Chamada autenticada; renova o token uma vez se o Inter responder 401. */
function interApi(PDO $pdo, array $conta, string $metodo, string $caminho, ?array $corpo = null): array
{
    [$status, $resposta] = interHttp($conta, $metodo, $caminho, $corpo, interToken($pdo, $conta));
    if ($status === 401) {
        [$status, $resposta] = interHttp($conta, $metodo, $caminho, $corpo, interToken($pdo, $conta, true));
    }
    if ($status >= 400) {
        throw new RuntimeException(interMensagemErro($status, $resposta));
    }
    return is_array($resposta) ? $resposta : [];
}

// ---------------------------------------------------------------
// Boleto
// ---------------------------------------------------------------

/**
 * Corpo da emissão: valor, vencimento, pagador (cadastro completo) e
 * multa/juros pela regra do tipo de recebimento (Cadastros › Tipos de recebimento).
 */
function corpoBoletoInter(PDO $pdo, array $titulo, array $pagador): array
{
    if ($falta = faltandoParaBoleto($pagador)) {
        throw new InvalidArgumentException('Complete o cadastro do pagador para emitir boleto: falta ' . implode(', ', $falta) . '.');
    }
    $doc = apenasDigitos((string) $pagador['documento']);
    $fone = apenasDigitos((string) ($pagador['celular'] ?: $pagador['telefone']));
    $corpo = [
        'seuNumero' => (string) $titulo['id'],
        'valorNominal' => round((float) $titulo['valor'], 2),
        'dataVencimento' => $titulo['data_vencimento'],
        'numDiasAgenda' => 60,
        'pagador' => array_filter([
            'cpfCnpj' => $doc,
            'tipoPessoa' => strlen($doc) === 14 ? 'JURIDICA' : 'FISICA',
            'nome' => mb_substr($pagador['nome'], 0, 100),
            'endereco' => mb_substr((string) $pagador['logradouro'], 0, 90),
            'numero' => mb_substr((string) $pagador['numero'], 0, 10),
            'complemento' => mb_substr((string) $pagador['complemento'], 0, 30),
            'bairro' => mb_substr((string) $pagador['bairro'], 0, 60),
            'cidade' => mb_substr((string) $pagador['cidade'], 0, 60),
            'uf' => $pagador['uf'],
            'cep' => apenasDigitos((string) $pagador['cep']),
            'email' => $pagador['email'] ?: null,
            'ddd' => strlen($fone) >= 10 ? substr($fone, 0, 2) : null,
            'telefone' => strlen($fone) >= 10 ? substr($fone, 2) : null,
        ], fn ($v) => $v !== null && $v !== ''),
    ];
    [$multaPct, $jurosMes] = regraAtualizacao($pdo, $titulo);
    if ($multaPct > 0) {
        $corpo['multa'] = ['codigo' => 'PERCENTUAL', 'taxa' => round($multaPct, 2)];
    }
    if ($jurosMes > 0) {
        $corpo['mora'] = ['codigo' => 'TAXAMENSAL', 'taxa' => round($jurosMes, 2)];
    }
    if (!empty($titulo['descricao'])) {
        $corpo['mensagem'] = ['linha1' => mb_substr($titulo['descricao'], 0, 78)];
    }
    return $corpo;
}

/** Emite o boleto do título na conta do Inter e guarda linha digitável e Pix. */
function emitirBoletoInter(PDO $pdo, array $titulo, array $conta): void
{
    if (!in_array($titulo['status'], ['pendente', 'devolvido'], true)) {
        throw new InvalidArgumentException('Só dá para emitir boleto de título em aberto.');
    }
    if ($titulo['boleto_codigo'] && !in_array($titulo['boleto_situacao'], ['CANCELADO', 'EXPIRADO'], true)) {
        throw new InvalidArgumentException('Este título já tem boleto emitido.');
    }
    $stmt = $pdo->prepare('SELECT * FROM pessoas WHERE id = ?');
    $stmt->execute([(int) $titulo['emitente_id']]);
    $pagador = $stmt->fetch();
    if (!$pagador) {
        throw new InvalidArgumentException('Informe o pagador do título antes de emitir o boleto.');
    }
    interContextoTitulo((int) $titulo['id'], true);
    $resposta = interApi($pdo, $conta, 'POST', '/cobranca/v3/cobrancas', corpoBoletoInter($pdo, $titulo, $pagador));
    $codigo = $resposta['codigoSolicitacao'] ?? null;
    if (!$codigo) {
        throw new RuntimeException('O Inter não devolveu o código da cobrança.');
    }
    $pdo->prepare("UPDATE contas_receber SET boleto_conta_id = ?, boleto_codigo = ?, boleto_situacao = 'EMITIDO', boleto_emitido_em = NOW(),
                   boleto_nosso_numero = NULL, boleto_linha_digitavel = NULL, boleto_codigo_barras = NULL, boleto_pix = NULL, conta_destino_id = ? WHERE id = ?")
        ->execute([$conta['id'], $codigo, $conta['id'], $titulo['id']]);
    // A linha digitável sai na consulta (o Inter registra o boleto em seguida).
    try {
        consultarBoletoInter($pdo, (int) $titulo['id']);
    } catch (Throwable $e) {
        // fica para a próxima consulta (webhook ou conferência)
    }
}

/**
 * Consulta a cobrança no Inter e atualiza o título; se estiver paga e o
 * título ainda em aberto, dá baixa na conta do Inter. Devolve a situação.
 */
function consultarBoletoInter(PDO $pdo, int $tituloId): string
{
    $stmt = $pdo->prepare('SELECT r.*, e.nome AS emitente_nome, cl.taxa_padrao AS cliente_taxa FROM contas_receber r LEFT JOIN pessoas e ON e.id = r.emitente_id LEFT JOIN pessoas cl ON cl.id = r.cliente_id WHERE r.id = ?');
    $stmt->execute([$tituloId]);
    $t = $stmt->fetch();
    if (!$t || !$t['boleto_codigo']) {
        throw new InvalidArgumentException('Este título não tem boleto do Inter.');
    }
    $conta = contaBancaria($pdo, (int) $t['boleto_conta_id']);
    interContextoTitulo($tituloId, true);
    $r = interApi($pdo, $conta, 'GET', '/cobranca/v3/cobrancas/' . rawurlencode($t['boleto_codigo']));
    $cob = $r['cobranca'] ?? [];
    $situacao = (string) ($cob['situacao'] ?? 'DESCONHECIDA');
    $pdo->prepare('UPDATE contas_receber SET boleto_situacao = ?, boleto_nosso_numero = COALESCE(?, boleto_nosso_numero), boleto_linha_digitavel = COALESCE(?, boleto_linha_digitavel),
                   boleto_codigo_barras = COALESCE(?, boleto_codigo_barras), boleto_pix = COALESCE(?, boleto_pix) WHERE id = ?')
        ->execute([$situacao, $r['boleto']['nossoNumero'] ?? null, $r['boleto']['linhaDigitavel'] ?? null, $r['boleto']['codigoBarras'] ?? null,
            $r['pix']['pixCopiaECola'] ?? null, $tituloId]);

    if ($situacao === 'RECEBIDO' && in_array($t['status'], ['pendente', 'devolvido'], true)) {
        $valor = (float) ($cob['valorTotalRecebido'] ?? $cob['valorNominal'] ?? $t['valor']);
        $data = substr((string) ($cob['dataSituacao'] ?? date('Y-m-d')), 0, 10) ?: date('Y-m-d');
        $pdo->beginTransaction();
        baixarReceber($pdo, $t, $data, $valor, (int) $conta['id']);
        $pdo->commit();
        registrarAtividade('boletos', 'baixa_automatica', 'Boleto pago no ' . $conta['nome'] . ': título #' . $tituloId . ' (' . ($t['emitente_nome'] ?? '-') . ') recebido ' . formatMoney($valor) . ' em ' . formatDate($data));
    }
    return $situacao;
}

/** PDF do boleto (binário). */
function pdfBoletoInter(PDO $pdo, array $titulo): string
{
    $conta = contaBancaria($pdo, (int) $titulo['boleto_conta_id']);
    interContextoTitulo((int) $titulo['id'], true);
    $r = interApi($pdo, $conta, 'GET', '/cobranca/v3/cobrancas/' . rawurlencode($titulo['boleto_codigo']) . '/pdf');
    $pdf = base64_decode((string) ($r['pdf'] ?? ''), true);
    if (!$pdf) {
        throw new RuntimeException('O Inter não devolveu o PDF do boleto.');
    }
    return $pdf;
}

function cancelarBoletoInter(PDO $pdo, array $titulo, string $motivo): void
{
    $conta = contaBancaria($pdo, (int) $titulo['boleto_conta_id']);
    interContextoTitulo((int) $titulo['id'], true);
    interApi($pdo, $conta, 'POST', '/cobranca/v3/cobrancas/' . rawurlencode($titulo['boleto_codigo']) . '/cancelar', ['motivoCancelamento' => mb_substr($motivo ?: 'Cancelado pelo beneficiário', 0, 50)]);
    $pdo->prepare("UPDATE contas_receber SET boleto_situacao = 'CANCELADO' WHERE id = ?")->execute([$titulo['id']]);
}

function rotuloSituacaoBoleto(?string $s): string
{
    return [
        'EMITIDO' => 'Emitido', 'A_RECEBER' => 'A receber', 'ATRASADO' => 'Atrasado', 'RECEBIDO' => 'Pago', 'MARCADO_RECEBIDO' => 'Marcado como pago',
        'CANCELADO' => 'Cancelado', 'EXPIRADO' => 'Expirado', 'FALHA_EMISSAO' => 'Falha na emissão', 'EM_PROCESSAMENTO' => 'Em processamento', 'PROTESTO' => 'Em protesto',
    ][$s ?? ''] ?? ($s ?: '-');
}

// ---------------------------------------------------------------
// Webhook (o Inter avisa quando o boleto/Pix é pago, cancelado...)
// ---------------------------------------------------------------

/** URL que o Inter chama: /webhook/inter.php?conta=<id>&s=<segredo>. */
function urlWebhookInter(PDO $pdo, array $conta): string
{
    if (empty($conta['webhook_segredo'])) {
        $conta['webhook_segredo'] = bin2hex(random_bytes(20));
        $pdo->prepare('UPDATE contas_bancarias SET webhook_segredo = ? WHERE id = ?')->execute([$conta['webhook_segredo'], $conta['id']]);
    }
    $https = !empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off';
    $host = (defined('URL_SISTEMA') && URL_SISTEMA) ? rtrim(URL_SISTEMA, '/') : ($https ? 'https://' : 'http://') . ($_SERVER['HTTP_HOST'] ?? 'localhost');
    return $host . '/webhook/inter.php?conta=' . (int) $conta['id'] . '&s=' . $conta['webhook_segredo'];
}

function registrarWebhookInter(PDO $pdo, array $conta): string
{
    $url = urlWebhookInter($pdo, $conta);
    if (!str_starts_with($url, 'https://')) {
        throw new InvalidArgumentException('O Inter só aceita webhook em endereço https:// — acesse o sistema pelo endereço com https (ou defina URL_SISTEMA no config.php).');
    }
    interContextoTitulo(null, true);
    interApi($pdo, $conta, 'PUT', '/cobranca/v3/cobrancas/webhook', ['webhookUrl' => $url]);
    $pdo->prepare('UPDATE contas_bancarias SET webhook_registrado_em = NOW() WHERE id = ?')->execute([$conta['id']]);
    return $url;
}

/** Webhook cadastrado hoje no banco (endereço e data), ou null se não houver. */
function consultarWebhookInter(PDO $pdo, array $conta): ?array
{
    interContextoTitulo(null, true);
    try {
        $r = interApi($pdo, $conta, 'GET', '/cobranca/v3/cobrancas/webhook');
    } catch (RuntimeException $e) {
        if (str_contains($e->getMessage(), '(404)') || str_contains($e->getMessage(), ' 404')) {
            return null;
        }
        throw $e;
    }
    return !empty($r['webhookUrl']) ? $r : null;
}

function excluirWebhookInter(PDO $pdo, array $conta): void
{
    interContextoTitulo(null, true);
    interApi($pdo, $conta, 'DELETE', '/cobranca/v3/cobrancas/webhook');
    $pdo->prepare('UPDATE contas_bancarias SET webhook_registrado_em = NULL WHERE id = ?')->execute([$conta['id']]);
}

/**
 * Processa os avisos do Inter (lista de cobranças). Não confia só no aviso:
 * para cada cobrança conhecida, consulta a API e só então dá a baixa.
 * Devolve quantos títulos foram atualizados.
 */
function processarWebhookInter(PDO $pdo, int $contaId, array $avisos): int
{
    $atualizados = 0;
    foreach ($avisos as $aviso) {
        $codigo = (string) ($aviso['codigoSolicitacao'] ?? '');
        $pdo->prepare('INSERT INTO webhook_eventos (conta_id, codigo, situacao, payload, ip) VALUES (?, ?, ?, ?, ?)')
            ->execute([$contaId, $codigo ?: null, $aviso['situacao'] ?? null, json_encode($aviso, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT), $_SERVER['REMOTE_ADDR'] ?? null]);
        $eventoId = (int) $pdo->lastInsertId();
        $stmt = $pdo->prepare('SELECT id FROM contas_receber WHERE boleto_codigo = ? AND boleto_conta_id = ?');
        $stmt->execute([$codigo, $contaId]);
        $tituloId = $stmt->fetchColumn();
        try {
            $resultado = $tituloId ? 'Título #' . $tituloId . ': ' . rotuloSituacaoBoleto(consultarBoletoInter($pdo, (int) $tituloId)) : 'cobrança não encontrada no sistema';
            $pdo->prepare('UPDATE webhook_eventos SET processado = ?, resultado = ? WHERE id = ?')->execute([$tituloId ? 1 : 0, $resultado, $eventoId]);
            $atualizados += $tituloId ? 1 : 0;
        } catch (Throwable $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            $pdo->prepare('UPDATE webhook_eventos SET resultado = ? WHERE id = ?')->execute([mb_substr($e->getMessage(), 0, 250), $eventoId]);
        }
    }
    return $atualizados;
}

/** Processa de novo um aviso já recebido (ex.: deu erro na primeira vez). */
function reprocessarEventoWebhook(PDO $pdo, int $eventoId): string
{
    $stmt = $pdo->prepare('SELECT * FROM webhook_eventos WHERE id = ? AND aceito = 1');
    $stmt->execute([$eventoId]);
    $ev = $stmt->fetch();
    if (!$ev || !$ev['codigo']) {
        throw new InvalidArgumentException('Aviso sem cobrança para processar.');
    }
    $stmt = $pdo->prepare('SELECT id FROM contas_receber WHERE boleto_codigo = ? AND boleto_conta_id = ?');
    $stmt->execute([$ev['codigo'], $ev['conta_id']]);
    $tituloId = (int) $stmt->fetchColumn();
    if (!$tituloId) {
        throw new InvalidArgumentException('A cobrança ' . $ev['codigo'] . ' não está em nenhum título do sistema.');
    }
    $resultado = 'Título #' . $tituloId . ': ' . rotuloSituacaoBoleto(consultarBoletoInter($pdo, $tituloId)) . ' (reprocessado)';
    $pdo->prepare('UPDATE webhook_eventos SET processado = 1, resultado = ? WHERE id = ?')->execute([$resultado, $eventoId]);
    return $resultado;
}

/** Títulos com boleto em aberto (de uma conta, ou de todas). */
function boletosEmAberto(PDO $pdo, ?int $contaId = null): array
{
    $stmt = $pdo->prepare(
        "SELECT r.*, e.nome AS pagador_nome FROM contas_receber r LEFT JOIN pessoas e ON e.id = r.emitente_id
         WHERE r.boleto_codigo IS NOT NULL AND r.status IN ('pendente', 'devolvido')
           AND (r.boleto_situacao IS NULL OR r.boleto_situacao NOT IN ('CANCELADO', 'EXPIRADO'))" . ($contaId ? ' AND r.boleto_conta_id = ?' : '') . '
         ORDER BY r.data_vencimento, r.id'
    );
    $stmt->execute($contaId ? [$contaId] : []);
    return $stmt->fetchAll();
}

/**
 * Consulta no banco os boletos em aberto e dá baixa nos pagos (cron e a aba
 * Conferir boletos). Devolve [conferidos, pagos, erros[id => mensagem], situacoes[id => situação]].
 */
function conferirBoletosInter(PDO $pdo, ?int $contaId = null): array
{
    $boletos = boletosEmAberto($pdo, $contaId);
    $pagos = 0;
    $erros = [];
    $situacoes = [];
    foreach ($boletos as $b) {
        try {
            $situacoes[$b['id']] = consultarBoletoInter($pdo, (int) $b['id']);
            $pagos += $situacoes[$b['id']] === 'RECEBIDO' ? 1 : 0;
        } catch (Throwable $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            $erros[$b['id']] = $e->getMessage();
        }
    }
    return [count($boletos), $pagos, $erros, $situacoes];
}
