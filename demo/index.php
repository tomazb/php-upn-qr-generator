<?php

declare(strict_types=1);

require __DIR__ . '/../vendor/autoload.php';

use DataLinx\PhpUpnQrGenerator\UPNQR;

$buildDir = __DIR__ . '/build';
if (! is_dir($buildDir)) {
    mkdir($buildDir, 0777, true);
}

$defaults = [
    'recipientIban' => 'SI56020360253863406',
    'recipientCity' => 'Ljubljana',
    'recipientName' => 'Demo Company d.o.o.',
    'recipientStreetAddress' => 'Dunajska cesta 1',
    'payerName' => 'Janez Novak',
    'payerStreetAddress' => 'Lepa ulica 33',
    'payerCity' => 'Koper',
    'amount' => '42.50',
    'purposeCode' => 'GDSV',
    'paymentPurpose' => 'Demo order #1234',
];

$data = array_merge($defaults, $_POST ?? []);
$error = null;
$svgFile = null;
$pngFile = null;
$payload = null;

function h(?string $v): string
{
    return htmlspecialchars($v ?? '', ENT_QUOTES, 'UTF-8');
}

try {
    $qr = new UPNQR();
    $qr->setRecipientIban($data['recipientIban']);
    $qr->setRecipientCity($data['recipientCity']);
    $qr->setRecipientName($data['recipientName'] ?: null);
    $qr->setRecipientStreetAddress($data['recipientStreetAddress'] ?: null);
    $qr->setPayerName($data['payerName'] ?: null);
    $qr->setPayerStreetAddress($data['payerStreetAddress'] ?: null);
    $qr->setPayerCity($data['payerCity'] ?: null);
    $qr->setAmount($data['amount'] !== '' ? (float) $data['amount'] : null);
    $qr->setPurposeCode($data['purposeCode'] ?: null);
    $qr->setPaymentPurpose($data['paymentPurpose'] ?: null);

    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        $qr->validate();
        $payload = $qr->getPayload();

        $svgFile = $buildDir . '/demo.svg';
        $qr->generateQrCode($svgFile);

        if (extension_loaded('imagick')) {
            $pngFile = $buildDir . '/demo.png';
            $qr->generateQrCode($pngFile);
        }
    }
} catch (Exception $e) {
    $error = $e->getMessage();
}

function embedSvg(?string $path): ?string
{
    if (! $path || ! file_exists($path)) {
        return null;
    }
    $content = file_get_contents($path);
    return 'data:image/svg+xml;base64,' . base64_encode($content);
}

