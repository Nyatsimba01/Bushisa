<?php

declare(strict_types=1);

require_once __DIR__ . '/../php/confession_reports.php';

$checks = [
    [1, 'harassment', '', true],
    [5, 'private_information', 'Shared personal details', true],
    [8, 'spam', str_repeat('x', 140), true],
    [0, 'harassment', '', false],
    [-1, 'spam', '', false],
    [4, 'not_a_reason', '', false],
    [4, '', '', false],
    [4, 'hate_speech', str_repeat('x', 141), false],
];

$failed = 0;
foreach ($checks as [$id, $reason, $details, $expected]) {
    $result = bushisa_validate_confession_report($id, $reason, $details);
    $valid = $result === null;
    if ($valid !== $expected) {
        fwrite(STDERR, 'FAIL: confession=' . $id . ' reason=' . $reason . PHP_EOL);
        ++$failed;
    }
}
if (count(bushisa_confession_report_reasons()) !== 6) {
    fwrite(STDERR, 'FAIL: reason list changed unexpectedly' . PHP_EOL);
    ++$failed;
}
if ($failed > 0) {
    exit(1);
}
echo "Confessions reporting validation passed (" . count($checks) . " cases).\n";
