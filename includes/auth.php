<?php

function tentarLogin(PDO $pdo, string $email, string $senha): bool
{
    $stmt = $pdo->prepare(
        'SELECT u.*, p.nome AS perfil_nome, p.administrador
         FROM usuarios u JOIN perfis p ON p.id = u.perfil_id
         WHERE u.email = ? AND u.ativo = 1'
    );
    $stmt->execute([$email]);
    $usuario = $stmt->fetch();

    if (!$usuario || !password_verify($senha, $usuario['senha'])) {
        return false;
    }

    iniciarSessaoUsuario($pdo, $usuario);
    registrarAtividade('login', 'login', 'Login realizado');
    return true;
}

function iniciarSessaoUsuario(PDO $pdo, array $usuario): void
{
    $stmt = $pdo->prepare(
        'SELECT perm.chave FROM perfil_permissoes pp JOIN permissoes perm ON perm.id = pp.permissao_id WHERE pp.perfil_id = ?'
    );
    $stmt->execute([$usuario['perfil_id']]);
    $permissoes = $stmt->fetchAll(PDO::FETCH_COLUMN);

    // Um token novo a cada login "derruba" qualquer sessão anterior dessa
    // conta: a sessão antiga só descobre isso na próxima ação dela.
    $tokenSessao = bin2hex(random_bytes(32));
    $pdo->prepare('UPDATE usuarios SET token_sessao = ? WHERE id = ?')->execute([$tokenSessao, $usuario['id']]);

    session_regenerate_id(true);
    $_SESSION['user_id'] = (int) $usuario['id'];
    $_SESSION['user_nome'] = $usuario['nome'];
    $_SESSION['user_perfil_id'] = (int) $usuario['perfil_id'];
    $_SESSION['user_perfil_nome'] = $usuario['perfil_nome'] ?? '';
    $_SESSION['user_administrador'] = (bool) ($usuario['administrador'] ?? false);
    $_SESSION['user_permissoes'] = $permissoes;
    $_SESSION['user_tema'] = $usuario['tema'] ?? 'claro';
    $_SESSION['token_sessao'] = $tokenSessao;
    $_SESSION['ultima_atividade'] = time();
}

function fazerLogout(string $descricao = 'Logout realizado'): void
{
    if (usuarioLogado()) {
        registrarAtividadeUsuario(usuarioAtualId(), usuarioAtualNome(), 'login', 'logout', $descricao);
    }

    $_SESSION = [];
    session_destroy();
}

function usuarioLogado(): bool
{
    return isset($_SESSION['user_id']);
}

function usuarioAtualId(): ?int
{
    return $_SESSION['user_id'] ?? null;
}

function usuarioAtualNome(): string
{
    return $_SESSION['user_nome'] ?? '';
}

function usuarioAtualTema(): string
{
    return ($_SESSION['user_tema'] ?? 'claro') === 'escuro' ? 'escuro' : 'claro';
}

function usuarioEhAdmin(): bool
{
    return (bool) ($_SESSION['user_administrador'] ?? false);
}

function usuarioTemPermissao(string $chave): bool
{
    return usuarioEhAdmin() || in_array($chave, $_SESSION['user_permissoes'] ?? [], true);
}

// Permissão que cada pasta de telas exige (a página só abre com ela).
// Telas fora da lista (Início, Minha conta...) só pedem login.
function permissaoDaPagina(string $script): ?array
{
    $mapa = [
        '/operacoes/' => ['operacoes', 'Você não tem acesso às operações de desconto.'],
        '/adiantamentos/criar_rapido.php' => ['operacoes', 'Você não tem acesso às operações de desconto.'],
        '/adiantamentos/' => ['repasses', 'Você não tem acesso aos repasses.'],
        '/receber/' => ['receber', 'Você não tem acesso ao contas a receber.'],
        '/pagar/' => ['pagar', 'Você não tem acesso ao contas a pagar.'],
        '/financeiro/' => ['movimentacao', 'Você não tem acesso aos recebimentos e pagamentos.'],
        '/cartoes/' => ['cartoes', 'Você não tem acesso aos cartões de crédito.'],
        '/contas/' => ['contas', 'Você não tem acesso às contas bancárias.'],
        '/pessoas/' => ['pessoas', 'Você não tem acesso aos cadastros de pessoas.'],
        '/categorias/' => ['cadastros_auxiliares', 'Você não tem acesso aos cadastros auxiliares.'],
        '/configuracoes/recebimento.php' => ['cadastros_auxiliares', 'Você não tem acesso aos cadastros auxiliares.'],
    ];
    foreach ($mapa as $prefixo => $permissao) {
        if (str_starts_with($script, $prefixo)) {
            return $permissao;
        }
    }
    return null;
}

