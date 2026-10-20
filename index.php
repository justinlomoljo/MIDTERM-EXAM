<?php
declare(strict_types=1);

require_once __DIR__ . '/domain.php';

$errorMessage = null;
$pdo = null;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    try {
        verifyCsrf();
        $action = (string) ($_POST['action'] ?? '');

        if ($action === 'login') {
            $identity = trim((string) ($_POST['identity'] ?? ''));
            $password = (string) ($_POST['password'] ?? '');
            $statement = db()->prepare(
                'SELECT id, role, full_name, student_id, email, password_hash
                 FROM users
                 WHERE is_active = 1 AND (email = :email OR student_id = :student_id)
                 LIMIT 1'
            );
            $statement->execute(['email' => $identity, 'student_id' => $identity]);
            $record = $statement->fetch();
            if (!$record || !password_verify($password, $record['password_hash'])) {
                throw new DomainException('Invalid email/student ID or password.');
            }
            session_regenerate_id(true);
            $_SESSION['user'] = [
                'id' => (int) $record['id'],
                'role' => $record['role'],
                'full_name' => $record['full_name'],
                'student_id' => $record['student_id'],
                'email' => $record['email'],
            ];
            flash('success', 'Welcome, ' . $record['full_name'] . '.');
            header('Location: index.php');
            exit;
        }

        if ($action === 'logout') {
            requireUser();
            $_SESSION = [];
            session_regenerate_id(true);
            flash('success', 'You have signed out.');
            header('Location: index.php');
            exit;
        }

        $actor = requireUser();
        $pdo = db();
        if ($action === 'submit') {
            requireRole('scholar');
            $pdo->beginTransaction();
            try {
                saveSubmission($pdo, $actor, $_POST);
                $pdo->commit();
            } catch (Throwable $error) {
                if ($pdo->inTransaction()) {
                    $pdo->rollBack();
                }
                throw $error;
            }
            flash('success', 'Your grade summary was submitted and is pending evidence verification.');
        } elseif ($action === 'verify') {
            $pdo->beginTransaction();
            try {
                verifyEvidence(
                    $pdo,
                    $actor,
                    (int) ($_POST['submission_id'] ?? 0),
                    (string) ($_POST['decision'] ?? ''),
                    (string) ($_POST['reason'] ?? '')
                );
                $pdo->commit();
            } catch (Throwable $error) {
                if ($pdo->inTransaction()) {
                    $pdo->rollBack();
                }
                throw $error;
            }
            flash('success', 'Evidence verification was recorded.');
        } elseif ($action === 'evaluate') {
            $pdo->beginTransaction();
            try {
                $result = evaluateSubmission($pdo, $actor, (int) ($_POST['submission_id'] ?? 0));
                $pdo->commit();
            } catch (Throwable $error) {
                if ($pdo->inTransaction()) {
                    $pdo->rollBack();
                }
                throw $error;
            }
            flash(
                $result['status'] === 'compliant' ? 'success' : 'warning',
                $result['status'] === 'compliant'
                    ? 'Evaluation complete: Compliant.'
                    : 'Evaluation complete: With Deficiency.'
            );
        } elseif ($action === 'renewal') {
            $pdo->beginTransaction();
            try {
                decideRenewal(
                    $pdo,
                    $actor,
                    (int) ($_POST['submission_id'] ?? 0),
                    (string) ($_POST['decision'] ?? ''),
                    (string) ($_POST['note'] ?? '')
                );
                $pdo->commit();
            } catch (Throwable $error) {
                if ($pdo->inTransaction()) {
                    $pdo->rollBack();
                }
                throw $error;
            }
            flash('success', 'Renewal decision and note were saved.');
        } elseif ($action === 'register') {
            registerScholar($pdo, $actor, $_POST);
            flash('success', 'Scholar registered and assigned to the selected policy.');
        } else {
            throw new DomainException('Unknown action.');
        }

        header('Location: index.php');
        exit;
    } catch (DomainException $error) {
        $errorMessage = $error->getMessage();
    } catch (PDOException $error) {
        error_log('Scholarship system database error: ' . $error->getMessage());
        $errorMessage = 'The request could not be saved because of a database error. Check that the schema is installed and try again.';
    }
}

