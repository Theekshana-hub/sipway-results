<?php
/**
 * includes/payment_import.php
 * ---------------------------------------------------------------
 * Shared payment-Excel importer.
 * Used by BOTH  results.php (HDICRA upload)  and  payments.php.
 *
 * Reads, for every "Batch" sheet of HDICRA.xlsx:
 *   - Registration Fee
 *   - 1st ... 6th Installment amounts (+ "PAID" markers)
 *   - Course Fee / Offer (One time, Two time, Offer(xxxx)) / Course Fee Status
 *   - payment dates
 * and saves them into the `payments` table.
 * ---------------------------------------------------------------
 */

if (!defined('PAY_FULL_STATUS')) {
    define('PAY_FULL_STATUS',    'COMPLETED');
    define('PAY_ONETIME_STATUS', 'ONE_TIME');
    define('PAY_PARTIAL_STATUS', 'PENDING');
    define('PAY_NONE_STATUS',    'NOT_PAID');
    define('DEFAULT_FEE_OLD',    30000);   // Batch 1-3
    define('DEFAULT_FEE_NEW',    32000);   // Batch 4+
    define('ONE_TIME_FEE',       25000);
    define('TWO_TIME_FEE',       28000);
    define('MAX_SINGLE_PAYMENT', 100000);
}

/* ===================== DB structure ===================== */
function payColExists($conn, $col) {
    $r = @$conn->query("SHOW COLUMNS FROM payments LIKE '" . $conn->real_escape_string($col) . "'");
    return ($r && $r->num_rows > 0);
}

function ensurePaymentsSchema($conn) {
    try {
        foreach ([
            'reg_fee'           => 'DECIMAL(10,2) NULL',
            'course_paid'       => 'DECIMAL(10,2) NULL',
            'course_due'        => 'DECIMAL(10,2) NULL',
            'balance'           => 'DECIMAL(10,2) NULL',
            'installments_json' => 'TEXT NULL',
        ] as $c => $def) {
            if (!payColExists($conn, $c)) @$conn->query("ALTER TABLE payments ADD COLUMN $c $def");
        }
        // payment_status enum must contain every status we use
        $need = ['PENDING','PAID','COMPLETED','ONE_TIME','CONVA_PAY','NOT_PAID'];
        $r = $conn->query("SHOW COLUMNS FROM payments LIKE 'payment_status'");
        if ($r && ($row = $r->fetch_assoc())) {
            $type = $row['Type'];
            if (stripos($type, 'enum(') === 0) {
                preg_match_all("/'((?:[^']|'')*)'/", $type, $m);
                $have = array_map(function ($v) { return str_replace("''", "'", $v); }, $m[1]);
                $missing = array_diff($need, $have);
                if ($missing) {
                    $all = array_values(array_unique(array_merge($have, $need)));
                    $list = implode(',', array_map(function ($v) use ($conn) {
                        return "'" . $conn->real_escape_string($v) . "'";
                    }, $all));
                    @$conn->query("ALTER TABLE payments MODIFY COLUMN payment_status ENUM($list) NULL DEFAULT 'PENDING'");
                }
            }
        }
    } catch (Throwable $e) { /* ignore */ }
}

/* ===================== small helpers ===================== */
function payNorm($v) { return strtolower(trim(preg_replace('/\s+/', ' ', (string)$v))); }
function payCell($row, $i) { return ($i === null || !isset($row[$i])) ? '' : trim((string)$row[$i]); }
function payIsReg($v) { return (bool)preg_match('/^[A-Za-z]+\/[A-Za-z0-9]+\/\d+/', trim((string)$v)); }
function moneyFmt($n) { return number_format((float)$n, 0, '.', ','); }

function payStatusSafe($s) {
    $ok = ['PENDING','PAID','COMPLETED','ONE_TIME','CONVA_PAY','NOT_PAID'];
    $s = strtoupper(trim((string)$s));
    return in_array($s, $ok, true) ? $s : 'PENDING';
}

function parsePayDate($v) {
    $v = trim((string)$v);
    if ($v === '') return null;
    if (is_numeric($v)) {
        $f = (float)$v;
        return ($f > 30000 && $f < 80000) ? gmdate('Y-m-d', (int)(($f - 25569) * 86400)) : null;
    }
    $v = preg_replace('/\s+/', '', $v);           // "2025/11/ 17" -> "2025/11/17"
    $t = strtotime(str_replace('/', '-', $v));
    return ($t && $t > strtotime('2020-01-01') && $t < strtotime('2035-01-01')) ? date('Y-m-d', $t) : null;
}

