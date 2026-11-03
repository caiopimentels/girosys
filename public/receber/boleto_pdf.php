<?php
// PDF do boleto do Inter (busca na API na hora; não guarda arquivo).
require_once __DIR__ . '/../../includes/bootstrap.php';
exigirLogin();

$pdo = getDB();
$stmt = $pdo->prepare('SELECT * FROM contas_receber WHERE id = ? AND boleto_codigo IS NOT NULL');
$stmt->execute([(int) ($_GET['id'] ?? 0)]);
$t = $stmt->fetch();
if (!$t) {
    http_response_code(404);
    exit('Boleto não encontrado.');
}
try {
    $pdf = pdfBoletoInter($pdo, $t);
} catch (Throwable $e) {
    http_response_code(502);
    exit('Não foi possível buscar o PDF no Inter: ' . e($e->getMessage()));
}
header('Content-Type: application/pdf');
header('Content-Disposition: inline; filename="boleto-' . (int) $t['id'] . '.pdf"');
echo $pdf;
