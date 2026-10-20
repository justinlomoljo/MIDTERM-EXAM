# Scholarship Monitor

A PHP/MySQL implementation of Part II of the ITPC111 midterm practical: a Student Scholarship Monitoring and Academic Compliance System. The PHP app is designed for the XAMPP environment and uses the Asia/Manila timezone.

## Features

- Staff and scholar sign-in with server-side role checks and session protection.
- Scholar records are restricted to their own submissions; staff can review all submissions.
- Staff can register a scholar and assign one of the persisted Merit Scholar or Community Scholar policies.
- Semester submissions validate GWA, counts, units, academic year, grade-document reference, and duplicate periods.
- Staff can verify evidence or return it with a reason; returned submissions can be corrected and resubmitted without losing audit history.
- Verified submissions are evaluated against the scholar's persisted policy, with specific deficiency reasons and inclusive GWA/deadline boundaries.
- Staff can record approval only for compliant submissions or hold a decision with a note.
- Staff dashboard metrics, name/student-ID search, program/compliance filters, and actor/timestamp decision history.
- Six seeded synthetic test records A01–A06 matching the exam's required cases.

## Run with XAMPP

1. Start Apache and MySQL in the XAMPP Control Panel.
2. Create the local MySQL database and tables by importing `schema.mysql.sql` in phpMyAdmin, or run from Command Prompt:

   ```bat
   C:\xampp\mysql\bin\mysql.exe -u root < C:\xampp\htdocs\lomoljo\exam\schema.mysql.sql
   ```

3. From `C:\xampp\htdocs\lomoljo\exam`, seed the policies, demo accounts, and required records:

   ```bat
   C:\xampp\php\php.exe seed.php
   ```

4. Open `http://localhost/lomoljo/exam/`.

The default database connection is `127.0.0.1:3306`, database `scholarship_monitor`, user `root`, and an empty password. Override these with the `DB_HOST`, `DB_PORT`, `DB_NAME`, `DB_USER`, and `DB_PASS` environment variables when needed.

## Supabase PostgreSQL schema

`schema.sql` is the PostgreSQL/Supabase version of the database schema. To set up the database in Supabase, open **SQL Editor**, create a new query, paste the contents of `schema.sql`, and run it. It creates the tables, seeds both program policies, links application profiles to `auth.users`, and enables row-level security for scholars and staff.

New Supabase Auth accounts automatically receive a scholar profile. Assign a student ID and a policy to the scholar, and promote staff accounts to the `staff` role, only from a trusted administrative context. Do not put a Supabase service-role key in browser code.

**Important:** importing the PostgreSQL schema does not by itself connect the existing XAMPP PHP app to Supabase. The app currently expects MySQL, uses MySQL-specific SQL, and authenticates against its local `users` table; Supabase uses PostgreSQL and Supabase Auth. Using this schema with the running app requires a separate backend migration (including a PostgreSQL PDO driver, PostgreSQL-compatible queries, and Supabase Auth integration). The XAMPP setup and PHP seed/test commands above use `schema.mysql.sql` and do not initialize Supabase.

### Synthetic test accounts

These are local-only demonstration accounts. Change or replace them before any non-demo deployment.

| Role | Sign-in identifier | Password |
|---|---|---|
| Staff | `staff@school.test` | `StaffDemo!2026` |
| Scholar A01–A06 | student ID `A01` through `A06` | `ScholarDemo!2026` |

The six student records and grade summaries are fictional test inputs, not official grades or evidence.

## Run the functional tests

After the schema and seed steps, run:

```bat
C:\xampp\php\php.exe tests\run.php
```

The runner exercises the required TC01–TC10 plus staff registration, prints a results table including action, expected and actual outcomes, pass/fail, and evidence references, and rolls back all test mutations. A01–A06 remain unchanged.

The policy and input-validation tests can also run without a database:

```bat
C:\xampp\php\php.exe tests\unit.php
```

For a saved test-results transcript from Command Prompt:

```bat
C:\xampp\php\php.exe tests\run.php > test-results.txt
```

## Critical-path demonstration

Use screenshots or a short recording to capture:

1. Scholar A01 signing in and seeing only their own semester record.
2. Staff reviewing A06's pending evidence and A04's deficiency reasons.
3. A returned record being corrected by its scholar and returning to Pending verification.
4. Staff recording a compliant approval and a deficient hold.
5. Dashboard metrics, search/filter controls, audit history, and a scholar's denied staff-only action.

## Policy values used

| Policy | Maximum GWA | Minimum units | Failed | Incomplete | Dropped |
|---|---:|---:|---:|---:|---:|
| Merit Scholar | 1.75 | 18 | 0 | 0 | 0 |
| Community Scholar | 2.25 | 15 | 0 | 0 | 1 |

Both policies use the exam deadline of October 30, 2026 at 5:00 PM Asia/Manila. A submission exactly at the deadline is on time.

## Scope and known limitations

- Grade evidence is captured as a reference string; the system does not upload or verify actual registrar documents.
- No live Registrar API, SMS gateway, machine-learning model, payment module, or full appeal workflow is included, per the exam scope.
- Demo accounts are seeded with shared passwords for evaluation only; there is no self-service password reset or production identity provider.
- The six seed records intentionally include records submitted at or after the specified deadline to exercise the policy rules; they are synthetic examination fixtures.
