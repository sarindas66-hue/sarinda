<?php
$hostname_db = "localhost";
$database_db = "chiangrai_cri";
$username_db = "postgres";
$password_db = "postgres";
$port_db = "5432";

$db = pg_connect("host=$hostname_db port=$port_db dbname=$database_db user=$username_db password=$password_db");

$lat = isset($_GET['lat']) ? floatval($_GET['lat']) : null;
$lng = isset($_GET['lng']) ? floatval($_GET['lng']) : null;
$distance = isset($_GET['distance']) ? floatval($_GET['distance']) : null;

if ($lat === null || $lng === null || $distance === null) {
    echo json_encode(["error" => "Missing lat/lng/distance"]);
    exit;
}

// SQL Query: ดึงหมู่บ้านในระยะที่กำหนด
$sql = "
SELECT gid,
       name,
       tam_name,
       amp_name,
       prv_name,
       ST_AsGeoJSON(ST_Transform(geom,4326)) AS geojson
FROM village_cri
WHERE ST_DWithin(
        ST_Transform(ST_SetSRID(ST_MakePoint($lng,$lat),4326),3857),
        ST_Transform(geom,3857),
        $distance
      );
";

$query = pg_query($db, $sql);
if (!$query) {
    echo json_encode(["error" => pg_last_error($db)]);
    exit;
}

$geojson = [
    "type" => "FeatureCollection",
    "features" => []
];

while ($row = pg_fetch_assoc($query)) {
    $feature = [
        "type" => "Feature",
        "geometry" => json_decode($row["geojson"], true),
        "properties" => [
            "gid" => $row["gid"],
            "name" => $row["name"],
            "tam_name" => $row["tam_name"],
            "amp_name" => $row["amp_name"],
            "prv_name" => $row["prv_name"]
        ]
    ];
    $geojson["features"][] = $feature;
}

echo json_encode($geojson);
?>