$user = currentUser();
$flashes = takeFlashMessages();
$policies = [];
$submissions = [];
$historyBySubmission = [];
$selectedYear = (string) ($_GET['academic_year'] ?? '2026-2027');
$selectedSemester = (int) ($_GET['semester'] ?? 1);
$complianceFilter = '';
if (!preg_match('/^\d{4}-\d{4}$/', $selectedYear)) {
    $selectedYear = '2026-2027';
}
if (!in_array($selectedSemester, [1, 2], true)) {
    $selectedSemester = 1;
}

if ($user !== null) {
    try {
        $pdo = db();
        if ($user['role'] === 'staff') {
            $policies = $pdo->query('SELECT id, code, name, max_gwa, min_units FROM program_policies ORDER BY name')->fetchAll();
            $search = trim((string) ($_GET['q'] ?? ''));
            $programFilter = trim((string) ($_GET['program'] ?? ''));
            $complianceFilter = trim((string) ($_GET['compliance'] ?? ''));
            $where = ['s.academic_year = :academic_year', 's.semester = :semester'];
            $parameters = ['academic_year' => $selectedYear, 'semester' => $selectedSemester];
            if ($search !== '') {
                $where[] = '(u.full_name LIKE :search_name OR u.student_id LIKE :search_id)';
                $parameters['search_name'] = '%' . $search . '%';
                $parameters['search_id'] = '%' . $search . '%';
            }
            if ($programFilter !== '') {
                $where[] = 'p.code = :program';
                $parameters['program'] = $programFilter;
            }
            if (in_array($complianceFilter, ['pending', 'compliant', 'with_deficiency', 'not_evaluated'], true)) {
                if ($complianceFilter === 'not_evaluated') {
                    $where[] = 's.evaluation_status IS NULL';
                } elseif ($complianceFilter === 'pending') {
                    $where[] = 's.verification_status = "pending"';
                } else {
                    $where[] = 's.evaluation_status = :compliance';
                    $parameters['compliance'] = $complianceFilter;
                }
            } else {
                $complianceFilter = '';
            }
            $list = $pdo->prepare(
                'SELECT s.*, u.full_name, u.student_id, p.code AS policy_code, p.name AS policy_name,
                        p.max_gwa, p.min_units, p.max_failed, p.max_incomplete, p.max_dropped,
                        p.deadline_at
                 FROM submissions s
                 JOIN users u ON u.id = s.scholar_id
                 JOIN scholars sc ON sc.user_id = u.id
                 JOIN program_policies p ON p.id = sc.policy_id
                 WHERE ' . implode(' AND ', $where) . '
                 ORDER BY u.student_id, u.full_name'
            );
            $list->execute($parameters);
            $submissions = $list->fetchAll();
            if ($submissions !== []) {
                $ids = array_map(static fn(array $row): int => (int) $row['id'], $submissions);
                $placeholders = implode(',', array_fill(0, count($ids), '?'));
                $logs = $pdo->prepare(
                    'SELECT a.*, u.full_name AS actor_name
                     FROM audit_logs a LEFT JOIN users u ON u.id = a.actor_id
                     WHERE a.submission_id IN (' . $placeholders . ')
                     ORDER BY a.created_at DESC, a.id DESC'
                );
                $logs->execute($ids);
                foreach ($logs->fetchAll() as $log) {
                    $historyBySubmission[(int) $log['submission_id']][] = $log;
                }
            }

            $metricsStatement = $pdo->prepare(
                'SELECT
                    (SELECT COUNT(*) FROM scholars) AS assigned,
                    COUNT(CASE WHEN s.verification_status = "pending" THEN 1 END) AS pending,
                    COUNT(CASE WHEN s.evaluation_status = "compliant" THEN 1 END) AS compliant,
                    COUNT(CASE WHEN s.evaluation_status = "with_deficiency" THEN 1 END) AS deficient
                 FROM submissions s
                 WHERE s.academic_year = :academic_year AND s.semester = :semester'
            );
            $metricsStatement->execute(['academic_year' => $selectedYear, 'semester' => $selectedSemester]);
            $metrics = $metricsStatement->fetch();
        } else {
            $profileStatement = $pdo->prepare(
                'SELECT p.name AS policy_name, p.code AS policy_code, p.max_gwa, p.min_units,
                        p.max_failed, p.max_incomplete, p.max_dropped, p.deadline_at
                 FROM scholars sc JOIN program_policies p ON p.id = sc.policy_id
                 WHERE sc.user_id = :user_id'
            );
            $profileStatement->execute(['user_id' => $user['id']]);
            $scholarProfile = $profileStatement->fetch();
            $own = $pdo->prepare(
                'SELECT * FROM submissions
                 WHERE scholar_id = :scholar_id
                 ORDER BY academic_year DESC, semester DESC, submitted_at DESC'
            );
            $own->execute(['scholar_id' => $user['id']]);
            $submissions = $own->fetchAll();
            if ($submissions !== []) {
                $ids = array_map(static fn(array $row): int => (int) $row['id'], $submissions);
                $placeholders = implode(',', array_fill(0, count($ids), '?'));
                $logs = $pdo->prepare(
                    'SELECT a.*, u.full_name AS actor_name
                     FROM audit_logs a LEFT JOIN users u ON u.id = a.actor_id
                     WHERE a.submission_id IN (' . $placeholders . ')
                     ORDER BY a.created_at DESC, a.id DESC'
                );
                $logs->execute($ids);
                foreach ($logs->fetchAll() as $log) {
                    $historyBySubmission[(int) $log['submission_id']][] = $log;
                }
            }
        }
    } catch (PDOException $error) {
        error_log('Scholarship system page load error: ' . $error->getMessage());
        $errorMessage = 'Unable to load system data. Check the database connection and schema.';
    }
}

