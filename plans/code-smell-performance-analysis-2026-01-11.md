# Code Smell & Performance Analysis Improvement Plan

**Project:** `datalinx/php-upn-qr-generator`  
**Date:** 2026-01-11  
**Scope:** `src/UPNQR.php` (670 lines, single-class library)

---

## Progress Log

- **Phase 1 (Validation & Data Integrity)** — Completed
  - Added payload length guard (411 chars) with memoized serialization cache.
  - Normalized setters (trim/empty→null), charset validation (ISO-8859-2), stricter date parsing with `DateTimeImmutable`, amount rounding/limits.
  - Added dirty-flag caching, consistent OUTPUT_ENCODING constant, and validation helpers.
  - Tests updated for new behaviors; PNG tests conditionally skipped when imagick missing; now pass with imagick installed.
  - Test suite: `composer test` (27 tests, 156 assertions).

- **Phase 2 (Exception Hierarchy & Error Clarity)** — Completed
  - Added custom `QrGenerationException` for unexpected writer/IO failures; user input errors bubble as `InvalidArgumentException`.
  - Added directory existence/writability preflight; PNG imagick check kept with clear error.
  - Added writer factory hook for tests; tests cover invalid dir/extension and wrapped failures.
  - Test suite: `composer test` (28 tests, 160 assertions).

- **Phase 3 (Performance Optimizations)** — Completed
  - Added `generateQrCodeWithRenderer` to reuse pre-configured renderers/backends.
  - Extracted directory writability check to a helper for reuse.

- **Phase 4 (API Ergonomics & Developer Experience)** — Completed
  - Added `validate()` and `getPayload()` helpers for explicit validation and payload inspection.
  - Added renderer-generation coverage for `generateQrCodeWithRenderer()`.
  - Suite now `composer test` (30 tests, 164 assertions).

- **Phase 5 (Tests & Demo Application)** — Completed
  - Expanded tests: directory writability error path, renderer reuse, DX helpers.
  - Added interactive demo app (`demo/index.php`) with form inputs, live SVG/PNG previews, and payload display; handles validation errors gracefully.
  - Demo instructions included in-page; uses `demo/build/` for outputs.

---

## Executive Summary

This document outlines identified code smells, performance concerns, and a prioritized improvement roadmap for the UPN QR code generator library. The library is well-structured but has validation gaps, inconsistent patterns, and missing spec compliance checks.

---

## Phase 1: Critical — Validation & Data Integrity

### 1.1 Enforce Required Fields at Construction

**Problem:** `recipientIban` and `recipientCity` are non-nullable but uninitialized. Consumers can instantiate and call methods that fail late.

**Location:** `src/UPNQR.php:20-38, 126-138`

**Solution:** Add a static factory or constructor with required params:

```php
// Option A: Constructor with required fields
public function __construct(string $recipientIban, string $recipientCity)
{
    $this->setRecipientIban($recipientIban);
    $this->setRecipientCity($recipientCity);
}

// Option B: Named constructor for BC (keep default constructor)
public static function create(string $recipientIban, string $recipientCity): self
{
    return (new self())
        ->setRecipientIban($recipientIban)
        ->setRecipientCity($recipientCity);
}
```

**BC consideration:** Option B preserves backward compatibility; deprecate parameterless constructor in docblock.

---

### 1.2 Normalize Setter Validation Pattern

**Problem:** Inconsistent trim/null handling across setters.

**Location:** Various setters throughout the class

**Current patterns:**
```php
// Pattern 1 (conditional trim, allows empty string)
if ($value) { $value = trim($value); ... }

// Pattern 2 (unconditional trim, no null check)
$value = trim($value);  // throws if null passed to non-nullable
```

**Unified pattern:**

```php
public function setPayerName(?string $payerName): self
{
    if ($payerName !== null) {
        $payerName = trim($payerName);
        if ($payerName === '') {
            $payerName = null; // Normalize empty to null
        }
    }

    if ($payerName !== null && mb_strlen($payerName) > 33) {
        throw new InvalidArgumentException("Payer name must not exceed 33 characters.");
    }

    $this->payerName = $payerName;
    return $this;
}
```

