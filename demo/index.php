<?php

declare(strict_types=1);

require __DIR__ . '/../vendor/autoload.php';

use DataLinx\PhpUpnQrGenerator\UPNQR;

$buildDir = __DIR__ . '/build';
if (! is_dir($buildDir)) {
    mkdir($buildDir, 0755, true);
}

$defaults = [
    'mode' => 'minimal',
    'recipientIban' => 'SI56040000278839633',
    'recipientCity' => 'Prevorje',
    'recipientName' => 'Sama Navitas d.o.o.',
    'recipientStreetAddress' => 'Lopaca 30',
    'recipientReference' => 'SI99',
    'payerName' => '',
    'payerStreetAddress' => '',
    'payerCity' => '',
    'amount' => '10.00',
    'purposeCode' => 'GDSV',
    'paymentPurpose' => 'Namen #123',
];

$data = array_merge($defaults, $_POST ?? []);
$mode = in_array($data['mode'] ?? 'minimal', ['minimal', 'full'], true) ? $data['mode'] : 'minimal';
$error = null;
$svgFile = null;
$pngFile = null;
$payload = null;

function h(?string $v): string
{
    return htmlspecialchars($v ?? '', ENT_QUOTES, 'UTF-8');
}

function payloadForDisplay(string $payload): string
{
    $converted = iconv('ISO-8859-2', 'UTF-8', $payload);

    if ($converted === false) {
        return '[Unable to convert payload from ISO-8859-2 to UTF-8]';
    }

    return $converted;
}

