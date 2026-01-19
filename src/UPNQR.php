<?php

namespace DataLinx\PhpUpnQrGenerator;

use BaconQrCode\Renderer\Image\EpsImageBackEnd;
use BaconQrCode\Renderer\Image\ImagickImageBackEnd;
use BaconQrCode\Renderer\Image\SvgImageBackEnd;
use BaconQrCode\Renderer\ImageRenderer;
use BaconQrCode\Renderer\RendererStyle\RendererStyle;
use BaconQrCode\Writer;
use DataLinx\PhpUpnQrGenerator\Exception\QrGenerationException;
use DateTimeImmutable;
use Exception;
use InvalidArgumentException;
use RuntimeException;

class UPNQR
{
    public const LEADING_STRING = "UPNQR";
    public const DEFAULT_PURPOSE_CODE = "OTHR";
    private const MAX_PAYLOAD_LENGTH = 411;
    private const OUTPUT_ENCODING = 'ISO-8859-2';
    private const DATE_INPUT_FORMAT = 'Y-m-d';
    private const DATE_OUTPUT_FORMAT = 'd.m.Y';
    private const DEFAULT_RECIPIENT_REFERENCE = 'SI99';
    private const IBAN_LENGTHS = [
        'AD' => 24,
        'AE' => 23,
        'AL' => 28,
        'AT' => 20,
        'AZ' => 28,
        'BA' => 20,
        'BE' => 16,
        'BG' => 22,
        'BH' => 22,
        'BI' => 27,
        'BR' => 29,
        'BY' => 28,
        'CH' => 21,
        'CR' => 22,
        'CY' => 28,
        'CZ' => 24,
        'DE' => 22,
        'DJ' => 27,
        'DK' => 18,
        'DO' => 28,
        'EE' => 20,
        'EG' => 29,
        'ES' => 24,
        'FI' => 18,
        'FK' => 18,
        'FO' => 18,
        'FR' => 27,
        'GB' => 22,
        'GE' => 22,
        'GI' => 23,
        'GL' => 18,
        'GR' => 27,
        'GT' => 28,
        'HN' => 28,
        'HR' => 21,
        'HU' => 28,
        'IE' => 22,
        'IL' => 23,
        'IQ' => 23,
        'IS' => 26,
        'IT' => 27,
        'JO' => 30,
        'KW' => 30,
        'KZ' => 20,
        'LB' => 28,
        'LC' => 32,
        'LI' => 21,
        'LT' => 20,
        'LU' => 20,
        'LV' => 21,
        'LY' => 25,
        'MC' => 27,
        'MD' => 24,
        'ME' => 22,
        'MK' => 19,
        'MN' => 20,
        'MR' => 27,
        'MT' => 31,
        'MU' => 30,
        'NI' => 28,
        'NL' => 18,
        'NO' => 15,
        'OM' => 23,
        'PK' => 24,
        'PL' => 28,
        'PS' => 29,
        'PT' => 25,
        'QA' => 29,
        'RO' => 24,
        'RS' => 22,
        'RU' => 33,
        'SA' => 24,
        'SC' => 31,
        'SD' => 18,
        'SE' => 24,
        'SI' => 19,
        'SK' => 24,
        'SM' => 27,
        'SO' => 23,
        'ST' => 25,
        'SV' => 28,
        'TL' => 23,
        'TN' => 24,
        'TR' => 26,
        'UA' => 29,
        'VA' => 22,
        'VG' => 24,
        'XK' => 20,
        'YE' => 30,
    ];

    protected ?string $payerIban;
    protected ?bool $deposit;
    protected ?bool $withdraw;
    protected ?string $payerReference;
    protected ?string $payerName;
    protected ?string $payerStreetAddress;
    protected ?string $payerCity;
    protected ?float $amount;
    protected ?string $paymentDate;
    protected ?bool $urgent;
    protected ?string $purposeCode;
    protected ?string $paymentPurpose;
    protected ?string $paymentDueDate;
    protected string $recipientIban;
    protected ?string $recipientReference;
    protected ?string $recipientName;
    protected ?string $recipientStreetAddress;
    protected string $recipientCity;
    private ?string $cachedSerializedPayload = null;
    private bool $isDirty = true;

