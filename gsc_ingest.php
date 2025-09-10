<?php
/**
 * Google Search Console ingestion script.
 *
 * Queries Search Console for page/query metrics and stores results in
 * msw_products_seo, msw_product_keywords and msw_product_trends tables.
 *
 * Environment variables:
 *  - GSC_ACCESS_TOKEN: OAuth access token with Search Console scope.
 *  - GSC_SITE_URL: URL property to query, e.g. https://example.com/
 *  - MSW_TOKEN: token used to decrypt DB config (matches dashboard login token).
 */

session_start();
$_SESSION['token'] = getenv('MSW_TOKEN') ?: '';

// --- Helpers copied from ajax.php ---
function secure_load_config(){
    if(!isset($_SESSION['token'])) return false;
    $path = __DIR__.'/config.secure';
    if(!file_exists($path)) return false;
    $raw = base64_decode(file_get_contents($path));
    $iv = substr($raw,0,16);
    $enc = substr($raw,16);
    $key = hash('sha256', $_SESSION['token'], true);
    $json = openssl_decrypt($enc, 'AES-256-CBC', $key, OPENSSL_RAW_DATA, $iv);
    return $json ? json_decode($json,true) : false;
}

function connect($cfg){
    try{
        $mysqli = new mysqli($cfg['host'],$cfg['user'],$cfg['pass'],$cfg['name']);
    }catch(mysqli_sql_exception $e){
        fwrite(STDERR, "DB connect error: {$e->getMessage()}\n");
        return false;
    }
    if($mysqli->connect_errno){
        fwrite(STDERR, "DB connect errno: {$mysqli->connect_error}\n");
        return false;
    }
    $mysqli->set_charset('utf8mb4');
    return $mysqli;
}
// --- end helpers ---

$cfg = secure_load_config();
if(!$cfg){
    fwrite(STDERR, "Missing DB configuration.\n");
    exit(1);
}

$token = getenv('GSC_ACCESS_TOKEN');
$siteUrl = getenv('GSC_SITE_URL');
if(!$token || !$siteUrl){
    fwrite(STDERR, "Missing GSC_ACCESS_TOKEN or GSC_SITE_URL env vars.\n");
    exit(1);
}

$db = connect($cfg);
if(!$db) exit(1);

// Create tables if they do not exist
$db->query("CREATE TABLE IF NOT EXISTS msw_products_seo (
    page VARCHAR(255) PRIMARY KEY,
    clicks INT DEFAULT 0,
    impressions INT DEFAULT 0,
    ctr FLOAT DEFAULT 0,
    position FLOAT DEFAULT 0,
    updated_at DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci");

$db->query("CREATE TABLE IF NOT EXISTS msw_product_keywords (
    page VARCHAR(255) NOT NULL,
    query VARCHAR(255) NOT NULL,
    clicks INT DEFAULT 0,
    impressions INT DEFAULT 0,
    ctr FLOAT DEFAULT 0,
    position FLOAT DEFAULT 0,
    PRIMARY KEY(page,query)
) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci");

$db->query("CREATE TABLE IF NOT EXISTS msw_product_trends (
    date DATE PRIMARY KEY,
    clicks INT DEFAULT 0,
    impressions INT DEFAULT 0,
    ctr FLOAT DEFAULT 0,
    position FLOAT DEFAULT 0
) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci");

$start = date('Y-m-d', strtotime('-7 days'));
$end   = date('Y-m-d');

function gsc_query($siteUrl,$token,$body){
    $ch = curl_init('https://searchconsole.googleapis.com/webmasters/v3/sites/'.rawurlencode($siteUrl).'/searchAnalytics/query');
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_HTTPHEADER, [
        'Content-Type: application/json',
        'Authorization: Bearer '.$token
    ]);
    curl_setopt($ch, CURLOPT_POST, true);
    curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($body));
    $res = curl_exec($ch);
    if(curl_errno($ch)){
        fwrite(STDERR, 'cURL error: '.curl_error($ch).'\n');
        return [];
    }
    $code = curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
    if($code >= 400){
        fwrite(STDERR, "HTTP $code: $res\n");
        return [];
    }
    $data = json_decode($res,true);
    return $data['rows'] ?? [];
}

$rows = gsc_query($siteUrl,$token,[
    'startDate'=>$start,
    'endDate'=>$end,
    'dimensions'=>['page','query'],
    'rowLimit'=>2500
]);

$pageAgg = [];
foreach($rows as $r){
    $page = $r['keys'][0];
    $query = $r['keys'][1];
    $clicks = $r['clicks'];
    $impr = $r['impressions'];
    $ctr = $r['ctr'];
    $pos = $r['position'];

    $stmt = $db->prepare("REPLACE INTO msw_product_keywords(page,query,clicks,impressions,ctr,position) VALUES(?,?,?,?,?,?)");
    $stmt->bind_param('ssiddi',$page,$query,$clicks,$impr,$ctr,$pos);
    $stmt->execute();
    $stmt->close();

    if(!isset($pageAgg[$page])){
        $pageAgg[$page] = ['clicks'=>0,'impressions'=>0,'ctr'=>0,'position'=>0,'count'=>0];
    }
    $pageAgg[$page]['clicks'] += $clicks;
    $pageAgg[$page]['impressions'] += $impr;
    $pageAgg[$page]['ctr'] += $ctr;
    $pageAgg[$page]['position'] += $pos;
    $pageAgg[$page]['count']++;
}
foreach($pageAgg as $page=>$m){
    $avgCtr = $m['count'] ? $m['ctr']/$m['count'] : 0;
    $avgPos = $m['count'] ? $m['position']/$m['count'] : 0;
    $stmt = $db->prepare("REPLACE INTO msw_products_seo(page,clicks,impressions,ctr,position,updated_at) VALUES(?,?,?,?,?,NOW())");
    $stmt->bind_param('siidd',$page,$m['clicks'],$m['impressions'],$avgCtr,$avgPos);
    $stmt->execute();
    $stmt->close();
}

// Trends by date
$rows = gsc_query($siteUrl,$token,[
    'startDate'=>$start,
    'endDate'=>$end,
    'dimensions'=>['date'],
    'rowLimit'=>2500
]);
foreach($rows as $r){
    $date = $r['keys'][0];
    $clicks = $r['clicks'];
    $impr = $r['impressions'];
    $ctr = $r['ctr'];
    $pos = $r['position'];
    $stmt = $db->prepare("REPLACE INTO msw_product_trends(date,clicks,impressions,ctr,position) VALUES(?,?,?,?,?)");
    $stmt->bind_param('siidd',$date,$clicks,$impr,$ctr,$pos);
    $stmt->execute();
    $stmt->close();
}

// Insert sample rows if tables are empty
function ensure_samples($db){
    $tables = [
        'msw_products_seo'=>"INSERT INTO msw_products_seo(page,clicks,impressions,ctr,position,updated_at) VALUES('https://example.com/sample-product',10,100,0.1,5,NOW())",
        'msw_product_keywords'=>"INSERT INTO msw_product_keywords(page,query,clicks,impressions,ctr,position) VALUES('https://example.com/sample-product','sample keyword',10,100,0.1,5)",
        'msw_product_trends'=>"INSERT INTO msw_product_trends(date,clicks,impressions,ctr,position) VALUES(CURDATE(),10,100,0.1,5)"
    ];
    foreach($tables as $t=>$ins){
        $res = $db->query("SELECT COUNT(*) c FROM $t");
        if($res && ($res->fetch_assoc()['c'] ?? 0) == 0){
            $db->query($ins);
        }
    }
}
ensure_samples($db);

$db->close();
?>
