<?php
require_once __DIR__ . '/../includes/auth.php';
requireLogin();
$pageTitle = 'Results';
$conn = getConnection();

/**
 * Lightweight multi-sheet XLSX reader (no Composer needed)
 * Returns: [ 'SheetName' => [ [col0, col1, ...], ... ], ... ]
 */
function readXlsxAllSheets($path) {
    $result = [];
    if (!class_exists('ZipArchive')) return $result;
    $zip = new ZipArchive();
    if ($zip->open($path) !== true) return $result;

    // Shared strings
    $shared = [];
    $ssXml = $zip->getFromName('xl/sharedStrings.xml');
    if ($ssXml) {
        $sx = @simplexml_load_string($ssXml);
        if ($sx) {
            foreach ($sx->si as $si) {
                if (isset($si->t)) {
                    $shared[] = (string)$si->t;
                } else {
                    $t = '';
                    if (isset($si->r)) {
                        foreach ($si->r as $r) $t .= (string)$r->t;
                    }
                    $shared[] = $t;
                }
            }
        }
    }

    // Workbook sheet names + paths
    $wbXml = $zip->getFromName('xl/workbook.xml');
    $relsXml = $zip->getFromName('xl/_rels/workbook.xml.rels');
    $sheetFiles = [];
    if ($wbXml && $relsXml) {
        $rels = [];
        $rx = @simplexml_load_string($relsXml);
        if ($rx) {
            foreach ($rx->Relationship as $rel) {
                $id = (string)$rel['Id'];
                $target = (string)$rel['Target'];
                if (strpos($target, 'worksheets/') !== false) {
                    $rels[$id] = 'xl/' . ltrim($target, '/');
                }
            }
        }
        $wx = @simplexml_load_string($wbXml);
        if ($wx) {
            $wx->registerXPathNamespace('m', 'http://schemas.openxmlformats.org/spreadsheetml/2006/main');
            $wx->registerXPathNamespace('r', 'http://schemas.openxmlformats.org/officeDocument/2006/relationships');
            $i = 0;
            foreach ($wx->sheets->sheet as $sheet) {
                $name = (string)$sheet['name'];
                $rid = (string)$sheet->attributes('r', true)['id'];
                if (!$rid) {
                    // fallback sheet1, sheet2...
                    $i++;
                    $pathSheet = "xl/worksheets/sheet{$i}.xml";
                } else {
                    $pathSheet = $rels[$rid] ?? null;
                }
                if ($pathSheet) $sheetFiles[$name] = $pathSheet;
            }
        }
    }
    if (empty($sheetFiles)) {
        // fallback
        for ($i = 1; $i <= 20; $i++) {
            $p = "xl/worksheets/sheet{$i}.xml";
            if ($zip->getFromName($p) !== false) $sheetFiles["Sheet$i"] = $p;
        }
    }

    foreach ($sheetFiles as $sheetName => $sheetPath) {
        $xml = $zip->getFromName($sheetPath);
        if (!$xml) continue;
        $sx = @simplexml_load_string($xml);
        if (!$sx) continue;

        $grid = [];
        $maxCol = 0;
        foreach ($sx->sheetData->row as $row) {
            $rIdx = (int)$row['r'] - 1;
            foreach ($row->c as $c) {
                $ref = (string)$c['r'];
                if (!preg_match('/^([A-Z]+)(\d+)$/', $ref, $m)) continue;
                $col = 0;
                foreach (str_split($m[1]) as $ch) $col = $col * 26 + (ord($ch) - 64);
                $col--;
                if ($col > $maxCol) $maxCol = $col;
                $val = '';
                $type = isset($c['t']) ? (string)$c['t'] : '';
                if ($type === 's') {
                    $si = (int)$c->v;
                    $val = $shared[$si] ?? '';
                } elseif ($type === 'inlineStr') {
                    $val = (string)($c->is->t ?? '');
                } else {
                    $val = (string)($c->v ?? '');
                }
                $grid[$rIdx][$col] = $val;
            }
        }
        $rows = [];
        if (!empty($grid)) {
            ksort($grid);
            $maxR = max(array_keys($grid));
            for ($r = 0; $r <= $maxR; $r++) {
                $line = [];
                for ($c = 0; $c <= $maxCol; $c++) {
                    $line[] = isset($grid[$r][$c]) ? $grid[$r][$c] : '';
                }
                $rows[] = $line;
            }
        }
        $result[$sheetName] = $rows;
    }
    $zip->close();
    return $result;
}

