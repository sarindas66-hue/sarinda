<?php 
$hostname_db = "localhost"; 
$database_db = "chiangrai_cri";   // ✅ ชื่อฐานข้อมูล
$username_db = "postgres"; 
$password_db = "postgres"; 
$port_db     = "5432"; 

// 🔹 เชื่อมต่อฐานข้อมูล
$db = pg_connect("host=$hostname_db port=$port_db dbname=$database_db user=$username_db password=$password_db");

if (!$db) {
    die(json_encode(["success" => false, "message" => "เชื่อมต่อฐานข้อมูลไม่สำเร็จ"]));
}

// 🔹 รับค่าจาก JavaScript หรือ GET
$lat     = $_GET['lat']     ?? null; 
$lng     = $_GET['lng']     ?? null; 
$name    = $_GET['name']    ?? null; 
$action  = $_GET['action']  ?? null; 
$id      = $_GET['id']      ?? null;

// ----------------------------------------------------------------------
// 🔸 ฟังก์ชัน: เพิ่มข้อมูลลงในตาราง points
// ----------------------------------------------------------------------
if ($lat && $lng && $name && !$action) {
    // ตรวจสอบจุดซ้ำในฐานข้อมูล
    $sql_check = "SELECT COUNT(*) FROM points WHERE ST_X(geom) = $lng AND ST_Y(geom) = $lat";
    $result_check = pg_query($db, $sql_check);
    $row = pg_fetch_assoc($result_check);

    if ($row['count'] > 0) {
        echo json_encode(['success' => false, 'message' => 'ข้อมูลซ้ำ']);
        exit;
    }

    // ถ้าไม่มีข้อมูลซ้ำแล้ว เพิ่มข้อมูลใหม่
    $sql_insert = "INSERT INTO points(geom, name) 
                   VALUES (ST_SetSRID(ST_Point($lng, $lat),4326), '$name');";
    $result = pg_query($db, $sql_insert);

    if ($result) {
        echo json_encode(['success' => true, 'message' => 'เพิ่มข้อมูลสำเร็จ']);
    } else {
        echo json_encode(['success' => false, 'message' => 'เพิ่มข้อมูลไม่สำเร็จ']);
    }
    exit;
}

// ----------------------------------------------------------------------
// 🔸 ฟังก์ชัน: ลบข้อมูลจากฐานข้อมูล (ลบถาวร)
// ----------------------------------------------------------------------
if ($action == 'delete') {
    if ($id) {
        // ลบโดยใช้ gid
        $sql_delete = "DELETE FROM points WHERE gid = $id;";
    } elseif ($lat && $lng) {
        // ลบโดยใช้พิกัดกรณีไม่มี gid
        $sql_delete = "DELETE FROM points 
                       WHERE ST_X(geom) = $lng AND ST_Y(geom) = $lat;";
    } else {
        echo json_encode(['success' => false, 'message' => 'ไม่พบข้อมูลที่จะลบ']);
        exit;
    }

    $result = pg_query($db, $sql_delete);

    if ($result && pg_affected_rows($result) > 0) {
        echo json_encode(['success' => true, 'message' => 'ลบข้อมูลสำเร็จ']);
    } else {
        echo json_encode(['success' => false, 'message' => 'ลบข้อมูลไม่สำเร็จ']);
    }
    exit;
}

// ----------------------------------------------------------------------
// 🔸 ฟังก์ชัน: ค้นหาสถานที่จากชื่อ (search)
// ----------------------------------------------------------------------
if ($action == 'search' && isset($_GET['q'])) {
    $search_term = pg_escape_string($_GET['q']);
    $sql_search = "
        SELECT gid, name, ST_AsGeoJSON(geom,5) AS geojson 
        FROM points 
        WHERE name ILIKE '%$search_term%';
    ";
    $query = pg_query($db, $sql_search);

    $geojson = [
        'type' => 'FeatureCollection',
        'features' => []
    ];

    while ($edge = pg_fetch_assoc($query)) {
        $feature = [
            'type' => 'Feature',
            'geometry' => json_decode($edge['geojson'], true),
            'crs' => [
                'type' => 'EPSG',
                'properties' => ['code' => '4326']
            ],
            'properties' => [
                'gid'  => $edge['gid'],
                'name' => $edge['name']
            ]
        ];
        array_push($geojson['features'], $feature);
    }

    echo json_encode($geojson);
    exit;
}

// ----------------------------------------------------------------------
// 🔸 ฟังก์ชัน: ดึงข้อมูลทั้งหมดออกมา (กรณีไม่มี action)
// ----------------------------------------------------------------------
$sql_select = "
    SELECT gid, name, ST_AsGeoJSON(geom,5) AS geojson 
    FROM points;
";
$query = pg_query($db, $sql_select);

$geojson = [
    'type' => 'FeatureCollection',
    'features' => []
];

while ($edge = pg_fetch_assoc($query)) {
    $feature = [
        'type' => 'Feature',
        'geometry' => json_decode($edge['geojson'], true),
        'crs' => [
            'type' => 'EPSG',
            'properties' => ['code' => '4326']
        ],
        'properties' => [
            'gid'  => $edge['gid'],
            'name' => $edge['name']
        ]
    ];
    array_push($geojson['features'], $feature);
}

echo json_encode($geojson);
?>
