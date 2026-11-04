<?php
require_once __DIR__ . '/../../includes/bootstrap.php';
require_once __DIR__ . '/../../includes/smtp_mailer.php';
exigirAdmin();

header('Content-Type: application/json; charset=utf-8');

$pdo = getDB();
$config = buscarConfiguracoes($pdo);

$emailTeste = trim($_POST['email_teste'] ?? '');

if ($emailTeste === '' || !filter_var($emailTeste, FILTER_VALIDATE_EMAIL)) {
    echo json_encode(['sucesso' => false, 'mensagem' => 'Informe um e-mail válido para o teste.']);
    exit;
}

// Usa os valores que estão no formulário na hora do teste (mesmo que ainda
// não tenham sido salvos), para o admin poder testar antes de gravar. Senha
// em branco cai para a senha já salva, seguindo a mesma regra do salvar.
$configTeste = [
    'smtp_host' => trim($_POST['smtp_host'] ?? ''),
    'smtp_porta' => (int) ($_POST['smtp_porta'] ?? 587),
    'smtp_seguranca' => $_POST['smtp_seguranca'] ?? 'tls',
    'smtp_usuario' => trim($_POST['smtp_usuario'] ?? ''),
    'smtp_senha' => ($_POST['smtp_senha'] ?? '') !== '' ? $_POST['smtp_senha'] : configSmtp()['smtp_senha'],
    'smtp_remetente_nome' => trim($_POST['smtp_remetente_nome'] ?? ''),
    'smtp_remetente_email' => trim($_POST['smtp_remetente_email'] ?? ''),
];

$nomeSistema = trim($_POST['nome_sistema'] ?? '') ?: nomeSistema();

$corpoHtml = '<p>Este é um e-mail de teste enviado pelo ' . e($nomeSistema) . '.</p><p>Se você recebeu esta mensagem, a configuração de SMTP está funcionando corretamente.</p>';

$resultado = enviarEmail($configTeste, $emailTeste, '', 'Teste de e-mail - ' . $nomeSistema, $corpoHtml);

echo json_encode([
    'sucesso' => $resultado['sucesso'],
    'mensagem' => $resultado['sucesso'] ? 'E-mail de teste enviado com sucesso!' : $resultado['mensagem'],
]);
