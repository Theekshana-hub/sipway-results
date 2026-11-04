<?php
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/xlsx_helper.php';
require_once __DIR__ . '/../includes/payment_import.php';   // NEW: payment importer (shared with payments.php)
requireLogin();
$pageTitle = 'Results';
$conn = getConnection();



function resultsColFlags($conn) {
    $f = ['json'=>false,'a3'=>false,'remarks'=>false];
    try {
        $f['json'] = ($conn->query("SHOW COLUMNS FROM results LIKE 'assignments_json'")->num_rows > 0);
        $f['a3'] = ($conn->query("SHOW COLUMNS FROM results LIKE 'assignment3'")->num_rows > 0);
        $f['remarks'] = ($conn->query("SHOW COLUMNS FROM results LIKE 'remarks'")->num_rows > 0);
    } catch (Throwable $e) {}
    return $f;
}

function createPaymentStub($conn, $sid) {
    $hasReg = false; $hasInst = false;
    try {
        $hasReg = ($conn->query("SHOW COLUMNS FROM payments LIKE 'reg_fee'")->num_rows > 0);
        $hasInst = ($conn->query("SHOW COLUMNS FROM payments LIKE 'installments_json'")->num_rows > 0);
    } catch (Throwable $e) {}
    if ($hasReg && $hasInst) {
        $conn->query("INSERT INTO payments (student_id, payment_status, reg_fee, course_paid, course_due, balance, amount, installments_json) VALUES ($sid, 'PENDING', 0, 0, 0, 0, 0, '{}')");
    } elseif ($hasReg) {
        $conn->query("INSERT INTO payments (student_id, payment_status, reg_fee, course_paid, course_due, balance, amount) VALUES ($sid, 'PENDING', 0, 0, 0, 0, 0)");
    } else {
        $conn->query("INSERT INTO payments (student_id, payment_status) VALUES ($sid, 'PENDING')");
    }
}