function normalizeHeader($h) {
    return strtolower(trim(preg_replace('/\s+/', ' ', (string)$h)));
}

/**
 * Detect column map from a sheet's rows (HDICRA style)
 */
function detectHdicraColumns($rows) {
    $map = ['reg' => null, 'name' => null, 'phone' => null, 'a1' => null, 'a2' => null, 'a3' => null, 'exam' => null, 'header_row' => null];

    for ($r = 0; $r < min(25, count($rows)); $r++) {
        $row = $rows[$r];
        $foundReg = false;
        for ($c = 0; $c < count($row); $c++) {
            $h = normalizeHeader($row[$c]);
            if ($h === '' ) continue;
            if ($h === 'reg no' || $h === 'registration number' || strpos($h, 'reg no') === 0) {
                $map['reg'] = $c; $foundReg = true;
            }
            if ($h === 'student id number' && $map['reg'] === null) {
                // Batch 08 style - may not be real ICRA reg
                $map['reg'] = $c; $foundReg = true;
            }
            if ($h === 'name' || $h === 'full name') $map['name'] = $c;
            if (strpos($h, 'whatsapp') !== false || $h === 'phone') $map['phone'] = $c;
            if (strpos($h, '1st assignment') !== false || strpos($h, '01st assig') !== false) $map['a1'] = $c;
            if (strpos($h, '2nd assignment') !== false || strpos($h, '02nd assig') !== false) $map['a2'] = $c;
            if (strpos($h, '3rd assignment') !== false) $map['a3'] = $c;
            if (strpos($h, 'final exam') !== false) $map['exam'] = $c;
        }
        if ($foundReg && ($map['name'] !== null || $map['a1'] !== null)) {
            $map['header_row'] = $r;
            break;
        }
    }
    return $map;
}

function cellVal($row, $idx) {
    if ($idx === null || $idx < 0 || $idx >= count($row)) return '';
    $v = $row[$idx];
    if ($v === null) return '';
    return trim((string)$v);
}

function parseNum($v) {
    $v = trim((string)$v);
    if ($v === '' || !is_numeric($v)) return null;
    return (float)$v;
}

function ensureCourseAndBatch($conn, $regNo) {
    // ICRA/B01/001 → course ICRA, batch B01
    if (!preg_match('/^([A-Za-z]+)\/([A-Za-z0-9]+)\/(\d+)/', $regNo, $m)) {
        return null;
    }
    $courseCode = strtoupper($m[1]);
    $batchCode = strtoupper($m[2]);

    $course = $conn->query("SELECT id FROM courses WHERE course_code='".$conn->real_escape_string($courseCode)."' LIMIT 1")->fetch_assoc();
    if (!$course) {
        $conn->query("INSERT INTO courses (course_code, course_name, is_active) VALUES ('".$conn->real_escape_string($courseCode)."', '".$conn->real_escape_string($courseCode)." Course', 1)");
        $courseId = $conn->insert_id;
    } else {
        $courseId = (int)$course['id'];
    }

    $batch = $conn->query("SELECT id FROM batches WHERE course_id=$courseId AND batch_code='".$conn->real_escape_string($batchCode)."' LIMIT 1")->fetch_assoc();
    if (!$batch) {
        $conn->query("INSERT INTO batches (course_id, batch_code, batch_name, is_active) VALUES ($courseId, '".$conn->real_escape_string($batchCode)."', '".$conn->real_escape_string($courseCode.' '.$batchCode)."', 1)");
        $batchId = $conn->insert_id;
    } else {
        $batchId = (int)$batch['id'];
    }
    return $batchId;
}

