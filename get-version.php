<?php

/**
 * Extract version from the plugin file for build scripts.
 */
$content = file_get_contents(__DIR__.'/bank-transfer-payments-for-woocommerce.php');
if (preg_match('/Version:\s*([0-9.]+)/', $content, $matches)) {
    echo $matches[1];
} else {
    echo '0.0.0';
}