function payNum($v) {
    $v = trim((string)$v);
    if ($v === '') return null;
    $v = str_replace([',', ' '], '', $v);
    if ($v === '' || !is_numeric($v)) return null;
    $f = (float)$v;
    return ($f > 0 && $f <= MAX_SINGLE_PAYMENT) ? $f : null;
}

function manualNotesPart($existing) {
    $existing = (string)$existing;
    if ($existing === '') return '';
    if (strpos($existing, '[Excel]') === 0 || strpos($existing, '[CSV]') === 0) {
        $p = strpos($existing, ' ## ');
        return $p === false ? '' : substr($existing, $p + 4);
    }
    return $existing;
}

function parseInstJson($json) {
    if (!$json) return [];
    $d = json_decode($json, true);
    return is_array($d) ? $d : [];
}

/* ===================== XLSX reader (exact columns) ===================== */
function payXlsxColIndex($ref) {
    $letters = preg_replace('/[^A-Z]/', '', strtoupper($ref));
    $n = 0;
    for ($i = 0; $i < strlen($letters); $i++) $n = $n * 26 + (ord($letters[$i]) - 64);
    return $n - 1;
}

/** returns [sheetName => rows[][]]  (A=0, B=1 ...) */
function payReadXlsx($path) {
    $out = [];
    if (!class_exists('ZipArchive')) return $out;
    $zip = new ZipArchive();
    if ($zip->open($path) !== true) return $out;

    $shared = [];
    $ssXml = $zip->getFromName('xl/sharedStrings.xml');
    if ($ssXml !== false) {
        $x = @simplexml_load_string($ssXml);
        if ($x) foreach ($x->si as $si) {
            if (isset($si->t)) $shared[] = (string)$si->t;
            else { $t = ''; foreach ($si->r as $r) $t .= (string)$r->t; $shared[] = $t; }
        }
    }
    $wb   = @simplexml_load_string($zip->getFromName('xl/workbook.xml') ?: '');
    $rels = @simplexml_load_string($zip->getFromName('xl/_rels/workbook.xml.rels') ?: '');
    if (!$wb || !$rels) { $zip->close(); return $out; }
    $relMap = [];
    foreach ($rels->Relationship as $rel) $relMap[(string)$rel['Id']] = (string)$rel['Target'];

    foreach ($wb->sheets->sheet as $sh) {
        $name   = (string)$sh['name'];
        $rid    = (string)$sh->attributes('http://schemas.openxmlformats.org/officeDocument/2006/relationships')['id'];
        $target = $relMap[$rid] ?? '';
        if ($target === '') continue;
        $target = ltrim($target, '/');
        $file   = (strpos($target, 'xl/') === 0) ? $target : 'xl/' . $target;
        $xml    = $zip->getFromName($file);
        if ($xml === false) continue;
        $ws = @simplexml_load_string($xml);
        if (!$ws || !isset($ws->sheetData)) continue;

        $rows = [];
        foreach ($ws->sheetData->row as $row) {
            $rIdx  = (int)$row['r'] - 1;
            $cells = [];
            $maxC  = -1;
            foreach ($row->c as $c) {
                $ci = payXlsxColIndex((string)$c['r']);
                $t  = (string)$c['t'];
                $v  = '';
                if ($t === 's') { $v = $shared[(int)$c->v] ?? ''; }
                elseif ($t === 'inlineStr') {
                    $v = isset($c->is->t) ? (string)$c->is->t : '';
                    if ($v === '' && isset($c->is->r)) foreach ($c->is->r as $r) $v .= (string)$r->t;
                }
                else { $v = isset($c->v) ? (string)$c->v : ''; }
                $cells[$ci] = $v;
                if ($ci > $maxC) $maxC = $ci;
            }
            $dense = $maxC >= 0 ? array_fill(0, $maxC + 1, '') : [];
            foreach ($cells as $ci => $v) $dense[$ci] = $v;
            $rows[$rIdx] = $dense;
        }
        if ($rows) {
            $maxR = max(array_keys($rows));
            $full = [];
            for ($i = 0; $i <= $maxR; $i++) $full[$i] = $rows[$i] ?? [];
            $out[$name] = $full;
        }
    }
    $zip->close();
    return $out;
}