    /**
     * Named constructor that enforces required fields.
     */
    public static function create(string $recipientIban, string $recipientCity): self
    {
        return (new self())
            ->setRecipientIban($recipientIban)
            ->setRecipientCity($recipientCity);
    }

    /**
     * Serialize UPN contents
     * @return string
     * @throws Exception
     */
    public function serializeContents(): string
    {
        if (! $this->isDirty && $this->cachedSerializedPayload !== null) {
            return $this->cachedSerializedPayload;
        }

        // Check if all required parameters are set
        $this->checkRequiredParameters();

        $qrDelim = "\n";

        $qrContentStr = implode($qrDelim, [
                self::LEADING_STRING,
                $this->getPayerIban(),
                $this->getDeposit() ? 'X' : '',
                $this->getWithdraw() ? 'X' : '',
                $this->getPayerReference(),
                $this->getPayerName(),
                $this->getPayerStreetAddress(),
                $this->getPayerCity(),
                isset($this->amount) ? $this->getFormattedAmount() : "",
                isset($this->paymentDate) ? $this->formatDate($this->getPaymentDate()) : "",
                $this->getUrgent() ? 'X' : '',
                $this->getPurposeCode() ? strtoupper($this->getPurposeCode()) : self::DEFAULT_PURPOSE_CODE,
                $this->getPaymentPurpose(),
                isset($this->paymentDueDate) ? $this->formatDate($this->getPaymentDueDate()) : "",
                $this->getRecipientIban(),
                $this->getRecipientReference() ?: self::DEFAULT_RECIPIENT_REFERENCE,
                $this->getRecipientName(),
                $this->getRecipientStreetAddress(),
                $this->getRecipientCity(),
            ]) . $qrDelim;

        $encodedPayload = iconv('UTF-8', self::OUTPUT_ENCODING . '//IGNORE', $qrContentStr);
        $payloadLength = strlen($encodedPayload);
        if ($payloadLength > self::MAX_PAYLOAD_LENGTH) {
            throw new InvalidArgumentException(
                sprintf(
                    "QR payload exceeds maximum %d bytes (current: %d). Reduce field lengths.",
                    self::MAX_PAYLOAD_LENGTH,
                    $payloadLength
                )
            );
        }

        $qrContentStr .= sprintf('%03d', $payloadLength);

        $this->cachedSerializedPayload = $qrContentStr;
        $this->isDirty = false;

        return $this->cachedSerializedPayload;
    }

    /**
     * Generate QR code based on object data. You can define the filetype by providing the file extension.
     * Different file types are supported: .png, .svg, .eps (see docs: https://github.com/Bacon/BaconQrCode)
     * @param string $filename target file name
     * @param int $size optional size parameter (default: 400)
     * @return void
     * @throws Exception
     */
    public function generateQrCode(string $filename, int $size = 400): void
    {
        $this->assertWritableDirectory($filename);

        switch (pathinfo($filename, PATHINFO_EXTENSION)) {
            case 'svg':
                $imageBackEnd = new SvgImageBackEnd();
                break;

            case 'png':
                if (! extension_loaded('imagick')) {
                    throw new RuntimeException("PNG generation requires the imagick PHP extension. Install php-imagick or use .svg/.eps.");
                }
                $imageBackEnd = new ImagickImageBackEnd();
                break;

            case 'eps':
                $imageBackEnd = new EpsImageBackEnd();
                break;

            default:
                throw new InvalidArgumentException("Please provide a valid path with a supported extension (.png, .svg or .eps).");
        }

        $renderer = new ImageRenderer(
            new RendererStyle($size),
            $imageBackEnd
        );

        $writer = $this->createWriter($renderer);

        try {
            $writer->writeFile($this->serializeContents(), $filename, self::OUTPUT_ENCODING);
        } catch (InvalidArgumentException $exception) {
            // Bubble user/input errors unchanged
            throw $exception;
        } catch (Exception $exception) {
            throw new QrGenerationException("QR code generation failed: " . $exception->getMessage(), 0, $exception);
        }
    }