function statusLabel(?string $status): string
{
    if ($status === null || $status === '') {
        return 'Not evaluated';
    }
    return ucwords(str_replace('_', ' ', $status));
}

function statusClass(?string $status): string
{
    return match ($status) {
        'verified', 'compliant', 'approved' => 'good',
        'returned', 'with_deficiency', 'held' => 'bad',
        default => 'pending',
    };
}

function displayDate(?string $date): string
{
    if ($date === null || $date === '') {
        return '—';
    }
    return (new DateTimeImmutable($date, new DateTimeZone('Asia/Manila')))->format('M j, Y g:i A');
}

function renderSummaryFields(array $values = []): void
{
    ?>
    <div class="form-grid">
        <label>Academic year
            <input name="academic_year" value="<?= h($values['academic_year'] ?? '2026-2027') ?>" required pattern="\d{4}-\d{4}" placeholder="2026-2027">
        </label>
        <label>Semester
            <select name="semester" required>
                <option value="1" <?= (int) ($values['semester'] ?? 1) === 1 ? 'selected' : '' ?>>Semester 1</option>
                <option value="2" <?= (int) ($values['semester'] ?? 1) === 2 ? 'selected' : '' ?>>Semester 2</option>
            </select>
        </label>
        <label>GWA (1.00–5.00)
            <input name="gwa" type="number" min="1" max="5" step="0.01" value="<?= h($values['gwa'] ?? '') ?>" required>
        </label>
        <label>Enrolled units
            <input name="enrolled_units" type="number" min="0" step="1" value="<?= h($values['enrolled_units'] ?? '') ?>" required>
        </label>
        <label>Failed subjects
            <input name="failed_subjects" type="number" min="0" step="1" value="<?= h($values['failed_subjects'] ?? 0) ?>" required>
        </label>
        <label>Incomplete grades
            <input name="incomplete_grades" type="number" min="0" step="1" value="<?= h($values['incomplete_grades'] ?? 0) ?>" required>
        </label>
        <label>Dropped subjects
            <input name="dropped_subjects" type="number" min="0" step="1" value="<?= h($values['dropped_subjects'] ?? 0) ?>" required>
        </label>
        <label class="wide">Grade-document reference
            <input name="grade_document_reference" maxlength="255" value="<?= h($values['grade_document_reference'] ?? '') ?>" placeholder="e.g. registrar receipt/reference number" required>
        </label>
    </div>
    <?php
}
?>
<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Scholarship Monitor</title>
    <link rel="stylesheet" href="assets/style.css">
