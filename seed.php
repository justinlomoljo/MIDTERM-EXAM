<?php
declare(strict_types=1);

require_once __DIR__ . '/domain.php';

$pdo = db();
$deadline = '2026-10-30 17:00:00';
$policies = [
    ['MERIT', 'Merit Scholar', 1.75, 18, 0, 0, 0],
    ['COMMUNITY', 'Community Scholar', 2.25, 15, 0, 0, 1],
];

$insertPolicy = $pdo->prepare(
    'INSERT INTO program_policies
     (code, name, max_gwa, min_units, max_failed, max_incomplete, max_dropped, deadline_at, timezone)
     VALUES (:code, :name, :max_gwa, :min_units, :max_failed, :max_incomplete, :max_dropped,
             :deadline_at, "Asia/Manila")
     ON DUPLICATE KEY UPDATE name = VALUES(name), max_gwa = VALUES(max_gwa),
         min_units = VALUES(min_units), max_failed = VALUES(max_failed),
         max_incomplete = VALUES(max_incomplete), max_dropped = VALUES(max_dropped),
         deadline_at = VALUES(deadline_at), timezone = VALUES(timezone)'
);
foreach ($policies as [$code, $name, $maxGwa, $minUnits, $maxFailed, $maxIncomplete, $maxDropped]) {
    $insertPolicy->execute([
        'code' => $code,
        'name' => $name,
        'max_gwa' => $maxGwa,
        'min_units' => $minUnits,
        'max_failed' => $maxFailed,
        'max_incomplete' => $maxIncomplete,
        'max_dropped' => $maxDropped,
        'deadline_at' => $deadline,
    ]);
}

$policyIds = [];
foreach ($pdo->query('SELECT id, code FROM program_policies') as $policy) {
    $policyIds[$policy['code']] = (int) $policy['id'];
}

$createUser = $pdo->prepare(
    'INSERT INTO users (role, full_name, student_id, email, password_hash)
     VALUES (:role, :full_name, :student_id, :email, :password_hash)'
);
$findUser = $pdo->prepare('SELECT id FROM users WHERE email = :email');
$createAssignment = $pdo->prepare(
    'INSERT INTO scholars (user_id, policy_id) VALUES (:user_id, :policy_id)
     ON DUPLICATE KEY UPDATE policy_id = VALUES(policy_id)'
);
$accounts = [
    ['staff', 'Demo Scholarship Staff', null, 'staff@school.test', 'StaffDemo!2026', null],
    ['scholar', 'Ava Santos', 'A01', 'a01@students.test', 'ScholarDemo!2026', 'MERIT'],
    ['scholar', 'Ben Cruz', 'A02', 'a02@students.test', 'ScholarDemo!2026', 'MERIT'],
    ['scholar', 'Cara Reyes', 'A03', 'a03@students.test', 'ScholarDemo!2026', 'COMMUNITY'],
    ['scholar', 'Diego Lim', 'A04', 'a04@students.test', 'ScholarDemo!2026', 'COMMUNITY'],
    ['scholar', 'Ella Garcia', 'A05', 'a05@students.test', 'ScholarDemo!2026', 'MERIT'],
    ['scholar', 'Finn Dela Cruz', 'A06', 'a06@students.test', 'ScholarDemo!2026', 'COMMUNITY'],
];

$userIds = [];
foreach ($accounts as [$role, $name, $studentId, $email, $password, $policyCode]) {
    $findUser->execute(['email' => $email]);
    $user = $findUser->fetch();
    if (!$user) {
        $createUser->execute([
            'role' => $role,
            'full_name' => $name,
            'student_id' => $studentId,
            'email' => $email,
            'password_hash' => password_hash($password, PASSWORD_DEFAULT),
        ]);
        $userId = (int) $pdo->lastInsertId();
    } else {
        $userId = (int) $user['id'];
    }
    $userIds[$studentId ?? 'staff'] = $userId;
    if ($role === 'scholar') {
        $createAssignment->execute([
            'user_id' => $userId,
            'policy_id' => $policyIds[$policyCode],
        ]);
    }
}