// ===================== EXCEL UPLOAD =====================
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'upload') {
    @set_time_limit(300);
    @ini_set('memory_limit', '512M');
    $admin = getAdminUsername();
    $createMissing = !empty($_POST['create_students']);
    $stats = ['sheets'=>0,'students_new'=>0,'students_found'=>0,'results_new'=>0,'results_updated'=>0,'skipped'=>0,'errors'=>[]];
    $cols = resultsColFlags($conn);
    ensurePaymentsSchema($conn);   // NEW: make sure payments table has all columns + status enum values

    if (!isset($_FILES['excel_file']) || $_FILES['excel_file']['error'] !== UPLOAD_ERR_OK) {
        setFlash('error', 'Please select a valid Excel file.');
        redirect('results.php');
    }
    $file = $_FILES['excel_file'];
    $ext = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));
    $tmp = $file['tmp_name'];
    if (!in_array($ext, ['xlsx','xls','csv'])) {
        setFlash('error', 'Only .xlsx or .csv allowed.');
        redirect('results.php');
    }

    $allSheets = [];
    if ($ext === 'csv') {
        $rows = []; $h = fopen($tmp, 'r');
        $bom = fread($h, 3); if ($bom !== "\xEF\xBB\xBF") rewind($h);
        while (($d = fgetcsv($h)) !== false) $rows[] = $d;
        fclose($h); $allSheets['CSV'] = $rows;
    } else {
        $allSheets = readXlsxAllSheets($tmp);
        if (empty($allSheets)) {
            setFlash('error', 'Could not read Excel file.');
            redirect('results.php');
        }
    }

    foreach ($allSheets as $sheetName => $rows) {
        if (empty($rows)) continue;
        $map = detectHdicraColumns($rows);
        if ($map['header_row'] === null) {
            $found = false;
            for ($r = 0; $r < min(20, count($rows)); $r++) {
                $c0 = cellVal($rows[$r], 0);
                if (preg_match('/^[A-Za-z]+\/[A-Za-z0-9]+\/\d+/', $c0)) {
                    $map = ['reg'=>0,'name'=>2,'phone'=>4,'a1'=>null,'a2'=>null,'a3'=>null,'exam'=>null,'header_row'=>max(0,$r-1)];
                    $found = true; break;
                }
            }
            if (!$found) continue;
        }
        $stats['sheets']++;
        for ($r = $map['header_row'] + 1; $r < count($rows); $r++) {
            $row = $rows[$r];
            $regNo = cellVal($row, $map['reg']);
            if (!preg_match('/^[A-Za-z]+\/[A-Za-z0-9]+\/\d+/', $regNo)) {
                $c0 = cellVal($row, 0); $c1 = cellVal($row, 1);
                if (preg_match('/^[A-Za-z]+\/[A-Za-z0-9]+\/\d+/', $c0)) $regNo = $c0;
                elseif (preg_match('/^[A-Za-z]+\/[A-Za-z0-9]+\/\d+/', $c1)) $regNo = $c1;
            }
            if (!preg_match('/^[A-Za-z]+\/[A-Za-z0-9]+\/\d+/', $regNo)) continue;

            $name = cellVal($row, $map['name']);
            $phone = cellVal($row, $map['phone']);
            if (is_numeric($phone) && strpos($phone, '.') !== false) $phone = (string)(int)(float)$phone;

            $a1 = parseNum(cellVal($row, $map['a1']));
            $a2 = parseNum(cellVal($row, $map['a2']));
            $a3 = parseNum(cellVal($row, $map['a3']));
            $exam = parseNum(cellVal($row, $map['exam']));

            $st = $conn->prepare("SELECT id FROM students WHERE registration_number = ? LIMIT 1");
            $st->bind_param("s", $regNo); $st->execute();
            $student = $st->get_result()->fetch_assoc(); $st->close();

            if (!$student) {
                if (!$createMissing) {
                    $stats['errors'][] = "$sheetName: Student not found $regNo";
                    $stats['skipped']++; continue;
                }
                $batchId = ensureCourseAndBatch($conn, $regNo);
                if (!$batchId) { $stats['skipped']++; continue; }
                if ($name === '') $name = $regNo;
                $ins = $conn->prepare("INSERT INTO students (registration_number, full_name, phone, batch_id, is_active) VALUES (?,?,?,?,1)");
                $ins->bind_param("sssi", $regNo, $name, $phone, $batchId);
                if (!$ins->execute()) { $stats['errors'][] = "$regNo create fail"; $stats['skipped']++; $ins->close(); continue; }
                $sid = $ins->insert_id; $ins->close();
                $conn->query("INSERT INTO results (student_id, result_status) VALUES ($sid, 'PENDING')");
                createPaymentStub($conn, $sid);
                $conn->query("INSERT INTO certificates (student_id, certificate_status) VALUES ($sid, 'PENDING')");
                $stats['students_new']++;
            } else {
                $sid = (int)$student['id'];
                $stats['students_found']++;
            }

            $assignments = [];
            if ($a1 !== null) $assignments[] = $a1;
            if ($a2 !== null) $assignments[] = $a2;
            if ($a3 !== null) $assignments[] = $a3;
            $exams = [];
            if ($exam !== null) $exams[] = $exam;
            $full = array_sum($assignments) + array_sum($exams);
            if ($full == 0) $full = null;
            $allM = array_merge($assignments, $exams);
            $pct = count($allM) > 0 ? array_sum($allM) / count($allM) : null;
            $status = ($full !== null && $full > 0) ? 'PRESENT' : 'PENDING';

            if ($full === null && empty($assignments) && empty($exams)) continue; // no marks in excel

            $a1s = isset($assignments[0]) ? (string)$assignments[0] : null;
            $a2s = isset($assignments[1]) ? (string)$assignments[1] : null;
            $a3s = isset($assignments[2]) ? (string)$assignments[2] : null;
            $fes = isset($exams[0]) ? (string)$exams[0] : null;
            $fulls = $full !== null ? (string)$full : null;
            $pcts = $pct !== null ? (string)round($pct, 2) : null;
            $aj = json_encode($assignments);
            $ej = json_encode($exams);

            $exists = $conn->query("SELECT id FROM results WHERE student_id=$sid")->fetch_assoc();
            try {
                if ($exists) {
                    // status first
                    $s1 = $conn->prepare("UPDATE results SET result_status=?, entered_by=? WHERE student_id=?");
                    $s1->bind_param("ssi", $status, $admin, $sid); $s1->execute(); $s1->close();
                    if ($cols['a3'] && $cols['json']) {
                        $stmt = $conn->prepare("UPDATE results SET assignment1=?,assignment2=?,assignment3=?,final_exam=?,full_marks=?,marks_percentage=?,assignments_json=?,exams_json=? WHERE student_id=?");
                        $stmt->bind_param("ssssssssi", $a1s,$a2s,$a3s,$fes,$fulls,$pcts,$aj,$ej,$sid);
                    } elseif ($cols['a3']) {
                        $stmt = $conn->prepare("UPDATE results SET assignment1=?,assignment2=?,assignment3=?,final_exam=?,full_marks=?,marks_percentage=? WHERE student_id=?");
                        $stmt->bind_param("ssssssi", $a1s,$a2s,$a3s,$fes,$fulls,$pcts,$sid);
                    } else {
                        $stmt = $conn->prepare("UPDATE results SET assignment1=?,assignment2=?,final_exam=?,full_marks=?,marks_percentage=? WHERE student_id=?");
                        $stmt->bind_param("sssssi", $a1s,$a2s,$fes,$fulls,$pcts,$sid);
                    }
                    if ($stmt->execute()) $stats['results_updated']++;
                    else $stats['errors'][] = "$regNo: ".$stmt->error;
                    $stmt->close();
                } else {
                    if ($cols['a3'] && $cols['json']) {
                        $stmt = $conn->prepare("INSERT INTO results (student_id,assignment1,assignment2,assignment3,final_exam,full_marks,marks_percentage,result_status,entered_by,assignments_json,exams_json) VALUES (?,?,?,?,?,?,?,?,?,?,?)");
                        $stmt->bind_param("issssssssss", $sid,$a1s,$a2s,$a3s,$fes,$fulls,$pcts,$status,$admin,$aj,$ej);
                    } else {
                        $stmt = $conn->prepare("INSERT INTO results (student_id,assignment1,assignment2,final_exam,full_marks,marks_percentage,result_status,entered_by) VALUES (?,?,?,?,?,?,?,?)");
                        $stmt->bind_param("isssssss", $sid,$a1s,$a2s,$fes,$fulls,$pcts,$status,$admin);
                    }
                    if ($stmt->execute()) $stats['results_new']++;
                    else $stats['errors'][] = "$regNo: ".$stmt->error;
                    $stmt->close();
                }
            } catch (Throwable $e) {
                $stats['errors'][] = "$regNo: ".$e->getMessage();
            }
        }
    }

    // ===================== NEW: PAYMENTS from the same Excel =====================
    // (runs AFTER the students were created above, so every student already exists)
    $payStats = null; $payNotes = [];
    if ($ext === 'xlsx') {
        $payStats = payImportFromXlsx($conn, $tmp, $admin, $payNotes);
    } elseif ($ext === 'xls') {
        $payNotes[] = 'Payments: .xls is not supported for payments, please save the file as .xlsx';
    }
    // ==============================================================================

    logActivity($admin, 'HDICRA_UPLOAD', json_encode(['results' => $stats, 'payments' => $payStats]));
    $msg = "Sheets: {$stats['sheets']} | Students new: {$stats['students_new']} | Found: {$stats['students_found']} | Results new: {$stats['results_new']} | Updated: {$stats['results_updated']}";
    if ($payStats) {
        $msg .= " || PAYMENTS saved: {$payStats['students']} (Completed {$payStats['completed']}, One time {$payStats['one_time']}, Partial {$payStats['partial']}, Not paid {$payStats['none']}, No data {$payStats['nodata']}, Not found {$payStats['notfound']}, Failed {$payStats['failed']})";
    }
    $allNotes = array_merge($stats['errors'], $payNotes);
    if ($stats['errors']) $msg .= " | Errors: ".count($stats['errors']);
    if ($allNotes) $_SESSION['upload_errors'] = array_slice($allNotes, 0, 60);
    setFlash('success', $msg);
    redirect('results.php');
}