</head>
<body>
<header class="topbar">
    <a class="brand" href="index.php"><span class="brand-mark">S</span><span>Scholarship<span class="brand-light">Monitor</span></span></a>
    <?php if ($user !== null): ?>
        <div class="account">
            <div><strong><?= h($user['full_name']) ?></strong><span><?= $user['role'] === 'staff' ? 'Scholarship staff' : 'Scholar · ' . h($user['student_id']) ?></span></div>
            <form method="post">
                <input type="hidden" name="csrf_token" value="<?= h(csrfToken()) ?>">
                <input type="hidden" name="action" value="logout">
                <button class="button button-quiet" type="submit">Sign out</button>
            </form>
        </div>
    <?php else: ?>
        <span class="topbar-note">Academic compliance workspace</span>
    <?php endif; ?>
</header>

<main class="container">
    <section class="hero">
        <div>
            <p class="eyebrow">STUDENT SCHOLARSHIP MONITORING</p>
            <h1>Academic compliance, made clear.</h1>
            <p class="hero-copy">A secure workspace for grade submissions, evidence verification, program-based evaluation, and renewal decisions.</p>
        </div>
        <?php if ($user !== null): ?>
            <div class="deadline-pill"><span class="live-dot"></span> Deadline · Oct 30, 2026 · 5:00 PM Manila</div>
        <?php endif; ?>
    </section>

    <?php if ($errorMessage !== null): ?>
        <div class="notice error" role="alert"><?= h($errorMessage) ?></div>
    <?php endif; ?>
    <?php foreach ($flashes as $message): ?>
        <div class="notice <?= h($message['type']) ?>" role="status"><?= h($message['message']) ?></div>
    <?php endforeach; ?>

    <?php if ($user === null): ?>
        <section class="login-layout">
            <div class="login-intro">
                <p class="eyebrow">ONE PLACE, TWO ROLES</p>
                <h2>Welcome back.</h2>
                <p>Scholars can submit and correct their own academic summaries. Staff can verify records, review compliance, and record renewal decisions.</p>
                <div class="intro-points">
                    <span><i>01</i> Private scholar records</span>
                    <span><i>02</i> Auditable staff decisions</span>
                    <span><i>03</i> Policy-based checks</span>
                </div>
            </div>
            <form class="panel login-card" method="post">
                <div class="panel-heading"><div><p class="eyebrow">SIGN IN</p><h2>Access your account</h2></div><span class="lock-icon">↗</span></div>
                <input type="hidden" name="csrf_token" value="<?= h(csrfToken()) ?>">
                <input type="hidden" name="action" value="login">
                <label>Email or student ID
                    <input name="identity" autocomplete="username" required autofocus placeholder="you@school.edu">
                </label>
                <label>Password
                    <input name="password" type="password" autocomplete="current-password" required>
                </label>
                <button class="button button-primary button-full" type="submit">Sign in <span>→</span></button>
                <p class="fine-print">Your role controls which records and actions are available.</p>
            </form>
        </section>
    <?php elseif ($user['role'] === 'staff'): ?>
        <section class="section-heading">
            <div><p class="eyebrow">STAFF WORKSPACE</p><h2>Overview &amp; review queue</h2><p>Monitor the selected semester and process submitted academic records.</p></div>
            <form class="semester-picker" method="get">
                <label>Academic year<input name="academic_year" value="<?= h($selectedYear) ?>" pattern="\d{4}-\d{4}" required></label>
                <label>Semester<select name="semester"><option value="1" <?= $selectedSemester === 1 ? 'selected' : '' ?>>Semester 1</option><option value="2" <?= $selectedSemester === 2 ? 'selected' : '' ?>>Semester 2</option></select></label>
                <button class="button button-secondary" type="submit">View</button>
            </form>
        </section>
        <section class="metrics-grid">
            <?php foreach ([
                ['Assigned scholars', $metrics['assigned'] ?? 0, 'Profiles with a persisted program policy', 'metric-indigo'],
                ['Pending verification', $metrics['pending'] ?? 0, 'Evidence waiting for staff review', 'metric-amber'],
                ['Compliant', $metrics['compliant'] ?? 0, 'Evaluated and meeting policy', 'metric-green'],
                ['With deficiency', $metrics['deficient'] ?? 0, 'Evaluated with one or more issues', 'metric-rose'],
            ] as [$title, $value, $caption, $class]): ?>
                <article class="metric-card <?= h($class) ?>"><div class="metric-top"><span><?= h($title) ?></span><span class="metric-glyph"></span></div><strong><?= h($value) ?></strong><small><?= h($caption) ?></small></article>
            <?php endforeach; ?>
        </section>

        <section class="panel register-panel">
            <div class="panel-heading"><div><p class="eyebrow">SCHOLAR MANAGEMENT</p><h3>Register a scholar</h3></div><span class="panel-hint">Assign one saved program policy</span></div>
            <form method="post" class="register-form">
                <input type="hidden" name="csrf_token" value="<?= h(csrfToken()) ?>"><input type="hidden" name="action" value="register">
                <label>Full name<input name="full_name" maxlength="150" required></label>
                <label>Student ID<input name="student_id" maxlength="50" required></label>
                <label>Email<input name="email" type="email" maxlength="190" required></label>
                <label>Temporary password<input name="password" type="password" minlength="10" required></label>
                <label>Program policy<select name="policy_id" required><option value="">Choose policy</option><?php foreach ($policies as $policy): ?><option value="<?= h($policy['id']) ?>"><?= h($policy['name']) ?> · GWA ≤ <?= h($policy['max_gwa']) ?> · <?= h($policy['min_units']) ?> units</option><?php endforeach; ?></select></label>
                <button class="button button-primary" type="submit">Register scholar</button>
            </form>
        </section>

        <section class="panel table-panel">
            <div class="panel-heading table-heading"><div><p class="eyebrow">SUBMISSIONS</p><h3><?= h($selectedYear) ?> · Semester <?= h($selectedSemester) ?></h3></div><span class="result-count"><?= count($submissions) ?> records</span></div>
            <form method="get" class="filter-bar">
                <input type="hidden" name="academic_year" value="<?= h($selectedYear) ?>"><input type="hidden" name="semester" value="<?= h($selectedSemester) ?>">
                <label class="search-box"><span>⌕</span><input name="q" value="<?= h($_GET['q'] ?? '') ?>" placeholder="Search student ID or name"></label>
                <select name="program"><option value="">All programs</option><?php foreach ($policies as $policy): ?><option value="<?= h($policy['code']) ?>" <?= ($_GET['program'] ?? '') === $policy['code'] ? 'selected' : '' ?>><?= h($policy['name']) ?></option><?php endforeach; ?></select>
                <select name="compliance"><option value="">All statuses</option><option value="pending" <?= $complianceFilter === 'pending' ? 'selected' : '' ?>>Pending verification</option><option value="not_evaluated" <?= $complianceFilter === 'not_evaluated' ? 'selected' : '' ?>>Not evaluated</option><option value="compliant" <?= $complianceFilter === 'compliant' ? 'selected' : '' ?>>Compliant</option><option value="with_deficiency" <?= $complianceFilter === 'with_deficiency' ? 'selected' : '' ?>>With deficiency</option></select>
                <button class="button button-secondary" type="submit">Filter</button>
            </form>
            <div class="table-scroll"><table>
                <thead><tr><th>Scholar</th><th>Program</th><th>GWA</th><th>Evidence</th><th>Compliance</th><th>Renewal</th></tr></thead>
                <tbody>
                <?php if ($submissions === []): ?><tr><td colspan="6" class="empty-state">No submissions match this semester and filter.</td></tr><?php endif; ?>
                <?php foreach ($submissions as $submission):
                    $reasons = $submission['evaluation_reasons'] ? json_decode($submission['evaluation_reasons'], true) : [];
                    ?>
                    <tr>
                        <td><strong><?= h($submission['full_name']) ?></strong><small class="cell-sub"><?= h($submission['student_id']) ?> · AY <?= h($submission['academic_year']) ?> S<?= h($submission['semester']) ?></small></td>
                        <td><?= h($submission['policy_name']) ?></td>
                        <td><strong><?= h(number_format((float) $submission['gwa'], 2)) ?></strong><small class="cell-sub"><?= h($submission['enrolled_units']) ?> units</small></td>
                        <td><span class="badge <?= h(statusClass($submission['verification_status'])) ?>"><?= h(statusLabel($submission['verification_status'])) ?></span>
                            <small class="cell-sub">Submitted <?= h(displayDate($submission['submitted_at'])) ?></small>
                            <?php if ($submission['verification_reason']): ?><small class="reason"><?= h($submission['verification_reason']) ?></small><?php endif; ?>
                        </td>
                        <td><span class="badge <?= h(statusClass($submission['evaluation_status'])) ?>"><?= h(statusLabel($submission['evaluation_status'])) ?></span>
                            <?php if ($reasons): ?><ul class="reason-list"><?php foreach ($reasons as $reason): ?><li><?= h($reason) ?></li><?php endforeach; ?></ul><?php endif; ?>
                        </td>
                        <td><span class="badge <?= h(statusClass($submission['renewal_decision'])) ?>"><?= h(statusLabel($submission['renewal_decision'])) ?></span>
                            <?php if ($submission['renewal_note']): ?><small class="cell-sub"><?= h($submission['renewal_note']) ?></small><?php endif; ?>
                        </td>
                    </tr>
                    <tr class="action-row"><td colspan="6">
                        <details>
                            <summary>Review record · <?= h($submission['student_id']) ?></summary>
                            <div class="detail-grid">
                                <div><span>Academic summary</span><strong><?= h($submission['gwa']) ?> GWA · <?= h($submission['enrolled_units']) ?> units · <?= h($submission['failed_subjects']) ?> failed · <?= h($submission['incomplete_grades']) ?> incomplete · <?= h($submission['dropped_subjects']) ?> dropped</strong></div>
                                <div><span>Grade-document reference</span><strong><?= h($submission['grade_document_reference']) ?></strong></div>
                                <div><span>Policy checks</span><strong>GWA ≤ <?= h($submission['max_gwa']) ?> · Units ≥ <?= h($submission['min_units']) ?> · Failed ≤ <?= h($submission['max_failed']) ?> · INC ≤ <?= h($submission['max_incomplete']) ?> · Dropped ≤ <?= h($submission['max_dropped']) ?></strong></div>
                                <div><span>Deadline</span><strong><?= h(displayDate($submission['deadline_at'])) ?> · Asia/Manila</strong></div>
                            </div>
                            <div class="action-forms">
                                <?php if ($submission['verification_status'] === 'pending'): ?>
                                    <form method="post" class="mini-form">
                                        <input type="hidden" name="csrf_token" value="<?= h(csrfToken()) ?>"><input type="hidden" name="action" value="verify"><input type="hidden" name="submission_id" value="<?= h($submission['id']) ?>">
                                        <label>Evidence decision<select name="decision"><option value="verified">Verified</option><option value="returned">Returned for correction</option></select></label>
                                        <label>Reason (required for Returned)<input name="reason" maxlength="1000" placeholder="Explain what needs correction"></label>
                                        <button class="button button-primary" type="submit">Save verification</button>
                                    </form>
                                <?php elseif ($submission['verification_status'] === 'verified' && $submission['evaluation_status'] === null): ?>
                                    <form method="post"><input type="hidden" name="csrf_token" value="<?= h(csrfToken()) ?>"><input type="hidden" name="action" value="evaluate"><input type="hidden" name="submission_id" value="<?= h($submission['id']) ?>"><button class="button button-primary" type="submit">Evaluate compliance</button></form>
                                <?php endif; ?>
                                <?php if ($submission['evaluation_status'] !== null): ?>
                                    <form method="post" class="mini-form">
                                        <input type="hidden" name="csrf_token" value="<?= h(csrfToken()) ?>"><input type="hidden" name="action" value="renewal"><input type="hidden" name="submission_id" value="<?= h($submission['id']) ?>">
                                        <label>Renewal decision<select name="decision"><option value="held">Held</option><?php if ($submission['evaluation_status'] === 'compliant'): ?><option value="approved">Approved</option><?php endif; ?></select></label>
                                        <label>Decision note<input name="note" maxlength="1000" required placeholder="Record the reason or decision note"></label>
                                        <button class="button button-secondary" type="submit">Save decision</button>
                                    </form>
                                <?php endif; ?>
                            </div>
                            <div class="history"><strong>Decision history</strong>
                                <?php foreach ($historyBySubmission[(int) $submission['id']] ?? [] as $log):
                                    $details = json_decode($log['details'], true) ?: [];
                                    ?>
                                    <div class="history-item"><span><?= h(str_replace('_', ' ', ucfirst($log['event_type']))) ?></span><span><?= h($log['actor_name'] ?? 'System') ?> · <?= h(displayDate($log['created_at'])) ?></span>
                                        <?php if (!empty($details['reasons'])): ?><ul class="reason-list"><?php foreach ($details['reasons'] as $reason): ?><li><?= h($reason) ?></li><?php endforeach; ?></ul><?php elseif (!empty($details['note'])): ?><small><?= h($details['note']) ?></small><?php elseif (!empty($details['reason'])): ?><small><?= h($details['reason']) ?></small><?php endif; ?>
                                    </div>
                                <?php endforeach; ?>
                            </div>
                        </details>
                    </td></tr>
                <?php endforeach; ?>
                </tbody>
            </table></div>
        </section>
    <?php else: ?>
        <section class="section-heading">
            <div><p class="eyebrow">SCHOLAR WORKSPACE</p><h2>Your academic submissions</h2><p>Submit a semester summary and follow its evidence and renewal status.</p></div>
            <?php if (!empty($scholarProfile)): ?><div class="policy-chip"><span class="live-dot"></span><?= h($scholarProfile['policy_name']) ?> policy</div><?php endif; ?>
        </section>
        <?php if (empty($scholarProfile)): ?>
            <div class="notice error">Your scholar profile has no assigned policy. Please contact staff before submitting.</div>
        <?php else: ?>
            <section class="policy-summary">
                <div><span>Maximum GWA</span><strong><?= h($scholarProfile['max_gwa']) ?></strong></div>
                <div><span>Minimum enrolled units</span><strong><?= h($scholarProfile['min_units']) ?></strong></div>
                <div><span>Allowed failed / incomplete</span><strong><?= h($scholarProfile['max_failed']) ?> / <?= h($scholarProfile['max_incomplete']) ?></strong></div>
                <div><span>Allowed dropped</span><strong><?= h($scholarProfile['max_dropped']) ?></strong></div>
                <div><span>Submission deadline</span><strong><?= h(displayDate($scholarProfile['deadline_at'])) ?></strong></div>
            </section>
            <section class="panel submit-panel">
                <div class="panel-heading"><div><p class="eyebrow">GRADE SUBMISSION</p><h3>Submit your academic summary</h3></div><span class="panel-hint">One submission per academic year and semester</span></div>
                <form method="post">
                    <input type="hidden" name="csrf_token" value="<?= h(csrfToken()) ?>"><input type="hidden" name="action" value="submit">
                    <?php renderSummaryFields(); ?>
                    <button class="button button-primary" type="submit">Submit grade summary <span>→</span></button>
                </form>
            </section>
            <section class="submissions-list">
                <div class="list-heading"><div><p class="eyebrow">YOUR RECORDS</p><h3>Submission history</h3></div><span><?= count($submissions) ?> records</span></div>
                <?php if ($submissions === []): ?><div class="panel empty-state">No submissions yet. Your records will appear here after submission.</div><?php endif; ?>
                <?php foreach ($submissions as $submission):
                    $reasons = $submission['evaluation_reasons'] ? json_decode($submission['evaluation_reasons'], true) : [];
                    ?>
                    <article class="panel scholar-record">
                        <div class="record-top"><div><p class="eyebrow">AY <?= h($submission['academic_year']) ?> · SEMESTER <?= h($submission['semester']) ?></p><h3><?= h(number_format((float) $submission['gwa'], 2)) ?> GWA <span>· <?= h($submission['enrolled_units']) ?> units</span></h3></div>
                            <div class="badge-stack"><span class="badge <?= h(statusClass($submission['verification_status'])) ?>"><?= h(statusLabel($submission['verification_status'])) ?></span><span class="badge <?= h(statusClass($submission['evaluation_status'])) ?>"><?= h(statusLabel($submission['evaluation_status'])) ?></span><span class="badge <?= h(statusClass($submission['renewal_decision'])) ?>"><?= h(statusLabel($submission['renewal_decision'])) ?></span></div>
                        </div>
                        <p class="record-meta">Submitted <?= h(displayDate($submission['submitted_at'])) ?> · Ref <?= h($submission['grade_document_reference']) ?></p>
                        <?php if ($submission['verification_reason']): ?><div class="notice warning"><?= h($submission['verification_reason']) ?></div><?php endif; ?>
                        <?php if ($reasons): ?><ul class="reason-list"><?php foreach ($reasons as $reason): ?><li><?= h($reason) ?></li><?php endforeach; ?></ul><?php endif; ?>
                        <?php if ($submission['verification_status'] === 'returned'): ?>
                            <details class="correction">
                                <summary>Correct and resubmit</summary>
                                <form method="post">
                                    <input type="hidden" name="csrf_token" value="<?= h(csrfToken()) ?>"><input type="hidden" name="action" value="submit"><input type="hidden" name="submission_id" value="<?= h($submission['id']) ?>">
                                    <?php renderSummaryFields($submission); ?>
                                    <button class="button button-primary" type="submit">Resubmit for verification</button>
                                </form>
                            </details>
                        <?php endif; ?>
                        <details class="history scholar-history"><summary>View decision history</summary>
                            <?php foreach ($historyBySubmission[(int) $submission['id']] ?? [] as $log):
                                $details = json_decode($log['details'], true) ?: [];
                                ?>
                                <div class="history-item"><span><?= h(str_replace('_', ' ', ucfirst($log['event_type']))) ?></span><span><?= h($log['actor_name'] ?? 'System') ?> · <?= h(displayDate($log['created_at'])) ?></span>
                                    <?php if (!empty($details['reasons'])): ?><ul class="reason-list"><?php foreach ($details['reasons'] as $reason): ?><li><?= h($reason) ?></li><?php endforeach; ?></ul><?php elseif (!empty($details['note'])): ?><small><?= h($details['note']) ?></small><?php elseif (!empty($details['reason'])): ?><small><?= h($details['reason']) ?></small><?php endif; ?>
                                </div>
                            <?php endforeach; ?>
                        </details>
                    </article>
                <?php endforeach; ?>
            </section>
        <?php endif; ?>
    <?php endif; ?>
    <footer><span>Scholarship Monitor</span><span>Academic year 2026–2027 · Asia/Manila</span></footer>
</main>
</body>
</html>