**Apply to:** `setPayerName`, `setPayerStreetAddress`, `setPayerCity`, `setPaymentPurpose`, `setRecipientName`, `setRecipientStreetAddress`, `setPayerReference`, `setRecipientReference`.

---

### 1.3 Add Payload Length Validation

**Problem:** UPN QR spec limits payload to 411 characters before checksum. No enforcement exists.

**Location:** `src/UPNQR.php:44-79`

**Solution:** Add validation in `serializeContents()`:

```php
public function serializeContents(): string
{
    $this->checkRequiredParameters();

    $qrDelim = "\n";
    $qrContentStr = implode($qrDelim, [
        // ... existing fields
    ]) . $qrDelim;

    $length = mb_strlen($qrContentStr);

    if ($length > 411) {
        throw new InvalidArgumentException(
            sprintf("QR payload exceeds maximum 411 characters (current: %d). Reduce field lengths.", $length)
        );
    }

    $qrContentStr .= sprintf('%03d', $length);

    return $qrContentStr;
}
```

---

### 1.4 Validate Character Set (ISO-8859-2 Compatibility)

**Problem:** Inputs can contain characters outside ISO-8859-2 (emoji, CJK, etc.), causing encoding failures or data corruption.

**Location:** `src/UPNQR.php:115` (hardcoded encoding)

**Solution:** Add charset validation helper:

```php
private function validateCharset(string $value, string $fieldName): void
{
    // ISO-8859-2 supports Latin Extended-A/B characters (Central European)
    $converted = @iconv('UTF-8', 'ISO-8859-2//IGNORE', $value);
    $backConverted = @iconv('ISO-8859-2', 'UTF-8', $converted);

    if ($backConverted !== $value) {
        throw new InvalidArgumentException(
            sprintf("%s contains characters not supported by ISO-8859-2 encoding.", $fieldName)
        );
    }
}
```

**Call in setters** for all string fields (names, addresses, purpose).

---

### 1.5 Fix Amount Precision Handling

**Problem:** Floats with >2 decimals are silently truncated by `number_format()`.

**Location:** `src/UPNQR.php:347-350, 359-368`

**Solution:**

```php
public function setAmount(?float $amount): self
{
    if ($amount !== null) {
        if ($amount <= 0 || $amount > 999999999.99) {
            throw new InvalidArgumentException("Amount must be between 0.01 and 999,999,999.99");
        }

        // Round to 2 decimals to avoid precision issues
        $amount = round($amount, 2);
    }

    $this->amount = $amount;
    return $this;
}
```

---

### 1.6 Use DateTimeImmutable for Date Handling

**Problem:** `strtotime()`/`date()` are timezone-dependent.

**Location:** `src/UPNQR.php:385-400, 494-509, 665-668`

**Solution:**

```php
private function validateAndParseDate(?string $date, string $fieldName): ?string
{
    if ($date === null) {
        return null;
    }

    $date = trim($date);
    if ($date === '') {
        return null;
    }

    if (! preg_match('/^\d{4}-\d{2}-\d{2}$/', $date)) {
        throw new InvalidArgumentException("$fieldName must be in YYYY-MM-DD format and be a valid date.");
    }

    $parsed = DateTimeImmutable::createFromFormat('Y-m-d', $date);
    $errors = DateTimeImmutable::getLastErrors();

    if ($parsed === false || ($errors && ($errors['warning_count'] > 0 || $errors['error_count'] > 0))) {
        throw new InvalidArgumentException("$fieldName must be in YYYY-MM-DD format and be a valid date.");
    }

    return $date;
}

private function formatDate(string $date): string
{
    $parsed = DateTimeImmutable::createFromFormat('Y-m-d', $date);
    return $parsed->format('d.m.Y');
}
```

---

## Phase 2: Exception Hierarchy & Error Clarity

### 2.1 Separate User Errors from System Errors

**Problem:** All exceptions wrapped as `RuntimeException`, obscuring root cause.

**Location:** `src/UPNQR.php:89-119`

**Solution:**

