# Falcon Media -- Magento 2 CSP SRI Lock

**Package:** `falconmedia/magento2-csp-sri-lock`\
**Type:** Magento 2 Module\
**License:** MIT\
**Maintainer:** Henk Valk <henk@falconmedia.nl>

------------------------------------------------------------------------


------------------------------------------------------------------------

## Installation

### Via Composer

    composer require falconmedia/magento2-csp-sri-lock
    php bin/magento module:enable FalconMedia_CspSriLock
    php bin/magento setup:upgrade
    php bin/magento cache:flush

------------------------------------------------------------------------

## 🔍 Verification

After installation, verify that the correct storage class is active:

    php bin/magento dev:di:info Magento\Csp\Model\SubresourceIntegrity\Storage\File

Expected output:

    Preference: FalconMedia\CspSriLock\Model\SubresourceIntegrity\Storage\File

------------------------------------------------------------------------

## Problem

Magento 2.4.x stores Subresource Integrity (SRI) hashes in:

    pub/static/frontend/sri-hashes.json
    pub/static/adminhtml/sri-hashes.json

Under load, multiple PHP-FPM workers can write to the same file
simultaneously.

Magento's default implementation writes using file mode `'w'` without
locking.

This can cause:

-   Truncated JSON files
-   Partially written content
-   Invalid JSON
-   Fatal error in checkout:

```{=html}
<!-- -->
```
    Unable to unserialize value. Error: Syntax error
    Magento\Csp\Model\SubresourceIntegrityRepository->getData()

This often results in checkout becoming completely unavailable.

------------------------------------------------------------------------

## Root Cause

The core implementation:

-   Opens the file with mode `'w'` (truncate immediately)
-   Does not use file locking
-   Does not use atomic file replacement

If two requests write simultaneously:

    Request A → truncates file
    Request B → truncates file
    Request A → writes partial JSON
    Request B → overwrites partially

Result: corrupted JSON → checkout crash.

------------------------------------------------------------------------

## Solution

This module replaces Magento's default SRI file storage with a safer
implementation that:

-   Uses `flock()` for exclusive locking
-   Writes to a temporary file first
-   Replaces the target using atomic `rename()`
-   Prevents truncated or corrupted JSON
-   Keeps full backward compatibility

No database changes.\
No configuration required.\
Drop-in safe fix.


## Testing

### 1. Remove existing SRI files

    rm -f pub/static/frontend/sri-hashes.json
    rm -f pub/static/adminhtml/sri-hashes.json
    php bin/magento cache:flush

### 2. Generate concurrent requests

    for i in {1..30}; do curl -s https://yourdomain.com/checkout/ > /dev/null & done; wait

### 3. Validate JSON

    php -r 'json_decode(@file_get_contents("pub/static/frontend/sri-hashes.json")); echo json_last_error();'

Expected result:

    0

------------------------------------------------------------------------

## Compatibility

-   Magento 2.4.x
-   PHP 8.1 / 8.2 / 8.3
-   Single-node and multi-node environments

------------------------------------------------------------------------

## Why This Matters

Checkout outages caused by corrupted SRI files can result in:

-   Lost revenue
-   Broken storefront
-   Emergency hotfixes
-   Unnecessary cache clears

This module eliminates that class of failure entirely.

------------------------------------------------------------------------

## License

MIT License\
© 2026 Falcon Media