function sessaoTimeoutSegundos(): int
{
    return max(1, (int) configuracao('sessao_timeout_minutos', 60)) * 60;
}

// Quantos segundos faltam para a sessão atual expirar por inatividade —
// usado pelo timer regressivo exibido no topo do sistema.
function sessaoSegundosRestantes(): int
{
    return max(0, sessaoTimeoutSegundos() - (time() - ($_SESSION['ultima_atividade'] ?? time())));
}

function sairPorSessao(string $descricao, string $mensagem): void
{
    fazerLogout($descricao);
    session_start();
    if (ehRequisicaoAjax()) {
        http_response_code(401);
        responderJson(['sucesso' => false, 'mensagem' => $mensagem]);
    }
    flash('danger', $mensagem);
    redirect('/login.php');
}

function verificarTimeoutSessao(): void
{
    if ((time() - ($_SESSION['ultima_atividade'] ?? time())) > sessaoTimeoutSegundos()) {
        sairPorSessao('Sessão expirada por inatividade', 'Sua sessão expirou por inatividade. Entre novamente.');
    }
    $_SESSION['ultima_atividade'] = time();
}

// Não permite duas sessões abertas ao mesmo tempo para a mesma conta: quando
// alguém loga de novo (outro aparelho/navegador), o token muda no banco e
// esta sessão passa a não bater mais com ele. Usuário desativado também sai.
function verificarSessaoUnica(): void
{
    $stmt = getDB()->prepare('SELECT token_sessao FROM usuarios WHERE id = ? AND ativo = 1');
    $stmt->execute([usuarioAtualId()]);
    $tokenAtual = $stmt->fetchColumn();

    if ($tokenAtual === false || $tokenAtual !== ($_SESSION['token_sessao'] ?? null)) {
        sairPorSessao('Sessão encerrada por login em outro dispositivo', 'Sua conta foi acessada em outro dispositivo. Entre novamente.');
    }
}

function exigirLogin(): void
{
    if (!usuarioLogado()) {
        if (ehRequisicaoAjax()) {
            http_response_code(401);
            responderJson(['sucesso' => false, 'mensagem' => 'Sessão expirada. Entre novamente.']);
        }
        redirect('/login.php');
    }
    verificarSessaoUnica();
    verificarTimeoutSessao();

    $permissao = permissaoDaPagina((string) parse_url($_SERVER['SCRIPT_NAME'] ?? '', PHP_URL_PATH));
    if ($permissao && !usuarioTemPermissao($permissao[0])) {
        negarAcesso($permissao[1]);
    }
}

function negarAcesso(string $mensagem): void
{
    if (ehRequisicaoAjax()) {
        http_response_code(403);
        responderJson(['sucesso' => false, 'mensagem' => $mensagem]);
    }
    flash('danger', $mensagem);
    redirect('/sem_acesso.php');
}

function exigirAdmin(): void
{
    exigirLogin();
    if (!usuarioEhAdmin()) {
        negarAcesso('Apenas administradores podem acessar esta página.');
    }
}

function exigirPermissao(string $chave, string $mensagem): void
{
    exigirLogin();
    if (!usuarioTemPermissao($chave)) {
        negarAcesso($mensagem);
    }
}

// Para ações dentro de uma tela (ex.: dar baixa no contas a receber).
function exigirPermissaoAcao(string $chave, string $mensagem): void
{
    if (!usuarioTemPermissao($chave)) {
        negarAcesso($mensagem);
    }
}

function senhaDoUsuarioConfere(PDO $pdo, string $senha): bool
{
    $quem = (string) usuarioAtualId();
    if (limiteDeTentativasExcedido($pdo, 'senha_confirmacao', $quem, 5, 20, 15)) {
        return false;
    }
    $stmt = $pdo->prepare('SELECT senha FROM usuarios WHERE id = ?');
    $stmt->execute([usuarioAtualId()]);
    if ($senha !== '' && password_verify($senha, (string) $stmt->fetchColumn())) {
        limparTentativasAcesso($pdo, 'senha_confirmacao', $quem);
        return true;
    }
    registrarTentativaAcesso($pdo, 'senha_confirmacao', $quem);
    return false;
}
