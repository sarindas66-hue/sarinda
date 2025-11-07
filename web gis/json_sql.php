<?php
$host = "host=localhost";
$port = "port=5432";
$dbname = "dbname=chiangrai_cri";
$credentials = "user=postgres password=postgres";

// เชื่อมต่อฐานข้อมูล
$db = pg_connect("$host $port $dbname $credentials");

// ดึงข้อมูลจากตาราง tambon_cri (จำกัดให้แสดงแค่ 9 อำเภอ)
$sql = "SELECT *, ST_AsGeoJSON(ST_Transform(geom, 4326)) AS geojson 
        FROM tambon_cri 
        LIMIT 9;";
$query = pg_query($db, $sql);

if (!$query) {
    die("❌ Query failed: " . pg_last_error($db));
}

$geojson = array(
    'type' => 'FeatureCollection',
    'features' => array()
);

while ($edge = pg_fetch_assoc($query)) {
    $feature = array(
        'type' => 'Feature',
        'geometry' => json_decode($edge['geojson'], true),
        'properties' => array(
            'gid' => $edge['gid'] ?? null,
            'objectid' => $edge['objectid'] ?? null,
            'tambon_idn' => $edge['tambon_idn'] ?? null,
            'tam_code' => $edge['tam_code'] ?? null,
            'tam_nam_t' => $edge['tam_nam_t'] ?? null,  
            'amphoe_idn' => $edge['amphoe_idn'] ?? null,
            'amp_code' => $edge['amp_code'] ?? null,
            'amphoe_t' => $edge['amphoe_t'] ?? null,
            'amphoe_e' => $edge['amphoe_e'] ?? null,
            'prov_code' => $edge['prov_code'] ?? null,
            'prov_nam_t' => $edge['prov_nam_t'] ?? null,
            'prov_nam_e' => $edge['prov_nam_e'] ?? null,
            'orig_fid' => $edge['orig_fid'] ?? null,
            'shape_leng' => $edge['shape_leng'] ?? null,
            'shape_area' => $edge['shape_area'] ?? null,
            'remark' => $edge['remark'] ?? null
        )
    );
    array_push($geojson['features'], $feature);
}

pg_close($db);
echo json_encode($geojson, JSON_UNESCAPED_UNICODE);
?>