// ===================== SINGLE UPDATE =====================
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'update') {
    $student_id = (int)($_POST['student_id'] ?? 0);
    $admin = getAdminUsername();
    if (!$student_id) { setFlash('error', 'Invalid student'); redirect('results.php'); }

    $assignments = [];
    if (!empty($_POST['assignments']) && is_array($_POST['assignments'])) {
        foreach ($_POST['assignments'] as $val) if ($val !== '' && is_numeric($val)) $assignments[] = (float)$val;
    }
    $exams = [];
    if (!empty($_POST['exams']) && is_array($_POST['exams'])) {
        foreach ($_POST['exams'] as $val) if ($val !== '' && is_numeric($val)) $exams[] = (float)$val;
    }
    $full = array_sum($assignments) + array_sum($exams);
    if ($full == 0) $full = null;
    $allMarks = array_merge($assignments, $exams);
    $pct = null;
    if (isset($_POST['marks_percentage']) && $_POST['marks_percentage'] !== '') $pct = (float)$_POST['marks_percentage'];
    elseif (count($allMarks) > 0) $pct = array_sum($allMarks) / count($allMarks);

    $status = trim($_POST['result_status'] ?? 'PENDING');
    if (!in_array($status, ['PENDING','PRESENT','ABSENT','NOT_ELIGIBLE'], true)) $status = 'PENDING';
    $remarks = trim($_POST['remarks'] ?? '');

    $a1 = isset($assignments[0]) ? (string)$assignments[0] : null;
    $a2 = isset($assignments[1]) ? (string)$assignments[1] : null;
    $a3 = isset($assignments[2]) ? (string)$assignments[2] : null;
    $fe = isset($exams[0]) ? (string)$exams[0] : null;
    $full_s = $full !== null ? (string)$full : null;
    $pct_s = $pct !== null ? (string)round($pct, 2) : null;
    $aj = json_encode($assignments);
    $ej = json_encode($exams);
    $cols = resultsColFlags($conn);

    $ok = false; $errMsg = '';
    try {
        $exists = $conn->query("SELECT id FROM results WHERE student_id=$student_id LIMIT 1");
        if ($exists && $exists->num_rows > 0) {
            // ALWAYS save status first
            $s1 = $conn->prepare("UPDATE results SET result_status=?, entered_by=? WHERE student_id=?");
            $s1->bind_param("ssi", $status, $admin, $student_id);
            $ok = $s1->execute();
            if (!$ok) $errMsg = $s1->error;
            $s1->close();

            if ($ok) {
                if ($cols['a3'] && $cols['json'] && $cols['remarks']) {
                    $stmt = $conn->prepare("UPDATE results SET assignment1=?,assignment2=?,assignment3=?,final_exam=?,full_marks=?,marks_percentage=?,remarks=?,assignments_json=?,exams_json=? WHERE student_id=?");
                    $stmt->bind_param("sssssssssi", $a1,$a2,$a3,$fe,$full_s,$pct_s,$remarks,$aj,$ej,$student_id);
                } elseif ($cols['remarks']) {
                    $stmt = $conn->prepare("UPDATE results SET assignment1=?,assignment2=?,final_exam=?,full_marks=?,marks_percentage=?,remarks=? WHERE student_id=?");
                    $stmt->bind_param("ssssssi", $a1,$a2,$fe,$full_s,$pct_s,$remarks,$student_id);
                } else {
                    $stmt = $conn->prepare("UPDATE results SET assignment1=?,assignment2=?,final_exam=?,full_marks=?,marks_percentage=? WHERE student_id=?");
                    $stmt->bind_param("sssssi", $a1,$a2,$fe,$full_s,$pct_s,$student_id);
                }
                if (!$stmt->execute()) $errMsg = 'Status saved; marks: '.$stmt->error;
                $stmt->close();
            }
        } else {
            if ($cols['a3'] && $cols['json'] && $cols['remarks']) {
                $stmt = $conn->prepare("INSERT INTO results (student_id,assignment1,assignment2,assignment3,final_exam,full_marks,marks_percentage,result_status,remarks,entered_by,assignments_json,exams_json) VALUES (?,?,?,?,?,?,?,?,?,?,?,?)");
                $stmt->bind_param("isssssssssss", $student_id,$a1,$a2,$a3,$fe,$full_s,$pct_s,$status,$remarks,$admin,$aj,$ej);
            } elseif ($cols['remarks']) {
                $stmt = $conn->prepare("INSERT INTO results (student_id,assignment1,assignment2,final_exam,full_marks,marks_percentage,result_status,remarks,entered_by) VALUES (?,?,?,?,?,?,?,?,?)");
                $stmt->bind_param("issssssss", $student_id,$a1,$a2,$fe,$full_s,$pct_s,$status,$remarks,$admin);
            } else {
                $stmt = $conn->prepare("INSERT INTO results (student_id,assignment1,assignment2,final_exam,full_marks,marks_percentage,result_status,entered_by) VALUES (?,?,?,?,?,?,?,?)");
                $stmt->bind_param("isssssss", $student_id,$a1,$a2,$fe,$full_s,$pct_s,$status,$admin);
            }
            $ok = $stmt->execute();
            if (!$ok) $errMsg = $stmt->error;
            $stmt->close();
        }
    } catch (Throwable $e) { $ok = false; $errMsg = $e->getMessage(); }

    if ($ok) {
        $pay = $conn->query("SELECT payment_status FROM payments WHERE student_id=$student_id")->fetch_assoc();
        $payOk = $pay && in_array($pay['payment_status'], ['PAID','COMPLETED','ONE_TIME','CONVA_PAY']);
        $passOk = ($status === 'PRESENT') && (($full !== null && $full >= 40) || ($pct !== null && $pct >= 40));
        if ($status === 'ABSENT' || $status === 'NOT_ELIGIBLE') {
            $conn->query("UPDATE certificates SET certificate_status='NOT_ELIGIBLE' WHERE student_id=$student_id");
        } elseif ($passOk && $payOk) {
            $conn->query("UPDATE certificates SET certificate_status='READY' WHERE student_id=$student_id AND certificate_status!='ISSUED'");
        } else {
            $conn->query("UPDATE certificates SET certificate_status='PENDING' WHERE student_id=$student_id AND certificate_status!='ISSUED'");
        }
        logActivity($admin, 'UPDATE_RESULT', "Student:$student_id Status:$status");
        setFlash('success', 'Result saved. Status: ' . $status);
    } else {
        setFlash('error', 'Could not save: ' . ($errMsg ?: 'unknown'));
    }
    $qs = http_build_query(array_filter([
        'course_id' => $_POST['redirect_course'] ?? '',
        'batch_id' => $_POST['redirect_batch'] ?? '',
        'q' => $_POST['redirect_q'] ?? '',
    ]));
    redirect('results.php' . ($qs ? '?' . $qs : ''));
}

