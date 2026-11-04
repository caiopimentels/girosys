<?php
// Webhook do Banco Inter (cobrança): o Inter manda aqui a lista de cobranças
// que mudaram de situação (paga, cancelada, expirada...). Endereço registrado
// em Contas bancárias › conta do Inter › "Registrar webhook".
require_once __DIR__ . '/../../includes/bootstrap_webhook.php';

header('Content-Type: application/json; charset=utf-8');
$pdo = getDB();
$conta = contaBancaria($pdo, (int) ($_GET['conta'] ?? 0));
if (!$conta || !contaTemInter($conta) || empty($conta['webhook_segredo']) || !hash_equals($conta['webhook_segredo'], (string) ($_GET['s'] ?? ''))) {
    // Recusado: fica registrado na aba Webhook da conta (se a conta existe),
    // com o IP, para dar para ver quem está batendo no endereço.
    if ($conta && $_SERVER['REQUEST_METHOD'] === 'POST') {
        $pdo->prepare('INSERT INTO webhook_eventos (conta_id, payload, resultado, ip, aceito) VALUES (?, ?, ?, ?, 0)')
            ->execute([$conta['id'], mb_substr((string) file_get_contents('php://input'), 0, 20000), 'Recusado: segredo do endereço não confere', $_SERVER['REMOTE_ADDR'] ?? null]);
    }
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
