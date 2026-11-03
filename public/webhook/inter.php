<?php
// Webhook do Banco Inter (cobrança): o Inter manda aqui a lista de cobranças
// que mudaram de situação (paga, cancelada, expirada...). Endereço registrado
// em Contas bancárias › conta do Inter › "Registrar webhook".
require_once __DIR__ . '/../../includes/bootstrap_webhook.php';

header('Content-Type: application/json; charset=utf-8');
$pdo = getDB();
$conta = contaBancaria($pdo, (int) ($_GET['conta'] ?? 0));
if (!$conta || !contaTemInter($conta) || empty($conta['webhook_segredo']) || !hash_equals($conta['webhook_segredo'], (string) ($_GET['s'] ?? ''))) {
    http_response_code(403);
    echo json_encode(['ok' => false]);
    exit;
}
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    echo json_encode(['ok' => true, 'mensagem' => 'Webhook do Inter ativo.']);
    exit;
}

$corpo = json_decode((string) file_get_contents('php://input'), true);
$avisos = is_array($corpo) ? (array_is_list($corpo) ? $corpo : [$corpo]) : [];
$atualizados = processarWebhookInter($pdo, (int) $conta['id'], $avisos);
echo json_encode(['ok' => true, 'atualizados' => $atualizados]);
