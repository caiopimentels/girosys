<?php
require_once __DIR__ . '/../../includes/bootstrap.php';
exigirAdmin();

header('Content-Type: application/json; charset=utf-8');

$pdo = getDB();
$config = buscarConfiguracoes($pdo);

// Usa a configuração já salva (não o que está no formulário sem salvar),
// porque é exatamente isso que um erro de verdade usaria.
$destino = trim((string) ($config['email_notificacao_erros'] ?? ''));
if ($destino === '') {
    $destino = trim((string) ($config['smtp_remetente_email'] ?? ''));
}

if ($destino === '') {
    echo json_encode(['sucesso' => false, 'mensagem' => 'Salve um e-mail de remetente ou de notificação de erro antes de testar.']);
    exit;
}

$nomeSistema = nomeSistema();
$corpo = '<p><strong>Erro no sistema ' . e($nomeSistema) . '</strong> — simulado a pedido, para testar o aviso.</p>'
    . '<p><strong>Quando:</strong> ' . e(date('d/m/Y H:i:s')) . '</p>'
    . '<p><strong>Disparado por:</strong> ' . e(usuarioAtualNome()) . '</p>'
    . '<pre style="white-space:pre-wrap;font-family:monospace;font-size:13px;">Erro de teste: simulação manual disparada em Configurações &gt; Sistema.</pre>';

$resultado = enviarEmail(configSmtp(), $destino, '', 'Erro no sistema — ' . $nomeSistema . ' (teste)', $corpo);

echo json_encode([
    'sucesso' => $resultado['sucesso'],
    'mensagem' => $resultado['sucesso'] ? "E-mail de teste enviado para {$destino}." : $resultado['mensagem'],
]);
