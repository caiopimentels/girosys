<?php
require_once __DIR__ . '/../includes/bootstrap.php';

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

$token = $_GET['token'] ?? $_POST['token'] ?? '';
$erro = null;
$sucesso = null;

$stmt = $pdo->prepare(
    'SELECT r.*, u.nome AS usuario_nome
     FROM usuarios_reset_senha r
     JOIN usuarios u ON u.id = r.usuario_id
     WHERE r.token = ?'
);
$stmt->execute([$token]);
$reset = $stmt->fetch();

$tokenValido = $reset && $reset['usado_em'] === null && $reset['expira_em'] >= date('Y-m-d H:i:s');

if (!$reset) {
    $erro = 'Link inválido.';
} elseif (!$tokenValido) {
    $erro = 'Este link já foi usado ou expirou. Solicite um novo em "Esqueci minha senha".';
}

if ($tokenValido && $_SERVER['REQUEST_METHOD'] === 'POST') {
    $senha = input('senha');
    $confirmacao = input('senha_confirmacao');

    if (strlen($senha) < 6) {
        $erro = 'A senha deve ter pelo menos 6 caracteres.';
    } elseif ($senha !== $confirmacao) {
        $erro = 'As senhas não coincidem.';
    } else {
        $pdo->beginTransaction();

        $stmt = $pdo->prepare('UPDATE usuarios SET senha = ?, token_sessao = NULL WHERE id = ?');
        $stmt->execute([password_hash($senha, PASSWORD_DEFAULT), $reset['usuario_id']]);

        $stmt = $pdo->prepare('UPDATE usuarios_reset_senha SET usado_em = NOW() WHERE id = ?');
        $stmt->execute([$reset['id']]);

        $pdo->commit();

        $stmt = $pdo->prepare('SELECT nome FROM usuarios WHERE id = ?');
        $stmt->execute([$reset['usuario_id']]);
        registrarAtividadeUsuario((int) $reset['usuario_id'], (string) $stmt->fetchColumn(), 'login', 'senha', 'Senha redefinida pelo link de "esqueci minha senha"');

        flash('success', 'Senha redefinida com sucesso. Entre com sua nova senha.');
        redirect('/login.php');
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
    <title>Redefinir senha - <?= e($nomeSistema) ?></title>
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
        <div class="login-marca">Nova senha</div>
        <?php if ($tokenValido): ?>
            <div class="login-sub">Olá, <?= e($reset['usuario_nome']) ?></div>
        <?php endif; ?>
    </div>

    <?php if ($erro): ?>
        <div class="alert alert-danger py-2 text-center"><?= e($erro) ?></div>
    <?php endif; ?>

    <?php if ($tokenValido): ?>
        <form method="post">
            <input type="hidden" name="token" value="<?= e($token) ?>">
            <div class="mb-3">
                <label class="form-label">Nova senha</label>
                <input type="password" name="senha" class="form-control" required minlength="6" autofocus>
            </div>
            <div class="mb-4">
                <label class="form-label">Confirmar nova senha</label>
                <input type="password" name="senha_confirmacao" class="form-control" required minlength="6">
            </div>
            <button type="submit" class="btn btn-brand w-100 py-2">Salvar nova senha</button>
        </form>
    <?php else: ?>
        <div class="text-center">
            <a href="/recuperar_senha.php" class="btn btn-brand w-100 py-2">Solicitar novo link</a>
        </div>
    <?php endif; ?>

    <div class="text-center mt-3">
        <a href="/login.php" class="login-esqueci"><i class="bi bi-arrow-left"></i> Voltar para o login</a>
    </div>
</div>
<script src="/assets/js/csrf.js"></script>
</body>
</html>
