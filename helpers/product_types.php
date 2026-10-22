<?php

// Fixed general-insurance lines (master_products.product_type). A product is company-defined
// (code, name, commission, prefixes); its type is what tells the app which risk form and which
// form options apply. Adding a line is a code change here, not a DB change.
//
//   risk_group     key into RISK_FIELDS (helpers/risk_details.php), null = no risk details
//   construction   true when the policy form offers the construction class (I/II/III)
//   Enum options (vehicle usage, ICC clause, survey status...) are read from RISK_FIELDS so the
//   validator and the form can never disagree.

const PRODUCT_TYPES = [
    'fire'              => ['label' => 'Kebakaran / PAR',        'risk_group' => 'fire',  'construction' => true],
    'earthquake'        => ['label' => 'Gempa Bumi',             'risk_group' => 'fire',  'construction' => true],
    'motor'             => ['label' => 'Kendaraan Bermotor',     'risk_group' => 'motor', 'construction' => false],
    'cargo'             => ['label' => 'Pengangkutan (Cargo)',   'risk_group' => 'cargo', 'construction' => false],
    'travel'            => ['label' => 'Perjalanan (Travel)',    'risk_group' => 'travel', 'construction' => false],
    'personal_accident' => ['label' => 'Kecelakaan Diri',        'risk_group' => 'pa',    'construction' => false],
    'liability'         => ['label' => 'Tanggung Gugat',         'risk_group' => null,    'construction' => false],
    'engineering'       => ['label' => 'Rekayasa (CAR/EAR/CPM)', 'risk_group' => null,    'construction' => false],
    'marine_hull'       => ['label' => 'Rangka Kapal',           'risk_group' => null,    'construction' => false],
    'health'            => ['label' => 'Kesehatan',              'risk_group' => null,    'construction' => false],
    'bonds'             => ['label' => 'Jaminan (Surety/Bond)',  'risk_group' => null,    'construction' => false],
    'others'            => ['label' => 'Lainnya',                'risk_group' => null,    'construction' => false],
];

// Display labels for the enum options the form offers, keyed by option name then value.
const PRODUCT_OPTION_LABELS = [
    'construction_class' => [
        'I'   => 'Kelas I – Konstruksi Keras (beton, bata, besi/baja)',
        'II'  => 'Kelas II – Semi Keras (campuran beton & kayu)',
        'III' => 'Kelas III – Konstruksi Ringan (kayu, seng/genteng)',
    ],
];

function isValidProductType(?string $type): bool {
    return $type !== null && isset(PRODUCT_TYPES[$type]);
}

/** Enum options of a risk group, e.g. ['vehicle_usage' => ['private','commercial'], ...]. */
function productTypeEnumOptions(?string $risk_group): array {
    $out = [];
    foreach (RISK_FIELDS[$risk_group] ?? [] as $field => [$kind]) {
        if (str_starts_with($kind, 'enum:')) $out[$field] = explode(',', substr($kind, 5));
    }
    return $out;
}

/** Catalogue for GET /master-products/types: everything a form needs to render per type. */
function productTypeCatalog(): array {
    $out = [];
    foreach (PRODUCT_TYPES as $code => $t) {
        $options = productTypeEnumOptions($t['risk_group']);
        if ($t['construction']) $options['construction_class'] = ['I', 'II', 'III'];
        $out[] = [
            'product_type' => $code,
            'label'        => $t['label'],
            'risk_group'   => $t['risk_group'],
            'has_risk_details' => $t['risk_group'] !== null,
            'options'      => $options,
            'option_labels' => array_intersect_key(PRODUCT_OPTION_LABELS, $options),
        ];
    }
    return $out;
}

/**
 * The product type of a company's product code, or null when the product is unknown or not yet
 * classified. Cached per request: list endpoints resolve many policies of one company.
 */
function productTypeOf($conn, string $company_id, string $product_code): ?string {
    static $cache = [];
    if (!isset($cache[$company_id])) {
        $cache[$company_id] = [];
        $cid = mysqli_real_escape_string($conn, $company_id);
        $res = mysqli_query($conn, "SELECT product_code, product_type FROM " . APP_SCHEMA . ".master_products WHERE company_id = '$cid'");
        while ($res && ($r = mysqli_fetch_assoc($res))) $cache[$company_id][strtolower($r['product_code'])] = $r['product_type'];
    }
    return $cache[$company_id][strtolower(trim($product_code))] ?? null;
}