/* ===================== fee / status logic ===================== */
/** due amount + plan from "Offer" text and "Course Fee" cell */
function resolveDueAndPlan($reg, $courseFeeCell, $offerText) {
    $batchNo = preg_match('/^[A-Za-z]+\/[A-Za-z]*0*(\d+)\//', $reg, $bm) ? (int)$bm[1] : 99;
    $due = ($batchNo <= 3) ? DEFAULT_FEE_OLD : DEFAULT_FEE_NEW;
    $cf = payNum($courseFeeCell);
    if ($cf !== null && $cf >= 5000) $due = $cf;

    $plan = '';
    $off = strtolower(trim((string)$offerText));
    if ($off !== '' && $off !== 'null') {
        if (preg_match('/one\s*time/', $off)) {
            $plan = 'One time';
            $due = preg_match('/(\d{4,6})/', $off, $nm) ? (float)$nm[1] : ONE_TIME_FEE;
        } elseif (preg_match('/tow|two/', $off)) {
            $plan = 'Two time';
            $due = TWO_TIME_FEE;
        } elseif (preg_match('/offer\s*\(?\s*(\d{4,6})/', $off, $nm)) {
            $plan = 'Offer';
            $due = (float)$nm[1];
        }
    }
    return [$due, $plan];
}

function decidePayStatus($coursePaid, $due, $plan, $paidFlag, $excelStatusText) {
    $done = ($coursePaid + 0.01 >= $due);
    $txt = strtolower(trim((string)$excelStatusText));
    if (!$done && ($paidFlag || (strpos($txt, 'compl') !== false && strpos($txt, 'not') === false))) $done = true;
    if ($done) return ($plan === 'One time') ? PAY_ONETIME_STATUS : PAY_FULL_STATUS;
    if ($coursePaid > 0) return PAY_PARTIAL_STATUS;
    return PAY_NONE_STATUS;
}

/* ===================== sheet layout detection ===================== */
/**
 * Works for every batch layout:
 *  - header "Reg No" (Batch 1-5)  or  "STUDENT ID Number" (Batch 6-8)
 *  - row below header holds Amount / Bank / Ref No / Date ...
 *  - every payment amount column = the column just before a "Bank" column
 */
function detectPaymentLayout($rows) {
    $hr = null;
    for ($r = 0; $r < min(30, count($rows)); $r++) {
        for ($c = 0; $c < min(20, count($rows[$r] ?? [])); $c++) {
            $h = payNorm($rows[$r][$c] ?? '');
            if ($h === 'reg no' || $h === 'student id number' || strpos($h, 'reg no') === 0) { $hr = $r; break 2; }
        }
    }
    if ($hr === null || !isset($rows[$hr + 1])) return null;

    $rowA = $rows[$hr];
    $rowB = $rows[$hr + 1];
    $maxC = max(count($rowA), count($rowB), 110);
    for ($c = 0; $c < $maxC; $c++) {
        if (!isset($rowA[$c])) $rowA[$c] = '';
        if (!isset($rowB[$c])) $rowB[$c] = '';
    }

    // stop columns: "Course Fee Status", "Exam fee", "Batch transfer", "1st Assignment"
    $stop = $maxC;
    for ($c = 0; $c < $maxC; $c++) {
        $ha = payNorm($rowA[$c]);
        $hb = payNorm($rowB[$c]);
        if (strpos($ha, 'course fee statu') !== false || strpos($ha, 'exam fee') !== false ||
            strpos($ha, 'batch transfer') !== false || strpos($hb, 'batch transfer') !== false ||
            strpos($ha, '1st assignment') !== false) {
            $stop = min($stop, $c);
        }
    }

    $amountCols = [];
    for ($c = 0; $c < $stop - 1; $c++) {
        if (payNorm($rowB[$c + 1]) === 'bank') $amountCols[] = $c;
    }

    $regFee = null;
    for ($c = 0; $c < $maxC; $c++) {
        $ha = payNorm($rowA[$c]);
        if (strpos($ha, 'registration fee') !== false || $ha === 'reg fee') {
            foreach ($amountCols as $ac) { if ($ac >= $c && $ac <= $c + 2) { $regFee = $ac; break; } }
            break;
        }
    }
    if ($regFee === null && !empty($amountCols)) $regFee = $amountCols[0];

    $inst = array_values(array_filter($amountCols, function ($x) use ($regFee) { return $x !== $regFee; }));
    $inst = array_slice($inst, 0, 6);

    $courseCol = null; $offerCol = null; $statusCol = null;
    for ($c = 0; $c < $maxC; $c++) {
        $h = payNorm($rowA[$c]);
        if ($h === 'course fee' && $courseCol === null) $courseCol = $c;
        if ($h === 'offer' && $offerCol === null) $offerCol = $c;
        if (strpos($h, 'course fee statu') !== false && $statusCol === null) $statusCol = $c;
    }
    if ($regFee === null && empty($inst)) return null;
    return ['header_row' => $hr, 'regfee' => $regFee, 'inst' => $inst,
            'course' => $courseCol, 'offer' => $offerCol, 'status' => $statusCol];
}

