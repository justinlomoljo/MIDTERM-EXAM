<?php
declare(strict_types=1);

require_once dirname(__DIR__) . '/domain.php';

$pdo = db();
$staffRecord = $pdo->query('SELECT id, full_name FROM users WHERE role = "staff" ORDER BY id LIMIT 1')->fetch();
if (!$staffRecord) {
    fwrite(STDERR, "No staff account found. Import schema.sql and run seed.php first.\n");
    exit(2);
}

$lookup = $pdo->prepare(
    'SELECT s.*, u.student_id, p.max_gwa, p.min_units, p.max_failed, p.max_incomplete,
            p.max_dropped, p.deadline_at
     FROM submissions s
     JOIN users u ON u.id = s.scholar_id
     JOIN scholars sc ON sc.user_id = u.id
     JOIN program_policies p ON p.id = sc.policy_id
     WHERE u.student_id = :student_id AND s.academic_year = "2026-2027" AND s.semester = 1'
);
$records = [];
foreach (['A01', 'A02', 'A03', 'A04', 'A05', 'A06'] as $studentId) {
    $lookup->execute(['student_id' => $studentId]);
    $record = $lookup->fetch();
    if (!$record) {
        fwrite(STDERR, "Missing seeded record {$studentId}. Run seed.php first.\n");
        exit(2);
    }
    $records[$studentId] = $record;
}

$results = [];
$test = static function (string $id, string $action, string $expected, callable $run, string $evidence) use (&$results): void {
    try {
        $actual = $run();
        $pass = (bool) ($actual['pass'] ?? false);
        $actualText = (string) ($actual['actual'] ?? '');
    } catch (Throwable $error) {
        $pass = false;
        $actualText = 'ERROR: ' . $error->getMessage();
    }
    $results[] = [$id, $action, $expected, $actualText, $pass ? 'PASS' : 'FAIL', $evidence];
};