// ===================== LIST =====================
$filterCourse = isset($_GET['course_id']) ? (int)$_GET['course_id'] : 0;
$filterBatch  = isset($_GET['batch_id']) ? (int)$_GET['batch_id'] : 0;
$filterStatus = $_GET['status'] ?? '';
$search = trim($_GET['q'] ?? '');

$coursesArr = [];
$q = $conn->query("SELECT id, course_code, course_name FROM courses WHERE is_active=1 ORDER BY course_name");
while ($c = $q->fetch_assoc()) $coursesArr[] = $c;
$batchesArr = [];
$q = $conn->query("SELECT b.id, b.batch_code, b.batch_name, c.course_code FROM batches b JOIN courses c ON b.course_id=c.id ORDER BY c.course_code, b.batch_code");
while ($b = $q->fetch_assoc()) $batchesArr[] = $b;

$cols = resultsColFlags($conn);
$sql = "SELECT s.id AS student_id, s.registration_number, s.full_name, b.batch_code, c.course_code, c.course_name,
        r.assignment1, r.assignment2, r.final_exam, r.full_marks, r.marks_percentage, r.result_status, r.remarks";
if ($cols['a3']) $sql .= ", r.assignment3";
if ($cols['json']) $sql .= ", r.assignments_json, r.exams_json";
$sql .= " FROM students s JOIN batches b ON s.batch_id=b.id JOIN courses c ON b.course_id=c.id
        LEFT JOIN results r ON s.id=r.student_id WHERE s.is_active=1";