/* ===================== extract rows from workbook (no DB) ===================== */
/** returns list of raw payment records, one per student row */
function payExtractRecords($path, &$notes) {
    $records = [];
    $allSheets = payReadXlsx($path);
    if (empty($allSheets)) { $notes[] = 'Payments: could not read Excel file'; return $records; }

    foreach ($allSheets as $sheetName => $rows) {
        if (empty($rows) || count($rows) < 5) continue;
        $lay = detectPaymentLayout($rows);
        if ($lay === null) { $notes[] = "$sheetName: payment columns not found — skipped"; continue; }
        $notes[] = "$sheetName: header row " . ($lay['header_row'] + 1)
                 . " | regFee col " . var_export($lay['regfee'], true)
                 . " | installment cols " . json_encode($lay['inst']);

        for ($r = $lay['header_row'] + 2; $r < count($rows); $r++) {
            $row = $rows[$r];
            $reg = '';
            foreach ([0, 1, 2] as $ci) {
                $v = trim((string)payCell($row, $ci));
                if (payIsReg($v)) { $reg = $v; break; }
            }
            if ($reg === '') continue;

            $dates = []; $regFee = 0.0;
            if ($lay['regfee'] !== null) {
                $a = payNum(payCell($row, $lay['regfee']));
                if ($a !== null) {
                    $regFee = $a;
                    $d = parsePayDate(payCell($row, $lay['regfee'] + 3));
                    if ($d) $dates[] = $d;
                }
            }

            $inst = []; $paidFlag = false;
            foreach ($lay['inst'] as $k => $ci) {
                $raw = payCell($row, $ci);
                $a   = payNum($raw);
                $num = $k + 1;
                if ($a !== null) {
                    $inst[$num] = $a;
                    $d = parsePayDate(payCell($row, $ci + 3));
                    if ($d) $dates[] = $d;
                } elseif (strtoupper(trim((string)$raw)) === 'PAID') {
                    $inst[$num] = 'PAID';
                    $paidFlag = true;
                }
            }

            $records[] = [
                'sheet'   => $sheetName,
                'reg'     => $reg,
                'regFee'  => $regFee,
                'inst'    => $inst,
                'paid'    => $paidFlag,
                'dates'   => $dates,
                'course'  => $lay['course'] !== null ? payCell($row, $lay['course']) : '',
                'offer'   => $lay['offer']  !== null ? payCell($row, $lay['offer'])  : '',
                'status'  => $lay['status'] !== null ? payCell($row, $lay['status']) : '',
            ];
        }
    }
    return $records;
}

/** turn one raw record into the values that go into the DB (no DB access) */
function payBuildRecord($rec, $tag = 'Excel') {
    $coursePaid = 0.0;
    foreach ($rec['inst'] as $a) if (is_numeric($a)) $coursePaid += (float)$a;

    if ($rec['regFee'] == 0 && $coursePaid == 0 && !$rec['paid']) return null;   // nothing in excel

    list($due, $plan) = resolveDueAndPlan($rec['reg'], $rec['course'], $rec['offer']);
    $status  = decidePayStatus($coursePaid, $due, $plan, $rec['paid'], $rec['status']);
    $balance = in_array($status, [PAY_FULL_STATUS, PAY_ONETIME_STATUS]) ? 0.0 : max(0, $due - $coursePaid);
    $total   = $rec['regFee'] + $coursePaid;
    $last    = $rec['dates'] ? max($rec['dates']) : null;

    $parts = [];
    if ($rec['regFee'] > 0) $parts[] = 'Reg fee ' . moneyFmt($rec['regFee']);
    $parts[] = 'Course ' . moneyFmt($coursePaid) . ' / ' . moneyFmt($due) . ($plan ? " ($plan)" : '');
    if ($balance > 0) $parts[] = 'Balance ' . moneyFmt($balance);
    foreach ($rec['inst'] as $n => $a) $parts[] = 'Inst' . $n . ':' . (is_numeric($a) ? moneyFmt($a) : $a);

    $inst = $rec['inst'];
    ksort($inst);
    return [
        'reg'         => $rec['reg'],
        'status'      => $status,
        'reg_fee'     => (float)$rec['regFee'],
        'course_paid' => (float)$coursePaid,
        'course_due'  => (float)$due,
        'balance'     => (float)$balance,
        'amount'      => (float)$total,
        'date'        => $last,
        'excel_note'  => "[$tag] " . implode(' | ', $parts),
        'inst_json'   => empty($inst) ? '{}' : json_encode($inst, JSON_FORCE_OBJECT),
    ];
}