```php
public function generateQrCode(string $filename, int $size = 400): void
{
    $extension = pathinfo($filename, PATHINFO_EXTENSION);

    // User input validation — let InvalidArgumentException bubble
    $imageBackEnd = match ($extension) {
        'svg' => new SvgImageBackEnd(),
        'png' => new ImagickImageBackEnd(),
        'eps' => new EpsImageBackEnd(),
        default => throw new InvalidArgumentException(
            "Unsupported file extension '.$extension'. Use .png, .svg, or .eps."
        ),
    };

    // Preflight: check directory writability
    $dir = dirname($filename) ?: '.';
    if (!is_dir($dir) || !is_writable($dir)) {
        throw new InvalidArgumentException("Directory '$dir' does not exist or is not writable.");
    }

    try {
        $renderer = new ImageRenderer(new RendererStyle($size), $imageBackEnd);
        $writer = new Writer($renderer);
        $writer->writeFile($this->serializeContents(), $filename, "ISO-8859-2");
    } catch (InvalidArgumentException $e) {
        throw $e; // Re-throw user errors as-is
    } catch (Exception $e) {
        throw new RuntimeException("QR code generation failed: " . $e->getMessage(), 0, $e);
    }
}
```

---

### 2.2 Optional: Custom Exception Classes

For richer error handling downstream:

```php
// src/Exception/ValidationException.php
namespace DataLinx\PhpUpnQrGenerator\Exception;

use InvalidArgumentException;

class ValidationException extends InvalidArgumentException {}

// src/Exception/EncodingException.php
class EncodingException extends InvalidArgumentException {}

// src/Exception/QrGenerationException.php  
use RuntimeException;

class QrGenerationException extends RuntimeException {}
```

---

## Phase 3: Performance & Resource Optimization

### 3.1 Memoize Serialized Payload

**Problem:** Repeated `generateQrCode()` calls recompute payload.

**Solution:**

```php
private ?string $cachedPayload = null;
private bool $isDirty = true;

public function serializeContents(): string
{
    if (!$this->isDirty && $this->cachedPayload !== null) {
        return $this->cachedPayload;
    }

    // ... existing serialization logic ...

    $this->cachedPayload = $qrContentStr;
    $this->isDirty = false;

    return $qrContentStr;
}

// In every setter:
public function setPayerName(?string $payerName): self
{
    // ... validation ...
    $this->payerName = $payerName;
    $this->isDirty = true;  // Invalidate cache
    return $this;
}
```

---

### 3.2 Allow Renderer Injection

**Problem:** Hardcoded backends; no way to reuse Imagick instances or use alternatives.

**Solution:**

```php
use BaconQrCode\Renderer\ImageRendererInterface;

public function generateQrCode(
    string $filename,
    int $size = 400,
    ?ImageRendererInterface $customRenderer = null
): void {
    if ($customRenderer !== null) {
        $renderer = $customRenderer;
    } else {
        // existing logic to create renderer based on extension
    }

    // ...
}

// Alternative: dedicated method
public function generateQrCodeWithRenderer(
    string $filename, 
    ImageRendererInterface $renderer
): void {
    $writer = new Writer($renderer);
    $writer->writeFile($this->serializeContents(), $filename, "ISO-8859-2");
}
```

---

### 3.3 Add `getPayload()` Method for Inspection

**Benefit:** Consumers can inspect/log payload without file I/O.

```php
/**
 * Get the serialized QR payload without generating an image.
 * Useful for debugging or alternative QR generators.
 */
public function getPayload(): string
{
    return $this->serializeContents();
}
```

---

## Phase 4: API Ergonomics & Developer Experience

**Status:** Completed. Delivered `validate()` and `getPayload()` helpers; renderer reuse is covered under Phase 3.

### 4.1 Add Explicit `validate()` Method

```php
/**
 * Validate all fields without generating output.
 * @throws InvalidArgumentException if validation fails
 */
public function validate(): self
{
    $this->checkRequiredParameters();
    
    // Trigger serialization to validate lengths and charset
    $payload = $this->serializeContents();
    
    return $this;
}
```

---

### 4.2 Builder Pattern (Optional, Major Change)

For projects that prefer immutability:

```php
// src/UPNQRBuilder.php
class UPNQRBuilder
{
    private array $data = [];

    public function withRecipientIban(string $iban): self
    {
        $clone = clone $this;
        $clone->data['recipientIban'] = $iban;
        return $clone;
    }

    // ... other with* methods ...

    public function build(): UPNQR
    {
        if (!isset($this->data['recipientIban'], $this->data['recipientCity'])) {
            throw new InvalidArgumentException("recipientIban and recipientCity are required.");
        }

        $qr = new UPNQR();
        // ... set all fields ...
        return $qr;
    }
}
```