if ($filterCourse) $sql .= " AND c.id=$filterCourse";
if ($filterBatch) $sql .= " AND b.id=$filterBatch";
if ($filterStatus) $sql .= " AND r.result_status='".$conn->real_escape_string($filterStatus)."'";
if ($search) {
    $s = $conn->real_escape_string($search);
    $sql .= " AND (s.registration_number LIKE '%$s%' OR s.full_name LIKE '%$s%')";
}
$sql .= " ORDER BY c.course_code, b.batch_code, s.registration_number";
$allRows = [];
$res = $conn->query($sql);
if ($res) while ($r = $res->fetch_assoc()) $allRows[] = $r;

require_once __DIR__ . '/../includes/header.php';
?>

<div class="card mb-4 border-0 shadow-sm">
    <div class="card-header bg-white">
        <h5 class="mb-0"><i class="bi bi-file-earmark-excel text-success me-2"></i>Upload HDICRA Excel (all Batch sheets)</h5>
    </div>
    <div class="card-body">
        <form method="POST" enctype="multipart/form-data" class="row g-3 align-items-end">
            <input type="hidden" name="action" value="upload">
            <div class="col-md-5">
                <label class="form-label">HDICRA.xlsx file</label>
                <input type="file" name="excel_file" class="form-control" accept=".xlsx,.xls,.csv" required>
                <small class="text-muted">All sheets. Saves <strong>Results</strong> (Reg No, Name, Assignments, Final Exam) and <strong>Payments</strong> (Registration Fee, 1st&ndash;6th Installments) together.</small>
            </div>
            <div class="col-md-4">
                <div class="form-check mt-4">
                    <input class="form-check-input" type="checkbox" name="create_students" value="1" id="createStudents" checked>
                    <label class="form-check-label" for="createStudents"><strong>Auto-create students</strong> if missing</label>
                </div>
            </div>
            <div class="col-md-3">
                <button type="submit" class="btn btn-success w-100"><i class="bi bi-upload me-1"></i> Upload &amp; Import</button>
            </div>
        </form>
        <?php if (!empty($_SESSION['upload_errors'])): ?>
        <div class="alert alert-warning mt-3 mb-0">
            <strong>Notes:</strong>
            <ul class="mb-0 small"><?php foreach ($_SESSION['upload_errors'] as $e): ?><li><?php echo htmlspecialchars($e); ?></li><?php endforeach; ?></ul>
        </div>
        <?php unset($_SESSION['upload_errors']); endif; ?>
    </div>