try {
    $qr = new UPNQR();
    $qr->setRecipientIban($data['recipientIban']);
    $qr->setRecipientCity($data['recipientCity']);
    $qr->setRecipientName($data['recipientName'] ?: null);
    $qr->setRecipientStreetAddress($data['recipientStreetAddress'] ?: null);
    $qr->setRecipientReference($data['recipientReference'] ?: null);
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
        .mode-toggle { display: flex; gap: 0.5rem; align-items: center; flex-wrap: wrap; margin: 0.5rem 0; }
        .mode-btn { padding: 0.55rem 0.9rem; border: 1px solid #cbd2e1; background: #f5f7fb; color: #223; border-radius: 8px; font-weight: 600; cursor: pointer; }
        .mode-btn.active { background: #315efb; color: white; border-color: #315efb; box-shadow: 0 6px 14px rgba(49,94,251,0.25); }
        .fieldset { grid-column: 1 / -1; display: grid; gap: 0.75rem; grid-template-columns: repeat(auto-fit, minmax(240px, 1fr)); padding: 0; margin: 0; }
        .fieldset.hidden { display: none; }
        .fieldset legend { font-weight: 700; color: #1f2a44; margin-bottom: 0.25rem; }
        .pill { display: inline-flex; gap: 0.35rem; align-items: center; padding: 0.35rem 0.7rem; border-radius: 999px; background: #eef2ff; color: #223; font-weight: 600; }
    </style>
</head>
<body>
<div class="card">
    <h1>UPN QR Demo</h1>
    <p class="note">Enostaven prikaz generiranja UPN QR kode (SVG; PNG, če je na voljo Imagick).</p>
    <p class="note">Demo ni namenjen produkcijski rabi; ne vključuje CSRF zaščite ali sanitizacije podatkov.</p>

    <?php if ($error): ?>
        <div class="error"><?= h($error); ?></div>
    <?php endif; ?>

    <form method="post">
        <div class="mode-toggle">
            <input type="hidden" name="mode" id="modeField" value="<?= h($mode); ?>">
            <span class="note">Izberite zahtevnost obrazca:</span>
            <button type="button" class="mode-btn <?= $mode === 'minimal' ? 'active' : ''; ?>" data-mode="minimal">Minimalno (obvezno)</button>
            <button type="button" class="mode-btn <?= $mode === 'full' ? 'active' : ''; ?>" data-mode="full">Polno (vse možnosti)</button>
        </div>

        <fieldset class="fieldset">
            <legend>Obvezna polja</legend>
            <label>IBAN prejemnika
                <input name="recipientIban" required value="<?= h($data['recipientIban']); ?>">
            </label>
            <label>Kraj prejemnika
                <input name="recipientCity" required value="<?= h($data['recipientCity']); ?>">
            </label>
            <label>Naziv prejemnika
                <input name="recipientName" value="<?= h($data['recipientName']); ?>">
            </label>
            <label>Referenca prejemnika
                <input name="recipientReference" value="<?= h($data['recipientReference']); ?>">
                <span class="hint">Privzeto SI99 (sklic). Pustite prazno za privzeto.</span>
            </label>
            <label>Znesek (EUR)
                <input name="amount" type="number" min="0.01" step="0.01" value="<?= h($data['amount']); ?>">
                <span class="hint">Pustite prazno, če znesek ni določen.</span>
            </label>
            <label>Opis namena
                <textarea name="paymentPurpose" maxlength="42"><?= h($data['paymentPurpose']); ?></textarea>
                <span class="hint">Največ 42 znakov (zahteva standard).</span>
            </label>
        </fieldset>

        <fieldset class="fieldset <?= $mode === 'minimal' ? 'hidden' : ''; ?>" id="advancedFields">
            <legend>Dodatna (neobvezna) polja</legend>
            <label>Ulica prejemnika
                <input name="recipientStreetAddress" value="<?= h($data['recipientStreetAddress']); ?>">
            </label>
            <label>Naziv plačnika
                <input name="payerName" value="<?= h($data['payerName']); ?>">
            </label>
            <label>Ulica plačnika
                <input name="payerStreetAddress" value="<?= h($data['payerStreetAddress']); ?>">
            </label>
            <label>Kraj plačnika
                <input name="payerCity" value="<?= h($data['payerCity']); ?>">
            </label>
            <label>Šifra namena
                <input name="purposeCode" maxlength="4" pattern="^[A-Z]{4}$" value="<?= h($data['purposeCode']); ?>">
                <span class="hint">Štiri črke (npr. GDSV). Neobvezno.</span>
            </label>
        </fieldset>

        <div class="actions">
            <button type="submit">Ustvari QR</button>
            <span class="note">SVG vedno; PNG le, če je na voljo ext-imagick.</span>
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
        <div class="payload"><?= h(payloadForDisplay($payload)); ?></div>
    <?php endif; ?>

    <h3>Uporaba</h3>
    <ol>
        <li>Namestite odvisnosti: <code>composer install</code></li>
        <li>Zaženite demo strežnik: <code>php -S localhost:8000 -t demo</code></li>
        <li>Odprite <a href="http://localhost:8000">http://localhost:8000</a> v brskalniku.</li>
    </ol>

    <p class="note">PNG zahteva razširitev <code>ext-imagick</code>. SVG deluje vedno.</p>
</div>
<script>
    const form = document.querySelector('form');
    const errorBox = document.querySelector('.error');
    const modeButtons = document.querySelectorAll('.mode-btn');
    const modeField = document.querySelector('#modeField');
    const advancedFields = document.querySelector('#advancedFields');
    const fieldLabels = {
        recipientIban: 'IBAN prejemnika',
        recipientCity: 'Kraj prejemnika',
        recipientName: 'Naziv prejemnika',
        recipientReference: 'Referenca prejemnika',
        recipientStreetAddress: 'Ulica prejemnika',
        payerName: 'Naziv plačnika',
        payerStreetAddress: 'Ulica plačnika',
        payerCity: 'Kraj plačnika',
        amount: 'Znesek',
        purposeCode: 'Šifra namena',
        paymentPurpose: 'Opis namena',
    };

    modeButtons.forEach(btn => {
        btn.addEventListener('click', () => {
            const mode = btn.dataset.mode;
            if (!modeField) return;
            modeField.value = mode;
            modeButtons.forEach(b => b.classList.toggle('active', b.dataset.mode === mode));
            if (advancedFields) {
                advancedFields.classList.toggle('hidden', mode === 'minimal');
            }
        });
    });

    form?.addEventListener('submit', (event) => {
        errorBox?.classList.remove('show');
        if (!form.checkValidity()) {
            event.preventDefault();
            const invalidFields = Array.from(form.elements).filter(el => el instanceof HTMLInputElement || el instanceof HTMLTextAreaElement).filter(el => !el.checkValidity());
            const messages = invalidFields.map(el => {
                const label = fieldLabels[el.name] ?? el.name;
                if (el.validity.valueMissing) return `${label} je obvezno polje.`;
                if (el.validity.patternMismatch) return `${label} ima neveljaven format.`;
                if (el.validity.rangeUnderflow) return `${label} mora biti večje od ${el.min}.`;
                return `${label} ni veljavno.`;
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