// ===================== UPLOAD HDICRA / EXCEL =====================
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'upload') {
    $admin = getAdminUsername();
    $createMissing = !empty($_POST['create_students']);
    $stats = ['sheets' => 0, 'students_new' => 0, 'students_found' => 0, 'results_new' => 0, 'results_updated' => 0, 'skipped' => 0, 'errors' => []];

    if (!isset($_FILES['excel_file']) || $_FILES['excel_file']['error'] !== UPLOAD_ERR_OK) {
        setFlash('error', 'Please select a valid Excel file.');
        redirect('results.php');
    }

    $file = $_FILES['excel_file'];
    $ext = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));
    $tmp = $file['tmp_name'];

    if (!in_array($ext, ['xlsx', 'xls', 'csv'])) {
        setFlash('error', 'Only .xlsx or .csv allowed.');
        redirect('results.php');
    }

    $allSheets = [];
    if ($ext === 'csv') {
        $rows = [];
        $h = fopen($tmp, 'r');
        $bom = fread($h, 3);
        if ($bom !== "\xEF\xBB\xBF") rewind($h);
        while (($d = fgetcsv($h)) !== false) $rows[] = $d;
        fclose($h);
        $allSheets['CSV'] = $rows;
    } else {
        $allSheets = readXlsxAllSheets($tmp);
        if (empty($allSheets)) {
            setFlash('error', 'Could not read Excel file. Try Save As → CSV UTF-8.');
            redirect('results.php');
        }
    }

    foreach ($allSheets as $sheetName => $rows) {
        if (empty($rows)) continue;
        $map = detectHdicraColumns($rows);
        if ($map['header_row'] === null) {
            // try Batch 08 style: first cell looks like ICRA/Bxx/
            $found = false;
            for ($r = 0; $r < min(20, count($rows)); $r++) {
                $c0 = cellVal($rows[$r], 0);
                if (preg_match('/^[A-Za-z]+\/[A-Za-z0-9]+\/\d+/', $c0)) {
                    $map = ['reg' => 0, 'name' => 2, 'phone' => 4, 'a1' => 70, 'a2' => 71, 'exam' => 72, 'header_row' => $r - 1];
                    if ($map['header_row'] < 0) $map['header_row'] = $r;
                    $found = true;
                    break;
                }
            }
            if (!$found) continue;
        }

        $stats['sheets']++;
        $start = $map['header_row'] + 1;

        for ($r = $start; $r < count($rows); $r++) {
            $row = $rows[$r];
            $regNo = cellVal($row, $map['reg']);

            // Batch 08: header may point to SC-2026-xxx; real reg is often col 0
            if (!preg_match('/^[A-Za-z]+\/[A-Za-z0-9]+\/\d+/', $regNo)) {
                $c0 = cellVal($row, 0);
                if (preg_match('/^[A-Za-z]+\/[A-Za-z0-9]+\/\d+/', $c0)) {
                    $regNo = $c0;
                } else {
                    $c1 = cellVal($row, 1);
                    if (preg_match('/^[A-Za-z]+\/[A-Za-z0-9]+\/\d+/', $c1)) {
                        $regNo = $c1;
                    }
                }
            }

            if (!preg_match('/^[A-Za-z]+\/[A-Za-z0-9]+\/\d+/', $regNo)) {
                continue; // not a student row
            }

            $name  = cellVal($row, $map['name']);
            $phone = cellVal($row, $map['phone']);
            // clean phone (Excel may give float)
            if (is_numeric($phone) && strpos($phone, '.') !== false) $phone = (string)(int)(float)$phone;

            $a1 = parseNum(cellVal($row, $map['a1']));
            $a2 = parseNum(cellVal($row, $map['a2']));
            $a3 = parseNum(cellVal($row, $map['a3']));
            $exam = parseNum(cellVal($row, $map['exam']));

            // Find or create student
            $st = $conn->prepare("SELECT id FROM students WHERE registration_number = ? LIMIT 1");
            $st->bind_param("s", $regNo);
            $st->execute();
            $student = $st->get_result()->fetch_assoc();
            $st->close();

            if (!$student) {
                if (!$createMissing) {
                    $stats['errors'][] = "$sheetName: Student not found $regNo (enable Auto-create)";
                    $stats['skipped']++;
                    continue;
                }
                $batchId = ensureCourseAndBatch($conn, $regNo);
                if (!$batchId) {
                    $stats['errors'][] = "$sheetName: Bad reg format $regNo";
                    $stats['skipped']++;
                    continue;
                }
                if ($name === '') $name = $regNo;
                $ins = $conn->prepare("INSERT INTO students (registration_number, full_name, phone, batch_id, is_active) VALUES (?,?,?,?,1)");
                $ins->bind_param("sssi", $regNo, $name, $phone, $batchId);
                if ($ins->execute()) {
                    $sid = $ins->insert_id;
                    $conn->query("INSERT INTO results (student_id, result_status) VALUES ($sid, 'PENDING')");
                    $conn->query("INSERT INTO payments (student_id, payment_status) VALUES ($sid, 'PENDING')");
                    $conn->query("INSERT INTO certificates (student_id, certificate_status) VALUES ($sid, 'PENDING')");
                    $stats['students_new']++;
                } else {
                    $stats['errors'][] = "$sheetName: Cannot create $regNo — ".$ins->error;
                    $stats['skipped']++;
                    $ins->close();
                    continue;
                }
                $ins->close();
            } else {
                $sid = (int)$student['id'];
                $stats['students_found']++;
                // optional update name/phone if empty in DB
                if ($name !== '' || $phone !== '') {
                    $conn->query("UPDATE students SET full_name=IF(full_name='' OR full_name IS NULL, '".$conn->real_escape_string($name)."', full_name), phone=IF(phone='' OR phone IS NULL, '".$conn->real_escape_string($phone)."', phone) WHERE id=$sid");
                }
            }

            // Build marks
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

            $status = 'PENDING';
            if ($full !== null && $full > 0) $status = 'PRESENT';

            $a1s = isset($assignments[0]) ? (string)$assignments[0] : null;
            $a2s = isset($assignments[1]) ? (string)$assignments[1] : null;
            $a3s = isset($assignments[2]) ? (string)$assignments[2] : null;
            $fes = isset($exams[0]) ? (string)$exams[0] : null;
            $fulls = $full !== null ? (string)$full : null;
            $pcts = $pct !== null ? (string)round($pct, 2) : null;
            $aj = json_encode($assignments);
            $ej = json_encode($exams);

            $hasJson = ($conn->query("SHOW COLUMNS FROM results LIKE 'assignments_json'")->num_rows > 0);
            $exists = $conn->query("SELECT id FROM results WHERE student_id=$sid")->fetch_assoc();

            if ($exists) {
                // Only overwrite marks if Excel has at least one mark, else keep existing and just touch status if needed
                if ($full === null && empty($assignments) && empty($exams)) {
                    // no marks in excel — skip result update
                    continue;
                }
                if ($hasJson) {
                    $stmt = $conn->prepare("UPDATE results SET assignment1=?, assignment2=?, assignment3=?, final_exam=?, full_marks=?, marks_percentage=?, result_status=?, entered_by=?, assignments_json=?, exams_json=? WHERE student_id=?");
                    $stmt->bind_param("ssssssssssi", $a1s,$a2s,$a3s,$fes,$fulls,$pcts,$status,$admin,$aj,$ej,$sid);
                } else {
                    $stmt = $conn->prepare("UPDATE results SET assignment1=?, assignment2=?, assignment3=?, final_exam=?, full_marks=?, marks_percentage=?, result_status=?, entered_by=? WHERE student_id=?");
                    $stmt->bind_param("ssssssssi", $a1s,$a2s,$a3s,$fes,$fulls,$pcts,$status,$admin,$sid);
                }
                if ($stmt->execute()) $stats['results_updated']++;
                else $stats['errors'][] = "$regNo update fail: ".$stmt->error;
                $stmt->close();
            } else {
                if ($hasJson) {
                    $stmt = $conn->prepare("INSERT INTO results (student_id, assignment1, assignment2, assignment3, final_exam, full_marks, marks_percentage, result_status, entered_by, assignments_json, exams_json) VALUES (?,?,?,?,?,?,?,?,?,?,?)");
                    $stmt->bind_param("issssssssss", $sid,$a1s,$a2s,$a3s,$fes,$fulls,$pcts,$status,$admin,$aj,$ej);
                } else {
                    $stmt = $conn->prepare("INSERT INTO results (student_id, assignment1, assignment2, assignment3, final_exam, full_marks, marks_percentage, result_status, entered_by) VALUES (?,?,?,?,?,?,?,?,?)");
                    $stmt->bind_param("issssssss", $sid,$a1s,$a2s,$a3s,$fes,$fulls,$pcts,$status,$admin);
                }
                if ($stmt->execute()) $stats['results_new']++;
                else $stats['errors'][] = "$regNo insert fail: ".$stmt->error;
                $stmt->close();
            }
        }
    }

    logActivity($admin, 'HDICRA_UPLOAD', json_encode($stats));
    $msg = "Sheets: {$stats['sheets']} | Students new: {$stats['students_new']} | Found: {$stats['students_found']} | Results new: {$stats['results_new']} | Updated: {$stats['results_updated']} | Skipped: {$stats['skipped']}";
    if ($stats['errors']) {
        $_SESSION['upload_errors'] = array_slice($stats['errors'], 0, 20);
        $msg .= " | Errors: ".count($stats['errors']);
    }
    setFlash('success', $msg);
    redirect('results.php');
}

