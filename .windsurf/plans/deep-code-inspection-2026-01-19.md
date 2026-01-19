# Deep Code Inspection Findings

Comprehensive line-by-line review of the UPN QR library identifying code smells, logic issues, dead code, performance concerns, and missing functionality.

---

## Critical Issues

### 1. IBAN Validation Too Restrictive (Logic Error)
**Location:** `@/home/tomaz/sources/php-upn-qr-generator/src/UPNQR.php:260,640`

The regex `/^[a-z]{2}\d{17}$/i` only accepts exactly 19-char IBANs with 2-letter prefix + 17 digits. This is **Slovenia-specific** but:
- Other countries have different IBAN lengths (e.g., DE=22, GB=22, FR=27)
- The code claims "alpha-2 ISO standard" but actually enforces 19-char Slovenian format

**Impact:** Users trying to use non-Slovenian IBANs will get cryptic errors.

**Fix:** Either document as SI-only, or implement proper IBAN validation per ISO 13616.

---

### 2. `setRecipientIban` Accepts Empty String (Logic Error)
**Location:** `@/home/tomaz/sources/php-upn-qr-generator/src/UPNQR.php:636-649`

```php
public function setRecipientIban(string $recipientIban): self
{
    if ($recipientIban) {  // Empty string is falsy, skips validation
        $recipientIban = trim(str_replace(' ', '', $recipientIban));
        if (! preg_match(...)) { ... }
    }
    $this->recipientIban = $recipientIban;  // Empty string stored!
    ...
}
```

An empty string `""` passes through, setting `recipientIban` to `""`, which then passes `checkRequiredParameters()` (which uses `isset()`, not empty check). This breaks the required field contract.

**Fix:** Add empty string check or use the same pattern as `setRecipientCity`.

---

### 3. Unused Import
**Location:** `@/home/tomaz/sources/php-upn-qr-generator/src/UPNQR.php:12`

```php
use DateTimeZone;
```

`DateTimeZone` is imported but never used anywhere in the class.

**Fix:** Remove unused import.

---

### 4. Payload Length Measured in Wrong Encoding
**Location:** `@/home/tomaz/sources/php-upn-qr-generator/src/UPNQR.php:96-105`

```php
$payloadLength = mb_strlen($qrContentStr, 'UTF-8');
if ($payloadLength > self::MAX_PAYLOAD_LENGTH) { ... }
```

The spec states 411 **bytes** in ISO-8859-2, not UTF-8 characters. Multi-byte UTF-8 chars (like `č`, `š`, `ž`) count as 1 in `mb_strlen('UTF-8')` but occupy 2 bytes in UTF-8 (and 1 byte in ISO-8859-2). This check should measure in ISO-8859-2 after conversion.

**Fix:** Convert to ISO-8859-2 first, then use `strlen()` (byte length).

---

### 5. Test Anti-Pattern: Silent Catch Without Assertion
**Location:** `@/home/tomaz/sources/php-upn-qr-generator/tests/Unit/UPNQRTest.php:109-114` and many others

```php
try {
    $UPNQR->checkRequiredParameters();
} catch (Exception $e) {
    $this->assertEquals("recipientIban is required.", $e->getMessage());
}
// No $this->fail() if exception not thrown!
```

If the exception is NOT thrown, the test silently passes. This pattern is repeated throughout the test file for "wrongCases" loops.

**Fix:** Use `$this->expectException()` or add `$this->fail('Expected exception not thrown')` after the try block.

---

## Medium Issues

### 6. Inconsistent Null Coalescing in Getters
**Location:** Multiple getters

Some getters use `?? null`:
```php
public function getPayerIban(): ?string { return $this->payerIban ?? null; }
```

But `recipientIban` and `recipientCity` don't have this pattern because they're typed as non-nullable, yet they can be uninitialized. This creates a type inconsistency.

---

### 7. `formatDate` is Public but Should Be Private/Protected
**Location:** `@/home/tomaz/sources/php-upn-qr-generator/src/UPNQR.php:789-798`

`formatDate()` is a helper for internal date formatting. It's exposed publicly without clear need. The tests do call it, but that's testing implementation details.

---

### 8. Redundant Re-throw Pattern
**Location:** `@/home/tomaz/sources/php-upn-qr-generator/src/UPNQR.php:156-158,175-176`

```php
} catch (InvalidArgumentException $exception) {
    throw $exception;  // Catching just to re-throw unchanged
} catch (Exception $exception) { ... }
```

This is semantically correct but noisy. Could use `Throwable` filtering or let `InvalidArgumentException` bubble naturally.

---

### 9. Hardcoded "SI99" Default Reference
**Location:** `@/home/tomaz/sources/php-upn-qr-generator/src/UPNQR.php:90`

```php
$this->getRecipientReference() ?: "SI99",
```

The default `SI99` is hardcoded inline. Should be a class constant for clarity and consistency.

---

### 10. `strpos` vs `str_starts_with`
**Location:** `@/home/tomaz/sources/php-upn-qr-generator/src/UPNQR.php:345,679`

```php
if (0 === strpos($payerReference, "SI") && ...)
```