?><!doctype html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>UPN QR Demo</title>
    <style>
        body { font-family: system-ui, -apple-system, "Segoe UI", sans-serif; margin: 2rem; background: #f8f9fb; color: #222; }
        .card { background: white; padding: 1.5rem; border-radius: 12px; box-shadow: 0 8px 24px rgba(0,0,0,0.08); max-width: 960px; }
        h1 { margin-top: 0; }
        .row { display: flex; gap: 1rem; flex-wrap: wrap; }
        .qr { background: #f0f4ff; padding: 1rem; border-radius: 10px; }
        code { background: #eef1f5; padding: 2px 6px; border-radius: 4px; }
        .note { color: #444; font-size: 0.95rem; }
        form { display: grid; gap: 0.75rem; grid-template-columns: repeat(auto-fit, minmax(240px, 1fr)); margin: 1rem 0; }
        label { display: flex; flex-direction: column; font-weight: 600; font-size: 0.95rem; color: #333; }
        input, textarea { margin-top: 0.35rem; padding: 0.6rem 0.75rem; border: 1px solid #d6d9e0; border-radius: 8px; font-size: 1rem; }
        textarea { resize: vertical; min-height: 72px; }
        .actions { grid-column: 1 / -1; display: flex; gap: 0.75rem; align-items: center; }
        button { padding: 0.75rem 1.2rem; border: none; background: #315efb; color: white; border-radius: 8px; font-weight: 700; cursor: pointer; }
        button:hover { background: #2549c7; }
        .error { background: #ffecec; color: #a33; padding: 0.75rem 1rem; border-radius: 8px; margin-bottom: 1rem; }
        .payload { background: #0f172a; color: #e2e8f0; padding: 1rem; border-radius: 10px; font-family: ui-monospace, SFMono-Regular, Consolas, monospace; white-space: pre-wrap; word-break: break-all; }
        .hint { font-weight: 400; font-size: 0.85rem; color: #555; }
        .errors-list { margin: 0; padding-left: 1.25rem; }
    </style>
</head>
<body>
<div class="card">
    <h1>UPN QR Demo</h1>
    <p class="note">A minimal demo showing how to generate SVG (and PNG when Imagick is available) with the UPN QR library.</p>

    <?php if ($error): ?>
        <div class="error"><?= h($error); ?></div>
    <?php endif; ?>

    <form method="post">
        <label>Recipient IBAN
            <input name="recipientIban" required pattern="^SI\d{17}$" value="<?= h($data['recipientIban']); ?>">
            <span class="hint">Format: SI followed by 17 digits.</span>
        </label>
        <label>Recipient City
            <input name="recipientCity" required value="<?= h($data['recipientCity']); ?>">
        </label>
        <label>Recipient Name
            <input name="recipientName" value="<?= h($data['recipientName']); ?>">
        </label>
        <label>Recipient Street
            <input name="recipientStreetAddress" value="<?= h($data['recipientStreetAddress']); ?>">
        </label>
        <label>Payer Name
            <input name="payerName" value="<?= h($data['payerName']); ?>">
        </label>
        <label>Payer Street
            <input name="payerStreetAddress" value="<?= h($data['payerStreetAddress']); ?>">
        </label>
        <label>Payer City
            <input name="payerCity" value="<?= h($data['payerCity']); ?>">
        </label>
        <label>Amount (EUR)
            <input name="amount" type="number" min="0" step="0.01" value="<?= h($data['amount']); ?>">
            <span class="hint">Leave empty for unspecified amount.</span>
        </label>
        <label>Purpose Code
            <input name="purposeCode" maxlength="4" pattern="^[A-Za-z]{4}$" value="<?= h($data['purposeCode']); ?>">
            <span class="hint">Four letters (e.g., GDSV). Optional.</span>
        </label>
        <label>Payment Purpose
            <textarea name="paymentPurpose"><?= h($data['paymentPurpose']); ?></textarea>
        </label>
        <div class="actions">
            <button type="submit">Generate QR</button>
            <span class="note">SVG is always generated. PNG only when imagick is installed.</span>
        </div>
    </form>

    <div class="row">
        <?php if ($svgFile && ($dataUri = embedSvg($svgFile))): ?>
            <div class="qr">
                <h3>SVG output</h3>
                <img src="<?= $dataUri; ?>" alt="UPN QR SVG" width="220" height="220">
                <p><code><?= basename($svgFile); ?></code> saved in <code>demo/build/</code></p>
            </div>
        <?php endif; ?>

        <?php if ($pngFile): ?>
            <div class="qr">
                <h3>PNG output</h3>
                <img src="<?= 'data:image/png;base64,' . base64_encode(file_get_contents($pngFile)); ?>" alt="UPN QR PNG" width="220" height="220">
                <p><code><?= basename($pngFile); ?></code> saved in <code>demo/build/</code></p>
            </div>
        <?php endif; ?>
    </div>

    <?php if ($payload): ?>
        <h3>Payload (ISO-8859-2)</h3>
        <div class="payload"><?= h($payload); ?></div>
    <?php endif; ?>

    <h3>Usage</h3>
    <ol>
        <li>Install dependencies: <code>composer install</code></li>
        <li>Run the demo server: <code>php -S localhost:8000 -t demo</code></li>
        <li>Open <a href="http://localhost:8000">http://localhost:8000</a> in your browser.</li>
    </ol>

    <p class="note">PNG output requires the <code>ext-imagick</code> extension. SVG always works.</p>
</div>
<script>
    const form = document.querySelector('form');
    const errorBox = document.querySelector('.error');

    form?.addEventListener('submit', (event) => {
        errorBox?.classList.remove('show');
        if (!form.checkValidity()) {
            event.preventDefault();
            const invalidFields = Array.from(form.elements).filter(el => el instanceof HTMLInputElement || el instanceof HTMLTextAreaElement).filter(el => !el.checkValidity());
            const messages = invalidFields.map(el => {
                if (el.validity.valueMissing) return `${el.name} is required.`;
                if (el.validity.patternMismatch) return `${el.name} is in an invalid format.`;
                if (el.validity.rangeUnderflow) return `${el.name} must be greater than ${el.min}.`;
                return `${el.name} is invalid.`;
            });
            if (errorBox) {
                errorBox.innerHTML = `<ul class="errors-list">${messages.map(m => `<li>${m}</li>`).join('')}</ul>`;
                errorBox.style.display = 'block';
            }
        }
    });
</script>
</body>
</html>