    /**
     * Generate QR code using a pre-configured renderer (useful to reuse heavy backends).
     */
    public function generateQrCodeWithRenderer(ImageRenderer $renderer, string $filename): void
    {
        $this->assertWritableDirectory($filename);

        $writer = $this->createWriter($renderer);

        try {
            $writer->writeFile($this->serializeContents(), $filename, self::OUTPUT_ENCODING);
        } catch (InvalidArgumentException $exception) {
            throw $exception;
        } catch (Exception $exception) {
            throw new QrGenerationException("QR code generation failed: " . $exception->getMessage(), 0, $exception);
        }
    }

    /**
     * Validate all fields without generating output.
     */
    public function validate(): self
    {
        $this->serializeContents();

        return $this;
    }

    /**
     * Get serialized QR payload without generating an image.
     */
    public function getPayload(): string
    {
        return $this->serializeContents();
    }

    /**
     * Factory to create a QR writer. Overridable for testing.
     */
    protected function createWriter(ImageRenderer $renderer): Writer
    {
        return new Writer($renderer);
    }

    /**
     * Ensure the target directory exists and is writable.
     */
    private function assertWritableDirectory(string $filename): void
    {
        $dir = dirname($filename) ?: '.';
        if (! is_dir($dir)) {
            throw new InvalidArgumentException("Directory does not exist: {$dir}");
        }
        if (! is_writable($dir)) {
            throw new InvalidArgumentException("Directory is not writable: {$dir}");
        }
    }

    /**
     * Check if all the required parameters are set
     * @return void
     * @throws Exception
     */
    public function checkRequiredParameters(): void
    {
        $params = [
            'recipientIban',
            'recipientCity',
        ];

        foreach ($params as $param) {
            if (! isset($this->{$param})) {
                throw new InvalidArgumentException($this->formatRequiredMessage($param));
            }
        }
    }

    private function formatRequiredMessage(string $param): string
    {
        return match ($param) {
            'recipientIban' => 'Recipient IBAN is required.',
            'recipientCity' => 'Recipient city is required.',
            default => sprintf('%s is required.', $param),
        };
    }

    /**
     * @return string|null
     */
    public function getPayerIban(): ?string
    {
        return $this->payerIban ?? null;
    }

    /**
     * Payer IBAN account number (example: SI56020170014356205)
     * (sln. IBAN plačnika)
     * @param string|null $payerIban
     * @return $this
     * @throws Exception
     */
    public function setPayerIban(?string $payerIban): self
    {
        $payerIban = $this->normalizeOptionalString($payerIban);

        if ($payerIban !== null) {
            $payerIban = strtoupper(str_replace(' ', '', $payerIban));
            $this->validateIban($payerIban, 'Payer IBAN');
        }

        $this->payerIban = $payerIban;
        $this->isDirty = true;

        return $this;
    }

    /**
     * @return bool|null
     */
    public function getDeposit(): ?bool
    {
        return $this->deposit ?? null;
    }

    /**
     * Set order deposit state
     * (sln. polog)
     * @param bool|null $deposit
     * @return $this
     */
    public function setDeposit(?bool $deposit): self
    {
        $this->deposit = $deposit;
        $this->isDirty = true;

        return $this;
    }

    /**
     * @return bool|null
     */
    public function getWithdraw(): ?bool
    {
        return $this->withdraw ?? null;
    }

    /**
     * Set order withdrawal state
     * (sln. dvig)
     * @param bool|null $withdraw
     * @return $this
     */
    public function setWithdraw(?bool $withdraw): self
    {
        $this->withdraw = $withdraw;
        $this->isDirty = true;

        return $this;
    }