$records = [
    ['A01', 'MERIT', 1.75, 18, 0, 0, 0, '2026-10-30 17:00:00', 'verified'],
    ['A02', 'MERIT', 1.76, 18, 0, 0, 0, '2026-10-29 17:00:00', 'verified'],
    ['A03', 'COMMUNITY', 2.25, 15, 0, 0, 1, '2026-10-29 17:00:00', 'verified'],
    ['A04', 'COMMUNITY', 2.25, 14, 0, 1, 0, '2026-10-29 17:00:00', 'verified'],
    ['A05', 'MERIT', 1.50, 18, 1, 0, 0, '2026-10-30 17:01:00', 'verified'],
    ['A06', 'COMMUNITY', 2.00, 15, 0, 0, 0, '2026-10-29 17:00:00', 'pending'],
];

$insertSubmission = $pdo->prepare(
    'INSERT INTO submissions
     (scholar_id, academic_year, semester, gwa, enrolled_units, failed_subjects,
      incomplete_grades, dropped_subjects, grade_document_reference, submitted_at,
      verification_status, verified_by, verified_at)
     VALUES (:scholar_id, "2026-2027", 1, :gwa, :units, :failed, :incomplete,
             :dropped, :reference, :submitted_at, :verification_status, :verified_by, :verified_at)'
);
$findSubmission = $pdo->prepare(
    'SELECT id FROM submissions WHERE scholar_id = :scholar_id AND academic_year = "2026-2027" AND semester = 1'
);
$staffId = $userIds['staff'];
foreach ($records as [$studentId, $policyCode, $gwa, $units, $failed, $incomplete, $dropped, $submittedAt, $verification]) {
    $scholarId = $userIds[$studentId];
    $findSubmission->execute(['scholar_id' => $scholarId]);
    $existing = $findSubmission->fetch();
    if ($existing) {
        continue;
    }

    $insertSubmission->execute([
        'scholar_id' => $scholarId,
        'gwa' => $gwa,
        'units' => $units,
        'failed' => $failed,
        'incomplete' => $incomplete,
        'dropped' => $dropped,
        'reference' => 'seeded/' . strtolower($studentId) . '-2026-2027-sem1',
        'submitted_at' => $submittedAt,
        'verification_status' => $verification,
        'verified_by' => $verification === 'verified' ? $staffId : null,
        'verified_at' => $verification === 'verified' ? date('Y-m-d H:i:s') : null,
    ]);
    $submissionId = (int) $pdo->lastInsertId();

    if ($verification === 'verified') {
        $policy = [
            'max_gwa' => $policyCode === 'MERIT' ? 1.75 : 2.25,
            'min_units' => $policyCode === 'MERIT' ? 18 : 15,
            'max_failed' => 0,
            'max_incomplete' => 0,
            'max_dropped' => $policyCode === 'MERIT' ? 0 : 1,
            'deadline_at' => $deadline,
        ];
        $submission = [
            'verification_status' => 'verified',
            'gwa' => $gwa,
            'enrolled_units' => $units,
            'failed_subjects' => $failed,
            'incomplete_grades' => $incomplete,
            'dropped_subjects' => $dropped,
            'submitted_at' => $submittedAt,
        ];
        $result = evaluateCompliance($submission, $policy);
        $saveEvaluation = $pdo->prepare(
            'UPDATE submissions SET evaluation_status = :status, evaluation_reasons = :reasons,
                evaluated_by = :staff_id, evaluated_at = :evaluated_at WHERE id = :id'
        );
        $saveEvaluation->execute([
            'status' => $result['status'],
            'reasons' => json_encode($result['reasons'], JSON_THROW_ON_ERROR),
            'staff_id' => $staffId,
            'evaluated_at' => date('Y-m-d H:i:s'),
            'id' => $submissionId,
        ]);
        audit($pdo, $staffId, $submissionId, 'evidence_verified', ['reason' => 'Seeded verified evidence']);
        audit($pdo, $staffId, $submissionId, 'compliance_evaluated', $result);
    } else {
        audit($pdo, null, $submissionId, 'submission_created', ['source' => 'required test record']);
    }
}

echo "Seed complete. Demo accounts: staff@school.test and A01-A06 (shared demo passwords are in README.md).\n";
