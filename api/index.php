<?php

declare(strict_types=1);

if ($_SERVER['REQUEST_METHOD'] === 'POST'
    && str_contains($_SERVER['REQUEST_URI'] ?? '', '/webhooks/buymeacoffee')) {
    $signatureHeaders = [];

    foreach (getallheaders() as $name => $value) {
        if (stripos($name, 'signature') !== false) {
            $signatureHeaders[] = $name;
        }
    }

    error_log('[BMC DEBUG] Cabeçalhos de assinatura: ' . implode(', ', $signatureHeaders));
}

require_once dirname(__DIR__) .'/index.php';