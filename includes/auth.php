<?php

function tentarLogin(PDO $pdo, string $email, string $senha): bool
{
    $stmt = $pdo->prepare('SELECT * FROM usuarios WHERE email = ? AND ativo = 1');
    $stmt->execute([$email]);
    $usuario = $stmt->fetch();

    if (!$usuario || !password_verify($senha, $usuario['senha'])) {
        return false;
    }

    iniciarSessaoUsuario($usuario);
    return true;
}

function iniciarSessaoUsuario(array $usuario): void
{
    session_regenerate_id(true);
    $_SESSION['user_id'] = (int) $usuario['id'];
    $_SESSION['user_nome'] = $usuario['nome'];
    $_SESSION['user_tema'] = $usuario['tema'] ?? 'claro';
    $_SESSION['ultima_atividade'] = time();
}

function fazerLogout(): void
{
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

function sessaoTimeoutSegundos(): int
{
    return max(1, (int) SESSAO_TIMEOUT_MINUTOS) * 60;
}

function sessaoSegundosRestantes(): int
{
    return max(0, sessaoTimeoutSegundos() - (time() - ($_SESSION['ultima_atividade'] ?? time())));
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
    if ((time() - ($_SESSION['ultima_atividade'] ?? time())) > sessaoTimeoutSegundos()) {
        fazerLogout();
        session_start();
        flash('danger', 'Sua sessão expirou por inatividade. Entre novamente.');
        redirect('/login.php');
    }
    $_SESSION['ultima_atividade'] = time();
}

// Bloqueio simples contra chute de senha: 5 erros em 15 minutos.
function loginBloqueado(PDO $pdo, string $chave): bool
{
    $stmt = $pdo->prepare('SELECT COUNT(*) FROM tentativas_login WHERE chave = ? AND criado_em > NOW() - INTERVAL 15 MINUTE');
    $stmt->execute([$chave]);
    return (int) $stmt->fetchColumn() >= 5;
}

function registrarFalhaLogin(PDO $pdo, string $chave): void
{
    $pdo->prepare('INSERT INTO tentativas_login (chave) VALUES (?)')->execute([$chave]);
    $pdo->exec('DELETE FROM tentativas_login WHERE criado_em < NOW() - INTERVAL 1 DAY');
}

function limparFalhasLogin(PDO $pdo, string $chave): void
{
    $pdo->prepare('DELETE FROM tentativas_login WHERE chave = ?')->execute([$chave]);
}
