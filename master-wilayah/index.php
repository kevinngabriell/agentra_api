<?php

require_once __DIR__ . '/../general.php';
require_once __DIR__ . '/../connection/db.php';

// Reference data lives in CORE_SCHEMA (movira_core_dev), shared across
// Movira apps — not Agentra-specific, so it isn't in APP_SCHEMA.

// --- PROVINCES ---
function getProvinces($conn) {
    $result = mysqli_query($conn,
        "SELECT province_id, province_code, province_name
         FROM " . CORE_SCHEMA . ".master_province
         WHERE is_active = 1
         ORDER BY province_name ASC"
    );

    if ($result && mysqli_num_rows($result) > 0) {
        jsonResponse(200, 'Provinces found', ['data' => mysqli_fetch_all($result, MYSQLI_ASSOC)]);
    } else {
        jsonResponse(404, 'No provinces found');
    }
}

// --- CITIES ---
function getCities($conn, $province_code) {
    if ($province_code === '') {
        jsonResponse(400, 'province_code is required');
        return;
    }
    $province_code = mysqli_real_escape_string($conn, $province_code);

    $result = mysqli_query($conn,
        "SELECT c.city_id, c.province_id, c.city_code, c.city_name
         FROM " . CORE_SCHEMA . ".master_city c
         INNER JOIN " . CORE_SCHEMA . ".master_province p ON p.province_id = c.province_id
         WHERE c.is_active = 1 AND p.province_code = '$province_code'
         ORDER BY c.city_name ASC"
    );

    if ($result && mysqli_num_rows($result) > 0) {
        jsonResponse(200, 'Cities found', ['data' => mysqli_fetch_all($result, MYSQLI_ASSOC)]);
    } else {
        jsonResponse(404, 'No cities found');
    }
}

// --- DISTRICTS ---
function getDistricts($conn, $city_code) {
    if ($city_code === '') {
        jsonResponse(400, 'city_code is required');
        return;
    }
    $city_code = mysqli_real_escape_string($conn, $city_code);

    $result = mysqli_query($conn,
        "SELECT d.district_id, d.city_id, d.district_code, d.district_name
         FROM " . CORE_SCHEMA . ".master_district d
         INNER JOIN " . CORE_SCHEMA . ".master_city c ON c.city_id = d.city_id
         WHERE d.is_active = 1 AND c.city_code = '$city_code'
         ORDER BY d.district_name ASC"
    );

    if ($result && mysqli_num_rows($result) > 0) {
        jsonResponse(200, 'Districts found', ['data' => mysqli_fetch_all($result, MYSQLI_ASSOC)]);
    } else {
        jsonResponse(404, 'No districts found');
    }
}

// --- VILLAGES ---
function getVillages($conn, $district_code) {
    if ($district_code === '') {
        jsonResponse(400, 'district_code is required');
        return;
    }
    $district_code = mysqli_real_escape_string($conn, $district_code);

    $result = mysqli_query($conn,
        "SELECT v.village_id, v.district_id, v.village_code, v.village_name
         FROM " . CORE_SCHEMA . ".master_village v
         INNER JOIN " . CORE_SCHEMA . ".master_district d ON d.district_id = v.district_id
         WHERE v.is_active = 1 AND d.district_code = '$district_code'
         ORDER BY v.village_name ASC"
    );

    if ($result && mysqli_num_rows($result) > 0) {
        jsonResponse(200, 'Villages found', ['data' => mysqli_fetch_all($result, MYSQLI_ASSOC)]);
    } else {
        jsonResponse(404, 'No villages found');
    }
}

// ── Dispatch ──────────────────────────────────────────────────────────────────
// /api/v1/master-wilayah/{action}
//   provinces                        (no params)
//   cities?province_code=xx
//   districts?city_code=xx.xx
//   villages?district_code=xx.xx.xx

requireAuth();
$method = $_SERVER['REQUEST_METHOD'];

if ($method !== 'GET') {
    jsonResponse(405, 'Method Not Allowed');
}

try {
    $conn = getConn();

    switch ($action) {
        case 'provinces':
            getProvinces($conn);
            break;
        case 'cities':
            getCities($conn, trim($_GET['province_code'] ?? ''));
            break;
        case 'districts':
            getDistricts($conn, trim($_GET['city_code'] ?? ''));
            break;
        case 'villages':
            getVillages($conn, trim($_GET['district_code'] ?? ''));
            break;
        default:
            jsonResponse(404, 'Route not found');
    }

} catch (Exception $e) {
    jsonResponse(500, 'Internal Server Error', ['error' => $e->getMessage()]);
}
