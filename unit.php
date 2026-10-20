<?php
declare(strict_types=1);

require_once dirname(__DIR__) . '/domain.php';

$deadline = '2026-10-30 17:00:00';
$merit = [
    'max_gwa' => 1.75,
    'min_units' => 18,
    'max_failed' => 0,
    'max_incomplete' => 0,
    'max_dropped' => 0,
    'deadline_at' => $deadline,
];
$community = [
    'max_gwa' => 2.25,
    'min_units' => 15,
    'max_failed' => 0,
    'max_incomplete' => 0,
    'max_dropped' => 1,
    'deadline_at' => $deadline,
];
$record = static function (
    string $gwa,
    int $units,
    int $failed,
    int $incomplete,
    int $dropped,
    string $submittedAt,
    string $verification = 'verified'
): array {
    return [
        'gwa' => $gwa,
        'enrolled_units' => $units,
        'failed_subjects' => $failed,
        'incomplete_grades' => $incomplete,
        'dropped_subjects' => $dropped,
        'submitted_at' => $submittedAt,
        'verification_status' => $verification,
    ];
};

$cases = [
    ['TC01', 'Merit inclusive GWA and exact deadline', static function () use ($record, $merit): bool {
        $result = evaluateCompliance($record('1.75', 18, 0, 0, 0, '2026-10-30 17:00:00'), $merit);
        return $result['status'] === 'compliant' && $result['reasons'] === [];
    }],
    ['TC02', 'Above maximum GWA has a reason', static function () use ($record, $merit): bool {
        $result = evaluateCompliance($record('1.76', 18, 0, 0, 0, '2026-10-29 17:00:00'), $merit);
        return $result['status'] === 'with_deficiency'
            && count(array_filter($result['reasons'], static fn(string $reason): bool => str_contains($reason, 'GWA'))) > 0;
    }],
    ['TC03', 'Community policy allows one dropped subject', static function () use ($record, $community): bool {
        $result = evaluateCompliance($record('2.25', 15, 0, 0, 1, '2026-10-29 17:00:00'), $community);
        return $result['status'] === 'compliant';
    }],
    ['TC04', 'Multiple academic deficiencies are reported', static function () use ($record, $community): bool {
        $result = evaluateCompliance($record('2.25', 14, 0, 1, 0, '2026-10-29 17:00:00'), $community);
        $reasons = implode(' ', $result['reasons']);
        return str_contains($reasons, 'units') && str_contains($reasons, 'incomplete');
    }],
    ['TC05', 'Failure and one-minute lateness are reported', static function () use ($record, $merit): bool {
        $result = evaluateCompliance($record('1.50', 18, 1, 0, 0, '2026-10-30 17:01:00'), $merit);
        $reasons = implode(' ', $result['reasons']);
        return str_contains($reasons, 'failed subject') && str_contains($reasons, 'after the deadline');
    }],
    ['TC06', 'Unverified submission cannot be evaluated', static function () use ($record, $community): bool {
        try {
            evaluateCompliance($record('2.00', 15, 0, 0, 0, '2026-10-29 17:00:00', 'pending'), $community);
            return false;
        } catch (DomainException) {
            return true;
        }
    }],
    ['TC07', 'Invalid GWA and negative count are rejected', static function (): bool {
        $base = [
            'academic_year' => '2026-2027',
            'semester' => 1,
            'gwa' => '0.50',
            'enrolled_units' => 18,
            'failed_subjects' => 0,
            'incomplete_grades' => 0,
            'dropped_subjects' => 0,
            'grade_document_reference' => 'test/reference',
        ];
        try {
            validateSummary($base);
        } catch (DomainException) {
            $base['gwa'] = '2.00';
            $base['failed_subjects'] = -1;
            try {
                validateSummary($base);
                return false;
            } catch (DomainException) {
                return true;
            }
        }
        return false;
    }],
];

echo "| Test | Check | Result |\n|---|---|---|\n";
$failed = 0;
foreach ($cases as [$id, $description, $run]) {
    $passed = $run();
    if (!$passed) {
        $failed++;
    }
    echo '| ' . $id . ' | ' . $description . ' | ' . ($passed ? 'PASS' : 'FAIL') . " |\n";
}
echo "\nPassed: " . (count($cases) - $failed) . '/' . count($cases) . "\n";
exit($failed === 0 ? 0 : 1);
