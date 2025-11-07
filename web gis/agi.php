<?php
header('Content-Type: application/json; charset=utf-8');
header('Access-Control-Allow-Origin: *');
ini_set('display_errors', '0');
error_reporting(E_ALL);

function fail($msg, $code = 500){
  http_response_code($code);
  if (ob_get_length()) ob_clean();
  echo json_encode(["ok"=>false, "error"=>$msg], JSON_UNESCAPED_UNICODE);
  exit;
}

/* ===== ตั้งค่าฐานข้อมูล ===== */
$hostname_db = "localhost";
$database_db = "agi";
$username_db = "postgres";
$password_db = "postgres";
$port_db     = "5432";

/* ===== เชื่อมต่อฐานข้อมูล ===== */
$db = pg_connect("host=$hostname_db port=$port_db dbname=$database_db user=$username_db password=$password_db");
if (!$db) fail("ไม่สามารถเชื่อมต่อฐานข้อมูลได้");
pg_set_client_encoding($db, "UTF8");

/* ===== พารามิเตอร์จากฝั่งหน้าเว็บ =====
   - q: ค้นหาตามชื่อ/รหัส
   - faculty / curriculum: กรองตามดรอปดาวน์
   - years:
       64 = agi64 (ปี 4)
       65 = agi65 (ปี 3)
       66 = agi66 (ปี 2)
       67 = agi67 (ปี 1)
     ไม่ส่ง = รวมทุกตาราง (ใช้เติมดรอปดาวน์)
*/
$q                  = isset($_GET['q']) ? trim($_GET['q']) : '';
$facultyParamRaw    = $_GET['faculty']    ?? '';
$curriculumParamRaw = $_GET['curriculum'] ?? '';
$yearsParamRaw      = trim($_GET['years'] ?? '');

/* ===== เลือกตารางตาม years ===== */
switch ($yearsParamRaw) {
  case '64': $tables = ['agi64']; break; // ปี 4
  case '65': $tables = ['agi65']; break; // ปี 3
  case '66': $tables = ['agi66']; break; // ปี 2
  case '67': $tables = ['agi67']; break; // ปี 1
  default:   $tables = ['agi64','agi65','agi66','agi67']; // รวมทั้งหมด
}

/* ===== ฟังก์ชัน normalize string ===== */
function norm_param($s){
  $s = trim($s ?? '');
  $s = str_replace(["\u{200B}","\u{200C}","\u{FEFF}"], '', $s);
  $s = preg_replace('/\s+/u', ' ', $s);
  return mb_strtolower($s, 'UTF-8');
}
$facultyParam    = norm_param($facultyParamRaw);
$curriculumParam = norm_param($curriculumParamRaw);

/* ===== เตรียม SQL ===== */
$selects = [];
$params  = [];
$idx     = 0;

/* สร้าง expression normalize ฝั่ง DB ให้ตรงกับ norm_param */
$db_norm_fac = "lower(regexp_replace(replace(replace(replace(trim(faculty::text),E'\\u200B',''),E'\\u200C',''),E'\\uFEFF',''), '\\s+', ' ', 'g'))";
$db_norm_cur = "lower(regexp_replace(replace(replace(replace(trim(curriculum::text),E'\\u200B',''),E'\\u200C',''),E'\\uFEFF',''), '\\s+', ' ', 'g'))";

foreach ($tables as $tbl) {
  $sel = "
    SELECT
      s_id::text AS s_id,
      s_name::text AS s_name,
      curriculum::text AS curriculum,
      department::text AS department,
      faculty::text AS faculty,
      graduated_school::text AS graduated_school,
      CASE
        WHEN NULLIF(trim(lat::text),'') ~ '^-?[0-9]+([\\.,][0-9]+)?$'
          THEN REPLACE(trim(lat::text), ',', '.')::double precision
        ELSE NULL
      END AS lat,
      CASE
        WHEN NULLIF(trim(long::text),'') ~ '^-?[0-9]+([\\.,][0-9]+)?$'
          THEN REPLACE(trim(long::text), ',', '.')::double precision
        ELSE NULL
      END AS long,
      subdistrict::text AS subdistrict,
      district::text AS district,
      province::text AS province,
      '{$tbl}' AS from_table
    FROM {$tbl}
    WHERE 1=1
  ";

  /* ค้นหาด้วยชื่อ/รหัส ถ้าส่ง q มา */
  if ($q !== '') {
    $p1 = ++$idx; $p2 = ++$idx;
    $sel .= " AND (s_name ILIKE '%' || $" . $p1 . " || '%' OR s_id ILIKE '%' || $" . $p2 . " || '%') ";
    $params[] = $q;
    $params[] = $q;
  }

  /* กรองตามคณะ (faculty) */
  if ($facultyParam !== '') {
    $p = ++$idx;
    $sel .= " AND {$db_norm_fac} = $" . $p . " ";
    $params[] = $facultyParam;
  }

  /* กรองตามสาขา (curriculum) */
  if ($curriculumParam !== '') {
    $p = ++$idx;
    $sel .= " AND {$db_norm_cur} = $" . $p . " ";
    $params[] = $curriculumParam;
  }

  $selects[] = $sel;
}

$sql_base = implode("\nUNION ALL\n", $selects);

/* ===== สรุปจำนวนทั้งหมด/จำนวนที่มีพิกัด ===== */
$sql_count = "
  SELECT
    COUNT(*)::bigint AS total,
    COUNT(*) FILTER (
      WHERE lat IS NOT NULL AND long IS NOT NULL
        AND lat BETWEEN -90 AND 90 AND long BETWEEN -180 AND 180
    )::bigint AS with_coords
  FROM ({$sql_base}) AS all_rows
";
$q_count = pg_query_params($db, $sql_count, $params);
if (!$q_count) fail(pg_last_error($db));
$crow  = pg_fetch_assoc($q_count);
$total = (int)$crow['total'];
$with  = (int)$crow['with_coords'];
$without = $total - $with;

/* ===== ดึงข้อมูลจริง (ไม่มี LIMIT/OFFSET) ===== */
$sql = "
  SELECT *
  FROM ({$sql_base}) AS all_rows
  ORDER BY s_name NULLS LAST, s_id
";
$q_res = pg_query_params($db, $sql, $params);
if (!$q_res) fail(pg_last_error($db));

$rows = [];
while ($r = pg_fetch_assoc($q_res)) {
  $r['lat']  = $r['lat']  !== null ? (float)$r['lat']  : null;
  $r['long'] = $r['long'] !== null ? (float)$r['long'] : null;
  $rows[] = $r;
}

/* ===== ส่งออก JSON ===== */
if (ob_get_length()) ob_clean();
echo json_encode([
  "ok"              => true,
  "filters"         => [
    "faculty"    => $facultyParamRaw,
    "curriculum" => $curriculumParamRaw,
    "q"          => $q,
    "years"      => $yearsParamRaw,
  ],
  "total"           => $total,
  "with_coords"     => $with,
  "without_coords"  => $without,
  "count"           => count($rows),
  "items"           => $rows
], JSON_UNESCAPED_UNICODE);
?>