PHP 8.0+ has `str_starts_with()` which is clearer and faster.

---

### 11. Demo: Missing CSRF Protection
**Location:** `@/home/tomaz/sources/php-upn-qr-generator/demo/index.php`

The demo form has no CSRF token. While this is a demo, it's a bad practice to demonstrate.

---

## Minor Issues / Code Smells

### 12. Magic Number in Formatted Amount
**Location:** `@/home/tomaz/sources/php-upn-qr-generator/src/UPNQR.php:463`

```php
return str_pad(number_format($this->amount, 2, "", ""), 11, 0, STR_PAD_LEFT);
```

The `11` should be a constant (e.g., `AMOUNT_FORMAT_WIDTH`).

---

### 13. Inconsistent Exception Docblocks
**Location:** Multiple setters

Some setters have `@throws Exception` in docblocks, but they actually throw `InvalidArgumentException`. The docblocks are imprecise.

---

### 14. Test: Duplicate Test Cases
**Location:** `@/home/tomaz/sources/php-upn-qr-generator/tests/Unit/UPNQRTest.php:509-510`

```php
["  RF99123456789     ", "RF99123456789"],
["  RF99123456789     ", "RF99123456789"],  // Exact duplicate
```

---

### 15. No Explicit Return Type on `createWriter`
**Location:** `@/home/tomaz/sources/php-upn-qr-generator/src/UPNQR.php:203-206`

```php
protected function createWriter(ImageRenderer $renderer)  // No return type
```

Should be `: Writer` for clarity.

---

## Missing Functionality

### 16. No Checksum/Control Digit Validation for References
The SI and RF reference formats have checksum algorithms that could be validated. Currently only format is checked.

### 17. No IBAN Checksum Validation
IBANs have a MOD-97 checksum. The library only validates format, not checksum.

### 18. No Way to Get Raw (Pre-Checksum) Payload
`getPayload()` returns the full payload including the 3-digit length suffix. No method returns just the content portion.

---

## Performance Notes

### 19. Caching Works Correctly
The `isDirty` flag + `cachedSerializedPayload` pattern is implemented correctly.

### 20. No Obvious Memory Leaks
Object doesn't hold heavy resources beyond cached string.

---

## Summary Table

| # | Severity | Category | Location | Issue |
|---|----------|----------|----------|-------|
| 1 | High | Logic | L260,640 | IBAN validation is SI-only but documented as generic |
| 2 | High | Logic | L636-649 | Empty string accepted for required `recipientIban` |
| 3 | Low | Dead Code | L12 | Unused `DateTimeZone` import |
| 4 | Medium | Logic | L96-105 | Payload length check uses wrong encoding |
| 5 | Medium | Test | Multiple | Silent catch without failure assertion |
| 6 | Low | Smell | Getters | Inconsistent null coalescing |
| 7 | Low | API | L789 | `formatDate` shouldn't be public |
| 8 | Low | Smell | L156-158 | Redundant re-throw |
| 9 | Low | Smell | L90 | Hardcoded "SI99" default |
| 10 | Low | Smell | L345,679 | Use `str_starts_with` |
| 11 | Low | Security | demo | No CSRF token |
| 12 | Low | Smell | L463 | Magic number 11 |
| 13 | Low | Docs | Multiple | Imprecise `@throws` annotations |
| 14 | Low | Test | L509-510 | Duplicate test case |
| 15 | Low | Type | L203 | Missing return type |

---

## Implementation Plan (Bundled)

All fixes will be implemented together in a single pass.

### UPNQR.php Changes

1. **Full IBAN validation (ISO 13616)** — Replace 19-char SI-only regex with full validation:
   - Normalize: trim, remove spaces, uppercase.
   - Format: 2 letters + 2 digits + 11-30 alphanumeric (total 15-34).
   - Enforce length by country code using an IBAN length table.
   - Validate MOD-97 checksum per ISO 13616.
   - Update error messages to call out invalid format/length/checksum.

2. **Fix empty `recipientIban` bug** — Reject empty string with updated message.

3. **Fix payload length encoding** — Measure in ISO-8859-2 bytes:
   ```php
   $encoded = iconv('UTF-8', self::OUTPUT_ENCODING, $qrContentStr);
   $payloadLength = strlen($encoded);  // byte length
   ```

4. **Remove unused import** — Delete `use DateTimeZone;`

5. **Add missing constant** — `DEFAULT_RECIPIENT_REFERENCE = 'SI99'`

6. **Add return type** — `createWriter(): Writer`

7. **Modernize to `str_starts_with`** — Replace `0 === strpos(...)` patterns

8. **Align required-parameter errors** — Ensure `recipientIban`/`recipientCity` messages stay consistent with new wording.

### UPNQRTest.php Changes

9. **Fix silent catch anti-pattern** — Add `$this->fail()` after try blocks or use `expectException()`

10. **Remove duplicate test case** — Line 509-510

11. **Update IBAN test cases** — Add EU IBAN tests plus invalid length/checksum cases.

### Out of Scope (Deferred)

- CSRF for demo (not production code)
- Reference checksum validation (future enhancement)
