<?php
declare(strict_types=1);

require_once __DIR__ . '/config.php';

function h(mixed $value): string
{
    return htmlspecialchars((string) $value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

function csrfToken(): string
{
    if (empty($_SESSION['csrf_token'])) {
        $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
    }

    return $_SESSION['csrf_token'];
}

function verifyCsrf(): void
{
    $token = $_POST['csrf_token'] ?? '';
    if (!is_string($token) || !hash_equals(csrfToken(), $token)) {
        throw new DomainException('Your session token expired. Refresh the page and try again.');
    }
}

function currentUser(): ?array
{
    return $_SESSION['user'] ?? null;
}

function requireUser(): array
{
    $user = currentUser();
    if ($user === null) {
        throw new DomainException('Please sign in to continue.');
    }

    return $user;
}

function requireRole(string $role): array
{
    $user = requireUser();
    if ($user['role'] !== $role) {
        http_response_code(403);
        throw new DomainException('You do not have permission to perform this action.');
    }

    return $user;
}

function flash(string $type, string $message): void
{
    $_SESSION['flash'][] = ['type' => $type, 'message' => $message];
}

function takeFlashMessages(): array
{
    $messages = $_SESSION['flash'] ?? [];
    unset($_SESSION['flash']);

    return $messages;
}

function audit(PDO $pdo, ?int $actorId, ?int $submissionId, string $event, array $details): void
{
    $statement = $pdo->prepare(
        'INSERT INTO audit_logs (actor_id, submission_id, event_type, details, created_at)
         VALUES (:actor_id, :submission_id, :event_type, :details, :created_at)'
    );
    $statement->execute([
        'actor_id' => $actorId,
        'submission_id' => $submissionId,
        'event_type' => $event,
        'details' => json_encode($details, JSON_THROW_ON_ERROR),
        'created_at' => date('Y-m-d H:i:s'),
    ]);
}

function loadSubmission(PDO $pdo, int $id): ?array
{
    $statement = $pdo->prepare(
        'SELECT s.*, u.full_name, u.student_id, u.email, p.code AS policy_code,
                p.name AS policy_name, p.max_gwa, p.min_units, p.max_failed,
                p.max_incomplete, p.max_dropped, p.deadline_at
         FROM submissions s
         JOIN users u ON u.id = s.scholar_id
         JOIN scholars sc ON sc.user_id = u.id
         JOIN program_policies p ON p.id = sc.policy_id
         WHERE s.id = :id'
    );
    $statement->execute(['id' => $id]);
    $submission = $statement->fetch();

    return $submission ?: null;
}

function assertOwnSubmission(array $actor, array $submission): void
{
    if ($actor['role'] === 'scholar' && (int) $actor['id'] !== (int) $submission['scholar_id']) {
        http_response_code(403);
        throw new DomainException('You may only access your own submissions.');
    }
}

function validateSummary(array $input): array
{
    $gwaInput = trim((string) ($input['gwa'] ?? ''));
    $gwa = filter_var($gwaInput, FILTER_VALIDATE_FLOAT);
    if ($gwa === false || $gwa < 1 || $gwa > 5) {
        throw new DomainException('GWA must be a number from 1.00 through 5.00.');
    }

    $summary = ['gwa' => number_format((float) $gwa, 2, '.', '')];
    foreach ([
        'enrolled_units' => 'Enrolled units',
        'failed_subjects' => 'Failed subjects',
        'incomplete_grades' => 'Incomplete grades',
        'dropped_subjects' => 'Dropped subjects',
    ] as $field => $label) {
        $value = filter_var($input[$field] ?? null, FILTER_VALIDATE_INT);
        if ($value === false || $value < 0) {
            throw new DomainException($label . ' must be a nonnegative whole number.');
        }
        $summary[$field] = $value;
    }

    $reference = trim((string) ($input['grade_document_reference'] ?? ''));
    if ($reference === '' || mb_strlen($reference) > 255) {
        throw new DomainException('Enter a grade-document reference (maximum 255 characters).');
    }
    $summary['grade_document_reference'] = $reference;

    $year = trim((string) ($input['academic_year'] ?? ''));
    if (!preg_match('/^\d{4}-\d{4}$/', $year)) {
        throw new DomainException('Academic year must use the format YYYY-YYYY.');
    }
    $parts = explode('-', $year);
    if ((int) $parts[1] !== (int) $parts[0] + 1) {
        throw new DomainException('The academic year must contain consecutive years.');
    }
    $summary['academic_year'] = $year;

    $semester = filter_var($input['semester'] ?? null, FILTER_VALIDATE_INT);
    if ($semester !== 1 && $semester !== 2) {
        throw new DomainException('Select semester 1 or 2.');
    }
    $summary['semester'] = $semester;

    return $summary;
}

function evaluateCompliance(array $submission, array $policy): array
{
    if ($submission['verification_status'] !== 'verified') {
        throw new DomainException('Only verified submissions can be evaluated.');
    }

    $reasons = [];
    if ((float) $submission['gwa'] > (float) $policy['max_gwa']) {
        $reasons[] = sprintf(
            'GWA %.2f exceeds the maximum permitted %.2f.',
            (float) $submission['gwa'],
            (float) $policy['max_gwa']
        );
    }
    if ((int) $submission['enrolled_units'] < (int) $policy['min_units']) {
        $reasons[] = sprintf(
            '%d enrolled units is below the required %d.',
            (int) $submission['enrolled_units'],
            (int) $policy['min_units']
        );
    }

    foreach ([
        'failed_subjects' => ['max_failed', 'failed subject(s)'],
        'incomplete_grades' => ['max_incomplete', 'incomplete grade(s)'],
        'dropped_subjects' => ['max_dropped', 'dropped subject(s)'],
    ] as $field => [$limitField, $label]) {
        if ((int) $submission[$field] > (int) $policy[$limitField]) {
            $reasons[] = sprintf(
                '%d %s exceeds the permitted maximum of %d.',
                (int) $submission[$field],
                $label,
                (int) $policy[$limitField]
            );
        }
    }

    $submittedAt = new DateTimeImmutable($submission['submitted_at'], new DateTimeZone('Asia/Manila'));
    $deadline = new DateTimeImmutable($policy['deadline_at'], new DateTimeZone('Asia/Manila'));
    if ($submittedAt > $deadline) {
        $reasons[] = 'Submission was received after the deadline.';
    }

    return [
        'status' => $reasons === [] ? 'compliant' : 'with_deficiency',
        'reasons' => $reasons,
    ];
}

function saveSubmission(PDO $pdo, array $actor, array $input): int
{
    $summary = validateSummary($input);
    $scholar = $pdo->prepare('SELECT policy_id FROM scholars WHERE user_id = :id');
    $scholar->execute(['id' => $actor['id']]);
    if (!$scholar->fetch()) {
        throw new DomainException('Your scholar profile has not been assigned a policy. Contact staff.');
    }

    $existingId = $input['submission_id'] ?? null;
    if ($existingId !== null && $existingId !== '') {
        $submission = loadSubmission($pdo, (int) $existingId);
        if (!$submission) {
            throw new DomainException('The returned submission could not be found.');
        }
        assertOwnSubmission($actor, $submission);
        if ($submission['verification_status'] !== 'returned') {
            throw new DomainException('Only returned submissions can be corrected and resubmitted.');
        }
        if ($summary['academic_year'] !== $submission['academic_year']
            || $summary['semester'] !== (int) $submission['semester']) {
            throw new DomainException('A correction must keep the original academic year and semester.');
        }

        $statement = $pdo->prepare(
            'UPDATE submissions
             SET academic_year = :academic_year, semester = :semester, gwa = :gwa,
                 enrolled_units = :enrolled_units, failed_subjects = :failed_subjects,
                 incomplete_grades = :incomplete_grades, dropped_subjects = :dropped_subjects,
                 grade_document_reference = :grade_document_reference, submitted_at = :submitted_at,
                 verification_status = "pending", verification_reason = NULL,
                 verified_by = NULL, verified_at = NULL, evaluation_status = NULL,
                 evaluation_reasons = NULL, evaluated_by = NULL, evaluated_at = NULL,
                 renewal_decision = NULL, renewal_note = NULL, decided_by = NULL, decided_at = NULL
             WHERE id = :id'
        );
        $statement->execute($summary + [
            'submitted_at' => date('Y-m-d H:i:s'),
            'id' => $submission['id'],
        ]);
        audit($pdo, (int) $actor['id'], (int) $submission['id'], 'submission_resubmitted', [
            'academic_year' => $summary['academic_year'],
            'semester' => $summary['semester'],
        ]);

        return (int) $submission['id'];
    }

    $duplicate = $pdo->prepare(
        'SELECT id FROM submissions
         WHERE scholar_id = :scholar_id AND academic_year = :academic_year AND semester = :semester'
    );
    $duplicate->execute([
        'scholar_id' => $actor['id'],
        'academic_year' => $summary['academic_year'],
        'semester' => $summary['semester'],
    ]);
    if ($duplicate->fetch()) {
        throw new DomainException('A submission already exists for this academic year and semester. Use the correction workflow if it was returned.');
    }

    $statement = $pdo->prepare(
        'INSERT INTO submissions
         (scholar_id, academic_year, semester, gwa, enrolled_units, failed_subjects,
          incomplete_grades, dropped_subjects, grade_document_reference, submitted_at)
         VALUES
         (:scholar_id, :academic_year, :semester, :gwa, :enrolled_units, :failed_subjects,
          :incomplete_grades, :dropped_subjects, :grade_document_reference, :submitted_at)'
    );
    $statement->execute($summary + [
        'scholar_id' => $actor['id'],
        'submitted_at' => date('Y-m-d H:i:s'),
    ]);
    $id = (int) $pdo->lastInsertId();
    audit($pdo, (int) $actor['id'], $id, 'submission_created', [
        'academic_year' => $summary['academic_year'],
        'semester' => $summary['semester'],
    ]);

    return $id;
}

function verifyEvidence(PDO $pdo, array $actor, int $id, string $decision, string $reason): void
{
    requireRole('staff');
    if (!in_array($decision, ['verified', 'returned'], true)) {
        throw new DomainException('Select Verified or Returned.');
    }
    if ($decision === 'returned' && trim($reason) === '') {
        throw new DomainException('A reason is required when returning evidence.');
    }

    $submission = loadSubmission($pdo, $id);
    if (!$submission || $submission['verification_status'] !== 'pending') {
        throw new DomainException('Only pending submissions can be verified or returned.');
    }
    $statement = $pdo->prepare(
        'UPDATE submissions
         SET verification_status = :status, verification_reason = :reason,
             verified_by = :actor_id, verified_at = :verified_at
         WHERE id = :id'
    );
    $statement->execute([
        'status' => $decision,
        'reason' => $decision === 'returned' ? trim($reason) : null,
        'actor_id' => $actor['id'],
        'verified_at' => date('Y-m-d H:i:s'),
        'id' => $id,
    ]);
    audit($pdo, (int) $actor['id'], $id, 'evidence_' . $decision, ['reason' => trim($reason)]);
}

function evaluateSubmission(PDO $pdo, array $actor, int $id): array
{
    requireRole('staff');
    $submission = loadSubmission($pdo, $id);
    if (!$submission) {
        throw new DomainException('Submission not found.');
    }
    $result = evaluateCompliance($submission, $submission);
    $statement = $pdo->prepare(
        'UPDATE submissions
         SET evaluation_status = :status, evaluation_reasons = :reasons,
             evaluated_by = :actor_id, evaluated_at = :evaluated_at
         WHERE id = :id'
    );
    $statement->execute([
        'status' => $result['status'],
        'reasons' => json_encode($result['reasons'], JSON_THROW_ON_ERROR),
        'actor_id' => $actor['id'],
        'evaluated_at' => date('Y-m-d H:i:s'),
        'id' => $id,
    ]);
    audit($pdo, (int) $actor['id'], $id, 'compliance_evaluated', $result);

    return $result;
}

function decideRenewal(PDO $pdo, array $actor, int $id, string $decision, string $note): void
{
    requireRole('staff');
    if (!in_array($decision, ['approved', 'held'], true)) {
        throw new DomainException('Select Approved or Held.');
    }
    $submission = loadSubmission($pdo, $id);
    if (!$submission || $submission['evaluation_status'] === null) {
        throw new DomainException('Evaluate the submission before recording a renewal decision.');
    }
    if ($decision === 'approved' && $submission['evaluation_status'] !== 'compliant') {
        throw new DomainException('A deficient submission cannot be approved.');
    }
    if (trim($note) === '') {
        throw new DomainException('Add a decision note before saving the renewal decision.');
    }

    $statement = $pdo->prepare(
        'UPDATE submissions
         SET renewal_decision = :decision, renewal_note = :note,
             decided_by = :actor_id, decided_at = :decided_at
         WHERE id = :id'
    );
    $statement->execute([
        'decision' => $decision,
        'note' => trim($note),
        'actor_id' => $actor['id'],
        'decided_at' => date('Y-m-d H:i:s'),
        'id' => $id,
    ]);
    audit($pdo, (int) $actor['id'], $id, 'renewal_' . $decision, ['note' => trim($note)]);
}

function registerScholar(PDO $pdo, array $actor, array $input): void
{
    requireRole('staff');
    $name = trim((string) ($input['full_name'] ?? ''));
    $studentId = trim((string) ($input['student_id'] ?? ''));
    $email = trim((string) ($input['email'] ?? ''));
    $password = (string) ($input['password'] ?? '');
    $policyId = filter_var($input['policy_id'] ?? null, FILTER_VALIDATE_INT);
    if ($name === '' || mb_strlen($name) > 150) {
        throw new DomainException('Enter a scholar name (maximum 150 characters).');
    }
    if ($studentId === '' || mb_strlen($studentId) > 50) {
        throw new DomainException('Enter a student ID (maximum 50 characters).');
    }
    if (!filter_var($email, FILTER_VALIDATE_EMAIL) || mb_strlen($email) > 190) {
        throw new DomainException('Enter a valid email address.');
    }
    if (strlen($password) < 10) {
        throw new DomainException('The temporary password must contain at least 10 characters.');
    }
    if ($policyId === false) {
        throw new DomainException('Choose a program policy.');
    }

    $ownsTransaction = !$pdo->inTransaction();
    if ($ownsTransaction) {
        $pdo->beginTransaction();
    }
    try {
        $policy = $pdo->prepare('SELECT id FROM program_policies WHERE id = :id');
        $policy->execute(['id' => $policyId]);
        if (!$policy->fetch()) {
            throw new DomainException('The selected policy does not exist.');
        }
        $insertUser = $pdo->prepare(
            'INSERT INTO users (role, full_name, student_id, email, password_hash, is_active)
             VALUES ("scholar", :full_name, :student_id, :email, :password_hash, 1)'
        );
        $insertUser->execute([
            'full_name' => $name,
            'student_id' => $studentId,
            'email' => $email,
            'password_hash' => password_hash($password, PASSWORD_DEFAULT),
        ]);
        $userId = (int) $pdo->lastInsertId();
        $assignment = $pdo->prepare('INSERT INTO scholars (user_id, policy_id) VALUES (:user_id, :policy_id)');
        $assignment->execute(['user_id' => $userId, 'policy_id' => $policyId]);
        audit($pdo, (int) $actor['id'], null, 'scholar_registered', [
            'scholar_id' => $userId,
            'student_id' => $studentId,
            'policy_id' => $policyId,
        ]);
        if ($ownsTransaction) {
            $pdo->commit();
        }
    } catch (Throwable $error) {
        if ($ownsTransaction && $pdo->inTransaction()) {
            $pdo->rollBack();
        }
        if ($error instanceof PDOException && $error->getCode() === '23000') {
            throw new DomainException('That student ID or email is already registered.');
        }
        throw $error;
    }
}
