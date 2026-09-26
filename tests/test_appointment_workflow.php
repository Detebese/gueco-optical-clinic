<?php
// ============================================================
// AUTOMATED UNIT & BOUNDARY TESTS: CLINICAL APPOINTMENT WORKFLOW
// Tests state transitions, 15-min grace periods, cron auto-no-show,
// and doctor/admin safety reversal features.
// Run via: php tests/test_appointment_workflow.php
// ============================================================

define('BASE_URL', '../');
require_once __DIR__ . '/../config/functions.php';
require_once __DIR__ . '/../cron/auto_no_show.php';

class AppointmentWorkflowTestSuite {
    private PDO $db;
    private int $testPatientId = 0;
    private array $createdApptIds = [];
    private array $createdRecordIds = [];
    private array $createdRxIds = [];
    private int $passedCount = 0;
    private int $failedCount = 0;

    public function __construct() {
        $this->db = getDB();
    }

    public function run(): void {
        echo "============================================================\n";
        echo " RUNNING CLINICAL APPOINTMENT WORKFLOW TESTS\n";
        echo "============================================================\n\n";

        $this->setUp();

        try {
            $this->testPendingStatusRestrictions();
            $this->testTimingAndGracePeriodBoundaries();
            $this->testAutoNoShowCronCriteria();
            $this->testSafetyReversalWorkflow();
        } finally {
            $this->tearDown();
        }

        echo "\n============================================================\n";
        echo " TEST RESULTS: {$this->passedCount} PASSED, {$this->failedCount} FAILED\n";
        echo "============================================================\n";

        if ($this->failedCount > 0) {
            exit(1);
        }
    }

    private function setUp(): void {
        // Find or create test patient
        $stmt = $this->db->query("SELECT id FROM patients LIMIT 1");
        $patient = $stmt->fetch();
        if ($patient) {
            $this->testPatientId = (int)$patient['id'];
        } else {
            $ins = $this->db->prepare("INSERT INTO patients (full_name, phone, email) VALUES ('Test Automation Patient', '09123456789', 'test_auto@example.com')");
            $ins->execute();
            $this->testPatientId = (int)$this->db->lastInsertId();
        }
    }

    private function tearDown(): void {
        echo "\nCleaning up test artifacts...\n";
        if (!empty($this->createdRxIds)) {
            $inRx = implode(',', $this->createdRxIds);
            $this->db->exec("DELETE FROM prescriptions WHERE id IN ($inRx)");
        }
        if (!empty($this->createdRecordIds)) {
            $inRec = implode(',', $this->createdRecordIds);
            $this->db->exec("DELETE FROM patient_records WHERE id IN ($inRec)");
        }
        if (!empty($this->createdApptIds)) {
            $inAppt = implode(',', $this->createdApptIds);
            $this->db->exec("DELETE FROM appointments WHERE id IN ($inAppt)");
            $this->db->exec("DELETE FROM activity_logs WHERE module='Appointments' AND action LIKE '%Test Automation Patient%'");
        }
        echo "Cleanup complete.\n";
    }

