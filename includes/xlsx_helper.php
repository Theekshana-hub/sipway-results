<?php
/**
 * Shared helpers: XLSX reader + student import
 * All functions are guarded so this file can coexist with results.php
 * (results.php keeps its own copies, no changes needed there).
 */

if (!function_exists('readXlsxAllSheets')) {
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
                    if (isset($si->r)) foreach ($si->r as $r) $t .= (string)$r->t;
                    $shared[] = $t;
                }
            }
        }
    }

    // Sheet names + paths
    $wbXml   = $zip->getFromName('xl/workbook.xml');
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
            $i = 0;
            foreach ($wx->sheets->sheet as $sheet) {
                $name = (string)$sheet['name'];
                $rid  = (string)$sheet->attributes('r', true)['id'];
                $i++;
                $pathSheet = $rid ? ($rels[$rid] ?? null) : "xl/worksheets/sheet{$i}.xml";
                if ($pathSheet) $sheetFiles[$name] = $pathSheet;
            }
        }
    }
    if (empty($sheetFiles)) {
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
                $type = isset($c['t']) ? (string)$c['t'] : '';
                if ($type === 's') {
                    $val = $shared[(int)$c->v] ?? '';
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
}

if (!function_exists('normalizeHeader')) {
function normalizeHeader($h) {
    return strtolower(trim(preg_replace('/\s+/', ' ', (string)$h)));
}
}

if (!function_exists('cellVal')) {
function cellVal($row, $idx) {
    if ($idx === null || $idx < 0 || $idx >= count($row)) return '';
    $v = $row[$idx];
    if ($v === null) return '';
    return trim((string)$v);
}
}

if (!function_exists('isRegNo')) {
function isRegNo($v) {
    return (bool)preg_match('/^[A-Za-z]+\/[A-Za-z0-9]+\/\d+/', (string)$v);
}
}

if (!function_exists('ensureCourseAndBatch')) {
// "ICRA/B01/001" -> course ICRA, batch B01 ; returns batch id
function ensureCourseAndBatch($conn, $regNo) {
    if (!preg_match('/^([A-Za-z]+)\/([A-Za-z0-9]+)\/(\d+)/', $regNo, $m)) return null;
    $courseCode = strtoupper($m[1]);
    $batchCode  = strtoupper($m[2]);

    $cc = $conn->real_escape_string($courseCode);
    $course = $conn->query("SELECT id FROM courses WHERE course_code='$cc' LIMIT 1")->fetch_assoc();
    if (!$course) {
        $conn->query("INSERT INTO courses (course_code, course_name, is_active) VALUES ('$cc', '$cc Course', 1)");
        $courseId = $conn->insert_id;
    } else {
        $courseId = (int)$course['id'];
    }

    $bc = $conn->real_escape_string($batchCode);
    $batch = $conn->query("SELECT id FROM batches WHERE course_id=$courseId AND batch_code='$bc' LIMIT 1")->fetch_assoc();
    if (!$batch) {
        $bn = $conn->real_escape_string("$courseCode $batchCode");
        $conn->query("INSERT INTO batches (course_id, batch_code, batch_name, is_active) VALUES ($courseId, '$bc', '$bn', 1)");
        $batchId = $conn->insert_id;
    } else {
        $batchId = (int)$batch['id'];
    }
    return $batchId;
}
}

if (!function_exists('detectStudentColumns')) {
/**
 * Detect header row + columns. Works for BOTH:
 *  - simple CSV:  course_code, batch_code, full_name, registration_number, phone
 *  - HDICRA sheets: Reg No, Name, WhatsApp ...
 */
function detectStudentColumns($rows) {
    $map = ['reg'=>null,'name'=>null,'phone'=>null,'phone2'=>null,'address'=>null,'course'=>null,'batch'=>null,'header_row'=>null];

    for ($r = 0; $r < min(25, count($rows)); $r++) {
        $row = $rows[$r];
        $m = ['reg'=>null,'name'=>null,'phone'=>null,'phone2'=>null,'address'=>null,'course'=>null,'batch'=>null];

        for ($c = 0; $c < count($row); $c++) {
            $h = normalizeHeader($row[$c]);
            if ($h === '') continue;

            if (in_array($h, ['registration_number','registration number','reg no','reg_no','reg number','reg. no','reg.no']) || strpos($h, 'reg no') === 0) {
                $m['reg'] = $c;
            }
            if ($h === 'student id number' && $m['reg'] === null) $m['reg'] = $c;
            if (in_array($h, ['full_name','full name','name','student name'])) $m['name'] = $c;
            if (in_array($h, ['phone2','phone_2','phone 2'])) $m['phone2'] = $c;
            elseif (strpos($h, 'whatsapp') !== false || in_array($h, ['phone','mobile','phone number','contact'])) $m['phone'] = $c;
            if ($h === 'address') $m['address'] = $c;
            if (in_array($h, ['course_code','course code','course'])) $m['course'] = $c;
            if (in_array($h, ['batch_code','batch code','batch'])) $m['batch'] = $c;
        }

        $simpleOk = ($m['course'] !== null && $m['batch'] !== null && $m['name'] !== null);
        $hdicraOk = ($m['reg'] !== null && $m['name'] !== null);
        if ($simpleOk || $hdicraOk) {
            $m['header_row'] = $r;
            return $m;
        }
    }
    return $map;
}
}

if (!function_exists('generateRegNo')) {
// Next free reg no for a batch, e.g. ICRA/B01/013
function generateRegNo($conn, $batchId) {
    $batchId = (int)$batchId;
    $row = $conn->query("SELECT c.course_code, b.batch_code FROM batches b JOIN courses c ON b.course_id=c.id WHERE b.id=$batchId")->fetch_assoc();
    if (!$row) return null;
    $n = (int)$conn->query("SELECT COUNT(*) AS n FROM students WHERE batch_id=$batchId")->fetch_assoc()['n'] + 1;
    do {
        $reg = sprintf('%s/%s/%03d', $row['course_code'], $row['batch_code'], $n);
        $e = $conn->real_escape_string($reg);
        $exists = $conn->query("SELECT id FROM students WHERE registration_number='$e' LIMIT 1")->num_rows > 0;
        $n++;
    } while ($exists);
    return $reg;
}
}

if (!function_exists('createStudent')) {
// Returns 'new', 'exists' or an error string
function createStudent($conn, $regNo, $name, $phone, $batchId, $phone2 = '', $addr = '') {
    $st = $conn->prepare("SELECT id FROM students WHERE registration_number=? LIMIT 1");
    $st->bind_param("s", $regNo);
    $st->execute();
    $found = $st->get_result()->fetch_assoc();
    $st->close();
    if ($found) return 'exists';

    if ($name === '') $name = $regNo;
    $batchId = (int)$batchId;
    $ins = $conn->prepare("INSERT INTO students (registration_number, full_name, phone, phone2, address, batch_id, is_active) VALUES (?,?,?,?,?,?,1)");
    $ins->bind_param("sssssi", $regNo, $name, $phone, $phone2, $addr, $batchId);
    if (!$ins->execute()) { $e = $ins->error; $ins->close(); return $e; }
    $sid = (int)$ins->insert_id;
    $ins->close();

    $conn->query("INSERT INTO results (student_id, result_status) VALUES ($sid, 'PENDING')");
    $conn->query("INSERT INTO payments (student_id, payment_status) VALUES ($sid, 'PENDING')");
    $conn->query("INSERT INTO certificates (student_id, certificate_status) VALUES ($sid, 'PENDING')");
    return 'new';
}
}

if (!function_exists('importOneStudent')) {
/**
 * Resolve course/batch (from columns, else from reg no) and create student.
 * Returns 'new', 'exists' or an error string.
 */
function importOneStudent($conn, $reg, $name, $phone, $courseCode, $batchCode, $phone2 = '', $addr = '') {
    $batchId = null;
    if ($courseCode !== '' && $batchCode !== '') {
        $batchId = ensureCourseAndBatch($conn, strtoupper($courseCode) . '/' . strtoupper($batchCode) . '/1');
    } elseif (isRegNo($reg)) {
        $batchId = ensureCourseAndBatch($conn, $reg);
    }
    if (!$batchId) return 'Cannot work out course/batch (give course_code + batch_code, or a Reg No like ICRA/B01/001)';

    if ($reg === '') {
        $reg = generateRegNo($conn, $batchId);
        if (!$reg) return 'Could not generate registration number';
    }
    return createStudent($conn, $reg, $name, $phone, $batchId, $phone2, $addr);
}
}