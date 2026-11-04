<?php
require_once __DIR__ . '/../includes/bootstrap.php';
require_once __DIR__ . '/../includes/smtp_mailer.php';

if (usuarioLogado()) {
    redirect('/index.php');
}

$pdo = getDB();
$config = buscarConfiguracoes($pdo);
$logoSistema = $config['logo_sistema'] ?? null;
$nomeSistema = nomeSistema();
$logoPosX = (int) ($config['logo_pos_x'] ?? 50);
$logoPosY = (int) ($config['logo_pos_y'] ?? 50);
$logoZoom = (float) ($config['logo_zoom'] ?? 1);
$logoDeslocamentoX = ($logoPosX - 50) * 0.9;
$logoDeslocamentoY = ($logoPosY - 50) * 0.9;

$erro = null;
$sucesso = null;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $email = trim(input('email'));

    if ($email === '') {
        $erro = 'Informe seu e-mail.';
    } elseif (limiteDeTentativasExcedido($pdo, 'recuperar_senha', $email, 3, 15, 60)) {
        // Não revela que foi limitado — mesma mensagem de sempre, para não
        // dar pista nenhuma a quem está tentando adivinhar/varrer e-mails.
        $sucesso = 'Se este e-mail estiver cadastrado, enviamos um link para redefinir a senha.';
    } else {
        registrarTentativaAcesso($pdo, 'recuperar_senha', $email);

        $stmt = $pdo->prepare('SELECT id, nome, email FROM usuarios WHERE email = ? AND ativo = 1');
        $stmt->execute([$email]);
        $usuario = $stmt->fetch();

        // Mensagem de sucesso é sempre a mesma, exista ou não o e-mail, para
        // não revelar quais e-mails têm cadastro no sistema.
        $sucesso = 'Se este e-mail estiver cadastrado, enviamos um link para redefinir a senha.';

        if ($usuario) {
            // Invalida qualquer link anterior ainda não usado, para não deixar
            // mais de um link válido circulando para a mesma conta.
            $stmt = $pdo->prepare('UPDATE usuarios_reset_senha SET usado_em = NOW() WHERE usuario_id = ? AND usado_em IS NULL');
            $stmt->execute([$usuario['id']]);

            $token = bin2hex(random_bytes(32));
            $stmt = $pdo->prepare('INSERT INTO usuarios_reset_senha (usuario_id, token, expira_em) VALUES (?, ?, DATE_ADD(NOW(), INTERVAL 30 MINUTE))');
            $stmt->execute([$usuario['id'], $token]);

            $esquema = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
            $link = $esquema . '://' . $_SERVER['HTTP_HOST'] . '/redefinir_senha.php?token=' . $token;

            $corpoHtml = '
                <p>Olá, ' . e($usuario['nome']) . ',</p>
                <p>Recebemos um pedido para redefinir a senha da sua conta no ' . e($nomeSistema) . '.</p>
                <p><a href="' . e($link) . '" style="display:inline-block;padding:10px 20px;background:' . corSistema('primaria') . ';color:#fff;text-decoration:none;border-radius:6px;">Redefinir minha senha</a></p>
                <p>Ou copie e cole este link no navegador:<br>' . e($link) . '</p>
                <p>Este link expira em 30 minutos. Se você não pediu essa redefinição, pode ignorar este e-mail.</p>
            ';

            $resultado = enviarEmail(configSmtp(), $usuario['email'], $usuario['nome'], 'Redefinição de senha - ' . $nomeSistema, $corpoHtml);

            // Se o e-mail existe mas o envio falhou (ex.: SMTP mal configurado),
            // isso é um problema real do sistema, não uma tentativa de descobrir
            // e-mails cadastrados — então aqui sim mostramos o erro de verdade.
            if (!$resultado['sucesso']) {
                $sucesso = null;
                $erro = 'Não foi possível enviar o e-mail: ' . $resultado['mensagem'];
            }
        }
    }
}
?>
<!doctype html>
<html lang="pt-br">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="csrf-token" content="<?= e(csrfToken()) ?>">
    <?php if ($iconeAba = urlIconeAba()): ?><link rel="icon" type="image/png" href="<?= e($iconeAba) ?>"><?php endif; ?>
    <title>Recuperar senha - <?= e($nomeSistema) ?></title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.css" rel="stylesheet">
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;450;500;550;600&display=swap" rel="stylesheet">
    <link href="<?= e(assetUrl('/assets/css/app.css')) ?>" rel="stylesheet">
    <style>:root { --accent: <?= e(corSistema('primaria')) ?>; --accent-dark: <?= e(corSistema('secundaria')) ?>; }</style>
</head>
<body class="login-page">
<div class="login-card">
    <div class="mb-4 text-center">
        <span class="login-logo">
            <?php if (!empty($logoSistema)): ?>
                <img
                    src="<?= e($logoSistema) ?>"
                    alt="<?= e($nomeSistema) ?>"
                    class="login-logo-img"
                    style="
                        left: <?= $logoDeslocamentoX ?>%;
                        top: <?= $logoDeslocamentoY ?>%;
                        transform: scale(<?= $logoZoom ?>);
                    "
                >
            <?php else: ?>
                <span class="login-logo-letra">
                    <?= e(mb_strtoupper(mb_substr($nomeSistema, 0, 1))) ?>
                </span>
            <?php endif; ?>
        </span>
        <div class="login-marca">Recuperar senha</div>
        <div class="login-sub">Informe seu e-mail de acesso</div>
    </div>

    <?php if ($erro): ?>
        <div class="alert alert-danger py-2 text-center"><?= e($erro) ?></div>
    <?php endif; ?>

    <?php if ($sucesso): ?>
        <div class="alert alert-success py-2 text-center"><?= e($sucesso) ?></div>
    <?php else: ?>
        <form method="post">
            <div class="mb-4">
                <label class="form-label">E-mail</label>
                <input type="email" name="email" class="form-control" required autofocus value="<?= e($_POST['email'] ?? '') ?>">
            </div>
            <button type="submit" class="btn btn-brand w-100 py-2">Enviar link de recuperação</button>
        </form>
    <?php endif; ?>

    <div class="text-center mt-3">
        <a href="/login.php" class="login-esqueci"><i class="bi bi-arrow-left"></i> Voltar para o login</a>
    </div>
</div>
<script src="/assets/js/csrf.js"></script>
</body>
</html>