    private function createAppointment(string $date, string $time, string $status = 'pending', ?string $notes = null): int {
        $stmt = $this->db->prepare("
            INSERT INTO appointments (patient_id, appointment_date, appointment_time, purpose, status, notes, created_at)
            VALUES (?, ?, ?, 'consultation', ?, ?, NOW())
        ");
        $stmt->execute([$this->testPatientId, $date, $time, $status, $notes]);
        $id = (int)$this->db->lastInsertId();
        $this->createdApptIds[] = $id;
        return $id;
    }

    private function assert(bool $condition, string $message): void {
        if ($condition) {
            $this->passedCount++;
            echo " [PASS] $message\n";
        } else {
            $this->failedCount++;
            echo " [FAIL] $message\n";
        }
    }

    /**
     * Simulation of backend validation logic (shared between doctor/appointments and admin/appointments)
     */
    private function validateAndTransition(int $apptId, string $action, int $userId = 1): array {
        $stmt = $this->db->prepare("SELECT p.full_name, a.appointment_date, a.appointment_time, a.status FROM appointments a JOIN patients p ON p.id=a.patient_id WHERE a.id=?");
        $stmt->execute([$apptId]);
        $ptData = $stmt->fetch();
        if (!$ptData) return ['success' => false, 'error' => 'Appointment not found'];

        $apptDateTimeStr = ($ptData['appointment_date'] ?? '') . ' ' . ($ptData['appointment_time'] ?? '');
        $apptTimestamp = strtotime($apptDateTimeStr);
        $graceTimestamp = $apptTimestamp ? ($apptTimestamp + (15 * 60)) : 0;
        $now = time();

        if ($action === 'complete') {
            if ($ptData['status'] === 'pending') {
                return ['success' => false, 'error' => 'A consultation cannot be finished before it has actually taken place. Please confirm the appointment first.'];
            } elseif ($ptData['status'] === 'no_show') {
                $this->db->prepare("UPDATE appointments SET status='completed' WHERE id=?")->execute([$apptId]);
                logActivity("Marked appointment #$apptId as completed from No-Show for patient: {$ptData['full_name']}", "Appointments", $userId, 'staff');
                return ['success' => true, 'message' => 'Completed via delayed charting'];
            } elseif ($apptTimestamp && $now < $apptTimestamp) {
                return ['success' => false, 'error' => 'A consultation cannot be marked as completed before the scheduled appointment time.'];
            } else {
                $this->db->prepare("UPDATE appointments SET status='completed' WHERE id=?")->execute([$apptId]);
                logActivity("Marked appointment #$apptId as completed for patient: {$ptData['full_name']}", "Appointments", $userId, 'staff');
                return ['success' => true, 'message' => 'Completed successfully'];
            }
        } elseif ($action === 'no_show') {
            if ($ptData['status'] === 'pending') {
                return ['success' => false, 'error' => 'Cannot mark a Pending appointment as No-Show. Please confirm the booking first.'];
            } elseif ($apptTimestamp && $now < $graceTimestamp) {
                return ['success' => false, 'error' => 'Marking a patient as No-Show is premature until scheduled time and 15-minute grace period have elapsed.'];
            } else {
                $this->db->prepare("UPDATE appointments SET status='no_show' WHERE id=?")->execute([$apptId]);
                logActivity("Marked appointment #$apptId as No-Show for patient: {$ptData['full_name']}", "Appointments", $userId, 'staff');
                return ['success' => true, 'message' => 'Marked as No-Show'];
            }
        } elseif ($action === 'confirm') {
            $this->db->prepare("UPDATE appointments SET status='confirmed', verified_by=? WHERE id=?")->execute([$userId, $apptId]);
            logActivity("Confirmed appointment #$apptId for patient: {$ptData['full_name']}", "Appointments", $userId, 'staff');
            return ['success' => true, 'message' => 'Confirmed'];
        } elseif ($action === 'revert_confirmed') {
            if ($ptData['status'] === 'no_show') {
                $this->db->prepare("UPDATE appointments SET status='confirmed', verified_by=? WHERE id=?")->execute([$userId, $apptId]);
                logActivity("Reverted appointment #$apptId from No-Show back to Confirmed for patient: {$ptData['full_name']}", "Appointments", $userId, 'staff');
                return ['success' => true, 'message' => 'Reverted to Confirmed'];
            } else {
                return ['success' => false, 'error' => 'Only No-Show appointments can be reverted to Confirmed.'];
            }
        }

        return ['success' => false, 'error' => 'Unknown action'];
    }

    private function getApptStatus(int $apptId): string {
        $stmt = $this->db->prepare("SELECT status FROM appointments WHERE id=?");
        $stmt->execute([$apptId]);
        return (string)($stmt->fetch()['status'] ?? '');
    }

    // ========================================================
    // TEST SUITES
    // ========================================================

    public function testPendingStatusRestrictions(): void {
        echo "\n--- SUITE 1: Pending Status Restrictions ---\n";
        $apptId = $this->createAppointment(date('Y-m-d'), '10:00:00', 'pending');

        // 1. Attempting complete on Pending should fail
        $res = $this->validateAndTransition($apptId, 'complete');
        $this->assert($res['success'] === false && strpos($res['error'], 'confirm the appointment first') !== false,
            "Pending appointment cannot be marked as completed directly.");

        // 2. Attempting no-show on Pending should fail
        $res = $this->validateAndTransition($apptId, 'no_show');
        $this->assert($res['success'] === false && strpos($res['error'], 'Cannot mark a Pending appointment as No-Show') !== false,
            "Pending appointment cannot be marked as No-Show directly.");

        // 3. Confirming Pending should succeed
        $res = $this->validateAndTransition($apptId, 'confirm');
        $this->assert($res['success'] === true && $this->getApptStatus($apptId) === 'confirmed',
            "Pending appointment can be confirmed by Doctor or Admin.");
    }

    public function testTimingAndGracePeriodBoundaries(): void {
        echo "\n--- SUITE 2: Timing Boundaries & 15-Minute Grace Period ---\n";

        // Case A: Future confirmed appointment (scheduled 2 hours from now)
        $futureTime = date('H:i:s', time() + 7200);
        $futureDate = date('Y-m-d');
        $futureAppt = $this->createAppointment($futureDate, $futureTime, 'confirmed');

        $resCompleteFuture = $this->validateAndTransition($futureAppt, 'complete');
        $this->assert($resCompleteFuture['success'] === false && strpos($resCompleteFuture['error'], 'before the scheduled appointment time') !== false,
            "Confirmed appointment cannot be marked completed before scheduled time.");

        $resNoShowFuture = $this->validateAndTransition($futureAppt, 'no_show');
        $this->assert($resNoShowFuture['success'] === false && strpos($resNoShowFuture['error'], 'grace period') !== false,
            "Confirmed appointment cannot be marked No-Show before scheduled time.");

        // Case B: Appointment time reached, but within 15-min grace period (scheduled 5 minutes ago)
        $recentTime = date('H:i:s', time() - 300);
        $recentDate = date('Y-m-d');
        $recentAppt = $this->createAppointment($recentDate, $recentTime, 'confirmed');

        // Can mark complete once appointment time has arrived
        $resCompleteRecent = $this->validateAndTransition($recentAppt, 'complete');
        $this->assert($resCompleteRecent['success'] === true && $this->getApptStatus($recentAppt) === 'completed',
            "Consultation can be marked completed once scheduled appointment time is reached.");

        // New confirmed appointment within grace period for No-Show test
        $withinGraceAppt = $this->createAppointment($recentDate, $recentTime, 'confirmed');
        $resNoShowWithinGrace = $this->validateAndTransition($withinGraceAppt, 'no_show');
        $this->assert($resNoShowWithinGrace['success'] === false && strpos($resNoShowWithinGrace['error'], 'grace period') !== false,
            "Marking No-Show is rejected when within the 15-minute clinic grace period.");

        // Case C: Past 15-minute grace period (scheduled 30 minutes ago)
        $pastGraceTime = date('H:i:s', time() - 1800);
        $pastGraceDate = date('Y-m-d');
        $pastGraceAppt = $this->createAppointment($pastGraceDate, $pastGraceTime, 'confirmed');

        $resNoShowPastGrace = $this->validateAndTransition($pastGraceAppt, 'no_show');
        $this->assert($resNoShowPastGrace['success'] === true && $this->getApptStatus($pastGraceAppt) === 'no_show',
            "Patient can be marked No-Show once scheduled time plus 15-minute grace period has elapsed.");
    }

    public function testAutoNoShowCronCriteria(): void {
        echo "\n--- SUITE 3: Automated End-of-Day Cron (Auto No-Show) Criteria ---\n";

        $dateQualifying = date('Y-m-d', strtotime('-5 days'));
        $dateWithNotes  = date('Y-m-d', strtotime('-4 days'));
        $dateWithRecord = date('Y-m-d', strtotime('-3 days'));
        $dateWithRx     = date('Y-m-d', strtotime('-2 days'));

        // Case 1: Past confirmed with no notes, records, or prescriptions -> MUST AUTO NO-SHOW
        $qualifyingAppt = $this->createAppointment($dateQualifying, '10:00:00', 'confirmed', '');

        // Case 2: Past confirmed WITH clinical notes -> SHOULD BE SKIPPED
        $apptWithNotes = $this->createAppointment($dateWithNotes, '11:00:00', 'confirmed', 'Patient called: running late with doctor notice');

        // Case 3: Past confirmed WITH medical record encounter -> SHOULD BE SKIPPED
        $apptWithRecord = $this->createAppointment($dateWithRecord, '13:00:00', 'confirmed', '');
        $recStmt = $this->db->prepare("
            INSERT INTO patient_records (patient_id, doctor_id, appointment_id, visit_date, chief_complaint, diagnosis)
            VALUES (?, 1, ?, ?, 'Blurry vision', 'Myopia')
        ");
        $recStmt->execute([$this->testPatientId, $apptWithRecord, $dateWithRecord]);
        $recordId = (int)$this->db->lastInsertId();
        $this->createdRecordIds[] = $recordId;

        // Case 4: Past confirmed WITH prescription -> SHOULD BE SKIPPED
        $apptWithRx = $this->createAppointment($dateWithRx, '14:00:00', 'confirmed', '');
        $rxStmt = $this->db->prepare("
            INSERT INTO prescriptions (patient_id, doctor_id, od_sphere, created_at)
            VALUES (?, 1, -1.50, ?)
        ");
        $rxStmt->execute([$this->testPatientId, $dateWithRx . ' 14:15:00']);
        $rxId = (int)$this->db->lastInsertId();
        $this->createdRxIds[] = $rxId;

        // Run the cron automation
        $result = processAutoNoShowAppointments($this->db);

        // Verification 1: Qualifying appointment marked as no_show
        $this->assert($this->getApptStatus($qualifyingAppt) === 'no_show',
            "Cron auto-marks qualifying abandoned confirmed appointment as no_show.");

        // Verification 2: Note tag [AUTO_NOSHOW] added
        $noteStmt = $this->db->prepare("SELECT notes FROM appointments WHERE id=?");
        $noteStmt->execute([$qualifyingAppt]);
        $notes = $noteStmt->fetch()['notes'] ?? '';
        $this->assert(strpos($notes, '[AUTO_NOSHOW]') !== false,
            "Cron tags appointment notes with [AUTO_NOSHOW].");

        // Verification 3: Audit activity log created
        $logStmt = $this->db->prepare("
            SELECT action, user_type FROM activity_logs 
            WHERE module='Appointments' AND action LIKE ? AND user_type='system'
            ORDER BY id DESC LIMIT 1
        ");
        $logStmt->execute(["%Appointment #$qualifyingAppt%"]);
        $logEntry = $logStmt->fetch();
        $this->assert(!empty($logEntry) && strpos($logEntry['action'], 'Status updated to NO-SHOW by System Automation') !== false,
            "Cron logs audit activity: 'Status updated to NO-SHOW by System Automation' under user_type='system'.");

        // Verification 4: Appt with clinical notes NOT touched
        $this->assert($this->getApptStatus($apptWithNotes) === 'confirmed',
            "Cron skips appointment with existing clinical notes.");

        // Verification 5: Appt with medical record NOT touched
        $this->assert($this->getApptStatus($apptWithRecord) === 'confirmed',
            "Cron skips appointment with associated patient medical record encounter.");

        // Verification 6: Appt with prescription NOT touched
        $this->assert($this->getApptStatus($apptWithRx) === 'confirmed',
            "Cron skips appointment with associated optical prescription.");
    }

    public function testSafetyReversalWorkflow(): void {
        echo "\n--- SUITE 4: Doctor & Admin Safety / Reversal Workflow ---\n";

        // Create an appointment in no_show status
        $noShowAppt = $this->createAppointment(date('Y-m-d', strtotime('-1 day')), '11:00:00', 'no_show', '[AUTO_NOSHOW] Automated mark');

        // Reversal 1: Revert back to Confirmed
        $resRevert = $this->validateAndTransition($noShowAppt, 'revert_confirmed', 2);
        $this->assert($resRevert['success'] === true && $this->getApptStatus($noShowAppt) === 'confirmed',
            "Doctor/Admin can revert a No-Show appointment back to Confirmed for delayed charting.");

        // Reversal 2: Directly Mark as Completed from No-Show (Delayed Charting)
        $noShowAppt2 = $this->createAppointment(date('Y-m-d', strtotime('-1 day')), '14:00:00', 'no_show', '[AUTO_NOSHOW] Automated mark');
        $resDelayedComplete = $this->validateAndTransition($noShowAppt2, 'complete', 2);
        $this->assert($resDelayedComplete['success'] === true && $this->getApptStatus($noShowAppt2) === 'completed',
            "Doctor/Admin can directly mark No-Show appointment as Completed if patient visit was delayed.");

        // Verify audit log for delayed completion
        $logStmt = $this->db->prepare("SELECT action FROM activity_logs WHERE module='Appointments' AND action LIKE ? ORDER BY id DESC LIMIT 1");
        $logStmt->execute(["%Marked appointment #$noShowAppt2 as completed from No-Show%"]);
        $log = $logStmt->fetch();
        $this->assert(!empty($log),
            "Delayed charting completion creates an audit log entry detailing reversal from No-Show.");
    }
}

// Execute the test suite
$suite = new AppointmentWorkflowTestSuite();
$suite->run();
