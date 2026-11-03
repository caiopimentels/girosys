<?php
// Extrato do cliente para imprimir / salvar em PDF (sem menus).
require_once __DIR__ . '/../../includes/bootstrap.php';
exigirLogin();

$pdo = getDB();
$stmt = $pdo->prepare("SELECT * FROM pessoas WHERE id = ? AND papel = 'cliente'");
$stmt->execute([(int) ($_GET['id'] ?? 0)]);
$cliente = $stmt->fetch();
if (!$cliente) {
    redirect('/pessoas/listar.php');
}
$valida = fn ($v) => preg_match('/^\d{4}-\d{2}-\d{2}$/', (string) $v) ? $v : '';
$extDe = $valida($_GET['de'] ?? '');
$extAte = $valida($_GET['ate'] ?? '');
$extrato = extratoCliente($pdo, (int) $cliente['id'], $extDe, $extAte);
?>
<!doctype html>
<html lang="pt-br">
<head>
    <meta charset="utf-8">
    <title>Extrato · <?= e($cliente['nome']) ?></title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&display=swap" rel="stylesheet">
    <link href="<?= e(assetUrl('/assets/css/app.css')) ?>" rel="stylesheet">
    <link href="<?= e(assetUrl('/assets/css/sistema.css')) ?>" rel="stylesheet">
    <style>
        body { background: #fff; font-size: .9rem; }
        .folha { max-width: 900px; margin: 0 auto; padding: 1.5rem; }
        .card { box-shadow: none !important; }
        @media print { .nao-imprimir { display: none !important; } .folha { padding: 0; } @page { margin: 14mm; } }
    </style>
</head>
<body>
<div class="folha">
    <div class="d-flex justify-content-between align-items-start border-bottom pb-3 mb-3">
        <div>
            <div class="fw-bold fs-5"><?= e(nomeSistema()) ?></div>
            <div class="text-muted">Extrato do cliente</div>
        </div>
        <div class="text-end">
            <div class="fw-semibold"><?= e($cliente['nome']) ?></div>
            <?php if ($cliente['documento']): ?><div class="small text-muted"><?= e(documentoFormatado($cliente['documento'])) ?></div><?php endif; ?>
            <div class="small text-muted">Período: <?= $extDe || $extAte ? ($extDe ? formatDate($extDe) : 'início') . ' a ' . ($extAte ? formatDate($extAte) : 'hoje') : 'todo o histórico' ?></div>
            <div class="small text-muted">Emitido em <?= date('d/m/Y H:i') ?></div>
        </div>
    </div>
    <?php include __DIR__ . '/_extrato_tabela.php'; ?>
    <div class="text-center mt-4 nao-imprimir">
        <button class="btn btn-brand" onclick="window.print()"><i class="bi bi-printer"></i> Imprimir / salvar PDF</button>
    </div>
</div>
<script>window.addEventListener('load', () => setTimeout(() => window.print(), 300));</script>
</body>
</html>