</div>

<div class="filter-bar">
    <form method="GET" class="row g-2 align-items-end">
        <div class="col-md-2">
            <label class="form-label">Course</label>
            <select name="course_id" class="form-select" onchange="this.form.submit()">
                <option value="0">All</option>
                <?php foreach ($coursesArr as $c): ?>
                <option value="<?php echo $c['id']; ?>" <?php echo $filterCourse==$c['id']?'selected':''; ?>><?php echo sanitize($c['course_code']); ?></option>
                <?php endforeach; ?>
            </select>
        </div>
        <div class="col-md-2">
            <label class="form-label">Batch</label>
            <select name="batch_id" class="form-select" onchange="this.form.submit()">
                <option value="0">All</option>
                <?php foreach ($batchesArr as $b): ?>
                <option value="<?php echo $b['id']; ?>" <?php echo $filterBatch==$b['id']?'selected':''; ?>><?php echo sanitize($b['course_code'].'/'.$b['batch_code']); ?></option>
                <?php endforeach; ?>
            </select>
        </div>
        <div class="col-md-2">
            <label class="form-label">Status</label>
            <select name="status" class="form-select" onchange="this.form.submit()">
                <option value="">All</option>
                <?php foreach (['PRESENT','ABSENT','PENDING','NOT_ELIGIBLE'] as $st): ?>
                <option value="<?php echo $st; ?>" <?php echo $filterStatus===$st?'selected':''; ?>><?php echo $st; ?></option>
                <?php endforeach; ?>
            </select>
        </div>
        <div class="col-md-3">
            <label class="form-label">Search</label>
            <input type="text" name="q" class="form-control" value="<?php echo sanitize($search); ?>" placeholder="Reg / Name">
        </div>
        <div class="col-md-2">
            <button type="submit" class="btn btn-primary w-100"><i class="bi bi-search"></i></button>
        </div>
    </form>
</div>

