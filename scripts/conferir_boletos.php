<?php
// Confere no Inter os boletos em aberto (caso algum aviso do webhook não tenha
// chegado) e dá baixa nos que foram pagos. Rodar pelo cron, ex. a cada hora:
//   0 * * * * php /caminho/meu_sistema/scripts/conferir_boletos.php
require_once __DIR__ . '/../includes/bootstrap_webhook.php';

$pdo = getDB();
$ids = $pdo->query(
    "SELECT id FROM contas_receber
     WHERE boleto_codigo IS NOT NULL AND status IN ('pendente', 'devolvido')
       AND (boleto_situacao IS NULL OR boleto_situacao NOT IN ('CANCELADO', 'EXPIRADO'))"
)->fetchAll(PDO::FETCH_COLUMN);
$pagos = 0;
foreach ($ids as $id) {
    try {
        if (consultarBoletoInter($pdo, (int) $id) === 'RECEBIDO') {
            $pagos++;
        }
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        fwrite(STDERR, "Título #{$id}: " . $e->getMessage() . "\n");
    }
}
echo date('Y-m-d H:i') . ' · ' . count($ids) . " boleto(s) conferido(s), {$pagos} pago(s) baixado(s).\n";
