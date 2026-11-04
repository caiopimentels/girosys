<?php
require_once __DIR__ . '/../includes/bootstrap.php';

if (usuarioLogado()) {
    redirect('/index.php');
}

$pdo = getDB();
$nomeSistema = nomeSistema();
// Banco novo, sem nenhum usuário: a tela vira "criar o primeiro acesso".
$primeiroAcesso = (int) $pdo->query('SELECT COUNT(*) FROM usuarios')->fetchColumn() === 0;
$erro = null;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $email = mb_strtolower(input('email'));
    $senha = input('senha');

    if ($primeiroAcesso) {
        $nome = formatarNomePessoa(input('nome'));
        if ($nome === '' || !filter_var($email, FILTER_VALIDATE_EMAIL) || mb_strlen($senha) < 8) {
            $erro = 'Informe nome, um e-mail válido e senha com pelo menos 8 caracteres.';
        } else {
            $pdo->prepare('INSERT INTO usuarios (nome, email, senha, perfil_id) VALUES (?, ?, ?, 1)')->execute([$nome, $email, password_hash($senha, PASSWORD_DEFAULT)]);
            tentarLogin($pdo, $email, $senha);
            redirect('/contas/form.php?primeira=1');
        }
    } elseif ($email === '' || $senha === '') {
        $erro = 'Informe e-mail e senha.';
    } elseif (limiteDeTentativasExcedido($pdo, 'login', $email, 5, 20, 15)) {
        $erro = 'Muitas tentativas de login. Aguarde alguns minutos e tente novamente.';
    } elseif (tentarLogin($pdo, $email, $senha)) {
        limparTentativasAcesso($pdo, 'login', $email);
        redirect('/index.php');
    } else {
        registrarTentativaAcesso($pdo, 'login', $email);
        registrarTentativaLoginFalha($email);
        $erro = 'E-mail ou senha inválidos.';
    }
}
?>
<!doctype html>
<html lang="pt-br">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="csrf-token" content="<?= e(csrfToken()) ?>">
    <title>Entrar - <?= e($nomeSistema) ?></title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.css" rel="stylesheet">
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;450;500;550;600&display=swap" rel="stylesheet">
    <link href="<?= e(assetUrl('/assets/css/app.css')) ?>" rel="stylesheet">
    <?php if ($iconeAba = urlIconeAba()): ?><link rel="icon" type="image/png" href="<?= e($iconeAba) ?>"><?php endif; ?>
    <style>:root { --accent: <?= e(corSistema('primaria')) ?>; --accent-dark: <?= e(corSistema('secundaria')) ?>; }</style>
</head>
<body class="login-page">
<div class="login-card">
    <div class="mb-4 text-center">
        <span class="login-logo"><?= htmlLogoSistema('login') ?></span>
        <div class="login-marca"><?= e($nomeSistema) ?></div>
        <div class="login-sub"><?= $primeiroAcesso ? 'Crie o seu acesso para começar' : 'Entre com suas credenciais' ?></div>
    </div>

    <?php foreach (getFlashes() as $flash): ?>
        <div class="alert alert-<?= e($flash['tipo']) ?> py-2 text-center"><?= e($flash['mensagem']) ?></div>
    <?php endforeach; ?>
    <?php if ($erro): ?>
        <div class="alert alert-danger py-2 text-center"><?= e($erro) ?></div>
    <?php endif; ?>

    <form method="post">
        <?php if ($primeiroAcesso): ?>
        <div class="mb-3">
            <label class="form-label">Seu nome</label>
            <input type="text" name="nome" class="form-control" required autofocus value="<?= e($_POST['nome'] ?? '') ?>">
        </div>
        <?php endif; ?>
        <div class="mb-3">
            <label class="form-label">E-mail</label>
            <input type="email" name="email" class="form-control" required <?= $primeiroAcesso ? '' : 'autofocus' ?> value="<?= e($_POST['email'] ?? '') ?>">
        </div>
        <div class="mb-4">
            <label class="form-label">Senha<?= $primeiroAcesso ? ' (mínimo 8 caracteres)' : '' ?></label>
            <div class="login-senha-wrap">
                <input type="password" name="senha" id="campo-senha" class="form-control" required <?= $primeiroAcesso ? 'minlength="8"' : '' ?>>
                <button type="button" class="login-senha-toggle" id="btn-mostrar-senha" aria-label="Mostrar senha" tabindex="-1">
                    <i class="bi bi-eye"></i>
                </button>
            </div>
            <?php if (!$primeiroAcesso): ?><a href="/recuperar_senha.php" class="login-esqueci">Esqueci minha senha</a><?php endif; ?>
        </div>
        <button type="submit" class="btn btn-brand w-100 py-2"><?= $primeiroAcesso ? 'Criar acesso' : 'Entrar' ?></button>
    </form>
</div>
<script src="/assets/js/csrf.js"></script>
<script>
(function () {
    const campo = document.getElementById('campo-senha');
    const botao = document.getElementById('btn-mostrar-senha');
    botao.addEventListener('click', function () {
        const visivel = campo.type === 'text';
        campo.type = visivel ? 'password' : 'text';
        botao.querySelector('i').className = visivel ? 'bi bi-eye' : 'bi bi-eye-slash';
    });
})();
</script>
</body>
</html>