    /**
     * @return string|null
     */
    public function getPayerReference(): ?string
    {
        return $this->payerReference ?? null;
    }

    /**
     * Payer reference number (example: SI00225268-32526-222)
     * (sln. referenca plačnika)
     * @param string|null $payerReference
     * @return $this
     * @throws Exception
     */
    public function setPayerReference(?string $payerReference): self
    {
        $payerReference = $this->normalizeOptionalString($payerReference);

        if ($payerReference !== null) {
            $this->assertIso88592Charset($payerReference, 'Payer reference');

            if (! preg_match('/^(SI|RF)\d{2}/', $payerReference)) {
                throw new InvalidArgumentException("Payer reference must either be null or start with SI or RF and then 2 digits and other digits or characters.");
            }
            if (mb_strlen($payerReference) > 26) {
                throw new InvalidArgumentException("Payer reference should not have more than 26 characters.");
            }

            // Source: http://www.firmar.si/index.jsp?pg=nasveti-clanki/upn/referenca-si-in-rf-za-univerzalni-placilni-nalog-upn
            if (str_starts_with($payerReference, 'SI') && substr_count($payerReference, '-') > 2) {
                throw new InvalidArgumentException("Payer references that starts with SI should not have more than two dashes.");
            }
        }

        $this->payerReference = $payerReference;
        $this->isDirty = true;

        return $this;
    }

    /**
     * @return string|null
     */
    public function getPayerName(): ?string
    {
        return $this->payerName ?? null;
    }

    /**
     * Payer name/title
     * (sln. ime plačnika)
     * @param string|null $payerName
     * @return $this
     * @throws Exception
     */
    public function setPayerName(?string $payerName): self
    {
        $payerName = $this->normalizeOptionalString($payerName);
        if ($payerName !== null) {
            $this->assertIso88592Charset($payerName, 'Payer name');
            if (mb_strlen($payerName) > 33) {
                throw new InvalidArgumentException("Payer name must either be null or not have more than 33 characters.");
            }
        }

        $this->payerName = $payerName;
        $this->isDirty = true;

        return $this;
    }

    /**
     * @return string|null
     */
    public function getPayerStreetAddress(): ?string
    {
        return $this->payerStreetAddress ?? null;
    }

    /**
     * Payer street name and number
     * (sln. ulica in št. plačnika)
     * @param string|null $payerStreetAddress
     * @return $this
     * @throws Exception
     */
    public function setPayerStreetAddress(?string $payerStreetAddress): self
    {
        $payerStreetAddress = $this->normalizeOptionalString($payerStreetAddress);
        if ($payerStreetAddress !== null) {
            $this->assertIso88592Charset($payerStreetAddress, 'Payer street address');
            if (mb_strlen($payerStreetAddress) > 33) {
                throw new InvalidArgumentException("Payer street address must either be null or not have more than 33 characters.");
            }
        }

        $this->payerStreetAddress = $payerStreetAddress;
        $this->isDirty = true;

        return $this;
    }

    /**
     * @return string|null
     */
    public function getPayerCity(): ?string
    {
        return $this->payerCity ?? null;
    }

    /**
     * Payer city/location name
     * (sln. kraj plačnika)
     * @param string|null $payerCity
     * @return $this
     * @throws Exception
     */
    public function setPayerCity(?string $payerCity): self
    {
        $payerCity = $this->normalizeOptionalString($payerCity);
        if ($payerCity !== null) {
            $this->assertIso88592Charset($payerCity, 'Payer city');
            if (mb_strlen($payerCity) > 33) {
                throw new InvalidArgumentException("Payer city must either be null or not have more than 33 characters.");
            }
        }

        $this->payerCity = $payerCity;
        $this->isDirty = true;

        return $this;
    }

    /**
     * @return float|null
     */
    public function getAmount(): ?float
    {
        return $this->amount ?? null;
    }