/* ===================== DB writers ===================== */
function refreshCertificate($conn, $student_id, $payStatus) {
    $student_id = (int)$student_id;
    $q = $conn->query("SELECT result_status, full_marks, marks_percentage FROM results WHERE student_id=$student_id");
    $res = $q ? $q->fetch_assoc() : null;
    $payOk = in_array($payStatus, ['PAID', 'COMPLETED', 'ONE_TIME', 'CONVA_PAY']);

    if ($res && in_array($res['result_status'], ['ABSENT', 'NOT_ELIGIBLE'], true)) {
        $conn->query("UPDATE certificates SET certificate_status='NOT_ELIGIBLE' WHERE student_id=$student_id AND certificate_status!='ISSUED'");
        return;
    }
    $passOk = $res && $res['result_status'] === 'PRESENT' && (
        ($res['full_marks'] !== null && $res['full_marks'] >= 40) ||
        ($res['marks_percentage'] !== null && $res['marks_percentage'] >= 40)
    );
    if ($payOk && $passOk) {
        $conn->query("UPDATE certificates SET certificate_status='READY' WHERE student_id=$student_id AND certificate_status!='ISSUED'");
    } else {
        $conn->query("UPDATE certificates SET certificate_status='PENDING' WHERE student_id=$student_id AND certificate_status!='ISSUED'");
    }
}

/** insert or update the payment row of one student */
function savePaymentRow($conn, $sid, $status, $amount, $payDate, $notes, $admin, $regFee, $coursePaid, $courseDue, $balance, $instJson) {
    $status = payStatusSafe($status);
    $sid = (int)$sid;
    $exists = $conn->query("SELECT id FROM payments WHERE student_id=$sid LIMIT 1");
    if ($exists && $exists->num_rows > 0) {
        $stmt = $conn->prepare("UPDATE payments SET payment_status=?, amount=?, payment_date=?, notes=?, updated_by=?, reg_fee=?, course_paid=?, course_due=?, balance=?, installments_json=? WHERE student_id=?");
        if (!$stmt) return $conn->error;
        $stmt->bind_param("sdsssddddsi", $status, $amount, $payDate, $notes, $admin, $regFee, $coursePaid, $courseDue, $balance, $instJson, $sid);
    } else {
        $stmt = $conn->prepare("INSERT INTO payments (student_id, payment_status, amount, payment_date, notes, updated_by, reg_fee, course_paid, course_due, balance, installments_json) VALUES (?,?,?,?,?,?,?,?,?,?,?)");
        if (!$stmt) return $conn->error;
        $stmt->bind_param("isdsssdddds", $sid, $status, $amount, $payDate, $notes, $admin, $regFee, $coursePaid, $courseDue, $balance, $instJson);
    }
    $ok  = $stmt->execute();
    $err = $ok ? '' : $stmt->error;
    $stmt->close();
    return $ok ? true : $err;
}

/**
 * Save a list of raw payment records (from Excel or CSV) for every student found in the DB.
 * Returns stats array. $notes (by ref) receives info / error lines.
 */