---

### 4.3 Improve `checkRequiredParameters()` to Cover Spec

**Location:** `src/UPNQR.php:126-138`

Current implementation only checks 2 fields. Per UPN QR spec, consider:

```php
public function checkRequiredParameters(): void
{
    $required = [
        'recipientIban' => $this->recipientIban ?? null,
        'recipientCity' => $this->recipientCity ?? null,
    ];

    $missing = array_keys(array_filter($required, fn($v) => $v === null || $v === ''));

    if (!empty($missing)) {
        throw new InvalidArgumentException(
            sprintf("Required field(s) missing: %s", implode(', ', $missing))
        );
    }
}
```

---

## Phase 5: Test Improvements

### 5.1 Add Validation Unit Tests

```php
public function testPayloadLengthExceedsLimit(): void
{
    $qr = new UPNQR();
    $qr->setRecipientIban("SI56020360253863406");
    $qr->setRecipientCity("Ljubljana");
    $qr->setPaymentPurpose(str_repeat("A", 42));
    $qr->setPayerName(str_repeat("B", 33));
    // ... fill all fields to exceed 411 chars

    $this->expectException(InvalidArgumentException::class);
    $this->expectExceptionMessage("exceeds maximum 411 characters");
    $qr->serializeContents();
}

public function testInvalidCharsetThrows(): void
{
    $qr = new UPNQR();
    $qr->setRecipientIban("SI56020360253863406");
    $qr->setRecipientCity("Ljubljana");

    $this->expectException(InvalidArgumentException::class);
    $qr->setPayerName("Test 🎉 Emoji"); // Emoji not in ISO-8859-2
}
```

### 5.2 Isolate Filesystem Tests

```php
protected function tearDown(): void
{
    // Clean up generated files
    array_map('unlink', glob('./build/qr*.{svg,png,eps}', GLOB_BRACE));
    parent::tearDown();
}
```

---

## Implementation Roadmap

| Priority | Phase | Description | Effort | Breaking Change |
|----------|-------|-------------|--------|-----------------|
| **P0** | 1.3 | Payload length validation | 30 min | No |
| **P0** | 1.4 | Charset validation | 1 hr | No (adds errors) |
| **P0** | 1.5 | Amount precision | 15 min | No |
| **P1** | 1.2 | Normalize setters | 2 hr | Minor (stricter) |
| **P1** | 1.6 | DateTimeImmutable | 1 hr | No |
| **P1** | 2.1 | Exception separation | 1 hr | No |
| **P2** | 3.1 | Memoization | 1 hr | No |
| **P2** | 3.3 | `getPayload()` method | 15 min | No |
| **P2** | 4.1 | `validate()` method | 30 min | No |
| **P3** | 1.1 | Required fields constructor | 1 hr | Yes (major) |
| **P3** | 3.2 | Renderer injection | 1 hr | No |
| **P3** | 2.2 | Custom exceptions | 1 hr | No |
| **P4** | 4.2 | Builder pattern | 3 hr | No (additive) |

---

## Summary

### Quick wins (implement first):
- Payload length check (spec compliance)
- Charset validation (prevent silent data corruption)
- Amount rounding (precision safety)
- Exception separation (better DX)

### Medium effort, high value:
- Setter normalization (consistency)
- `getPayload()` + `validate()` (API clarity)
- Memoization (performance for repeated calls)

### Consider for a future major:
- Constructor with required fields (breaking)
- Full builder pattern
- Custom exception hierarchy

---

## Appendix: Original Findings

### Code Smells Identified
1. Getter/setter asymmetry allows undefined required fields until runtime
2. Incomplete validation consistency across setters
3. Date formatting without timezone control
4. All exceptions wrapped as RuntimeException
5. `checkRequiredParameters()` incomplete per spec
6. No payload length validation (411 char max)
7. Amount precision silently truncated
8. No charset validation for ISO-8859-2

### Performance Concerns
1. No caching of serialized content
2. Hardcoded Imagick backend (heavyweight)
3. No path writability preflight checks