<div class="card table-card">
    <div class="table-responsive">
        <table class="table table-hover mb-0">
            <thead>
                <tr>
                    <th>Reg. Number</th><th>Name</th><th>Course/Batch</th><th>Assignments</th><th>Exams</th><th>Full</th><th>%</th><th>Status</th><th width="70">Edit</th>
                </tr>
            </thead>
            <tbody>
            <?php if ($allRows): foreach ($allRows as $row):
                $asgList=[]; $examList=[];
                if (!empty($row['assignments_json'])) { $d=json_decode($row['assignments_json'],true); if(is_array($d))$asgList=$d; }
                else { foreach (['assignment1','assignment2','assignment3'] as $k) if (isset($row[$k]) && $row[$k] !== null && $row[$k] !== '') $asgList[] = $row[$k]; }
                if (!empty($row['exams_json'])) { $d=json_decode($row['exams_json'],true); if(is_array($d))$examList=$d; }
                elseif (isset($row['final_exam']) && $row['final_exam'] !== null && $row['final_exam'] !== '') $examList[] = $row['final_exam'];
            ?>
                <tr>
                    <td><code><?php echo sanitize($row['registration_number']); ?></code></td>
                    <td><?php echo sanitize($row['full_name']); ?></td>
                    <td><small><?php echo sanitize($row['course_code']); ?></small> <span class="badge bg-secondary"><?php echo sanitize($row['batch_code']); ?></span></td>
                    <td><?php if ($asgList): foreach ($asgList as $i=>$m): ?><span class="badge bg-info text-dark me-1">A<?php echo $i+1; ?>: <?php echo $m; ?></span><?php endforeach; else: echo '—'; endif; ?></td>
                    <td><?php if ($examList): foreach ($examList as $i=>$m): ?><span class="badge bg-warning text-dark me-1">E<?php echo $i+1; ?>: <?php echo $m; ?></span><?php endforeach; else: echo '—'; endif; ?></td>
                    <td><strong><?php echo $row['full_marks'] !== null ? $row['full_marks'] : '—'; ?></strong></td>
                    <td><?php
                        $all = array_merge($asgList, $examList);
                        echo count($all) ? number_format(array_sum($all)/count($all), 2).'%' : ($row['marks_percentage'] !== null ? number_format((float)$row['marks_percentage'], 2).'%' : '—');
                    ?></td>
                    <td><?php echo $row['result_status'] ? statusBadge($row['result_status']) : statusBadge('PENDING'); ?></td>
                    <td><button type="button" class="btn btn-sm btn-outline-primary" data-bs-toggle="modal" data-bs-target="#editResult<?php echo (int)$row['student_id']; ?>"><i class="bi bi-pencil"></i></button></td>
                </tr>
            <?php endforeach; else: ?>
                <tr><td colspan="9" class="text-center text-muted py-5">No records — upload HDICRA.xlsx above</td></tr>
            <?php endif; ?>
            </tbody>
        </table>
    </div>
</div>

<?php foreach ($allRows as $row):
    $asgList=[]; $examList=[];
    if (!empty($row['assignments_json'])) { $d=json_decode($row['assignments_json'],true); if(is_array($d))$asgList=$d; }
    else { foreach (['assignment1','assignment2','assignment3'] as $k) if (isset($row[$k]) && $row[$k] !== null && $row[$k] !== '') $asgList[] = $row[$k]; }
    if (!empty($row['exams_json'])) { $d=json_decode($row['exams_json'],true); if(is_array($d))$examList=$d; }
    elseif (isset($row['final_exam']) && $row['final_exam'] !== null && $row['final_exam'] !== '') $examList[] = $row['final_exam'];
?>
<div class="modal fade" id="editResult<?php echo (int)$row['student_id']; ?>" tabindex="-1">
    <div class="modal-dialog modal-lg"><div class="modal-content">
        <form method="POST" class="result-form">
            <div class="modal-header"><h5 class="modal-title">Update — <?php echo sanitize($row['registration_number']); ?></h5><button type="button" class="btn-close" data-bs-dismiss="modal"></button></div>
            <div class="modal-body">
                <input type="hidden" name="action" value="update">
                <input type="hidden" name="student_id" value="<?php echo (int)$row['student_id']; ?>">
                <input type="hidden" name="redirect_course" value="<?php echo $filterCourse; ?>">
                <input type="hidden" name="redirect_batch" value="<?php echo $filterBatch; ?>">
                <input type="hidden" name="redirect_q" value="<?php echo sanitize($search); ?>">
                <p class="text-muted"><?php echo sanitize($row['full_name']); ?></p>
                <div class="mb-3">
                    <div class="d-flex justify-content-between mb-2"><label class="fw-bold mb-0">Assignments</label><button type="button" class="btn btn-sm btn-outline-success add-assignment"><i class="bi bi-plus"></i></button></div>
                    <div class="assignments-container row g-2">
                        <?php $n = max(count($asgList), 1); for ($i = 0; $i < $n; $i++): ?>
                        <div class="col-md-4 assignment-item"><div class="input-group"><span class="input-group-text">A<?php echo $i+1; ?></span><input type="number" step="0.01" name="assignments[]" class="form-control mark-input" value="<?php echo $asgList[$i] ?? ''; ?>"><button type="button" class="btn btn-outline-danger remove-item" <?php echo $n <= 1 ? 'disabled' : ''; ?>><i class="bi bi-trash"></i></button></div></div>
                        <?php endfor; ?>
                    </div>
                </div>
                <div class="mb-3">
                    <div class="d-flex justify-content-between mb-2"><label class="fw-bold mb-0">Exams</label><button type="button" class="btn btn-sm btn-outline-success add-exam"><i class="bi bi-plus"></i></button></div>
                    <div class="exams-container row g-2">
                        <?php $n = max(count($examList), 1); for ($i = 0; $i < $n; $i++): ?>
                        <div class="col-md-4 exam-item"><div class="input-group"><span class="input-group-text">E<?php echo $i+1; ?></span><input type="number" step="0.01" name="exams[]" class="form-control mark-input" value="<?php echo $examList[$i] ?? ''; ?>"><button type="button" class="btn btn-outline-danger remove-item" <?php echo $n <= 1 ? 'disabled' : ''; ?>><i class="bi bi-trash"></i></button></div></div>
                        <?php endfor; ?>
                    </div>
                </div>
                <div class="row g-3">
                    <div class="col-md-4"><label class="form-label">Full (auto)</label><input type="number" step="0.01" class="form-control full-marks-input" value="<?php echo $row['full_marks'] ?? ''; ?>" readonly></div>
                    <div class="col-md-4"><label class="form-label">%</label><input type="number" step="0.01" name="marks_percentage" class="form-control percentage-input" value="<?php echo $row['marks_percentage'] ?? ''; ?>"></div>
                    <div class="col-md-4"><label class="form-label">Status</label>
                        <select name="result_status" class="form-select" required>
                            <?php foreach (['PENDING','PRESENT','ABSENT','NOT_ELIGIBLE'] as $st): ?>
                            <option value="<?php echo $st; ?>" <?php echo ($row['result_status'] ?? '') === $st ? 'selected' : ''; ?>><?php echo $st; ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="col-12"><label class="form-label">Remarks</label><textarea name="remarks" class="form-control" rows="2"><?php echo sanitize($row['remarks'] ?? ''); ?></textarea></div>
                </div>
            </div>
            <div class="modal-footer"><button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button><button type="submit" class="btn btn-primary">Save</button></div>
        </form>
    </div></div>