function paySaveRecords($conn, $records, $admin, &$notes, $tag = 'Excel') {
    $stats = ['rows' => 0, 'students' => 0, 'completed' => 0, 'one_time' => 0, 'partial' => 0,
              'none' => 0, 'notfound' => 0, 'nodata' => 0, 'failed' => 0];

    ensurePaymentsSchema($conn);
    if (!(payColExists($conn, 'reg_fee') && payColExists($conn, 'course_paid') && payColExists($conn, 'course_due')
          && payColExists($conn, 'balance') && payColExists($conn, 'installments_json'))) {
        $notes[] = 'payments table is missing columns (reg_fee, course_paid, course_due, balance, installments_json)';
        $stats['failed']++;
        return $stats;
    }

    $stuStmt = $conn->prepare("SELECT id FROM students WHERE registration_number = ? LIMIT 1");
    $seen = [];

    foreach ($records as $rec) {
        $stats['rows']++;
        $b = payBuildRecord($rec, $tag);
        if ($b === null) { $stats['nodata']++; continue; }

        $reg = $b['reg'];
        $stuStmt->bind_param("s", $reg);
        $stuStmt->execute();
        $stu = $stuStmt->get_result()->fetch_assoc();
        if (!$stu) { $stats['notfound']++; if (count($notes) < 80) $notes[] = "payment: student not found $reg ({$rec['sheet']})"; continue; }
        $sid = (int)$stu['id'];
        if (isset($seen[$sid]) && count($notes) < 80) $notes[] = "payment: $reg appears twice in the file (last row wins)";
        $seen[$sid] = true;

        $status = $b['status'];
        $ex = $conn->query("SELECT payment_status, notes FROM payments WHERE student_id=$sid LIMIT 1");
        $exRow = $ex ? $ex->fetch_assoc() : null;
        if ($exRow && $exRow['payment_status'] === 'CONVA_PAY') $status = 'CONVA_PAY';   // keep manual CONVA PAY
        $manual     = manualNotesPart($exRow['notes'] ?? '');
        $finalNotes = $manual !== '' ? $b['excel_note'] . ' ## ' . $manual : $b['excel_note'];

        $r = savePaymentRow($conn, $sid, $status, $b['amount'], $b['date'], $finalNotes, $admin,
                            $b['reg_fee'], $b['course_paid'], $b['course_due'], $b['balance'], $b['inst_json']);
        if ($r === true) {
            $stats['students']++;
            if ($b['status'] === PAY_ONETIME_STATUS)      $stats['one_time']++;
            elseif ($b['status'] === PAY_FULL_STATUS)     $stats['completed']++;
            elseif ($b['status'] === PAY_PARTIAL_STATUS)  $stats['partial']++;
            else                                          $stats['none']++;
            refreshCertificate($conn, $sid, $status);
        } else {
            $stats['failed']++;
            $notes[] = "payment SQL fail $reg: $r";
        }
    }
    $stuStmt->close();
    return $stats;
}

/** MAIN ENTRY for .xlsx: read the payment part of the workbook and save it */
function payImportFromXlsx($conn, $xlsxPath, $admin, &$notes) {
    @set_time_limit(300);
    @ini_set('memory_limit', '512M');
    $records = payExtractRecords($xlsxPath, $notes);
    return paySaveRecords($conn, $records, $admin, $notes, 'Excel');
}

/** CSV rows (columns: registration_number, reg_fee, inst1..inst6, course_fee, offer) -> records */
function payCsvRecords($handle) {
    $records = [];
    $header = fgetcsv($handle);
    if (!$header) return null;
    $col = array_flip(array_map(function ($h) { return strtolower(trim($h)); }, $header));
    if (!isset($col['registration_number'])) return false;
    while (($data = fgetcsv($handle)) !== false) {
        if (count(array_filter($data)) === 0) continue;
        $get = function ($name) use ($col, $data) {
            return (isset($col[$name]) && isset($data[$col[$name]])) ? trim($data[$col[$name]]) : '';
        };
        $reg = $get('registration_number');
        if ($reg === '' || !payIsReg($reg)) continue;
        $inst = []; $paid = false;
        for ($i = 1; $i <= 6; $i++) {
            $raw = $get('inst' . $i);
            $a = payNum($raw);
            if ($a !== null) $inst[$i] = $a;
            elseif (strtoupper($raw) === 'PAID') { $inst[$i] = 'PAID'; $paid = true; }
        }
        $records[] = ['sheet' => 'CSV', 'reg' => $reg, 'regFee' => (payNum($get('reg_fee')) ?: 0.0),
                      'inst' => $inst, 'paid' => $paid, 'dates' => [],
                      'course' => $get('course_fee'), 'offer' => $get('offer'), 'status' => ''];
    }
    return $records;
}