    /**
     * Returns amount in QR UPN required format. Example: 150.555 will be 00000015056
     * @return string
     */
    public function getFormattedAmount(): string
    {
        return str_pad(number_format($this->amount, 2, "", ""), 11, 0, STR_PAD_LEFT);
    }

    /**
     * Payment amount
     * (sln. znesek)
     * @param float|null $amount
     * @return $this
     * @throws Exception
     */
    public function setAmount(?float $amount): self
    {
        if ($amount !== null) {
            if ($amount < 0.01 || $amount > 999999999.99) {
                throw new InvalidArgumentException('Amount must either be null or a value between 0.01 and 999,999,999.99');
            }

            $amount = round($amount, 2);
        }

        $this->amount = $amount;
        $this->isDirty = true;

        return $this;
    }

    /**
     * @return string|null
     */
    public function getPaymentDate(): ?string
    {
        return $this->paymentDate ?? null;
    }

    /**
     * Payment date (example. 2022-06-16)
     * (sln. datum plačila)
     * @param string|null $paymentDate
     * @return $this
     * @throws Exception
     */
    public function setPaymentDate(?string $paymentDate): self
    {
        $paymentDate = $this->validateAndNormalizeDate($paymentDate, 'Payment date');
        $this->paymentDate = $paymentDate;
        $this->isDirty = true;

        return $this;
    }

    /**
     * @return bool|null
     */
    public function getUrgent(): ?bool
    {
        return $this->urgent ?? null;
    }

    /**
     * Set if order is urgent
     * (sln. nujno)
     * @param bool|null $urgent
     * @return $this
     */
    public function setUrgent(?bool $urgent): self
    {
        $this->urgent = $urgent;
        $this->isDirty = true;

        return $this;
    }

    /**
     * @return string|null
     */
    public function getPurposeCode(): ?string
    {
        return $this->purposeCode ?? null;
    }

    /**
     * Order purpose code (example: COST)
     * (sln. koda namena)
     * @param string|null $purposeCode 4-letter payment code in uppercase
     * @return $this
     * @throws Exception
     */
    public function setPurposeCode(?string $purposeCode): self
    {
        $purposeCode = $this->normalizeOptionalString($purposeCode);
        if ($purposeCode !== null) {
            $this->assertIso88592Charset($purposeCode, 'Purpose code');
            if (! preg_match('/^[A-Z]{4}$/', $purposeCode)) {
                throw new InvalidArgumentException("Purpose code must be null or have exactly four uppercase characters [A-Z].");
            }
        }

        $this->purposeCode = $purposeCode;
        $this->isDirty = true;

        return $this;
    }

    /**
     * @return string|null
     */
    public function getPaymentPurpose(): ?string
    {
        return $this->paymentPurpose ?? null;
    }

    /**
     * Payment purpose text
     * (sln. namen plačila)
     * @param string|null $paymentPurpose
     * @return $this
     * @throws Exception
     */
    public function setPaymentPurpose(?string $paymentPurpose): self
    {
        $paymentPurpose = $this->normalizeOptionalString($paymentPurpose);
        if ($paymentPurpose !== null) {
            $this->assertIso88592Charset($paymentPurpose, 'Payment purpose');
            if (mb_strlen($paymentPurpose) > 42) {
                throw new InvalidArgumentException("Payment purpose must either be null or not have more than 42 characters.");
            }
        }

        $this->paymentPurpose = $paymentPurpose;
        $this->isDirty = true;

        return $this;
    }

    /**
     * @return string|null
     */
    public function getPaymentDueDate(): ?string
    {
        return $this->paymentDueDate ?? null;
    }

    /**
     * Payment due date (example: 2022-09-05)
     * (sln. rok plačila)
     * @param string|null $paymentDueDate
     * @return $this
     * @throws Exception
     */
    public function setPaymentDueDate(?string $paymentDueDate): self
    {
        $paymentDueDate = $this->validateAndNormalizeDate($paymentDueDate, 'Payment due date');
        $this->paymentDueDate = $paymentDueDate;
        $this->isDirty = true;

        return $this;
    }