</div>
<?php endforeach; ?>

<script>
document.addEventListener('DOMContentLoaded', function () {
    function calc(f) {
        let t = 0, c = 0;
        f.querySelectorAll('.mark-input').forEach(function (i) {
            const v = parseFloat(i.value); if (!isNaN(v)) { t += v; c++; }
        });
        const a = f.querySelector('.full-marks-input'), p = f.querySelector('.percentage-input');
        if (a) a.value = t > 0 ? t.toFixed(2) : '';
        if (p) p.value = c > 0 ? (t / c).toFixed(2) : '';
    }
    document.body.addEventListener('input', function (e) {
        if (e.target.classList.contains('mark-input')) {
            const f = e.target.closest('form'); if (f) calc(f);
        }
    });
    document.body.addEventListener('click', function (e) {
        const ba = e.target.closest('.add-assignment');
        if (ba) {
            e.preventDefault();
            const box = ba.closest('.mb-3').querySelector('.assignments-container');
            const n = box.querySelectorAll('.assignment-item').length + 1;
            const d = document.createElement('div');
            d.className = 'col-md-4 assignment-item';
            d.innerHTML = '<div class="input-group"><span class="input-group-text">A' + n + '</span><input type="number" step="0.01" name="assignments[]" class="form-control mark-input"><button type="button" class="btn btn-outline-danger remove-item"><i class="bi bi-trash"></i></button></div>';
            box.appendChild(d);
        }
        const be = e.target.closest('.add-exam');
        if (be) {
            e.preventDefault();
            const box = be.closest('.mb-3').querySelector('.exams-container');
            const n = box.querySelectorAll('.exam-item').length + 1;
            const d = document.createElement('div');
            d.className = 'col-md-4 exam-item';
            d.innerHTML = '<div class="input-group"><span class="input-group-text">E' + n + '</span><input type="number" step="0.01" name="exams[]" class="form-control mark-input"><button type="button" class="btn btn-outline-danger remove-item"><i class="bi bi-trash"></i></button></div>';
            box.appendChild(d);
        }
        const rm = e.target.closest('.remove-item');
        if (rm) {
            e.preventDefault();
            const item = rm.closest('.assignment-item, .exam-item');
            const box = item.parentElement;
            const f = item.closest('form');
            item.remove();
            box.querySelectorAll('.assignment-item, .exam-item').forEach(function (it, i) {
                it.querySelector('.input-group-text').textContent = (box.classList.contains('assignments-container') ? 'A' : 'E') + (i + 1);
            });
            if (f) calc(f);
        }
    });
    document.querySelectorAll('.result-form').forEach(calc);
});
</script>
<?php require_once __DIR__ . '/../includes/footer.php'; ?>