$staffActor = [
    'id' => (int) $staffRecord['id'],
    'role' => 'staff',
    'full_name' => $staffRecord['full_name'],
];
$_SESSION['user'] = $staffActor;
$pdo->beginTransaction();
try {
    $test('TC01', 'Evaluate A01 at GWA/deadline boundaries', 'Compliant; exact GWA and deadline pass', static function () use ($records): array {
        $result = evaluateCompliance($records['A01'], $records['A01']);
        return [
            'pass' => $result['status'] === 'compliant' && $result['reasons'] === [],
            'actual' => ucwords(str_replace('_', ' ', $result['status'])) . '; ' . count($result['reasons']) . ' reasons',
        ];
    }, 'A01 seeded record');

    $test('TC02', 'Evaluate A02 above GWA limit', 'With Deficiency; GWA reason present', static function () use ($records): array {
        $result = evaluateCompliance($records['A02'], $records['A02']);
        $hasGwaReason = count(array_filter($result['reasons'], static fn(string $reason): bool => str_contains($reason, 'GWA')));
        return [
            'pass' => $result['status'] === 'with_deficiency' && $hasGwaReason > 0,
            'actual' => ucwords(str_replace('_', ' ', $result['status'])) . '; ' . implode(' ', $result['reasons']),
        ];
    }, 'A02 seeded record');

    $test('TC03', 'Evaluate A03 Community Scholar allowance', 'Compliant with one dropped subject', static function () use ($records): array {
        $result = evaluateCompliance($records['A03'], $records['A03']);
        return [
            'pass' => $result['status'] === 'compliant' && (int) $records['A03']['dropped_subjects'] === 1,
            'actual' => ucwords(str_replace('_', ' ', $result['status'])) . '; dropped=' . $records['A03']['dropped_subjects'],
        ];
    }, 'A03 seeded record');

    $test('TC04', 'Evaluate A04 multiple deficiencies', 'Insufficient units and incomplete grade identified', static function () use ($records): array {
        $result = evaluateCompliance($records['A04'], $records['A04']);
        $allReasons = implode(' ', $result['reasons']);
        $units = str_contains($allReasons, 'units');
        $incomplete = str_contains($allReasons, 'incomplete');
        return [
            'pass' => $result['status'] === 'with_deficiency' && $units && $incomplete,
            'actual' => implode(' | ', $result['reasons']),
        ];
    }, 'A04 seeded record');

    $test('TC05', 'Evaluate A05 failing subject and late submission', 'Both failure and late reasons identified', static function () use ($records): array {
        $result = evaluateCompliance($records['A05'], $records['A05']);
        $allReasons = implode(' ', $result['reasons']);
        return [
            'pass' => str_contains($allReasons, 'failed subject') && str_contains($allReasons, 'after the deadline'),
            'actual' => implode(' | ', $result['reasons']),
        ];
    }, 'A05 seeded record');

    $test('TC06', 'Try evaluating and approving pending A06', 'Both actions blocked before verification/evaluation', static function () use ($pdo, $staffActor, $records): array {
        $evaluationBlocked = false;
        $approvalBlocked = false;
        try {
            evaluateSubmission($pdo, $staffActor, (int) $records['A06']['id']);
        } catch (DomainException) {
            $evaluationBlocked = true;
        }
        try {
            decideRenewal($pdo, $staffActor, (int) $records['A06']['id'], 'approved', 'Test');
        } catch (DomainException) {
            $approvalBlocked = true;
        }
        return [
            'pass' => $evaluationBlocked && $approvalBlocked,
            'actual' => 'evaluation blocked=' . ($evaluationBlocked ? 'yes' : 'no')
                . '; approval blocked=' . ($approvalBlocked ? 'yes' : 'no'),
        ];
    }, 'A06 pending record');

    $test('TC07', 'Submit duplicate semester, GWA 0.50, and negative count', 'All invalid submissions rejected clearly', static function () use ($records): array {
        $base = [
            'academic_year' => '2026-2027',
            'semester' => 1,
            'gwa' => '0.50',
            'enrolled_units' => 18,
            'failed_subjects' => 0,
            'incomplete_grades' => 0,
            'dropped_subjects' => 0,
            'grade_document_reference' => 'test/ref',
        ];
        $invalidGwa = false;
        $negativeCount = false;
        $duplicate = false;
        try {
            validateSummary($base);
        } catch (DomainException) {
            $invalidGwa = true;
        }
        $negative = $base;
        $negative['gwa'] = '2.00';
        $negative['failed_subjects'] = -1;
        try {
            validateSummary($negative);
        } catch (DomainException) {
            $negativeCount = true;
        }
        try {
            $duplicateInput = $base;
            $duplicateInput['gwa'] = '1.50';
            saveSubmission($GLOBALS['pdo'], [
                'id' => (int) $records['A01']['scholar_id'],
                'role' => 'scholar',
                'student_id' => 'A01',
            ], $duplicateInput);
        } catch (DomainException $error) {
            $duplicate = str_contains($error->getMessage(), 'already exists');
        }
        return [
            'pass' => $invalidGwa && $negativeCount && $duplicate,
            'actual' => 'invalid GWA=' . ($invalidGwa ? 'rejected' : 'accepted')
                . '; negative count=' . ($negativeCount ? 'rejected' : 'accepted')
                . '; duplicate=' . ($duplicate ? 'rejected' : 'accepted'),
        ];
    }, 'A01 duplicate + invalid-value attempts');

    $test('TC08', 'Scholar attempts cross-record read and staff verification', 'Backend denies both actions', static function () use ($pdo, $records): array {
        $scholar = [
            'id' => (int) $records['A01']['scholar_id'],
            'role' => 'scholar',
            'full_name' => 'Test scholar',
        ];
        $readDenied = false;
        $verifyDenied = false;
        try {
            assertOwnSubmission($scholar, $records['A02']);
        } catch (DomainException) {
            $readDenied = true;
        }
        $_SESSION['user'] = $scholar;
        try {
            verifyEvidence($pdo, $scholar, (int) $records['A06']['id'], 'verified', '');
        } catch (DomainException) {
            $verifyDenied = true;
        }
        $_SESSION['user'] = $GLOBALS['staffActor'];
        return [
            'pass' => $readDenied && $verifyDenied,
            'actual' => 'cross-record read denied=' . ($readDenied ? 'yes' : 'no')
                . '; verification denied=' . ($verifyDenied ? 'yes' : 'no'),
        ];
    }, 'A01 attempting to access A02/A06');

    $test('TC09', 'Return, correct, resubmit, and inspect audit history', 'Pending verification after correction; prior actions retained', static function () use ($pdo, $staffActor): array {
        $suffix = bin2hex(random_bytes(4));
        $create = $pdo->prepare(
            'INSERT INTO users (role, full_name, student_id, email, password_hash)
             VALUES ("scholar", "TC09 Temporary Scholar", :student_id, :email, :password_hash)'
        );
        $studentId = 'T' . strtoupper($suffix);
        $create->execute([
            'student_id' => $studentId,
            'email' => strtolower($studentId) . '@test.invalid',
            'password_hash' => password_hash('TemporaryTest!2026', PASSWORD_DEFAULT),
        ]);
        $scholarId = (int) $pdo->lastInsertId();
        $policyId = (int) $pdo->query('SELECT id FROM program_policies WHERE code = "MERIT"')->fetchColumn();
        $assign = $pdo->prepare('INSERT INTO scholars (user_id, policy_id) VALUES (:user_id, :policy_id)');
        $assign->execute(['user_id' => $scholarId, 'policy_id' => $policyId]);
        $scholarActor = ['id' => $scholarId, 'role' => 'scholar', 'student_id' => $studentId];
        $input = [
            'academic_year' => '2027-2028',
            'semester' => 1,
            'gwa' => '1.50',
            'enrolled_units' => 18,
            'failed_subjects' => 0,
            'incomplete_grades' => 0,
            'dropped_subjects' => 0,
            'grade_document_reference' => 'tc09/reference',
        ];
        $_SESSION['user'] = $scholarActor;
        $submissionId = saveSubmission($pdo, $scholarActor, $input);
        $_SESSION['user'] = $staffActor;
        verifyEvidence($pdo, $staffActor, $submissionId, 'returned', 'Please attach the corrected document reference.');
        $input['submission_id'] = (string) $submissionId;
        $input['grade_document_reference'] = 'tc09/corrected-reference';
        $_SESSION['user'] = $scholarActor;
        saveSubmission($pdo, $scholarActor, $input);
        $check = $pdo->prepare('SELECT verification_status, grade_document_reference FROM submissions WHERE id = :id');
        $check->execute(['id' => $submissionId]);
        $updated = $check->fetch();
        $history = $pdo->prepare('SELECT COUNT(*) FROM audit_logs WHERE submission_id = :id');
        $history->execute(['id' => $submissionId]);
        $historyCount = (int) $history->fetchColumn();
        $_SESSION['user'] = $staffActor;
        return [
            'pass' => $updated['verification_status'] === 'pending'
                && $updated['grade_document_reference'] === 'tc09/corrected-reference'
                && $historyCount >= 3,
            'actual' => $updated['verification_status'] . '; corrected reference saved; audit entries=' . $historyCount,
        ];
    }, 'Temporary TC09 record; transaction rolled back after run');

    $test('TC10', 'Record compliant approval, deficient hold, and scholar denial', 'Staff can approve/hold; scholar cannot change decision', static function () use ($pdo, $staffActor, $records): array {
        decideRenewal($pdo, $staffActor, (int) $records['A01']['id'], 'approved', 'Meets all Merit Scholar checks.');
        decideRenewal($pdo, $staffActor, (int) $records['A02']['id'], 'held', 'GWA exceeds the permitted maximum.');
        $decisions = $pdo->prepare(
            'SELECT id, renewal_decision FROM submissions WHERE id IN (:compliant, :deficient)'
        );
        $decisions->execute([
            'compliant' => $records['A01']['id'],
            'deficient' => $records['A02']['id'],
        ]);
        $actual = [];
        foreach ($decisions->fetchAll() as $row) {
            $actual[(int) $row['id']] = $row['renewal_decision'];
        }
        $scholar = ['id' => (int) $records['A01']['scholar_id'], 'role' => 'scholar'];
        $_SESSION['user'] = $scholar;
        $scholarDenied = false;
        try {
            decideRenewal($pdo, $scholar, (int) $records['A01']['id'], 'held', 'Unauthorized');
        } catch (DomainException) {
            $scholarDenied = true;
        }
        $_SESSION['user'] = $staffActor;
        return [
            'pass' => ($actual[(int) $records['A01']['id']] ?? null) === 'approved'
                && ($actual[(int) $records['A02']['id']] ?? null) === 'held'
                && $scholarDenied,
            'actual' => 'A01=' . ($actual[(int) $records['A01']['id']] ?? 'missing')
                . '; A02=' . ($actual[(int) $records['A02']['id']] ?? 'missing')
                . '; scholar change denied=' . ($scholarDenied ? 'yes' : 'no'),
        ];
    }, 'A01/A02 decisions; transaction rolled back after run');

    $test('TC11', 'Staff registers a scholar and assigns a saved policy', 'Scholar profile and policy assignment are persisted', static function () use ($pdo, $staffActor): array {
        $suffix = bin2hex(random_bytes(4));
        $studentId = 'R' . strtoupper($suffix);
        $policyId = (int) $pdo->query('SELECT id FROM program_policies WHERE code = "COMMUNITY"')->fetchColumn();
        registerScholar($pdo, $staffActor, [
            'full_name' => 'TC11 Temporary Scholar',
            'student_id' => $studentId,
            'email' => strtolower($studentId) . '@test.invalid',
            'password' => 'TemporaryTest!2026',
            'policy_id' => $policyId,
        ]);
        $statement = $pdo->prepare(
            'SELECT u.role, sc.policy_id FROM users u JOIN scholars sc ON sc.user_id = u.id
             WHERE u.student_id = :student_id'
        );
        $statement->execute(['student_id' => $studentId]);
        $registered = $statement->fetch();
        return [
            'pass' => $registered && $registered['role'] === 'scholar'
                && (int) $registered['policy_id'] === $policyId,
            'actual' => $registered
                ? $registered['role'] . '; assigned policy ID=' . $registered['policy_id']
                : 'No scholar profile found',
        ];
    }, 'Temporary TC11 account; transaction rolled back after run');
} finally {
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }
}

echo "| Test | Input / action | Expected result | Actual result | Status | Evidence reference |\n";
echo "|---|---|---|---|---|---|\n";
foreach ($results as [$id, $action, $expected, $actual, $status, $evidence]) {
    $cells = array_map(
        static fn(string $value): string => str_replace('|', '\\|', str_replace(["\r", "\n"], ' ', $value)),
        [$id, $action, $expected, $actual, $status, $evidence]
    );
    echo '| ' . implode(' | ', $cells) . " |\n";
}

$failed = count(array_filter($results, static fn(array $result): bool => $result[4] !== 'PASS'));
echo "\nPassed: " . (count($results) - $failed) . '/' . count($results) . "\n";
exit($failed === 0 ? 0 : 1);