// ===================== SINGLE UPDATE (same as before) =====================
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'update') {
    $student_id = (int)$_POST['student_id'];
    $assignments = [];
    if (!empty($_POST['assignments']) && is_array($_POST['assignments'])) {
        foreach ($_POST['assignments'] as $val) {
            if ($val !== '' && is_numeric($val)) $assignments[] = (float)$val;
        }
    }
    $exams = [];
    if (!empty($_POST['exams']) && is_array($_POST['exams'])) {
        foreach ($_POST['exams'] as $val) {
            if ($val !== '' && is_numeric($val)) $exams[] = (float)$val;
        }
    }
    $full = array_sum($assignments) + array_sum($exams);
    if ($full == 0) $full = null;
    $allMarks = array_merge($assignments, $exams);
    $pct = null;
    if (isset($_POST['marks_percentage']) && $_POST['marks_percentage'] !== '') {
        $pct = (float)$_POST['marks_percentage'];
    } elseif (count($allMarks) > 0) {
        $pct = array_sum($allMarks) / count($allMarks);
    }
    $status = $_POST['result_status'] ?? 'PENDING';
    $remarks = trim($_POST['remarks'] ?? '');
    $admin = getAdminUsername();
    $aj = json_encode($assignments);
    $ej = json_encode($exams);
    $a1 = isset($assignments[0]) ? (string)$assignments[0] : null;
    $a2 = isset($assignments[1]) ? (string)$assignments[1] : null;
    $a3 = isset($assignments[2]) ? (string)$assignments[2] : null;
    $fe = isset($exams[0]) ? (string)$exams[0] : null;
    $full_s = $full !== null ? (string)$full : null;
    $pct_s = $pct !== null ? (string)$pct : null;
    $hasJson = ($conn->query("SHOW COLUMNS FROM results LIKE 'assignments_json'")->num_rows > 0);
    $check = $conn->query("SELECT id FROM results WHERE student_id=$student_id");
    if ($check && $check->num_rows > 0) {
        if ($hasJson) {
            $stmt = $conn->prepare("UPDATE results SET assignment1=?,assignment2=?,assignment3=?,final_exam=?,full_marks=?,marks_percentage=?,result_status=?,remarks=?,entered_by=?,assignments_json=?,exams_json=? WHERE student_id=?");
            $stmt->bind_param("sssssssssssi",$a1,$a2,$a3,$fe,$full_s,$pct_s,$status,$remarks,$admin,$aj,$ej,$student_id);
        } else {
            $stmt = $conn->prepare("UPDATE results SET assignment1=?,assignment2=?,assignment3=?,final_exam=?,full_marks=?,marks_percentage=?,result_status=?,remarks=?,entered_by=? WHERE student_id=?");
            $stmt->bind_param("sssssssssi",$a1,$a2,$a3,$fe,$full_s,$pct_s,$status,$remarks,$admin,$student_id);
        }
    } else {
        if ($hasJson) {
            $stmt = $conn->prepare("INSERT INTO results (student_id,assignment1,assignment2,assignment3,final_exam,full_marks,marks_percentage,result_status,remarks,entered_by,assignments_json,exams_json) VALUES (?,?,?,?,?,?,?,?,?,?,?,?)");
            $stmt->bind_param("isssssssssss",$student_id,$a1,$a2,$a3,$fe,$full_s,$pct_s,$status,$remarks,$admin,$aj,$ej);
        } else {
            $stmt = $conn->prepare("INSERT INTO results (student_id,assignment1,assignment2,assignment3,final_exam,full_marks,marks_percentage,result_status,remarks,entered_by) VALUES (?,?,?,?,?,?,?,?,?,?)");
            $stmt->bind_param("isssssssss",$student_id,$a1,$a2,$a3,$fe,$full_s,$pct_s,$status,$remarks,$admin);
        }
    }
    if ($stmt->execute()) {
        $pay = $conn->query("SELECT payment_status FROM payments WHERE student_id=$student_id")->fetch_assoc();
        $payOk = $pay && in_array($pay['payment_status'], ['PAID','COMPLETED','ONE_TIME','CONVA_PAY']);
        $passOk = ($status==='PRESENT') && (($full!==null && $full>=40) || ($pct!==null && $pct>=40));
        if ($status==='ABSENT' || $status==='NOT_ELIGIBLE') {
            $conn->query("UPDATE certificates SET certificate_status='NOT_ELIGIBLE' WHERE student_id=$student_id");
        } elseif ($passOk && $payOk) {
            $conn->query("UPDATE certificates SET certificate_status='READY' WHERE student_id=$student_id");
        } else {
            $conn->query("UPDATE certificates SET certificate_status='PENDING' WHERE student_id=$student_id");
        }
        setFlash('success', 'Result saved.');
    } else setFlash('error', $stmt->error);
    $stmt->close();
    redirect('results.php?'.http_build_query(array_filter(['course_id'=>$_POST['redirect_course']??'','batch_id'=>$_POST['redirect_batch']??'','q'=>$_POST['redirect_q']??''])));
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

$hasJson = ($conn->query("SHOW COLUMNS FROM results LIKE 'assignments_json'")->num_rows > 0);
$sql = "SELECT s.id AS student_id, s.registration_number, s.full_name, b.batch_code, c.course_code, c.course_name,
        r.assignment1, r.assignment2, r.assignment3, r.final_exam, r.full_marks, r.marks_percentage, r.result_status, r.remarks";
if ($hasJson) $sql .= ", r.assignments_json, r.exams_json";
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
                <small class="text-muted">Reads <strong>all sheets</strong> (Batch 01 … Batch 08). Columns: Reg No, Name, 1st/2nd Assignment, Final Exam.</small>
            </div>
            <div class="col-md-4">
                <div class="form-check mt-4">
                    <input class="form-check-input" type="checkbox" name="create_students" value="1" id="createStudents" checked>
                    <label class="form-check-label" for="createStudents">
                        <strong>Auto-create students</strong> if Reg No not in system<br>
                        <small class="text-muted">(also creates ICRA course + batches)</small>
                    </label>
                </div>
            </div>
            <div class="col-md-3">
                <button type="submit" class="btn btn-success w-100"><i class="bi bi-upload me-1"></i> Upload &amp; Import</button>
            </div>
        </form>
        <?php if (!empty($_SESSION['upload_errors'])): ?>
        <div class="alert alert-warning mt-3 mb-0">
            <strong>Notes / skipped rows:</strong>
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
                else { foreach(['assignment1','assignment2','assignment3'] as $k) if(isset($row[$k])&&$row[$k]!==null&&$row[$k]!=='') $asgList[]=$row[$k]; }
                if (!empty($row['exams_json'])) { $d=json_decode($row['exams_json'],true); if(is_array($d))$examList=$d; }
                elseif(isset($row['final_exam'])&&$row['final_exam']!==null&&$row['final_exam']!=='') $examList[]=$row['final_exam'];
            ?>
                <tr>
                    <td><code><?php echo sanitize($row['registration_number']); ?></code></td>
                    <td><?php echo sanitize($row['full_name']); ?></td>
                    <td><small><?php echo sanitize($row['course_code']); ?></small> <span class="badge bg-secondary"><?php echo sanitize($row['batch_code']); ?></span></td>
                    <td><?php if($asgList): foreach($asgList as $i=>$m): ?><span class="badge bg-info text-dark me-1">A<?php echo $i+1; ?>: <?php echo $m; ?></span><?php endforeach; else: echo '—'; endif; ?></td>
                    <td><?php if($examList): foreach($examList as $i=>$m): ?><span class="badge bg-warning text-dark me-1">E<?php echo $i+1; ?>: <?php echo $m; ?></span><?php endforeach; else: echo '—'; endif; ?></td>
                    <td><strong><?php echo $row['full_marks']!==null?$row['full_marks']:'—'; ?></strong></td>
                    <td><?php $all=array_merge($asgList,$examList); echo count($all)?number_format(array_sum($all)/count($all),2).'%':($row['marks_percentage']!==null?number_format((float)$row['marks_percentage'],2).'%':'—'); ?></td>
                    <td><?php echo $row['result_status']?statusBadge($row['result_status']):statusBadge('PENDING'); ?></td>
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
    else { foreach(['assignment1','assignment2','assignment3'] as $k) if(isset($row[$k])&&$row[$k]!==null&&$row[$k]!=='') $asgList[]=$row[$k]; }
    if (!empty($row['exams_json'])) { $d=json_decode($row['exams_json'],true); if(is_array($d))$examList=$d; }
    elseif(isset($row['final_exam'])&&$row['final_exam']!==null&&$row['final_exam']!=='') $examList[]=$row['final_exam'];
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
                        <?php $n=max(count($asgList),1); for($i=0;$i<$n;$i++): ?>
                        <div class="col-md-4 assignment-item"><div class="input-group"><span class="input-group-text">A<?php echo $i+1; ?></span><input type="number" step="0.01" name="assignments[]" class="form-control mark-input" value="<?php echo $asgList[$i]??''; ?>"><button type="button" class="btn btn-outline-danger remove-item" <?php echo $n<=1?'disabled':''; ?>><i class="bi bi-trash"></i></button></div></div>
                        <?php endfor; ?>
                    </div>
                </div>
                <div class="mb-3">
                    <div class="d-flex justify-content-between mb-2"><label class="fw-bold mb-0">Exams</label><button type="button" class="btn btn-sm btn-outline-success add-exam"><i class="bi bi-plus"></i></button></div>
                    <div class="exams-container row g-2">
                        <?php $n=max(count($examList),1); for($i=0;$i<$n;$i++): ?>
                        <div class="col-md-4 exam-item"><div class="input-group"><span class="input-group-text">E<?php echo $i+1; ?></span><input type="number" step="0.01" name="exams[]" class="form-control mark-input" value="<?php echo $examList[$i]??''; ?>"><button type="button" class="btn btn-outline-danger remove-item" <?php echo $n<=1?'disabled':''; ?>><i class="bi bi-trash"></i></button></div></div>
                        <?php endfor; ?>
                    </div>
                </div>
                <div class="row g-3">
                    <div class="col-md-4"><label class="form-label">Full (auto)</label><input type="number" step="0.01" class="form-control full-marks-input" value="<?php echo $row['full_marks']??''; ?>" readonly></div>
                    <div class="col-md-4"><label class="form-label">%</label><input type="number" step="0.01" name="marks_percentage" class="form-control percentage-input" value="<?php echo $row['marks_percentage']??''; ?>"></div>
                    <div class="col-md-4"><label class="form-label">Status</label>
                        <select name="result_status" class="form-select" required>
                            <?php foreach(['PENDING','PRESENT','ABSENT','NOT_ELIGIBLE'] as $st): ?>
                            <option value="<?php echo $st; ?>" <?php echo ($row['result_status']??'')===$st?'selected':''; ?>><?php echo $st; ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="col-12"><label class="form-label">Remarks</label><textarea name="remarks" class="form-control" rows="2"><?php echo sanitize($row['remarks']??''); ?></textarea></div>
                </div>
            </div>
            <div class="modal-footer"><button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button><button type="submit" class="btn btn-primary">Save</button></div>
        </form>
    </div></div>
</div>
<?php endforeach; ?>

<script>
document.addEventListener('DOMContentLoaded',function(){
 function calc(f){let t=0,c=0;f.querySelectorAll('.mark-input').forEach(i=>{const v=parseFloat(i.value);if(!isNaN(v)){t+=v;c++;}});const a=f.querySelector('.full-marks-input'),p=f.querySelector('.percentage-input');if(a)a.value=t>0?t.toFixed(2):'';if(p)p.value=c>0?(t/c).toFixed(2):'';}
 document.body.addEventListener('input',e=>{if(e.target.classList.contains('mark-input')){const f=e.target.closest('form');if(f)calc(f);}});
 document.body.addEventListener('click',e=>{
  const ba=e.target.closest('.add-assignment');if(ba){e.preventDefault();const box=ba.closest('.mb-3').querySelector('.assignments-container');const n=box.querySelectorAll('.assignment-item').length+1;const d=document.createElement('div');d.className='col-md-4 assignment-item';d.innerHTML=`<div class="input-group"><span class="input-group-text">A${n}</span><input type="number" step="0.01" name="assignments[]" class="form-control mark-input"><button type="button" class="btn btn-outline-danger remove-item"><i class="bi bi-trash"></i></button></div>`;box.appendChild(d);}
  const be=e.target.closest('.add-exam');if(be){e.preventDefault();const box=be.closest('.mb-3').querySelector('.exams-container');const n=box.querySelectorAll('.exam-item').length+1;const d=document.createElement('div');d.className='col-md-4 exam-item';d.innerHTML=`<div class="input-group"><span class="input-group-text">E${n}</span><input type="number" step="0.01" name="exams[]" class="form-control mark-input"><button type="button" class="btn btn-outline-danger remove-item"><i class="bi bi-trash"></i></button></div>`;box.appendChild(d);}
  const rm=e.target.closest('.remove-item');if(rm){e.preventDefault();const item=rm.closest('.assignment-item,.exam-item');const box=item.parentElement;const f=item.closest('form');item.remove();box.querySelectorAll('.assignment-item,.exam-item').forEach((it,i)=>{it.querySelector('.input-group-text').textContent=(box.classList.contains('assignments-container')?'A':'E')+(i+1);});if(f)calc(f);}
 });
 document.querySelectorAll('.result-form').forEach(calc);
});
</script>
<?php require_once __DIR__ . '/../includes/footer.php'; ?>