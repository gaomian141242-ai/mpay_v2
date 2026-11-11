<?php
require_once __DIR__ . '/../app/service/payment/manual/ManualPaymentReportPolicy.php';

use app\service\payment\manual\ManualPaymentReportPolicy as Policy;

function check(bool $ok, string $message): void {
    if (!$ok) {
        throw new RuntimeException($message);
    }
}
foreach (['paid', 'unpaid', 'unknown'] as $result) {
    for ($attempt = 1; $attempt <= 3; ++$attempt) {
        $outcome = Policy::outcome($result, $attempt);
        check(!($result === 'paid' && $outcome['status'] === 'paid'), 'unverified paid must not confirm payment');
        if ($result === 'paid') {
            check($outcome['status'] === 'unknown' && $outcome['retry_seconds'] === null, 'paid requires review');
        } elseif ($attempt < 3) {
            check($outcome['status'] === 'pending' && $outcome['retry_seconds'] === 30 * $attempt, 'retry policy');
        } else {
            check($outcome['retry_seconds'] === null, 'retry limit');
            check($outcome['status'] === ($result === 'unpaid' ? 'unpaid' : 'error'), 'terminal result');
        }
    }
}
foreach ([['paid', 0], ['invalid', 1]] as [$result, $attempt]) {
    try {
        Policy::outcome($result, $attempt);
        throw new RuntimeException('invalid input accepted');
    } catch (InvalidArgumentException $expected) {
    }
}
echo "manual payment report policy OK\n";