    /**
     * @return string
     */
    public function getRecipientIban(): string
    {
        return $this->recipientIban;
    }

    /**
     * Recipient/payee IBAN account number (example: SI56020170014356205)
     * (sln. IBAN prejemnika)
     * @param string $recipientIban
     * @return $this
     * @throws Exception
     */
    public function setRecipientIban(string $recipientIban): self
    {
        $recipientIban = trim($recipientIban);
        if ($recipientIban === '') {
            throw new InvalidArgumentException('Recipient IBAN is required.');
        }

        $recipientIban = strtoupper(str_replace(' ', '', $recipientIban));
        $this->validateIban($recipientIban, 'Recipient IBAN');

        $this->recipientIban = $recipientIban;
        $this->isDirty = true;

        return $this;
    }

    /**
     * @return string|null
     */
    public function getRecipientReference(): ?string
    {
        return $this->recipientReference ?? null;
    }

    /**
     * Recipient/payee reference number (example: SI00225268-32526-222)
     * (sln. referenca prejemnika)
     * @param string|null $recipientReference
     * @return $this
     * @throws Exception
     */
    public function setRecipientReference(?string $recipientReference): self
    {
        $recipientReference = $this->normalizeOptionalString($recipientReference);

        if ($recipientReference !== null) {
            $this->assertIso88592Charset($recipientReference, 'Recipient reference');

            if (! preg_match('/^(SI|RF)\d{2}/', $recipientReference)) {
                throw new InvalidArgumentException("Recipient reference must either be null or start with SI or RF and then 2 digits and other digits or characters.");
            }
            if (mb_strlen($recipientReference) > 26) {
                throw new InvalidArgumentException("Recipient reference should not have more than 26 characters.");
            }
            if (str_starts_with($recipientReference, 'SI') && substr_count($recipientReference, '-') > 2) {
                throw new InvalidArgumentException("Recipient references that starts with SI should not have more than two dashes.");
            }
        }

        $this->recipientReference = $recipientReference;
        $this->isDirty = true;

        return $this;
    }

    /**
     * @return string|null
     */
    public function getRecipientName(): ?string
    {
        return $this->recipientName ?? null;
    }

    /**
     * Recipient/payee name/title
     * (sln. ime prejemnika)
     * @param string|null $recipientName
     * @return $this
     * @throws Exception
     */
    public function setRecipientName(?string $recipientName): self
    {
        $recipientName = $this->normalizeOptionalString($recipientName);
        if ($recipientName !== null) {
            $this->assertIso88592Charset($recipientName, 'Recipient name');
            if (mb_strlen($recipientName) > 33) {
                throw new InvalidArgumentException("Recipient name must either be null or not have more than 33 characters.");
            }
        }

        $this->recipientName = $recipientName;
        $this->isDirty = true;

        return $this;
    }

    /**
     * @return string|null
     */
    public function getRecipientStreetAddress(): ?string
    {
        return $this->recipientStreetAddress ?? null;
    }

    /**
     * Recipient/payee street name and number
     * (sln. ulica in št. prejemnika)
     * @param string|null $recipientStreetAddress
     * @return $this
     * @throws Exception
     */
    public function setRecipientStreetAddress(?string $recipientStreetAddress): self
    {
        $recipientStreetAddress = $this->normalizeOptionalString($recipientStreetAddress);
        if ($recipientStreetAddress !== null) {
            $this->assertIso88592Charset($recipientStreetAddress, 'Recipient street address');
            if (mb_strlen($recipientStreetAddress) > 33) {
                throw new InvalidArgumentException("Recipient street address must either be null or not have more than 33 characters.");
            }
        }

        $this->recipientStreetAddress = $recipientStreetAddress;
        $this->isDirty = true;

        return $this;
    }

    /**
     * @return string
     */
    public function getRecipientCity(): string
    {
        return $this->recipientCity;
    }

    /**
     * Recipient/payee city/location name
     * (sln. kraj prejemnika)
     * @param string $recipientCity
     * @return $this
     * @throws Exception
     */
    public function setRecipientCity(string $recipientCity): self
    {
        $recipientCity = trim($recipientCity);
        $this->assertIso88592Charset($recipientCity, 'Recipient city');
        if ($recipientCity === '') {
            throw new InvalidArgumentException("Recipient city is required.");
        }
        if (mb_strlen($recipientCity) > 33) {
            throw new InvalidArgumentException("Recipient city should not have more than 33 characters.");
        }

        $this->recipientCity = $recipientCity;
        $this->isDirty = true;

        return $this;
    }

    /**
     * Format date from "Y-m-d" to "d.m.Y"
     * @param string $date
     * @return string
     */
    public function formatDate(string $date): string
    {
        $parsed = DateTimeImmutable::createFromFormat(self::DATE_INPUT_FORMAT, $date);

        if ($parsed === false) {
            throw new InvalidArgumentException("Unable to format date, invalid input: {$date}");
        }

        return $parsed->format(self::DATE_OUTPUT_FORMAT);
    }

    /**
     * Normalize string input: trim, convert empty to null.
     */
    private function normalizeOptionalString(?string $value): ?string
    {
        if ($value === null) {
            return null;
        }

        $value = trim($value);

        return $value === '' ? null : $value;
    }

    /**
     * Validate ISO-8859-2 compatibility to avoid encoder surprises.
     */
    private function assertIso88592Charset(string $value, string $fieldName): void
    {
        $converted = @iconv('UTF-8', self::OUTPUT_ENCODING . '//IGNORE', $value);
        $back = @iconv(self::OUTPUT_ENCODING, 'UTF-8', $converted);

        if ($back !== $value) {
            throw new InvalidArgumentException(
                sprintf("%s contains characters not supported by %s encoding.", $fieldName, self::OUTPUT_ENCODING)
            );
        }
    }

    /**
     * Validate, normalize and return date string or null.
     */
    private function validateAndNormalizeDate(?string $date, string $fieldName): ?string
    {
        if ($date === null) {
            return null;
        }

        $date = trim($date);
        if ($date === '') {
            return null;
        }

        $parsed = DateTimeImmutable::createFromFormat(self::DATE_INPUT_FORMAT, $date);
        $errors = DateTimeImmutable::getLastErrors();

        if ($parsed === false || ($errors && ($errors['warning_count'] > 0 || $errors['error_count'] > 0))) {
            throw new InvalidArgumentException("$fieldName must be in YYYY-MM-DD format and be a valid date.");
        }

        return $date;
    }

    private function validateIban(string $iban, string $fieldName): void
    {
        $normalized = strtoupper(str_replace(' ', '', $iban));
        if (! preg_match('/^[A-Z]{2}\d{2}[A-Z0-9]{11,30}$/', $normalized)) {
            throw new InvalidArgumentException("{$fieldName} format is invalid.");
        }

        $countryCode = substr($normalized, 0, 2);
        $expectedLength = self::IBAN_LENGTHS[$countryCode] ?? null;
        if ($expectedLength === null) {
            throw new InvalidArgumentException("{$fieldName} country code is not supported.");
        }
        if (strlen($normalized) !== $expectedLength) {
            throw new InvalidArgumentException("{$fieldName} length is invalid.");
        }

        $rearranged = substr($normalized, 4) . substr($normalized, 0, 4);
        $remainder = 0;
        foreach (str_split($rearranged) as $char) {
            $segment = $char;
            if (ctype_alpha($char)) {
                $segment = (string) (ord($char) - 55);
            }

            foreach (str_split($segment) as $digit) {
                $remainder = ($remainder * 10 + (int) $digit) % 97;
            }
        }

        if ($remainder !== 1) {
            throw new InvalidArgumentException("{$fieldName} checksum is invalid.");
        }
    }
}
