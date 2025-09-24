<?php
session_start();
header('Content-Type: application/json; charset=utf-8');

$configFile = __DIR__.'/config.secure';
require_once __DIR__.'/prompt_template.php';
require_once __DIR__.'/classes/UserManager.php';
require_once __DIR__.'/classes/SanctionBypassManager.php';
require_once __DIR__.'/classes/ChatGPTManager.php';
require_once __DIR__.'/classes/SEOAnalyzer.php';
require_once __DIR__.'/classes/ProcessManager.php';
require_once __DIR__.'/classes/ProcessQueue.php';
$action = isset($_POST['action']) ? $_POST['action'] : '';

function has_perm($p){
  $perm = $_SESSION['permissions'] ?? '';
  return $perm === 'all' || strpos($perm,$p) !== false;
}

function compute_seo_score($t,$d,$c,$f){
  $baseUrl = $_SESSION['site_base_url'] ?? '';
  $a = SEOAnalyzer::analyze($t,$d,$c,$f,$baseUrl);
  return $a['score'] ?? 0;
}

function msw_debug_enabled(){
  static $enabled = null;
  if($enabled !== null){
    return $enabled;
  }
  $enabled = false;
  $flag = $_POST['msw_debug'] ?? ($_POST['debug'] ?? null);
  if($flag !== null){
    $normalized = strtolower((string)$flag);
    if(in_array($normalized,array('1','true','on','yes'),true)){
      $enabled = true;
      $_SESSION['msw_debug'] = true;
    } elseif(in_array($normalized,array('0','false','off','no'),true)){
      $_SESSION['msw_debug'] = false;
    }
  }
  if(!$enabled && isset($_SESSION['msw_debug']) && $_SESSION['msw_debug']){
    $enabled = true;
  }
  if(!$enabled && file_exists(__DIR__.'/debug.flag')){
    $enabled = true;
  }
  return $enabled;
}

function msw_debug_write_log(array $entry){
  if(!msw_debug_enabled()){
    return;
  }
  $entry['ts'] = $entry['ts'] ?? date('c');
  $json = json_encode($entry, JSON_UNESCAPED_UNICODE);
  if($json !== false){
    $path = __DIR__.'/msw_debug.log';
    file_put_contents($path, $json.PHP_EOL, FILE_APPEND);
  }
}

class MswDebugCollector{
  private $id = null;
  private $steps = array();

  public function __construct($channel){
    if(!msw_debug_enabled()){
      return;
    }
    try{
      $token = bin2hex(random_bytes(3));
    }catch(Exception $e){
      $token = uniqid();
    }
    $this->id = $channel.'-'.date('YmdHis').'-'.$token;
    $this->checkpoint('start',array('channel'=>$channel));
  }

  public function checkpoint($stage,array $context=array()){
    if(!$this->id){
      return;
    }
    $entry = array(
      'ts'=>date('c'),
      'stage'=>$stage,
      'context'=>$context
    );
    $this->steps[] = $entry;
    msw_debug_write_log(array_merge(array('id'=>$this->id),$entry));
  }

  public function finalize(array $response){
    if(!$this->id){
      return $response;
    }
    $response['debug_id'] = $this->id;
    $response['debug_steps'] = $this->steps;
    return $response;
  }
}

function handle_login(){
  $debug = new MswDebugCollector('login');
  $username = trim($_POST['username'] ?? '');
  $password = $_POST['password'] ?? '';
  $debug->checkpoint('payload_received',array(
    'username'=>$username,
    'password_length'=>strlen($password)
  ));
  $cfg = secure_load_local_config();
  if(!$cfg){
    $debug->checkpoint('local_config_missing');
    return $debug->finalize(array('success'=>false,'message'=>'تنظیمات پایگاه داده سامانه موجود نیست'));
  }
  $debug->checkpoint('local_config_loaded',array(
    'has_host'=>!empty($cfg['host'] ?? ''),
    'has_user'=>!empty($cfg['user'] ?? ''),
    'has_name'=>!empty($cfg['name'] ?? ''),
    'prefix'=>$cfg['prefix'] ?? ''
  ));
  try{
    $db = new mysqli($cfg['host'],$cfg['user'],$cfg['pass'],$cfg['name']);
  }catch(mysqli_sql_exception $e){
    $debug->checkpoint('local_db_exception',array('error'=>$e->getMessage()));
    return $debug->finalize(array('success'=>false,'message'=>'اتصال به پایگاه داده سامانه ناموفق بود'));
  }
  if($db->connect_errno){
    $debug->checkpoint('local_db_connect_error',array('errno'=>$db->connect_errno,'error'=>$db->connect_error));
    $msg = $db->connect_error ?: 'خطای اتصال پایگاه داده';
    $db->close();
    return $debug->finalize(array('success'=>false,'message'=>$msg));
  }
  $db->set_charset('utf8mb4');
  $debug->checkpoint('local_db_connected');
  $schemaErrors = init_local_tables($db,$cfg['prefix'],$debug);
  if(!empty($schemaErrors)){
    $debug->checkpoint('schema_setup_failed',array('count'=>count($schemaErrors),'errors'=>$schemaErrors));
    $db->close();
    $message = 'راه‌اندازی جداول سامانه با خطا مواجه شد';
    $details = format_schema_error_message($schemaErrors);
    if($details !== ''){
      $message .= ': '.$details;
    }
    return $debug->finalize(array('success'=>false,'message'=>$message));
  }
  $debug->checkpoint('schema_ready');
  $sql = "SELECT u.id,u.username,u.full_name,u.password_hash,u.role_id,COALESCE(r.permissions,'') AS permissions
     FROM {$cfg['prefix']}users u
     LEFT JOIN {$cfg['prefix']}roles r ON u.role_id=r.id
     WHERE u.username=? AND u.status='active'";
  $stmt = $db->prepare($sql);
  if(!$stmt){
    $debug->checkpoint('user_query_prepare_failed',array('error'=>$db->error));
    $db->close();
    return $debug->finalize(array('success'=>false,'message'=>'خطای داخلی سرور'));
  }
  $stmt->bind_param('s',$username);
  $stmt->execute();
  $res = $stmt->get_result();
  $row = $res ? $res->fetch_assoc() : null;
  $stmt->close();
  $debug->checkpoint('user_lookup_complete',array('found'=> (bool)$row));
  if($row && password_verify($password,$row['password_hash'])){
    $debug->checkpoint('password_verified',array('user_id'=>intval($row['id'])));
    $_SESSION['auth'] = true;
    $_SESSION['user_id'] = intval($row['id']);
    $_SESSION['username'] = $row['username'];
    $_SESSION['full_name'] = $row['full_name'] ?? '';
    $perms = $row['permissions'];
    if($perms === null){ $perms = ''; }
    if($perms === '' && (empty($row['role_id']) || intval($row['role_id']) === 0)){
      $perms = 'all';
    }
    $_SESSION['permissions'] = $perms;
    $_SESSION['role_id'] = isset($row['role_id']) ? intval($row['role_id']) : null;
    $_SESSION['logdb'] = $cfg;
    $mainCfg = secure_load_config();
    if($mainCfg){
      $_SESSION['db'] = $mainCfg;
      $debug->checkpoint('wp_config_loaded');
    } else {
      $debug->checkpoint('wp_config_missing');
    }
    try{
      log_event('login');
      $debug->checkpoint('login_event_logged');
    }catch(Throwable $e){
      $debug->checkpoint('login_event_failed',array('error'=>$e->getMessage()));
    }
    $db->close();
    return $debug->finalize(array('success'=>true));
  }
  $reason = $row ? 'password_mismatch' : 'user_not_found';
  $debug->checkpoint('login_failed',array('reason'=>$reason));
  $db->close();
  return $debug->finalize(array('success'=>false,'message'=>'ورود نامعتبر'));
}

$publicActions = array('login','db_connect','load_saved_config','local_db_connect',
  'local_load_config','local_check_config','admin_init','admin_check');
if(!isset($_SESSION['auth']) && !in_array($action,$publicActions)){
  http_response_code(401);
  echo json_encode(array('success'=>false,'message'=>'دسترسی غیرمجاز'));
  exit;
}

switch($action){
case 'login':
  $response = handle_login();
  echo json_encode($response);
  break;
case 'logout':
  log_event('logout');
  session_destroy();
  echo json_encode(array('success'=>true));
  break;
case 'db_connect':
  $host = isset($_POST['host']) ? $_POST['host'] : '';
  $name = isset($_POST['name']) ? $_POST['name'] : '';
  $user = isset($_POST['user']) ? $_POST['user'] : '';
  $pass = isset($_POST['pass']) ? $_POST['pass'] : '';
  $prefix = isset($_POST['prefix']) ? $_POST['prefix'] : 'wp_';
  try{
    $mysqli = new mysqli($host,$user,$pass,$name);
  }catch(mysqli_sql_exception $e){
    echo json_encode(array('success'=>false,'message'=>$e->getMessage()));
    break;
  }
  if($mysqli->connect_errno){
    echo json_encode(array('success'=>false,'message'=>$mysqli->connect_error));
  } else {
    $mysqli->set_charset('utf8mb4');
    $_SESSION['db'] = array('host'=>$host,'name'=>$name,'user'=>$user,'pass'=>$pass,'prefix'=>$prefix);
    $mysqli->close();
    secure_save_config($_SESSION['db']);
    echo json_encode(array('success'=>true));
  }
  break;
case 'load_saved_config':
  $cfg = secure_load_config();
  if($cfg){
    echo json_encode(array('success'=>true,'host'=>$cfg['host'],'name'=>$cfg['name'],'user'=>$cfg['user'],'pass'=>$cfg['pass'],'prefix'=>$cfg['prefix']));
  }else{
    echo json_encode(array('success'=>false));
  }
  break;
case 'load_prompt_template':
  $path=__DIR__.'/prompt_template.txt';
  if(file_exists($path)){
    echo json_encode(array('success'=>true,'template'=>file_get_contents($path)));
  }else{
    echo json_encode(array('success'=>false,'message'=>'template not found'));
  }
  break;
case 'save_prompt_template':
  $path=__DIR__.'/prompt_template.txt';
  $tpl=isset($_POST['template'])?$_POST['template']:'';
  if(file_put_contents($path,$tpl)!==false){ echo json_encode(array('success'=>true)); }
  else{ echo json_encode(array('success'=>false,'message'=>'failed to save')); }
  break;
case 'load_licenses':
  $path=__DIR__.'/licenses.json';
  $data=file_exists($path)?json_decode(file_get_contents($path),true):array();
  echo json_encode(array('success'=>true,'data'=>$data?:array()));
  break;
case 'save_licenses':
  $path=__DIR__.'/licenses.json';
  $licenses=isset($_POST['licenses'])?json_decode($_POST['licenses'],true):array();
  if(file_put_contents($path,json_encode($licenses,JSON_UNESCAPED_UNICODE))!==false){ echo json_encode(array('success'=>true)); }
  else{ echo json_encode(array('success'=>false,'message'=>'ذخیره نشد')); }
  break;
case 'local_db_connect':
  $host = isset($_POST['host']) ? $_POST['host'] : '';
  $name = isset($_POST['name']) ? $_POST['name'] : '';
  $user = isset($_POST['user']) ? $_POST['user'] : '';
  $pass = isset($_POST['pass']) ? $_POST['pass'] : '';
  $prefix = isset($_POST['prefix']) ? $_POST['prefix'] : 'msw_';
  try{ $mysqli = new mysqli($host,$user,$pass,$name); }
  catch(mysqli_sql_exception $e){ echo json_encode(array('success'=>false,'message'=>$e->getMessage())); break; }
  if($mysqli->connect_errno){ echo json_encode(array('success'=>false,'message'=>$mysqli->connect_error)); break; }
  $mysqli->set_charset('utf8mb4');
  $_SESSION['logdb']=array('host'=>$host,'name'=>$name,'user'=>$user,'pass'=>$pass,'prefix'=>$prefix);
  secure_save_local_config($_SESSION['logdb']);
  $schemaErrors = init_local_tables($mysqli,$prefix);
  if(!empty($schemaErrors)){
    if(msw_debug_enabled()){
      msw_debug_write_log(array('id'=>'local_db_connect','stage'=>'schema_errors','errors'=>$schemaErrors));
    }
    $mysqli->close();
    $message = 'ایجاد جداول سامانه با خطا مواجه شد';
    $details = format_schema_error_message($schemaErrors);
    if($details !== ''){
      $message .= ': '.$details;
    }
    echo json_encode(array('success'=>false,'message'=>$message));
    break;
  }
  $mysqli->close();
  echo json_encode(array('success'=>true));
  break;
case 'local_load_config':
  $cfg = secure_load_local_config();
  if($cfg){ echo json_encode(array('success'=>true,'host'=>$cfg['host'],'name'=>$cfg['name'],'user'=>$cfg['user'],'pass'=>$cfg['pass'],'prefix'=>$cfg['prefix'])); }
  else{ echo json_encode(array('success'=>false)); }
  break;
case 'local_check_config':
  $cfg = secure_load_local_config();
  if(!$cfg){ echo json_encode(array('success'=>false,'message'=>'تنظیمات موجود نیست')); break; }
  try{ $mysqli = new mysqli($cfg['host'],$cfg['user'],$cfg['pass'],$cfg['name']); }
  catch(mysqli_sql_exception $e){ echo json_encode(array('success'=>false,'message'=>$e->getMessage())); break; }
  if($mysqli->connect_errno){ echo json_encode(array('success'=>false,'message'=>$mysqli->connect_error)); }
  else { $mysqli->close(); echo json_encode(array('success'=>true)); }
  break;
case 'fetch_user_logs':
  $db = connect_local();
  if(!$db){ echo json_encode(array('success'=>false,'message'=>'عدم اتصال به پایگاه داده سامانه')); break; }
  $prefix = $_SESSION['logdb']['prefix'];
  $uid = intval($_POST['id'] ?? 0);
  $rows = array();
  $stmt = $db->prepare("SELECT id, action, ip_address, country, city, isp, timestamp FROM {$prefix}user_logs WHERE user_id=? ORDER BY id DESC LIMIT 100");
  $warn='';
  if($stmt){
    $stmt->bind_param('i',$uid);
    $stmt->execute();
    $res=$stmt->get_result();
    while($r=$res->fetch_assoc()){
      if($r['country']=='' || $r['city']==''){
        $key=get_setting($db,$prefix,'ipify_key');
        if($key){
          $url="https://geo.ipify.org/api/v2/country,city?apiKey={$key}&ip={$r['ip_address']}";
          $resp=@file_get_contents($url);
          if($resp){
            $data=json_decode($resp,true);
            if($data){
              $r['country']=$data['location']['country']??'';
              $r['city']=$data['location']['city']??'';
              $r['isp']=$data['isp']??'';
              $upd=$db->prepare("UPDATE {$prefix}user_logs SET country=?, city=?, isp=? WHERE id=?");
              if($upd){$upd->bind_param('sssi',$r['country'],$r['city'],$r['isp'],$r['id']);$upd->execute();$upd->close();}
            } else { $warn='Geo decode failed'; }
          } else { $warn='Geo API request failed'; }
        } else { $warn='Geo API key missing'; }
      }
      $rows[] = array('action'=>$r['action'],'ip'=>$r['ip_address'],'country'=>$r['country'],'city'=>$r['city'],'isp'=>$r['isp'],'ts'=>$r['timestamp']);
    }
    $stmt->close();
  }
  $db->close();
  echo json_encode(array('success'=>true,'logs'=>$rows,'message'=>$warn));
  break;
case 'logs_list':
  $db = connect_local();
  if(!$db){ echo json_encode(array('success'=>false,'message'=>'عدم اتصال به پایگاه داده سامانه')); break; }
  $prefix = $_SESSION['logdb']['prefix'];
  $rows = array();
  $warn='';
  $res = $db->query("SELECT l.id,u.username, l.action, l.ip_address, l.country, l.city, l.isp, l.timestamp FROM {$prefix}user_logs l JOIN {$prefix}users u ON l.user_id=u.id ORDER BY l.id DESC LIMIT 200");
  if($res){
    $key=get_setting($db,$prefix,'ipify_key');
    while($r=$res->fetch_assoc()){
      if(($r['country']=='' || $r['city']=='') && $key){
        $url="https://geo.ipify.org/api/v2/country,city?apiKey={$key}&ip={$r['ip_address']}";
        $resp=@file_get_contents($url);
        if($resp){
          $data=json_decode($resp,true);
          if($data){
            $r['country']=$data['location']['country']??'';
            $r['city']=$data['location']['city']??'';
            $r['isp']=$data['isp']??'';
            $db->query("UPDATE {$prefix}user_logs SET country='".$db->real_escape_string($r['country'])."', city='".$db->real_escape_string($r['city'])."', isp='".$db->real_escape_string($r['isp'])."' WHERE id=".$r['id']);
          } else { $warn='Geo decode failed'; }
        } else { $warn='Geo API request failed'; }
      } elseif(($r['country']=='' || $r['city']=='') && !$key){
        $warn='Geo API key missing';
      }
      $rows[]=$r;
    }
  }
  $db->close();
  echo json_encode(array('success'=>true,'data'=>$rows,'message'=>$warn));
  break;
case 'fetch_search_console':
  $ldb = connect_local();
  if(!$ldb){ echo json_encode(array('success'=>false,'message'=>'عدم اتصال به پایگاه داده سامانه')); break; }
  $lp = $_SESSION['logdb']['prefix'];
  $cid = get_setting($ldb,$lp,'sc_client_id');
  $secret = get_setting($ldb,$lp,'sc_client_secret');
  $refresh = get_setting($ldb,$lp,'sc_refresh_token');
  $site = get_setting($ldb,$lp,'sc_site');
  $from = $_POST['from'] ?? date('Y-m-d',strtotime('-3 months'));
  $to = $_POST['to'] ?? date('Y-m-d');
  $query = $_POST['query'] ?? '';
  $device = $_POST['device'] ?? '';
  $country = $_POST['country'] ?? '';
  $dimension = $_POST['dimension'] ?? 'query';
  $ldb->close();
  if(!$cid || !$secret || !$refresh || !$site){
    echo json_encode(array('success'=>false,'message'=>'تنظیمات سرچ کنسول ناقص است')); break;
  }
  $ch = curl_init('https://oauth2.googleapis.com/token');
  curl_setopt_array($ch,array(
    CURLOPT_POST=>true,
    CURLOPT_POSTFIELDS=>http_build_query(array(
      'client_id'=>$cid,
      'client_secret'=>$secret,
      'refresh_token'=>$refresh,
      'grant_type'=>'refresh_token'
    )),
    CURLOPT_RETURNTRANSFER=>true
  ));
  $tok = curl_exec($ch);
  if($tok === false){ echo json_encode(array('success'=>false,'message'=>'token error')); break; }
  $tok = json_decode($tok,true);
  $acc = $tok['access_token'] ?? '';
  if(!$acc){ echo json_encode(array('success'=>false,'message'=>'token missing')); break; }
  $payloadArr = array(
    'startDate'=>$from,
    'endDate'=>$to,
    'dimensions'=>array($dimension),
    'rowLimit'=>250
  );
  $filters=array();
  if($query !== ''){ $filters[] = array('dimension'=>$dimension,'operator'=>'contains','expression'=>$query); }
  if($device !== ''){ $filters[] = array('dimension'=>'device','operator'=>'equals','expression'=>$device); }
  if($country !== ''){ $filters[] = array('dimension'=>'country','operator'=>'equals','expression'=>$country); }
  if($filters){
    $payloadArr['dimensionFilterGroups']=array(array('groupType'=>'and','filters'=>$filters));
  }
  $payload = json_encode($payloadArr);
  $ch = curl_init('https://searchconsole.googleapis.com/webmasters/v3/sites/'.urlencode($site).'/searchAnalytics/query');
  curl_setopt_array($ch,array(
    CURLOPT_POST=>true,
    CURLOPT_HTTPHEADER=>array('Content-Type: application/json','Authorization: Bearer '.$acc),
    CURLOPT_POSTFIELDS=>$payload,
    CURLOPT_RETURNTRANSFER=>true
  ));
  $resp = curl_exec($ch);
  $http = curl_getinfo($ch,CURLINFO_HTTP_CODE);
  if($resp === false){ echo json_encode(array('success'=>false,'message'=>'API error')); break; }
  $resp = json_decode($resp,true);
  if($http != 200){
    $msg = $resp['error']['message'] ?? 'خطای ناشناخته از سرچ کنسول';
    echo json_encode(array('success'=>false,'message'=>$msg));
    break;
  }
  $rows = $resp['rows'] ?? array();
  $data = array();
  foreach($rows as $r){
    $data[] = array(
      'key'=>$r['keys'][0] ?? '',
      'clicks'=>$r['clicks'] ?? 0,
      'impressions'=>$r['impressions'] ?? 0,
      'ctr'=>isset($r['ctr'])?round($r['ctr']*100,2) : 0,
      'position'=>$r['position'] ?? 0
    );
  }
  // second request for daily metrics
  $payload2 = json_encode(array(
    'startDate'=>$from,
    'endDate'=>$to,
    'dimensions'=>array('date'),
    'rowLimit'=>250,
    'dimensionFilterGroups'=>$filters?array(array('groupType'=>'and','filters'=>$filters)):null
  ));
  $ch2 = curl_init('https://searchconsole.googleapis.com/webmasters/v3/sites/'.urlencode($site).'/searchAnalytics/query');
  curl_setopt_array($ch2,array(
    CURLOPT_POST=>true,
    CURLOPT_HTTPHEADER=>array('Content-Type: application/json','Authorization: Bearer '.$acc),
    CURLOPT_POSTFIELDS=>$payload2,
    CURLOPT_RETURNTRANSFER=>true
  ));
  $resp2 = curl_exec($ch2);
  $http2 = curl_getinfo($ch2,CURLINFO_HTTP_CODE);
  $resp2 = json_decode($resp2,true);
  $dateRows = $resp2['rows'] ?? array();
  $dates = array();
  $sumClicks = 0; $sumImpr = 0; $sumPosWeighted = 0;
  foreach($dateRows as $r){
    $clicks=$r['clicks'] ?? 0;
    $impr=$r['impressions'] ?? 0;
    $ctr=$impr>0?round($clicks/$impr*100,2):0;
    $pos=$r['position'] ?? 0;
    $dates[] = array('date'=>$r['keys'][0] ?? '', 'clicks'=>$clicks, 'impressions'=>$impr, 'ctr'=>$ctr, 'position'=>$pos);
    $sumClicks += $clicks;
    $sumImpr += $impr;
    $sumPosWeighted += $pos*$impr;
  }
  if(empty($data) && empty($dates)){
    echo json_encode(array('success'=>false,'message'=>'داده‌ای برای بازه زمانی انتخاب نشده'));
  }else{
    $avgCtr = $sumImpr>0?round($sumClicks/$sumImpr*100,2):0;
    $avgPos = $sumImpr>0?round($sumPosWeighted/$sumImpr,2):0;
    echo json_encode(array('success'=>true,'data'=>array('rows'=>$data,'dates'=>$dates,'summary'=>array('clicks'=>$sumClicks,'impressions'=>$sumImpr,'ctr'=>$avgCtr,'position'=>$avgPos))));
  }
  break;
case 'load_api_settings':
  $db = connect_local();
  if(!$db){ echo json_encode(array('success'=>false)); break; }
  $prefix = $_SESSION['logdb']['prefix'];
  $ipify = get_setting($db,$prefix,'ipify_key');
  $cid = get_setting($db,$prefix,'sc_client_id');
  if(!$cid) $cid = '1086032045880-46lhdagtc1os3bq3v3dq8p57lqkgk9gv.apps.googleusercontent.com';
  $secret = get_setting($db,$prefix,'sc_client_secret');
  if(!$secret) $secret = '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';
  $site = get_setting($db,$prefix,'sc_site');
  $refresh = get_setting($db,$prefix,'sc_refresh_token');
  $db->close();
  echo json_encode(array('success'=>true,'ipify'=>$ipify,'sc_client_id'=>$cid,'sc_client_secret'=>$secret,'sc_site'=>$site,'sc_refresh_token'=>$refresh));
  break;
case 'save_api_settings':
  $db = connect_local();
  if(!$db){ echo json_encode(array('success'=>false)); break; }
  $prefix = $_SESSION['logdb']['prefix'];
  $ipify = $_POST['ipify'] ?? '';
  $cid = $_POST['sc_client_id'] ?? '';
  $secret = $_POST['sc_client_secret'] ?? '';
  $site = $_POST['sc_site'] ?? '';
  $refresh = $_POST['sc_refresh_token'] ?? '';
  save_setting($db,$prefix,'ipify_key',$ipify);
  save_setting($db,$prefix,'sc_client_id',$cid);
  save_setting($db,$prefix,'sc_client_secret',$secret);
  save_setting($db,$prefix,'sc_site',$site);
  save_setting($db,$prefix,'sc_refresh_token',$refresh);
  $db->close();
  echo json_encode(array('success'=>true));
  break;
case 'sc_exchange_code':
  $code = $_POST['code'] ?? '';
  $redirect = $_POST['redirect'] ?? '';
  $db = connect_local();
  if(!$db){ echo json_encode(array('success'=>false,'message'=>'عدم اتصال به پایگاه داده سامانه')); break; }
  $prefix = $_SESSION['logdb']['prefix'];
  $cid = get_setting($db,$prefix,'sc_client_id');
  $secret = get_setting($db,$prefix,'sc_client_secret');
  if(!$code || !$cid || !$secret || !$redirect){
    $db->close();
    echo json_encode(array('success'=>false,'message'=>'پارامتر ناقص'));
    break;
  }
  $ch = curl_init('https://oauth2.googleapis.com/token');
  curl_setopt_array($ch,array(
    CURLOPT_POST=>true,
    CURLOPT_POSTFIELDS=>http_build_query(array(
      'code'=>$code,
      'client_id'=>$cid,
      'client_secret'=>$secret,
      'redirect_uri'=>$redirect,
      'grant_type'=>'authorization_code'
    )),
    CURLOPT_RETURNTRANSFER=>true
  ));
  $tok = curl_exec($ch);
  if($tok === false){ $db->close(); echo json_encode(array('success'=>false,'message'=>'token error')); break; }
  $tok = json_decode($tok,true);
  $refresh = $tok['refresh_token'] ?? '';
  if(!$refresh){ $db->close(); echo json_encode(array('success'=>false,'message'=>'refresh token missing')); break; }
  save_setting($db,$prefix,'sc_refresh_token',$refresh);
  $db->close();
  echo json_encode(array('success'=>true));
  break;
case 'admin_check':
  $db = connect_local();
  if(!$db){ echo json_encode(array('success'=>false)); break; }
  $prefix = $_SESSION['logdb']['prefix'];
  $res = $db->query("SELECT COUNT(*) AS c FROM {$prefix}users WHERE role_id=1");
  $row = $res ? $res->fetch_assoc() : array('c'=>0);
  $db->close();
  echo json_encode(array('success'=>true,'exists'=>$row['c']>0));
  break;
case 'admin_init':
  $db = connect_local();
  if(!$db){ echo json_encode(array('success'=>false,'message'=>'عدم اتصال به پایگاه داده سامانه')); break; }
  $mgr = new UserManager($db,$_SESSION['logdb']['prefix']);
  $username = trim($_POST['username'] ?? '');
  $password = $_POST['password'] ?? '';
  if(!$username || !$password){ echo json_encode(array('success'=>false,'message'=>'نام کاربری و رمز عبور الزامی است')); $db->close(); break; }
  $data = array(
    'username'=>$username,
    'password'=>$password,
    'full_name'=>'',
    'phone_number'=>'',
    'role_id'=>1,
    'status'=>'active'
  );
  $ok = $mgr->create($data);
  $db->close();
  session_unset();
  session_destroy();
  echo json_encode(array('success'=>$ok,'message'=>$ok?'':'خطا در ذخیره'));
  break;
case 'users_list':
  if(!has_perm('view_users')){ echo json_encode(array('success'=>false,'message'=>'عدم دسترسی')); break; }
  $db = connect_local();
  if(!$db){ echo json_encode(array('success'=>false,'message'=>'عدم اتصال به پایگاه داده سامانه')); break; }
  $mgr = new UserManager($db, $_SESSION['logdb']['prefix']);
  echo json_encode(array('success'=>true,'data'=>$mgr->all()));
  $db->close();
  break;
case 'user_get':
  if(!has_perm('view_users')){ echo json_encode(array('success'=>false,'message'=>'عدم دسترسی')); break; }
  $db = connect_local();
  if(!$db){ echo json_encode(array('success'=>false,'message'=>'عدم اتصال به پایگاه داده سامانه')); break; }
  $mgr = new UserManager($db,$_SESSION['logdb']['prefix']);
  $id = intval($_POST['id'] ?? 0);
  $data = $mgr->get($id);
  if($data){ echo json_encode(array('success'=>true,'data'=>$data)); }
  else { echo json_encode(array('success'=>false,'message'=>'کاربر یافت نشد')); }
  $db->close();
  break;
case 'user_create':
  if(!has_perm('view_users')){ echo json_encode(array('success'=>false,'message'=>'عدم دسترسی')); break; }
  $db = connect_local();
  if(!$db){ echo json_encode(array('success'=>false,'message'=>'عدم اتصال به پایگاه داده سامانه')); break; }
  $mgr = new UserManager($db,$_SESSION['logdb']['prefix']);
  $username = trim($_POST['username'] ?? '');
  $password = $_POST['password'] ?? '';
  if(!$username || !$password){ echo json_encode(array('success'=>false,'message'=>'نام کاربری و رمز عبور الزامی است')); $db->close(); break; }
  $data = array(
    'username'=>$username,
    'password'=>$password,
    'full_name'=>$_POST['full_name'] ?? '',
    'phone_number'=>$_POST['phone_number'] ?? '',
    'role_id'=>intval($_POST['role_id'] ?? 0),
    'status'=>$_POST['status'] ?? 'active'
  );
  $ok = $mgr->create($data);
  echo json_encode(array('success'=>$ok,'message'=>$ok?'':'خطا در ذخیره'));
  $db->close();
  break;
case 'user_update':
  if(!has_perm('view_users')){ echo json_encode(array('success'=>false,'message'=>'عدم دسترسی')); break; }
  $db = connect_local();
  if(!$db){ echo json_encode(array('success'=>false,'message'=>'عدم اتصال به پایگاه داده سامانه')); break; }
  $mgr = new UserManager($db,$_SESSION['logdb']['prefix']);
  $id = intval($_POST['id'] ?? 0);
  $username = trim($_POST['username'] ?? '');
  if(!$id || !$username){ echo json_encode(array('success'=>false,'message'=>'داده نامعتبر')); $db->close(); break; }
  $data = array(
    'username'=>$username,
    'password'=>$_POST['password'] ?? '',
    'full_name'=>$_POST['full_name'] ?? '',
    'phone_number'=>$_POST['phone_number'] ?? '',
    'role_id'=>intval($_POST['role_id'] ?? 0),
    'status'=>$_POST['status'] ?? 'active'
  );
  $ok = $mgr->update($id,$data);
  echo json_encode(array('success'=>$ok,'message'=>$ok?'':'خطا در ذخیره'));
  $db->close();
  break;
case 'user_delete':
  if(!has_perm('view_users')){ echo json_encode(array('success'=>false,'message'=>'عدم دسترسی')); break; }
  $db = connect_local();
  if(!$db){ echo json_encode(array('success'=>false,'message'=>'عدم اتصال به پایگاه داده سامانه')); break; }
  $mgr = new UserManager($db,$_SESSION['logdb']['prefix']);
  $id = intval($_POST['id'] ?? 0);
  $ok = $mgr->delete($id);
  echo json_encode(array('success'=>$ok,'message'=>$ok?'':'حذف نشد'));
  $db->close();
  break;
case 'list_categories':
  if(!has_perm('view_assignments')){ echo json_encode(array('success'=>false,'message'=>'عدم دسترسی')); break; }
  $db = connect(); if(!$db) break;
  $prefix = $_SESSION['db']['prefix'];
  $rows = array();
  $res = $db->query("SELECT t.term_id,t.name FROM {$prefix}terms t JOIN {$prefix}term_taxonomy tt ON t.term_id=tt.term_id WHERE tt.taxonomy='product_cat'");
  if($res){ while($r=$res->fetch_assoc()){ $rows[] = array('id'=>$r['term_id'],'name'=>$r['name']); } }
  $db->close();
  echo json_encode(array('success'=>true,'data'=>$rows));
  break;
case 'product_total':
  if(!has_perm('view_assignments')){ echo json_encode(array('success'=>false,'message'=>'عدم دسترسی')); break; }
  $db = connect(); if(!$db) break;
  $prefix = $_SESSION['db']['prefix'];
  $cnt = 0;
  $res = $db->query("SELECT COUNT(*) c FROM {$prefix}posts WHERE post_type='product' AND post_status='publish'");
  if($res){ $row = $res->fetch_assoc(); $cnt = intval($row['c']); }
  $db->close();
  echo json_encode(array('success'=>true,'total'=>$cnt));
  break;
case 'unassigned_products':
  if(!has_perm('view_assignments')){ echo json_encode(array('success'=>false,'message'=>'عدم دسترسی')); break; }
  $db = connect(); if(!$db) break;
  $ldb = connect_local(); if(!$ldb){ echo json_encode(array('success'=>false,'data'=>array())); $db->close(); break; }
  $prefix = $_SESSION['db']['prefix'];
  $lp = $_SESSION['logdb']['prefix'];
  $assigned = array();
  $ares = $ldb->query("SELECT product_id FROM {$lp}product_assignments");
  if($ares){ while($a=$ares->fetch_assoc()){ $assigned[] = intval($a['product_id']); } $ares->close(); }
  $search = isset($_POST['q']) ? trim($_POST['q']) : '';
  $query = "SELECT ID,post_title FROM {$prefix}posts WHERE post_type='product' AND post_status='publish'";
  if($assigned){ $query .= " AND ID NOT IN (".implode(',',$assigned).")"; }
  if($search !== ''){ $query .= " AND post_title LIKE '%".$db->real_escape_string($search)."%'"; }
  $query .= " ORDER BY ID DESC LIMIT 50";
  $rows = array();
  $res = $db->query($query);
  if($res){ while($r=$res->fetch_assoc()){ $rows[] = array('id'=>$r['ID'],'text'=>$r['post_title']); } }
  $db->close();
  $ldb->close();
  echo json_encode(array('success'=>true,'data'=>$rows));
  break;
case 'assign_quota':
  $user = intval($_POST['user_id'] ?? 0);
  $count = intval($_POST['count'] ?? 0);
  if(!$user || $count<=0){ echo json_encode(array('success'=>false,'message'=>'داده نامعتبر')); break; }
  if(!has_perm('view_assignments')){ echo json_encode(array('success'=>false,'message'=>'عدم دسترسی')); break; }
  $db = connect(); if(!$db) break;
  $ldb = connect_local(); if(!$ldb){ echo json_encode(array('success'=>false,'message'=>'عدم اتصال به پایگاه داده سامانه')); $db->close(); break; }
  $wp = $_SESSION['db']['prefix'];
  $lp = $_SESSION['logdb']['prefix'];
  $assigned = array();
  $ares = $ldb->query("SELECT product_id FROM {$lp}product_assignments");
  if($ares){ while($a=$ares->fetch_assoc()){ $assigned[] = intval($a['product_id']); } }
  $ares && $ares->close();
  $query = "SELECT ID FROM {$wp}posts WHERE post_type='product' AND post_status='publish'";
  if($assigned){ $query .= " AND ID NOT IN (".implode(',',$assigned).")"; }
  $query .= " LIMIT $count";
  $res = $db->query($query);
  $inserted = 0;
  if($res){
    while($r=$res->fetch_assoc()){
      $pid = intval($r['ID']);
      $stmt = $ldb->prepare("INSERT INTO {$lp}product_assignments (user_id,product_id) VALUES (?,?)");
      if($stmt){ $stmt->bind_param('ii',$user,$pid); if($stmt->execute()) $inserted++; $stmt->close(); }
    }
    $res->close();
  }
  $db->close();
  $ldb->close();
  echo json_encode(array('success'=>true,'assigned'=>$inserted));
  break;
case 'assign_category':
  $user = intval($_POST['user_id'] ?? 0);
  $cat = intval($_POST['cat_id'] ?? 0);
  if(!$user || !$cat){ echo json_encode(array('success'=>false,'message'=>'داده نامعتبر')); break; }
  if(!has_perm('view_assignments')){ echo json_encode(array('success'=>false,'message'=>'عدم دسترسی')); break; }
  $db = connect(); if(!$db) break;
  $ldb = connect_local(); if(!$ldb){ echo json_encode(array('success'=>false,'message'=>'عدم اتصال به پایگاه داده سامانه')); $db->close(); break; }
  $wp = $_SESSION['db']['prefix'];
  $lp = $_SESSION['logdb']['prefix'];
  $assigned = array();
  $ares = $ldb->query("SELECT product_id FROM {$lp}product_assignments");
  if($ares){ while($a=$ares->fetch_assoc()){ $assigned[] = intval($a['product_id']); } }
  $ares && $ares->close();
  $query = "SELECT p.ID FROM {$wp}posts p JOIN {$wp}term_relationships tr ON p.ID=tr.object_id JOIN {$wp}term_taxonomy tt ON tr.term_taxonomy_id=tt.term_taxonomy_id WHERE p.post_type='product' AND p.post_status='publish' AND tt.taxonomy='product_cat' AND tt.term_id=$cat";
  if($assigned){ $query .= " AND p.ID NOT IN (".implode(',',$assigned).")"; }
  $res = $db->query($query);
  $inserted = 0;
  if($res){
    while($r=$res->fetch_assoc()){
      $pid = intval($r['ID']);
      $stmt = $ldb->prepare("INSERT INTO {$lp}product_assignments (user_id,product_id) VALUES (?,?)");
      if($stmt){ $stmt->bind_param('ii',$user,$pid); if($stmt->execute()) $inserted++; $stmt->close(); }
    }
    $res->close();
  }
  $db->close();
  $ldb->close();
  echo json_encode(array('success'=>true,'assigned'=>$inserted));
  break;
case 'assign_manual':
  $user = intval($_POST['user_id'] ?? 0);
  $ids = isset($_POST['ids']) ? $_POST['ids'] : '';
  $arr = array_filter(array_map('intval',explode(',', $ids)));
  if(!$user || !$arr){ echo json_encode(array('success'=>false,'message'=>'داده نامعتبر')); break; }
  if(!has_perm('view_assignments')){ echo json_encode(array('success'=>false,'message'=>'عدم دسترسی')); break; }
  $ldb = connect_local(); if(!$ldb){ echo json_encode(array('success'=>false,'message'=>'عدم اتصال به پایگاه داده سامانه')); break; }
  $lp = $_SESSION['logdb']['prefix'];
  $inserted=0; $conflicts=array();
  foreach($arr as $pid){
    $check = $ldb->query("SELECT user_id FROM {$lp}product_assignments WHERE product_id=$pid");
  if($check && $check->num_rows){
      $assigned = intval($check->fetch_assoc()['user_id']);
      if($assigned != $user){ $conflicts[]=$pid; continue; }
    }
    $stmt = $ldb->prepare("INSERT INTO {$lp}product_assignments (user_id,product_id) VALUES (?,?)");
  if($stmt){ $stmt->bind_param('ii',$user,$pid); if($stmt->execute()) $inserted++; $stmt->close(); }
  }
  $ldb->close();
  if($conflicts){ echo json_encode(array('success'=>false,'message'=>'برخی محصولات قبلاً اختصاص یافته‌اند')); }
  else{ echo json_encode(array('success'=>true,'assigned'=>$inserted)); }
  break;

case 'assignment_users':
  if(!has_perm('view_assignments')){ echo json_encode(array('success'=>false,'message'=>'عدم دسترسی')); break; }
  $ldb = connect_local();
  if(!$ldb){ echo json_encode(array('success'=>false,'data'=>array())); break; }
  $lp = $_SESSION['logdb']['prefix'];
  $res = $ldb->query("SELECT u.id,u.username,am.mode, (SELECT COUNT(*) FROM {$lp}product_assignments pa WHERE pa.user_id=u.id) AS cnt FROM {$lp}users u LEFT JOIN {$lp}assignment_modes am ON am.user_id=u.id");
  $rows=array();
  if($res){ while($r=$res->fetch_assoc()){ $rows[]=$r; } }
  echo json_encode(array('success'=>true,'data'=>$rows));
  $ldb->close();
  break;

case 'get_assign_mode':
  $uid = intval($_POST['user_id'] ?? 0);
  if(!has_perm('view_assignments')){ echo json_encode(array('success'=>false,'message'=>'عدم دسترسی')); break; }
  $ldb = connect_local();
  if(!$ldb){ echo json_encode(array('success'=>false)); break; }
  $lp = $_SESSION['logdb']['prefix'];
  $row = null;
  $res = $ldb->query("SELECT mode,quota_min,quota_max,category_id FROM {$lp}assignment_modes WHERE user_id=$uid");
  if($res){ $row = $res->fetch_assoc(); }
  echo json_encode(array('success'=>true,'data'=>$row));
  $ldb->close();
  break;

case 'set_assign_mode':
  $uid = intval($_POST['user_id'] ?? 0);
  $mode = $_POST['mode'] ?? '';
  if(!has_perm('view_assignments')){ echo json_encode(array('success'=>false,'message'=>'عدم دسترسی')); break; }
  $ldb = connect_local();
  if(!$ldb){ echo json_encode(array('success'=>false,'message'=>'عدم اتصال به پایگاه داده سامانه')); break; }
  $lp = $_SESSION['logdb']['prefix'];
  $stmt = $ldb->prepare("INSERT INTO {$lp}assignment_modes(user_id,mode,quota_min,quota_max,category_id) VALUES(?,?,?,?,?) ON DUPLICATE KEY UPDATE mode=VALUES(mode), quota_min=VALUES(quota_min), quota_max=VALUES(quota_max), category_id=VALUES(category_id)");
  $qmin = intval($_POST['quota_min'] ?? 0);
  $qmax = intval($_POST['quota_max'] ?? 0);
  $cat = intval($_POST['category_id'] ?? 0);
  $stmt->bind_param('isiii',$uid,$mode,$qmin,$qmax,$cat);
  $ok = $stmt->execute();
  $stmt->close();
  $ldb->close();
  echo json_encode(array('success'=>$ok));
  break;

case 'user_assignments':
  $uid = intval($_POST['user_id'] ?? 0);
  if(!has_perm('view_assignments')){ echo json_encode(array('success'=>false,'message'=>'عدم دسترسی')); break; }
  $db = connect(); if(!$db) break;
  $ldb = connect_local(); if(!$ldb){ echo json_encode(array('success'=>false,'data'=>array())); $db->close(); break; }
  $wp = $_SESSION['db']['prefix'];
  $lp = $_SESSION['logdb']['prefix'];
  $res = $ldb->query("SELECT product_id FROM {$lp}product_assignments WHERE user_id=$uid");
  $ids=array();
  if($res){ while($r=$res->fetch_assoc()){ $ids[] = intval($r['product_id']); } }
  $rows=array();
  if($ids){
    $idlist = implode(',',$ids);
    $pres = $db->query("SELECT ID,post_title FROM {$wp}posts WHERE ID IN ($idlist)");
  if($pres){ while($p=$pres->fetch_assoc()){ $rows[] = array('id'=>$p['ID'],'title'=>$p['post_title']); } }
  }
  $db->close();
  $ldb->close();
  echo json_encode(array('success'=>true,'data'=>$rows));
  break;

case 'remove_assignment':
  if(!has_perm('view_assignments')){ echo json_encode(array('success'=>false,'message'=>'عدم دسترسی')); break; }
  $uid = intval($_POST['user_id'] ?? 0);
  $pid = intval($_POST['product_id'] ?? 0);
  $ldb = connect_local(); if(!$ldb){ echo json_encode(array('success'=>false)); break; }
  $lp = $_SESSION['logdb']['prefix'];
  $stmt = $ldb->prepare("DELETE FROM {$lp}product_assignments WHERE user_id=? AND product_id=?");
  $stmt->bind_param('ii',$uid,$pid);
  $ok = $stmt->execute();
  $stmt->close();
  $ldb->close();
  echo json_encode(array('success'=>$ok));
  break;

case 'transfer_assignment':
  if(!has_perm('view_assignments')){ echo json_encode(array('success'=>false,'message'=>'عدم دسترسی')); break; }
  $pid = intval($_POST['product_id'] ?? 0);
  $target = intval($_POST['target_user'] ?? 0);
  $ldb = connect_local(); if(!$ldb){ echo json_encode(array('success'=>false,'message'=>'عدم اتصال به پایگاه داده سامانه')); break; }
  $lp = $_SESSION['logdb']['prefix'];
  $check = $ldb->query("SELECT user_id FROM {$lp}product_assignments WHERE product_id=$pid");
  if(!$check || !$check->num_rows){ echo json_encode(array('success'=>false,'message'=>'محصول یافت نشد')); $ldb->close(); break; }
  $current = intval($check->fetch_assoc()['user_id']);
  if($current == $target){ echo json_encode(array('success'=>true)); $ldb->close(); break; }
  $stmt = $ldb->prepare("UPDATE {$lp}product_assignments SET user_id=? WHERE product_id=?");
  $stmt->bind_param('ii',$target,$pid);
  $ok = $stmt->execute();
  $stmt->close();
  $ldb->close();
  echo json_encode(array('success'=>$ok));
  break;
case 'roles_list':
  $db = connect_local();
  if(!$db){ echo json_encode(array('success'=>false,'message'=>'عدم اتصال به پایگاه داده سامانه')); break; }
  $prefix = $_SESSION['logdb']['prefix'];
  $res = $db->query("SELECT id,name,permissions FROM {$prefix}roles ORDER BY id ASC");
  $rows = array();
  if($res){ while($r=$res->fetch_assoc()){ $rows[]=$r; } }
  echo json_encode(array('success'=>true,'data'=>$rows));
  $db->close();
  break;
case 'role_get':
  $db = connect_local();
  if(!$db){ echo json_encode(array('success'=>false,'message'=>'عدم اتصال به پایگاه داده سامانه')); break; }
  $prefix = $_SESSION['logdb']['prefix'];
  $id = intval($_POST['id'] ?? 0);
  $stmt = $db->prepare("SELECT id,name,permissions FROM {$prefix}roles WHERE id=?");
  $stmt->bind_param('i',$id);
  $stmt->execute();
  $res = $stmt->get_result();
  $row = $res ? $res->fetch_assoc() : null;
  $stmt->close();
  echo json_encode(array('success'=> $row?true:false,'data'=>$row));
  $db->close();
  break;
case 'role_save':
  $db = connect_local();
  if(!$db){ echo json_encode(array('success'=>false,'message'=>'عدم اتصال به پایگاه داده سامانه')); break; }
  $prefix = $_SESSION['logdb']['prefix'];
  $id = intval($_POST['id'] ?? 0);
  $name = trim($_POST['name'] ?? '');
  $perms = $_POST['permissions'] ?? '';
  if(!$name){ echo json_encode(array('success'=>false,'message'=>'نام نقش الزامی است')); $db->close(); break; }
  if($id==1){ $perms='all'; }
  if($id){
    $stmt = $db->prepare("UPDATE {$prefix}roles SET name=?,permissions=? WHERE id=?");
    $stmt->bind_param('ssi',$name,$perms,$id);
  }else{
    $stmt = $db->prepare("INSERT INTO {$prefix}roles(name,permissions) VALUES (?,?)");
    $stmt->bind_param('ss',$name,$perms);
  }
  $ok = $stmt->execute();
  $stmt->close();
  echo json_encode(array('success'=>$ok,'message'=>$ok?'':'خطا در ذخیره'));
  $db->close();
  break;
case 'role_delete':
  $db = connect_local();
  if(!$db){ echo json_encode(array('success'=>false,'message'=>'عدم اتصال به پایگاه داده سامانه')); break; }
  $prefix = $_SESSION['logdb']['prefix'];
  $id = intval($_POST['id'] ?? 0);
  if($id==1){ echo json_encode(array('success'=>false,'message'=>'نقش مدیر کل قابل حذف نیست')); $db->close(); break; }
  $stmt = $db->prepare("DELETE FROM {$prefix}roles WHERE id=?");
  $stmt->bind_param('i',$id);
  $ok = $stmt->execute();
  $stmt->close();
  echo json_encode(array('success'=>$ok,'message'=>$ok?'':'حذف نشد'));
  $db->close();
  break;
case 'list_products':
  $db = connect(); if(!$db) break;
  $prefix = $_SESSION['db']['prefix'];
  $perm = $_SESSION['permissions'] ?? '';
  $query = "SELECT ID,post_title,post_content,post_name FROM {$prefix}posts WHERE post_type='product' AND post_status='publish'";
  if($perm !== 'all'){
      $ldb = connect_local();
      if(!$ldb){ echo json_encode(array('success'=>false,'message'=>'عدم اتصال به پایگاه داده سامانه')); $db->close(); break; }
      $lp = $_SESSION['logdb']['prefix'];
      $uid = intval($_SESSION['user_id']);
      $ids = array();
      $ires = $ldb->query("SELECT product_id FROM {$lp}product_assignments WHERE user_id=$uid");
      if($ires){ while($i=$ires->fetch_assoc()){ $ids[] = intval($i['product_id']); } $ires->close(); }
      $ldb->close();
      if($ids){
        $query .= " AND ID IN (".implode(',', $ids).")";
      }else{
        // اگر هیچ تخصیصی وجود نداشته باشد هیچ محصولی نمایش داده نشود
        $query .= " AND 1=0";
      }
    }
  try{
    $res = $db->query($query);
  if(!$res){ throw new Exception($db->error); }
    $rows = array();
    $scheme = isset($_SERVER['REQUEST_SCHEME']) ? $_SERVER['REQUEST_SCHEME'] : 'http';
    $site = $scheme.'://'.$_SERVER['HTTP_HOST'];
    $scores = array();
    $ldb = connect_local();
  if($ldb){
      $lp = $_SESSION['logdb']['prefix'];
      $scRes = $ldb->query("SELECT product_id,score FROM {$lp}product_seo_scores");
      if($scRes){ while($sc=$scRes->fetch_assoc()){ $scores[intval($sc['product_id'])]=intval($sc['score']); } $scRes->close(); }
      $ldb->close();
    }
    while($row = $res->fetch_assoc()){
        $id = $row['ID'];
        $imgRes = $db->query("SELECT p2.guid FROM {$prefix}postmeta pm JOIN {$prefix}posts p2 ON p2.ID = pm.meta_value WHERE pm.post_id=$id AND pm.meta_key='_thumbnail_id' ORDER BY pm.meta_id DESC LIMIT 1");
        $imgRow = $imgRes ? $imgRes->fetch_assoc() : null; $image = ($imgRow && isset($imgRow['guid'])) ? $imgRow['guid'] : '';
        $priceRes = $db->query("SELECT meta_value FROM {$prefix}postmeta WHERE post_id=$id AND meta_key='_price'");
        $priceRow = $priceRes ? $priceRes->fetch_assoc() : null; $price = ($priceRow && isset($priceRow['meta_value'])) ? $priceRow['meta_value'] : '';
        $stockRes = $db->query("SELECT meta_value FROM {$prefix}postmeta WHERE post_id=$id AND meta_key='_stock_status'");
        $stockRow = $stockRes ? $stockRes->fetch_assoc() : null; $stock = ($stockRow && isset($stockRow['meta_value'])) ? $stockRow['meta_value'] : 'instock';
        $priceDisplay = ($price && $price !== '0') ? $price : 'بدون قیمت';
        $stockDisplay = $stock=='instock' ? 'موجود' : 'ناموجود';
        $productUrl = rtrim($site,'/').'/'.$row['post_name'].'/';
        $rows[] = array(
          'id'=>$id,
          'image'=>$image,
          'name'=>$row['post_title'],
          'price'=>$priceDisplay,
          'stock'=>$stockDisplay,
          'score'=> isset($scores[$id]) ? $scores[$id] : null,
          'link'=>$productUrl
        );
    }
    echo json_encode(array('success'=>true,'data'=>$rows));
  }catch(Exception $e){
    echo json_encode(array('success'=>false,'message'=>$e->getMessage()));
  }finally{
    $db->close();
  }
  break;

case 'list_prices':
  $steps=[]; $flags=['db'=>false,'query'=>false];
  $db = connect();
  if(!$db){
    $steps[]='db connection failed';
    echo json_encode(['success'=>false,'message'=>'عدم اتصال به دیتابیس','steps'=>$steps,'flags'=>$flags]);
    break;
  }
  $flags['db']=true; $steps[]='db connected';
  $prefix = $_SESSION['db']['prefix'];
  $limit=200; $steps[]='limit set to '.$limit;
  $start=microtime(true);
  $sql = "SELECT p.ID,p.post_title,pr.meta_value price,im.guid image FROM {$prefix}posts p LEFT JOIN {$prefix}postmeta pr ON p.ID=pr.post_id AND pr.meta_key='_price' LEFT JOIN {$prefix}postmeta tm ON p.ID=tm.post_id AND tm.meta_key='_thumbnail_id' LEFT JOIN {$prefix}posts im ON im.ID=tm.meta_value WHERE p.post_type='product' AND p.post_status='publish' ORDER BY p.ID LIMIT $limit";
  $res = $db->query($sql);
  $rows=array();
  if($res){
    while($r=$res->fetch_assoc()){
      $rows[]=array('id'=>$r['ID'],'name'=>$r['post_title'],'price'=>$r['price']?:'0','image'=>$r['image']);
    }
    $flags['query']=true;
    $steps[]='products fetched: '.count($rows);
    $steps[]='query time: '.round((microtime(true)-$start)*1000).'ms';
    $res->close();
  }else{
    $steps[]='query error: '.$db->error;
    echo json_encode(['success'=>false,'message'=>'خطای دیتابیس','steps'=>$steps,'flags'=>$flags]);
    $db->close();
    break;
  }
  $db->close();
  echo json_encode(['success'=>true,'data'=>$rows,'steps'=>$steps,'flags'=>$flags]);
  break;

case 'update_price':
  $steps=[]; $flags=['db'=>false,'update'=>false];
  $db = connect();
  if(!$db){
    $steps[]='db connection failed';
    echo json_encode(['success'=>false,'message'=>'عدم اتصال به دیتابیس','steps'=>$steps,'flags'=>$flags]);
    break;
  }
  $flags['db']=true; $steps[]='db connected';
  $prefix = $_SESSION['db']['prefix'];
  $id = intval($_POST['id']);
  $price = $db->real_escape_string($_POST['price']);
  $meta = $db->query("SELECT meta_id FROM {$prefix}postmeta WHERE post_id=$id AND meta_key='_price'");
  if($meta && $meta->num_rows){
    $db->query("UPDATE {$prefix}postmeta SET meta_value='$price' WHERE post_id=$id AND meta_key='_price'");
    $steps[]='price updated';
  } else {
    $db->query("INSERT INTO {$prefix}postmeta(post_id,meta_key,meta_value) VALUES ($id,'_price','$price')");
    $steps[]='price inserted';
  }
  if($db->affected_rows>=0){ $flags['update']=true; }
  $meta && $meta->close();
  $db->close();
  echo json_encode(['success'=>true,'steps'=>$steps,'flags'=>$flags]);
  break;
case 'get_product':
  $db = connect(); if(!$db) break;
  $prefix = $_SESSION['db']['prefix'];
  $id = intval($_POST['id']);
  $perm = $_SESSION['permissions'] ?? '';
  if($perm !== 'all'){
    $ldb = connect_local();
  if(!$ldb){ echo json_encode(array('success'=>false,'message'=>'عدم اتصال به پایگاه داده سامانه')); $db->close(); break; }
    $lp = $_SESSION['logdb']['prefix'];
    $uid = intval($_SESSION['user_id']);
    $check = $ldb->query("SELECT 1 FROM {$lp}product_assignments WHERE user_id=$uid AND product_id=$id");
    $allowed = ($check && $check->num_rows>0);
    $check && $check->close();
    $ldb->close();
  if(!$allowed){ $db->close(); echo json_encode(array('success'=>false,'message'=>'دسترسی غیرمجاز')); break; }
  }
  $pRes = $db->query("SELECT post_title,post_content,post_name FROM {$prefix}posts WHERE ID=$id");
  $p = $pRes ? $pRes->fetch_assoc() : null;
  if(!$p){ echo json_encode(array('success'=>false,'message'=>'محصول یافت نشد')); break; }
  $priceRes = $db->query("SELECT meta_value FROM {$prefix}postmeta WHERE post_id=$id AND meta_key='_price'");
  $priceRow = $priceRes ? $priceRes->fetch_assoc() : null; $price = ($priceRow && isset($priceRow['meta_value'])) ? $priceRow['meta_value'] : '';
  $skuRes = $db->query("SELECT meta_value FROM {$prefix}postmeta WHERE post_id=$id AND meta_key='_sku'");
  $skuRow = $skuRes ? $skuRes->fetch_assoc() : null; $model = ($skuRow && isset($skuRow['meta_value'])) ? $skuRow['meta_value'] : '';
  $stockRes = $db->query("SELECT meta_value FROM {$prefix}postmeta WHERE post_id=$id AND meta_key='_stock_status'");
  $stockRow = $stockRes ? $stockRes->fetch_assoc() : null; $stock = ($stockRow && isset($stockRow['meta_value'])) ? $stockRow['meta_value'] : 'instock';
  $titleRes = $db->query("SELECT meta_value FROM {$prefix}postmeta WHERE post_id=$id AND meta_key='_yoast_wpseo_title'");
  $titleRow = $titleRes ? $titleRes->fetch_assoc() : null; $seoTitle = ($titleRow && isset($titleRow['meta_value'])) ? $titleRow['meta_value'] : '';
  $descRes = $db->query("SELECT meta_value FROM {$prefix}postmeta WHERE post_id=$id AND meta_key='_yoast_wpseo_metadesc'");
  $descRow = $descRes ? $descRes->fetch_assoc() : null; $seoDesc = ($descRow && isset($descRow['meta_value'])) ? $descRow['meta_value'] : '';
  $focusRes = $db->query("SELECT meta_value FROM {$prefix}postmeta WHERE post_id=$id AND meta_key='_yoast_wpseo_focuskw'");
  $focusRow = $focusRes ? $focusRes->fetch_assoc() : null; $primaryKeyword = ($focusRow && isset($focusRow['meta_value'])) ? $focusRow['meta_value'] : '';
  $catsRes = $db->query("SELECT t.term_id,t.name,t.slug FROM {$prefix}terms t JOIN {$prefix}term_taxonomy tt ON t.term_id=tt.term_id JOIN {$prefix}term_relationships tr ON tr.term_taxonomy_id=tt.term_taxonomy_id WHERE tt.taxonomy='product_cat' AND tr.object_id=$id");
  $selected = array();
  if($catsRes){ while($c=$catsRes->fetch_assoc()) $selected[]=$c; }
  $selectedIds = array_column($selected,'term_id');
  $allCats = $db->query("SELECT t.term_id,t.name,t.slug FROM {$prefix}terms t JOIN {$prefix}term_taxonomy tt ON t.term_id=tt.term_id WHERE tt.taxonomy='product_cat'");
  $catsHtml='';
  if($allCats){
    while($c=$allCats->fetch_assoc()){
      $idAttr='cat'.$c['term_id'];
      $checked=in_array($c['term_id'],$selectedIds)?'checked':'';
      $catsHtml.='<input type="checkbox" class="btn-check" id="'.$idAttr.'" name="cats[]" value="'.$c['term_id'].'" '.$checked.'>';
      $catsHtml.='<label class="btn btn-outline-primary m-1" for="'.$idAttr.'">'.$c['name'].'</label> ';
    }
  }
  $scheme = isset($_SERVER['REQUEST_SCHEME']) ? $_SERVER['REQUEST_SCHEME'] : 'http';
  $site = $scheme.'://'.$_SERVER['HTTP_HOST'];
  $imgRes = $db->query("SELECT p2.guid FROM {$prefix}postmeta pm JOIN {$prefix}posts p2 ON p2.ID = pm.meta_value WHERE pm.post_id=$id AND pm.meta_key='_thumbnail_id' ORDER BY pm.meta_id DESC LIMIT 1");
  $imgRow = $imgRes ? $imgRes->fetch_assoc() : null; $image = ($imgRow && isset($imgRow['guid'])) ? $imgRow['guid'] : '';
  $categoryUrl = '';
  if(!empty($selected)){ $categoryUrl = rtrim($site,'/').'/'.$selected[0]['slug'].'/'; }
  $productUrl = rtrim($site,'/').'/'.$p['post_name'].'/';
  $categoriesList = implode('، ', array_column($selected,'name'));
  $catLinksHtml = '<ul>'; foreach($selected as $c){ $catLinksHtml.='<li><a href="'.rtrim($site,'/').'/'.$c['slug'].'/">'.$c['name'].'</a></li>'; } $catLinksHtml.='</ul>';
  $shippingNotes='';
  $seo_prompt = build_prompt(array(
    '{{PRODUCT_TITLE}}'=>$p['post_title'],
    '{{BRAND_OR_SERIES}}'=>'',
    '{{MODEL_CODE}}'=>$model,
    '{{PRODUCT_URL}}'=>$productUrl,
    '{{PRODUCT_IMAGE_URL}}'=>$image,
    '{{CATEGORIES_LIST}}'=>$categoriesList,
    '{{PRIMARY_KEYWORD}}'=>$primaryKeyword ?: $p['post_title'],
    '{{CATEGORY_LINKS_HTML}}'=>$catLinksHtml,
    '{{SHIPPING_WARRANTY_NOTES}}'=>$shippingNotes,
    '{{SIZE_WEIGHT}}'=>'',
    '{{COLORS}}'=>'',
    '{{OTHER_SPECS}}'=>'',
    '{{VALUE_1}}'=>'',
    '{{ALT_1}}'=>'',
    '{{VALUE_2}}'=>'',
    '{{ALT_2}}'=>'',
    '{{VALUE_3}}'=>'',
    '{{ALT_3}}'=>'',
    '{{RELATED_TOPIC_1}}'=>'',
    '{{RELATED_TOPIC_2}}'=>''
  ), $selected, $p['post_content']);
  $siteBase = $_SESSION['site_base_url'] ?? '';
  $analysis = SEOAnalyzer::analyze($seoTitle ?: $p['post_title'], $seoDesc, $p['post_content'], $primaryKeyword ?: $p['post_title'], $siteBase);
  echo json_encode(array(
    'success'=>true,
    'product'=>array('id'=>$id,'name'=>$p['post_title'],'slug'=>$p['post_name'],'description'=>$p['post_content'],'price'=>$price),
    'categories_html'=>$catsHtml,
    'seo_prompt'=>$seo_prompt,
    'seo_title'=>$seoTitle,
    'seo_desc'=>$seoDesc,
    'seo_score'=>$analysis['score'],
    'seo_details'=>$analysis['details'],
    'focus_keyword'=>$primaryKeyword,
    'stock_status'=>$stock,
    'product_url'=>$productUrl
  ));
  $db->close();
  break;
case 'save_product':
  $db = connect(); if(!$db) break;
  $prefix = $_SESSION['db']['prefix'];
  $id = intval($_POST['id']);
  $nameRaw = $_POST['name'] ?? '';
  $slugRaw = $_POST['slug'] ?? '';
  $oldSlugInput = $_POST['old_slug'] ?? '';
  $descRaw = $_POST['description'] ?? '';
  $priceRaw = $_POST['price'] ?? '';
  $stockRaw = $_POST['stock_status'] ?? '';
  $seoTitleInput = $_POST['seo_title'] ?? '';
  $seoDescInput = $_POST['seo_desc'] ?? '';
  $focusKwInput = $_POST['focus_kw'] ?? '';
  $perm = $_SESSION['permissions'] ?? '';
  $ldb = connect_local();
  if(!$ldb){ $db->close(); echo json_encode(array('success'=>false,'message'=>'عدم اتصال به پایگاه داده سامانه')); break; }
  $lp = $_SESSION['logdb']['prefix'];
  ensure_kpi_tables($ldb,$lp);
  $uid = intval($_SESSION['user_id']);
  $assignedUserId = null;
  $assignRes = $ldb->query("SELECT user_id FROM {$lp}product_assignments WHERE product_id=$id");
  if($assignRes){
    $assignRow = $assignRes->fetch_assoc();
    if($assignRow && $assignRow['user_id'] !== null){ $assignedUserId = intval($assignRow['user_id']); }
    $assignRes->close();
  }
  if($perm !== 'all' && $assignedUserId !== $uid){
    $ldb->close();
    $db->close();
    echo json_encode(array('success'=>false,'message'=>'دسترسی غیرمجاز'));
    break;
  }
  $prodRes = $db->query("SELECT post_title, post_name, post_content FROM {$prefix}posts WHERE ID=$id");
  $prodRow = $prodRes ? $prodRes->fetch_assoc() : null;
  if($prodRes){ $prodRes->close(); }
  $oldName = $prodRow ? $prodRow['post_title'] : '';
  $oldContent = $prodRow ? $prodRow['post_content'] : '';
  $oldSlug = $prodRow ? $prodRow['post_name'] : '';
  $metaMap = array();
  $metaRes = $db->query("SELECT meta_key, meta_value FROM {$prefix}postmeta WHERE post_id=$id AND meta_key IN ('_yoast_wpseo_title','_yoast_wpseo_metadesc','_yoast_wpseo_focuskw')");
  if($metaRes){
    while($m = $metaRes->fetch_assoc()){
      $metaMap[$m['meta_key']] = $m['meta_value'];
    }
    $metaRes->close();
  }
  $oldSeoTitle = $metaMap['_yoast_wpseo_title'] ?? $oldName;
  $oldSeoDesc = $metaMap['_yoast_wpseo_metadesc'] ?? '';
  $oldFocus = $metaMap['_yoast_wpseo_focuskw'] ?? $oldName;
  $siteBase = $_SESSION['site_base_url'] ?? '';
  $oldScore = null;
  $scoreRes = $ldb->query("SELECT score FROM {$lp}product_seo_scores WHERE product_id=$id");
  if($scoreRes){
    $scoreRow = $scoreRes->fetch_assoc();
    if($scoreRow && $scoreRow['score'] !== null){ $oldScore = floatval($scoreRow['score']); }
    $scoreRes->close();
  }
  if($oldScore === null){
    $analysisBefore = SEOAnalyzer::analyze($oldSeoTitle ?: $oldName, $oldSeoDesc, $oldContent, $oldFocus ?: $oldName, $siteBase);
    $oldScore = $analysisBefore['score'];
  }
  $wordsBefore = estimate_word_count($oldContent);
  $name = $db->real_escape_string($nameRaw);
  $slug = $db->real_escape_string($slugRaw);
  $old_slug = $db->real_escape_string($oldSlugInput);
  $desc = $db->real_escape_string($descRaw);
  $priceClean = preg_replace('/[^0-9.]/','',$priceRaw);
  $price = $db->real_escape_string($priceClean);
  $stock = $db->real_escape_string($stockRaw);
  $db->query("UPDATE {$prefix}posts SET post_title='$name', post_name='$slug', post_content='$desc' WHERE ID=$id");
  $meta = $db->query("SELECT meta_id FROM {$prefix}postmeta WHERE post_id=$id AND meta_key='_price'");
  if($meta && $meta->num_rows){
    $db->query("UPDATE {$prefix}postmeta SET meta_value='$price' WHERE post_id=$id AND meta_key='_price'");
  }else{
    $db->query("INSERT INTO {$prefix}postmeta(post_id,meta_key,meta_value) VALUES ($id,'_price','$price')");
  }
  if($meta){ $meta->close(); }
  $meta = $db->query("SELECT meta_id FROM {$prefix}postmeta WHERE post_id=$id AND meta_key='_stock_status'");
  if($meta && $meta->num_rows){
    $db->query("UPDATE {$prefix}postmeta SET meta_value='$stock' WHERE post_id=$id AND meta_key='_stock_status'");
  }else{
    $db->query("INSERT INTO {$prefix}postmeta(post_id,meta_key,meta_value) VALUES ($id,'_stock_status','$stock')");
  }
  if($meta){ $meta->close(); }
  $db->query("DELETE FROM {$prefix}postmeta WHERE post_id=$id AND meta_key IN ('_yoast_wpseo_title','_yoast_wpseo_metadesc')");
  if($seoTitleInput !== ''){
    $st = $db->real_escape_string($seoTitleInput);
    $db->query("INSERT INTO {$prefix}postmeta(post_id,meta_key,meta_value) VALUES ($id,'_yoast_wpseo_title','$st')");
  }
  if($seoDescInput !== ''){
    $sd = $db->real_escape_string($seoDescInput);
    $db->query("INSERT INTO {$prefix}postmeta(post_id,meta_key,meta_value) VALUES ($id,'_yoast_wpseo_metadesc','$sd')");
  }
  $db->query("DELETE FROM {$prefix}postmeta WHERE post_id=$id AND meta_key='_yoast_wpseo_focuskw'");
  if($focusKwInput !== ''){
    $fk = $db->real_escape_string($focusKwInput);
    $db->query("INSERT INTO {$prefix}postmeta(post_id,meta_key,meta_value) VALUES ($id,'_yoast_wpseo_focuskw','$fk')");
  }
  $db->query("DELETE tr FROM {$prefix}term_relationships tr JOIN {$prefix}term_taxonomy tt ON tr.term_taxonomy_id=tt.term_taxonomy_id WHERE tr.object_id=$id AND tt.taxonomy='product_cat'");
  if(isset($_POST['categories'])){
     foreach($_POST['categories'] as $cat){
       $cat = intval($cat);
       $ttRes = $db->query("SELECT term_taxonomy_id FROM {$prefix}term_taxonomy WHERE taxonomy='product_cat' AND term_id=$cat");
       $tt = $ttRes ? $ttRes->fetch_assoc() : null;
       if($tt){
         $ttid = $tt['term_taxonomy_id'];
         $db->query("INSERT INTO {$prefix}term_relationships (object_id,term_taxonomy_id) VALUES ($id,$ttid)");
       }
       if($ttRes){ $ttRes->close(); }
     }
  }
  $redirect_success = false;
  if($oldSlugInput && $oldSlugInput !== $slugRaw){
    $check = $db->query("SHOW TABLES LIKE '{$prefix}yoast_redirects'");
    if($check && $check->num_rows){
      $oldPath = '/'.$db->real_escape_string($oldSlugInput).'/';
      $newPath = '/'.$slug.'/';
      if($db->query("INSERT INTO {$prefix}yoast_redirects (origin,target,type) VALUES ('$oldPath','$newPath','301')")){
        $redirect_success = true;
      }
    }
    if($check){ $check->close(); }
  }
  $vres = $ldb->query("SELECT MAX(version) v FROM {$lp}product_content_history WHERE product_id=$id");
  $vrow = $vres ? $vres->fetch_assoc() : null;
  $next = $vrow ? intval($vrow['v'])+1 : 1;
  if($vres){ $vres->close(); }
  $stmtHist = $ldb->prepare("INSERT INTO {$lp}product_content_history (product_id, old_content, new_content, changed_by, changed_at, version) VALUES (?,?,?,?,NOW(),?)");
  $historyId = 0;
  if($stmtHist){
    $stmtHist->bind_param('issii',$id,$oldContent,$descRaw,$uid,$next);
    $stmtHist->execute();
    $historyId = $stmtHist->insert_id ?: $ldb->insert_id;
    $stmtHist->close();
  }
  $analysisAfter = SEOAnalyzer::analyze($seoTitleInput ?: $nameRaw, $seoDescInput, $descRaw, $focusKwInput ?: $nameRaw, $siteBase);
  $newScore = $analysisAfter['score'];
  $detailsJson = json_encode($analysisAfter['details'],JSON_UNESCAPED_UNICODE);
  $stmtScore = $ldb->prepare("REPLACE INTO {$lp}product_seo_scores (product_id,score,details,analyzed_at) VALUES (?,?,?,NOW())");
  if($stmtScore){
    $newScoreInt = intval($newScore);
    $stmtScore->bind_param('iis',$id,$newScoreInt,$detailsJson);
    $stmtScore->execute();
    $stmtScore->close();
  }
  $wordsAfter = estimate_word_count($descRaw);
  $wordDelta = $wordsAfter - $wordsBefore;
  $wordsAdded = $wordDelta > 0 ? $wordDelta : 0;
  $improvement = $newScore - $oldScore;
  $activityMinutes = estimate_activity_minutes($wordsBefore,$wordsAfter);
  if($historyId){
    $assignedBind = $assignedUserId !== null ? intval($assignedUserId) : 0;
    $editedAt = date('Y-m-d H:i:s');
    $seoBeforeVal = $oldScore !== null ? floatval($oldScore) : 0;
    $seoAfterVal = floatval($newScore);
    $improvementVal = ($oldScore !== null) ? floatval($improvement) : 0;
    $stmtEvent = $ldb->prepare("INSERT INTO {$lp}user_kpi_events (history_id,user_id,product_id,assigned_user_id,edited_at,seo_before,seo_after,seo_improvement,words_before,words_after,words_delta,words_added,activity_minutes) VALUES (?,?,?,NULLIF(?,0),?,?,?,?,?,?,?,?,?) ON DUPLICATE KEY UPDATE assigned_user_id=VALUES(assigned_user_id), seo_before=VALUES(seo_before), seo_after=VALUES(seo_after), seo_improvement=VALUES(seo_improvement), words_before=VALUES(words_before), words_after=VALUES(words_after), words_delta=VALUES(words_delta), words_added=VALUES(words_added), activity_minutes=VALUES(activity_minutes))");
    if($stmtEvent){
      $stmtEvent->bind_param('iiiisdddiiiid',$historyId,$uid,$id,$assignedBind,$editedAt,$seoBeforeVal,$seoAfterVal,$improvementVal,$wordsBefore,$wordsAfter,$wordDelta,$wordsAdded,$activityMinutes);
      $stmtEvent->execute();
      $stmtEvent->close();
    }
  }
  $assignedHit = ($assignedUserId !== null && $assignedUserId === $uid) ? 1 : 0;
  $seoBeforeSum = $oldScore !== null ? floatval($oldScore) : 0;
  $seoAfterSum = floatval($newScore);
  $improvementSum = ($oldScore !== null) ? floatval($improvement) : 0;
  $stmtDaily = $ldb->prepare("INSERT INTO {$lp}user_kpi_daily (date,user_id,total_edits,assigned_edits,seo_before_sum,seo_after_sum,improvement_sum,activity_minutes,words_added_sum,words_total_sum) VALUES (?,?,?,?,?,?,?,?,?,?) ON DUPLICATE KEY UPDATE total_edits=total_edits+VALUES(total_edits), assigned_edits=assigned_edits+VALUES(assigned_edits), seo_before_sum=seo_before_sum+VALUES(seo_before_sum), seo_after_sum=seo_after_sum+VALUES(seo_after_sum), improvement_sum=improvement_sum+VALUES(improvement_sum), activity_minutes=activity_minutes+VALUES(activity_minutes), words_added_sum=words_added_sum+VALUES(words_added_sum), words_total_sum=words_total_sum+VALUES(words_total_sum)");
  if($stmtDaily){
    $today = date('Y-m-d');
    $stmtDaily->bind_param('siiiddddii',$today,$uid,1,$assignedHit,$seoBeforeSum,$seoAfterSum,$improvementSum,$activityMinutes,$wordsAdded,$wordsAfter);
    $stmtDaily->execute();
    $stmtDaily->close();
  }
  $product_url = (isset($_POST['product_url']) && $_POST['product_url']) ? $_POST['product_url'] : ('https://'.($_SERVER['HTTP_HOST'] ?? '').'/'.$slug.'/');
  $indexRes = google_index_url($product_url);
  $ldb->close();
  echo json_encode(array('success'=>true,'redirect'=>$redirect_success,'indexed'=>$indexRes[0],'index_log'=>$indexRes[1]));
  $db->close();
  break;

case 'analyze_product_seo':
  $id = intval($_POST['id'] ?? 0);
  $db = connect(); if(!$db) break;
  $prefix = $_SESSION['db']['prefix'];
  $pRes = $db->query("SELECT post_title,post_content FROM {$prefix}posts WHERE ID=$id");
  $p = $pRes ? $pRes->fetch_assoc() : null;
  if(!$p){ $db->close(); echo json_encode(array('success'=>false,'message'=>'محصول یافت نشد')); break; }
  $titleRes = $db->query("SELECT meta_value FROM {$prefix}postmeta WHERE post_id=$id AND meta_key='_yoast_wpseo_title'");
  $titleRow = $titleRes ? $titleRes->fetch_assoc() : null; $seoTitle = ($titleRow['meta_value'] ?? '');
  $descRes = $db->query("SELECT meta_value FROM {$prefix}postmeta WHERE post_id=$id AND meta_key='_yoast_wpseo_metadesc'");
  $descRow = $descRes ? $descRes->fetch_assoc() : null; $seoDesc = ($descRow['meta_value'] ?? '');
  $focusRes = $db->query("SELECT meta_value FROM {$prefix}postmeta WHERE post_id=$id AND meta_key='_yoast_wpseo_focuskw'");
  $focusRow = $focusRes ? $focusRes->fetch_assoc() : null; $focus = ($focusRow['meta_value'] ?? $p['post_title']);
  $siteBase = $_SESSION['site_base_url'] ?? '';
  $analysis = SEOAnalyzer::analyze($seoTitle ?: $p['post_title'], $seoDesc, $p['post_content'], $focus, $siteBase);
  $ldb = connect_local();
  if($ldb){
    $lp = $_SESSION['logdb']['prefix'];
    $stmt = $ldb->prepare("REPLACE INTO {$lp}product_seo_scores (product_id,score,details,analyzed_at) VALUES (?,?,?,NOW())");
  if($stmt){ $det = json_encode($analysis['details'],JSON_UNESCAPED_UNICODE); $stmt->bind_param('iis',$id,$analysis['score'],$det); $stmt->execute(); $stmt->close(); }
    $ldb->close();
  }
  $db->close();
  echo json_encode(array('success'=>true,'data'=>$analysis,'suggestions'=>array('title'=>SEOAnalyzer::suggestTitle($p['post_title']),'meta'=>SEOAnalyzer::suggestMeta($p['post_title']))));
  break;

case 'analyze_seo':
  $title = $_POST['title'] ?? '';
  $desc  = $_POST['desc'] ?? '';
  $content = $_POST['content'] ?? '';
  $focus = $_POST['focus'] ?? $title;
  $siteBase = $_SESSION['site_base_url'] ?? '';
  $analysis = SEOAnalyzer::analyze($title,$desc,$content,$focus,$siteBase);
  echo json_encode(array('success'=>true,'data'=>$analysis,'suggestions'=>array('title'=>SEOAnalyzer::suggestTitle($title),'meta'=>SEOAnalyzer::suggestMeta($title))));
  break;

case 'bulk_analyze_product_seo':
  $db = connect(); if(!$db) break;
  $prefix = $_SESSION['db']['prefix'];
  $res = $db->query("SELECT ID,post_title,post_content FROM {$prefix}posts WHERE post_type='product' AND post_status='publish'");
  $count = 0;
  $ldb = connect_local();
  $siteBase = $_SESSION['site_base_url'] ?? '';
  if($res){
    while($p=$res->fetch_assoc()){
      $id = intval($p['ID']);
      $titleRes = $db->query("SELECT meta_value FROM {$prefix}postmeta WHERE post_id=$id AND meta_key='_yoast_wpseo_title'");
      $titleRow = $titleRes ? $titleRes->fetch_assoc() : null; $seoTitle = ($titleRow['meta_value'] ?? '');
      $descRes = $db->query("SELECT meta_value FROM {$prefix}postmeta WHERE post_id=$id AND meta_key='_yoast_wpseo_metadesc'");
      $descRow = $descRes ? $descRes->fetch_assoc() : null; $seoDesc = ($descRow['meta_value'] ?? '');
      $focusRes = $db->query("SELECT meta_value FROM {$prefix}postmeta WHERE post_id=$id AND meta_key='_yoast_wpseo_focuskw'");
      $focusRow = $focusRes ? $focusRes->fetch_assoc() : null; $focus = ($focusRow['meta_value'] ?? $p['post_title']);
      $analysis = SEOAnalyzer::analyze($seoTitle ?: $p['post_title'], $seoDesc, $p['post_content'], $focus, $siteBase);
      if($ldb){
        $lp = $_SESSION['logdb']['prefix'];
        $stmt = $ldb->prepare("REPLACE INTO {$lp}product_seo_scores (product_id,score,details,analyzed_at) VALUES (?,?,?,NOW())");
        if($stmt){ $det=json_encode($analysis['details'],JSON_UNESCAPED_UNICODE); $stmt->bind_param('iis',$id,$analysis['score'],$det); $stmt->execute(); $stmt->close(); }
      }
      $count++;
    }
    $res->close();
  }
  if($ldb){ $ldb->close(); }
  $db->close();
  echo json_encode(array('success'=>true,'processed'=>$count));
  break;

case 'get_content_history':
  $ldb = connect_local();
  if(!$ldb){ echo json_encode(array('success'=>false,'message'=>'عدم اتصال به پایگاه داده سامانه')); break; }
  $lp = $_SESSION['logdb']['prefix'];
  $pid = intval($_POST['product_id'] ?? 0);
  $user = intval($_POST['user'] ?? 0);
  $from = $_POST['from'] ?? '';
  $to = $_POST['to'] ?? '';
  $sql = "SELECT h.version,h.old_content,h.new_content,h.changed_at,COALESCE(u.username,'سیستم') username FROM {$lp}product_content_history h LEFT JOIN {$lp}users u ON h.changed_by=u.id WHERE h.product_id=$pid";
  if($user) $sql .= " AND h.changed_by=$user";
  if($from){ $f=$ldb->real_escape_string($from); $sql .= " AND h.changed_at>='$f'"; }
  if($to){ $t=$ldb->real_escape_string($to); $sql .= " AND h.changed_at<='$t'"; }
  $sql .= " ORDER BY h.version DESC";
  $rows=array();
  if($res=$ldb->query($sql)){ while($r=$res->fetch_assoc()){ $rows[]=$r; } $res->close(); }
  $ldb->close();
  echo json_encode(array('success'=>true,'data'=>$rows));
  break;

case 'revert_content':
  $pid = intval($_POST['product_id'] ?? 0);
  $version = intval($_POST['version'] ?? 0);
  $ldb = connect_local();
  if(!$ldb){ echo json_encode(array('success'=>false,'message'=>'عدم اتصال به پایگاه داده سامانه')); break; }
  $lp = $_SESSION['logdb']['prefix'];
  $res = $ldb->query("SELECT new_content FROM {$lp}product_content_history WHERE product_id=$pid AND version=$version");
  $row = $res ? $res->fetch_assoc() : null;
  if(!$row){ $ldb->close(); echo json_encode(array('success'=>false,'message'=>'نسخه یافت نشد')); break; }
  $newContent = $row['new_content'];
  if($res){ $res->close(); }
  if(!isset($_SESSION['db'])){ $cfg = secure_load_config(); if(!$cfg){ $ldb->close(); echo json_encode(array('success'=>false,'message'=>'عدم اتصال به پایگاه داده')); break; } $_SESSION['db']=$cfg; } else { $cfg=$_SESSION['db']; }
  try{ $wdb = new mysqli($cfg['host'],$cfg['user'],$cfg['pass'],$cfg['name']); } catch(mysqli_sql_exception $e){ $ldb->close(); echo json_encode(array('success'=>false,'message'=>'عدم اتصال به پایگاه داده')); break; }
  if($wdb->connect_errno){ $ldb->close(); echo json_encode(array('success'=>false,'message'=>'عدم اتصال به پایگاه داده')); break; }
  $wdb->set_charset('utf8mb4');
  $wp = $cfg['prefix'];
  $cres = $wdb->query("SELECT post_content FROM {$wp}posts WHERE ID=$pid");
  $crow = $cres ? $cres->fetch_assoc() : null;
  $current = $crow ? $crow['post_content'] : '';
  if($cres){ $cres->close(); }
  $stmt = $wdb->prepare("UPDATE {$wp}posts SET post_content=? WHERE ID=?");
  if($stmt){ $stmt->bind_param('si',$newContent,$pid); $stmt->execute(); $stmt->close(); }
  $vres = $ldb->query("SELECT MAX(version) v FROM {$lp}product_content_history WHERE product_id=$pid");
  $vrow = $vres ? $vres->fetch_assoc() : null;
  $next = $vrow ? intval($vrow['v'])+1 : 1;
  if($vres){ $vres->close(); }
  $uid = intval($_SESSION['user_id']);
  $stmt2 = $ldb->prepare("INSERT INTO {$lp}product_content_history (product_id, old_content, new_content, changed_by, changed_at, version) VALUES (?,?,?,?,NOW(),?)");
  if($stmt2){ $stmt2->bind_param('issii',$pid,$current,$newContent,$uid,$next); $stmt2->execute(); $stmt2->close(); }
  $wdb->close();
  $ldb->close();
  echo json_encode(array('success'=>true));
  break;

case 'sync_internal_links':
  $db = connect_local();
  if(!$db){ echo json_encode(['success'=>false]); break; }
  $p = $_SESSION['logdb']['prefix'];
  // purge duplicates and enforce unique constraint so repeated syncs don't accumulate rows
  $db->query("DELETE t1 FROM {$p}internal_links t1 JOIN {$p}internal_links t2 ON t1.category=t2.category AND t1.id>t2.id");
  $db->query("ALTER TABLE {$p}internal_links ADD UNIQUE KEY uniq_category (category)");
  $cfg = isset($_SESSION['db']) ? $_SESSION['db'] : secure_load_config();
  $slugs = [];
  if($cfg){
    try{ $wdb = new mysqli($cfg['host'],$cfg['user'],$cfg['pass'],$cfg['name']); }
    catch(mysqli_sql_exception $e){ $wdb = null; }
  if($wdb && !$wdb->connect_errno){
      $wdb->set_charset('utf8mb4');
      $wp = $cfg['prefix'];
      $base = '';
      if($r=$wdb->query("SELECT option_value FROM {$wp}options WHERE option_name='siteurl' LIMIT 1")){
        $row = $r->fetch_assoc();
        $base = rtrim($row['option_value'],'/');
        $r->close();
      }
      if($res=$wdb->query("SELECT t.slug,t.name FROM {$wp}terms t JOIN {$wp}term_taxonomy tt ON t.term_id=tt.term_id WHERE tt.taxonomy='product_cat'")){
        while($cat=$res->fetch_assoc()){
          $slug = preg_replace('#^product-category/#','',$cat['slug']);
          if(isset($slugs[$slug])) continue; // avoid duplicates
          $slugs[$slug] = true;
          $eslug = $db->real_escape_string($slug);
          $title = $db->real_escape_string($cat['name']);
          $url = $db->real_escape_string(rtrim($base,'/').'/'.$slug.'/');
          $db->query("INSERT INTO {$p}internal_links(category,url,title) VALUES('$eslug','$url','$title') ON DUPLICATE KEY UPDATE url='$url', title='$title'");
        }
        $res->close();
      }
      $wdb->close();
    }
  }
  $keep = array_map([$db,'real_escape_string'], array_keys($slugs));
  if(count($keep)>0){
    $list = "'".implode("','", $keep)."'";
    $db->query("DELETE FROM {$p}internal_links WHERE category NOT IN ($list)");
  } else {
    $db->query("DELETE FROM {$p}internal_links");
  }
  $db->close();
  echo json_encode(['success'=>true]);
  break;

case 'list_internal_links':
  $db = connect_local();
  if(!$db){ echo json_encode(['success'=>false]); break; }
  $p = $_SESSION['logdb']['prefix'];
  $rows = [];
  if($res=$db->query("SELECT id,category,url,title FROM {$p}internal_links")){
    while($r=$res->fetch_assoc()) $rows[] = $r;
    $res->close();
  }
  $db->close();
  echo json_encode(['success'=>true,'data'=>$rows]);
  break;

case 'save_internal_link':
  $db=connect_local();
  if(!$db){ echo json_encode(array('success'=>false)); break; }
  $p=$_SESSION['logdb']['prefix'];
  $id=intval($_POST['id']??0);
  $category=$db->real_escape_string($_POST['category']??'');
  $url=$db->real_escape_string(preg_replace('#/product-category/#','/',$_POST['url']??''));
  $title=$db->real_escape_string($_POST['title']??'');
  if($id>0){
    $db->query("UPDATE {$p}internal_links SET category='$category',url='$url',title='$title' WHERE id=$id");
  }else{
    $db->query("INSERT INTO {$p}internal_links(category,url,title) VALUES('$category','$url','$title')");
  }
  $db->close();
  echo json_encode(array('success'=>true));
  break;

case 'list_external_links':
  $db=connect_local();
  if(!$db){ echo json_encode(array('success'=>false)); break; }
  $p=$_SESSION['logdb']['prefix'];
  $rows=array();
  if($res=$db->query("SELECT id,url,title FROM {$p}external_links")){
    while($r=$res->fetch_assoc()) $rows[]=$r;
    $res->close();
  }
  $db->close();
  echo json_encode(array('success'=>true,'data'=>$rows));
  break;

case 'save_external_link':
  $db=connect_local();
  if(!$db){ echo json_encode(array('success'=>false)); break; }
  $p=$_SESSION['logdb']['prefix'];
  $id=intval($_POST['id']??0);
  $url=$db->real_escape_string($_POST['url']??'');
  $title=$db->real_escape_string($_POST['title']??'');
  if($id>0){
    $db->query("UPDATE {$p}external_links SET url='$url',title='$title' WHERE id=$id");
  }else{
    $db->query("INSERT INTO {$p}external_links(url,title) VALUES('$url','$title')");
  }
  $db->close();
  echo json_encode(array('success'=>true));
  break;

case 'get_processes':
  $steps=array();
  $rows=ProcessManager::getProcesses($steps);
  echo json_encode(array('success'=>true,'data'=>$rows,'steps'=>$steps));
  break;

case 'save_processes':
  $data=json_decode($_POST['data'] ?? '[]',true);
  ProcessManager::saveProcesses($data);
  echo json_encode(array('success'=>true));
  break;

case 'run_process':
  $name=$_POST['name'] ?? '';
  $steps=array();
  ProcessManager::run($name,$steps);
  echo json_encode(array('success'=>true,'steps'=>$steps));
  break;

case 'run_due_processes':
  $steps=array();
  ProcessManager::runDue($steps);
  echo json_encode(array('success'=>true,'steps'=>$steps));
  break;

case 'bulk_stock':
  $db = connect(); if(!$db) break;
  $prefix = $_SESSION['db']['prefix'];
  $status = ($_POST['status'] ?? '') === 'instock' ? 'instock' : 'outofstock';
  $db->query("UPDATE {$prefix}postmeta SET meta_value='$status' WHERE meta_key='_stock_status'");
  $db->query("INSERT INTO {$prefix}postmeta (post_id,meta_key,meta_value) SELECT ID,'_stock_status','$status' FROM {$prefix}posts p WHERE p.post_type='product' AND NOT EXISTS (SELECT 1 FROM {$prefix}postmeta pm WHERE pm.post_id=p.ID AND pm.meta_key='_stock_status')");
  echo json_encode(array('success'=>true));
  $db->close();
  break;

case 'bulk_price':
  $db = connect(); if(!$db) break;
  $prefix = $_SESSION['db']['prefix'];
  $op = ($_POST['op'] ?? '') === 'dec' ? '-' : '+';
  $type = ($_POST['type'] ?? '') === 'fixed' ? 'fixed' : 'percent';
  $val = isset($_POST['value']) ? floatval($_POST['value']) : 0;
  if($val==0){ echo json_encode(array('success'=>false,'message'=>'مقدار نامعتبر')); $db->close(); break; }
  if($type==='percent'){
    $factor = $op==='+' ? (1 + $val/100) : (1 - $val/100);
    $db->query("UPDATE {$prefix}postmeta SET meta_value=ROUND(CAST(meta_value AS DECIMAL(10,2))*$factor,2) WHERE meta_key IN ('_price','_regular_price')");
  }else{
    $sign = $op==='+' ? '+' : '-';
    $db->query("UPDATE {$prefix}postmeta SET meta_value=ROUND(CAST(meta_value AS DECIMAL(10,2)) $sign $val,2) WHERE meta_key IN ('_price','_regular_price')");
  }
  echo json_encode(array('success'=>true));
  $db->close();
  break;

case 'bulk_seo_keywords':
  $db = connect(); if(!$db) break;
  $prefix = $_SESSION['db']['prefix'];
  $hasIndexTable = $db->query("SHOW TABLES LIKE '{$prefix}yoast_indexable'");
  $updateIndex = $hasIndexTable && $hasIndexTable->num_rows > 0;
  $products = $db->query("SELECT ID,post_title FROM {$prefix}posts WHERE post_type='product'");
  $ok=array();$fail=array();
  if($products){
    while($p=$products->fetch_assoc()){
      $id = intval($p['ID']);
      $title = $db->real_escape_string($p['post_title']);
      $del = $db->query("DELETE FROM {$prefix}postmeta WHERE post_id=$id AND meta_key IN ('_yoast_wpseo_focuskw','_yoast_wpseo_focuskw_text')");
      $ins = $db->query("INSERT INTO {$prefix}postmeta(post_id,meta_key,meta_value) VALUES ($id,'_yoast_wpseo_focuskw','$title'),($id,'_yoast_wpseo_focuskw_text','$title')");
      $idx=true;
      if($updateIndex){
        $idx = $db->query("UPDATE {$prefix}yoast_indexable SET primary_focus_keyword='$title' WHERE object_id=$id AND object_type='post'");
      }
      if($del && $ins && $idx){ $ok[]=$p['post_title']; } else { $fail[]=$p['post_title']; }
    }
  }
  echo json_encode(array('success'=>true,'report'=>array('ok'=>$ok,'fail'=>$fail)));
  $db->close();
  break;

case 'bulk_seo_desc':
  $db = connect(); if(!$db) break;
  $prefix = $_SESSION['db']['prefix'];
  $hasIndexTable = $db->query("SHOW TABLES LIKE '{$prefix}yoast_indexable'");
  $updateIndex = $hasIndexTable && $hasIndexTable->num_rows > 0;
  $products = $db->query("SELECT ID,post_title FROM {$prefix}posts WHERE post_type='product'");
  $ok=array();$fail=array();
  if($products){
    while($p=$products->fetch_assoc()){
      $id = intval($p['ID']);
      $title = $db->real_escape_string($p['post_title']);
      $desc  = $db->real_escape_string("خرید $title با بهترین قیمت از فروشگاه ما.");
      $del = $db->query("DELETE FROM {$prefix}postmeta WHERE post_id=$id AND meta_key='_yoast_wpseo_metadesc'");
      $ins = $db->query("INSERT INTO {$prefix}postmeta(post_id,meta_key,meta_value) VALUES ($id,'_yoast_wpseo_metadesc','$desc')");
      $idx=true;
      if($updateIndex){
        $idx = $db->query("UPDATE {$prefix}yoast_indexable SET description='$desc' WHERE object_id=$id AND object_type='post'");
      }
      if($del && $ins && $idx){ $ok[]=$p['post_title']; } else { $fail[]=$p['post_title']; }
    }
  }
  echo json_encode(array('success'=>true,'report'=>array('ok'=>$ok,'fail'=>$fail)));
  $db->close();
  break;

case 'bulk_alt_from_name':
  $db = connect(); if(!$db) break;
  $prefix = $_SESSION['db']['prefix'];
  $products = $db->query("SELECT p.ID,p.post_title,pm.meta_value img_id FROM {$prefix}posts p JOIN {$prefix}postmeta pm ON p.ID=pm.post_id AND pm.meta_key='_thumbnail_id' WHERE p.post_type='product'");
  $updated=0;
  if($products){
    while($p=$products->fetch_assoc()){
      $title=$db->real_escape_string($p['post_title']);
      $img=intval($p['img_id']);
      $check=$db->query("SELECT meta_id FROM {$prefix}postmeta WHERE post_id=$img AND meta_key='_wp_attachment_image_alt'");
      if($check && $check->num_rows){
        $db->query("UPDATE {$prefix}postmeta SET meta_value='$title' WHERE post_id=$img AND meta_key='_wp_attachment_image_alt'");
      } else {
        $db->query("INSERT INTO {$prefix}postmeta(post_id,meta_key,meta_value) VALUES ($img,'_wp_attachment_image_alt','$title')");
      }
      $updated++;
    }
    $products->close();
  }
  $db->close();
  echo json_encode(array('success'=>true,'updated'=>$updated));
  break;

case 'get_dns_settings':
  $mgr = new SanctionBypassManager();
  echo json_encode(array('success'=>true,'dns'=>$mgr->getDnsServers()));
  break;

case 'save_dns_settings':
  $mgr = new SanctionBypassManager();
  $dns = isset($_POST['dns']) ? explode(',', $_POST['dns']) : array();
  $mgr->setDnsServers($dns);
  echo json_encode(array('success'=>true));
  break;

case 'test_dns':
  $mgr = new SanctionBypassManager();
  $res = $mgr->testDnsLeak();
  echo json_encode($res);
  break;

case 'get_chatgpt_settings':
  $mgr = new ChatGPTManager();
  echo json_encode(['success'=>true,'config'=>$mgr->getConfig()]);
  break;

case 'save_chatgpt_settings':
  $mgr = new ChatGPTManager();
  $cfg = [
    'api_key'=>$_POST['api_key'] ?? '',
    'model'=>$_POST['model'] ?? '',
    'temperature'=>$_POST['temperature'] ?? '',
    'max_tokens'=>$_POST['max_tokens'] ?? ''
  ];
  $mgr->saveConfig($cfg);
  echo json_encode(['success'=>true]);
  break;

case 'test_chatgpt':
  $mgr = new ChatGPTManager();
  $res = $mgr->testConnection();
  echo json_encode($res);
  break;

case 'enqueue_process':
  $name = trim($_POST['name'] ?? '');
  if($name===''){ echo json_encode(['success'=>false,'message'=>'نام فرایند مشخص نیست']); break; }
  try{
    $pq = new ProcessQueue();
    $id = $pq->enqueue($name);
    @exec('php '. __DIR__ .'/worker.php > /dev/null 2>&1 &');
    echo json_encode(['success'=>true,'id'=>$id]);
  }catch(Exception $e){ echo json_encode(['success'=>false,'message'=>$e->getMessage()]); }
  break;

case 'list_process_queue':
  try{
    $pq = new ProcessQueue();
    $rows = $pq->listAll();
    echo json_encode(['success'=>true,'data'=>$rows]);
  }catch(Exception $e){ echo json_encode(['success'=>false,'data'=>[],'message'=>$e->getMessage()]); }
  break;

  case 'analytics':
   $db = connect(); if(!$db) break;
   $prefix = $_SESSION['db']['prefix'];
  $catRes = $db->query("SELECT COALESCE(pt.name,t.name) name,COUNT(tr.object_id) c FROM {$prefix}terms t JOIN {$prefix}term_taxonomy tt ON t.term_id=tt.term_id LEFT JOIN {$prefix}term_taxonomy ptt ON tt.parent=ptt.term_taxonomy_id LEFT JOIN {$prefix}terms pt ON ptt.term_id=pt.term_id JOIN {$prefix}term_relationships tr ON tr.term_taxonomy_id=tt.term_taxonomy_id WHERE tt.taxonomy='product_cat' GROUP BY name");
  $cat = array('labels'=>array(),'data'=>array());
  if($catRes){ while($r=$catRes->fetch_assoc()){ $cat['labels'][]=$r['name']; $cat['data'][]=$r['c']; }}
  $good=0;$bad=0;$missing=0;
  $posts = $db->query("SELECT ID,post_title,post_content FROM {$prefix}posts WHERE post_type='product'");
  if($posts){
    while($p=$posts->fetch_assoc()){
      $id=$p['ID'];
      $metaRes=$db->query("SELECT meta_key,meta_value FROM {$prefix}postmeta WHERE post_id=$id AND meta_key IN ('_yoast_wpseo_title','_yoast_wpseo_metadesc')");
      $seoTitle='';$seoDesc='';
      if($metaRes){ while($m=$metaRes->fetch_assoc()){ if($m['meta_key']=='_yoast_wpseo_title') $seoTitle=$m['meta_value']; elseif($m['meta_key']=='_yoast_wpseo_metadesc') $seoDesc=$m['meta_value']; }}
      if(!$p['post_content'] && !$seoTitle && !$seoDesc){ $missing++; continue; }
      $score=compute_seo_score($seoTitle ?: $p['post_title'],$seoDesc,$p['post_content'],$p['post_title']);
      if($score>=70) $good++; else $bad++;
    }
  }
  $seo = array('labels'=>array('خوب','بد','ناموجود'),'data'=>array($good,$bad,$missing));
  $stockRes = $db->query("SELECT meta_value FROM {$prefix}postmeta WHERE meta_key='_stock_status'");
  $instock=0;$out=0; if($stockRes){ while($r=$stockRes->fetch_assoc()){ if($r['meta_value']=='instock') $instock++; else $out++; }}
  $stock = array('labels'=>array('موجود','ناموجود'),'data'=>array($instock,$out));
  $priceRes = $db->query("SELECT COUNT(*) c FROM {$prefix}posts p LEFT JOIN {$prefix}postmeta pm ON p.ID=pm.post_id AND pm.meta_key='_price' WHERE p.post_type='product' AND (pm.meta_value='' OR pm.meta_value='0' OR pm.meta_value IS NULL)");
  $withoutPrice = $priceRes ? $priceRes->fetch_assoc()['c'] : 0;
  $totalRes = $db->query("SELECT COUNT(*) c FROM {$prefix}posts WHERE post_type='product'");
  $total = $totalRes ? $totalRes->fetch_assoc()['c'] : 0;
 $price = array('labels'=>array('بدون قیمت','دارای قیمت'),'data'=>array($withoutPrice,$total-$withoutPrice));
echo json_encode(array('success'=>true,'cat'=>$cat,'seo'=>$seo,'stock'=>$stock,'price'=>$price));
$db->close();
break;
case 'user_kpi_summary':
  if(!has_perm('view_kpis')){ echo json_encode(array('success'=>false,'message'=>'عدم دسترسی')); break; }
  date_default_timezone_set('Asia/Tehran');
  $range = $_POST['range'] ?? 'day';
  $validRanges = array('day','week','month');
  if(!in_array($range,$validRanges)){ $range='day'; }
  $end = date('Y-m-d');
  $start = $end;
  $rangeLabel = 'امروز';
  if($range==='week'){ $start = date('Y-m-d',strtotime('-6 days',strtotime($end))); $rangeLabel='۷ روز اخیر'; }
  elseif($range==='month'){ $start = date('Y-m-d',strtotime('-29 days',strtotime($end))); $rangeLabel='۳۰ روز اخیر'; }
  $db = connect_local();
  if(!$db){ echo json_encode(array('success'=>false,'message'=>'عدم اتصال به پایگاه داده سامانه')); break; }
  $prefix = $_SESSION['logdb']['prefix'];
  $kpiEnsure = ensure_kpi_tables($db,$prefix);
  if(!empty($kpiEnsure)){
    if(msw_debug_enabled()){
      msw_debug_write_log(array('id'=>'user_kpi_summary','stage'=>'schema_errors','errors'=>$kpiEnsure));
    }
    $db->close();
    echo json_encode(array('success'=>false,'message'=>'جداول KPI در دسترس نیستند'));
    break;
  }
  $manager = new UserManager($db,$prefix);
  $usersList = $manager->all();
  $userMap = array();
  foreach($usersList as $u){
    $uid = intval($u['id']);
    $labelName = trim($u['full_name'] ?? '');
    if(!$labelName){ $labelName = $u['username']; }
    $userMap[$uid] = array('label'=>$labelName,'username'=>$u['username']);
  }
  $stmt = $db->prepare("SELECT user_id,SUM(total_edits) AS total_edits,SUM(assigned_edits) AS assigned_edits,SUM(seo_before_sum) AS seo_before_sum,SUM(seo_after_sum) AS seo_after_sum,SUM(improvement_sum) AS improvement_sum,SUM(activity_minutes) AS activity_minutes,SUM(words_added_sum) AS words_added_sum,SUM(words_total_sum) AS words_total_sum FROM {$prefix}user_kpi_daily WHERE date BETWEEN ? AND ? GROUP BY user_id");
  $stmt->bind_param('ss',$start,$end);
  $stmt->execute();
  $res = $stmt->get_result();
  $records = array();
  $totalEditsRange = 0;
  $totalImprovementSum = 0;
  while($row=$res->fetch_assoc()){
    $uid = intval($row['user_id']);
    $edits = intval($row['total_edits']);
    $avgBefore = $edits>0 ? round(floatval($row['seo_before_sum'])/$edits,2) : 0;
    $avgAfter = $edits>0 ? round(floatval($row['seo_after_sum'])/$edits,2) : 0;
    $imprAvg = $edits>0 ? round(floatval($row['improvement_sum'])/$edits,2) : 0;
    $activity = round(floatval($row['activity_minutes']),2);
    $wordsAdded = intval($row['words_added_sum']);
    $wordsTotal = intval($row['words_total_sum']);
    $assigned = intval($row['assigned_edits']);
    $labelName = $userMap[$uid]['label'] ?? ('کاربر '.$uid);
    $records[] = array(
      'user_id'=>$uid,
      'label'=>$labelName,
      'username'=>$userMap[$uid]['username'] ?? '',
      'total_edits'=>$edits,
      'assigned_edits'=>$assigned,
      'avg_before'=>$avgBefore,
      'avg_after'=>$avgAfter,
      'improvement_avg'=>$imprAvg,
      'improvement_sum'=>round(floatval($row['improvement_sum']),2),
      'activity_minutes'=>$activity,
      'words_added'=>$wordsAdded,
      'words_total'=>$wordsTotal
    );
    $totalEditsRange += $edits;
    $totalImprovementSum += floatval($row['improvement_sum']);
  }
  $stmt->close();
  $barOrder = $records;
  usort($barOrder,function($a,$b){
    if($b['total_edits'] === $a['total_edits']){
      return $b['improvement_avg'] <=> $a['improvement_avg'];
    }
    return $b['total_edits'] <=> $a['total_edits'];
  });
  $barSlice = array_slice($barOrder,0,10);
  $barLabels = array();
  $barValues = array();
  foreach($barSlice as $entry){
    $barLabels[] = $entry['label'];
    $barValues[] = $entry['total_edits'];
  }
  $pieLabels = array();
  $pieValues = array();
  foreach($barOrder as $entry){
    $pieLabels[] = $entry['label'];
    $pieValues[] = $entry['total_edits'];
  }
  $stmt = $db->prepare("SELECT date,user_id,total_edits,seo_after_sum FROM {$prefix}user_kpi_daily WHERE date BETWEEN ? AND ? ORDER BY date ASC");
  $stmt->bind_param('ss',$start,$end);
  $stmt->execute();
  $res = $stmt->get_result();
  $lineLabels = array();
  $linePoints = array();
  while($row=$res->fetch_assoc()){
    $dateKey = $row['date'];
    if(!in_array($dateKey,$lineLabels)){ $lineLabels[] = $dateKey; }
    $uid = intval($row['user_id']);
    if(!isset($linePoints[$uid])){ $linePoints[$uid] = array(); }
    $avgAfterDay = intval($row['total_edits'])>0 ? round(floatval($row['seo_after_sum'])/intval($row['total_edits']),2) : null;
    $linePoints[$uid][$dateKey] = $avgAfterDay;
  }
  $stmt->close();
  $lineDatasets = array();
  foreach($linePoints as $uid=>$points){
    $series = array();
    foreach($lineLabels as $d){ $series[] = array_key_exists($d,$points) ? $points[$d] : null; }
    $lineDatasets[] = array(
      'user_id'=>$uid,
      'label'=>$userMap[$uid]['label'] ?? ('کاربر '.$uid),
      'data'=>$series
    );
  }
  $bestUser = array('name'=>'-','edits'=>0,'improvement'=>0);
  if(!empty($barOrder)){
    $top = $barOrder[0];
    $bestUser['name'] = $top['label'];
    $bestUser['edits'] = intval($top['total_edits']);
    $bestUser['improvement'] = isset($top['improvement_sum']) ? floatval($top['improvement_sum']) : 0;
  }
  $updatedAt = null;
  $lastRes = $db->query("SELECT MAX(edited_at) AS last_edit FROM {$prefix}user_kpi_events");
  if($lastRes){ $lastRow = $lastRes->fetch_assoc(); if($lastRow && $lastRow['last_edit']){ $updatedAt = $lastRow['last_edit']; } $lastRes->close(); }
  $cards = array(
    'total_label'=>'مجموع ویرایش‌های '.$rangeLabel,
    'total_edits'=>$totalEditsRange,
    'best_label'=>$range==='day' ? 'بهترین کاربر امروز' : 'برترین کاربر '.$rangeLabel,
    'best_user'=>$bestUser,
    'avg_improvement_label'=>'میانگین بهبود کل',
    'avg_improvement'=>$totalEditsRange>0 ? round($totalImprovementSum/$totalEditsRange,2) : 0
  );
  $leaderboard = $barOrder;
  $db->close();
  echo json_encode(array(
    'success'=>true,
    'range'=>$range,
    'range_label'=>$rangeLabel,
    'cards'=>$cards,
    'bar'=>array('labels'=>$barLabels,'data'=>$barValues),
    'pie'=>array('labels'=>$pieLabels,'data'=>$pieValues),
    'line'=>array('labels'=>$lineLabels,'datasets'=>$lineDatasets),
    'leaderboard'=>$leaderboard,
    'total_edits'=>$totalEditsRange,
    'updated_at'=>$updatedAt
  ));
  break;
case 'check_config':
  $cfg = secure_load_config();
  if(!$cfg){ echo json_encode(array('success'=>false,'message'=>'تنظیمات موجود نیست')); break; }
  try{ $mysqli = new mysqli($cfg['host'],$cfg['user'],$cfg['pass'],$cfg['name']); }
  catch(mysqli_sql_exception $e){ echo json_encode(array('success'=>false,'message'=>$e->getMessage())); break; }
  if($mysqli->connect_errno){ echo json_encode(array('success'=>false,'message'=>$mysqli->connect_error)); }
  else { $mysqli->close(); echo json_encode(array('success'=>true)); }
  break;
default:
  echo json_encode(array('success'=>false,'message'=>'دستور نامعتبر'));
}

function connect(){
  if(!isset($_SESSION['db'])){
    $cfg = secure_load_config();
  if(!$cfg){
      echo json_encode(array('success'=>false,'message'=>'عدم اتصال به پایگاه داده'));
      return false;
    }
    $_SESSION['db'] = $cfg;
  } else {
    $cfg = $_SESSION['db'];
  }
  try{
    $mysqli = new mysqli($cfg['host'],$cfg['user'],$cfg['pass'],$cfg['name']);
  }catch(mysqli_sql_exception $e){
    echo json_encode(array('success'=>false,'message'=>$e->getMessage()));
    return false;
  }
  if($mysqli->connect_errno){
    echo json_encode(array('success'=>false,'message'=>$mysqli->connect_error));
    return false;
  }
  $mysqli->set_charset('utf8mb4');
  if(!isset($_SESSION['site_base_url']) || !$_SESSION['site_base_url']){
    $site='';
    $sql="SELECT option_name,option_value FROM {$cfg['prefix']}options WHERE option_name IN ('home','siteurl')";
    if($optRes=$mysqli->query($sql)){
      while($row=$optRes->fetch_assoc()){
        $value=trim($row['option_value']);
        if(!$value) continue;
        $value=rtrim($value,'/');
        if(!$site || $row['option_name']=='home'){
          $site=$value;
        }
      }
      $optRes->close();
    }
    if($site){
      $_SESSION['site_base_url']=$site;
    }
  }
  return $mysqli;
}

function secure_save_config($data){
  $json = json_encode($data);
  file_put_contents(__DIR__.'/config.secure', $json);
}

function secure_load_config(){
  $path = __DIR__.'/config.secure';
  if(!file_exists($path)) return false;
  $json = file_get_contents($path);
  return $json ? json_decode($json,true) : false;
}

function connect_local(){
  if(!isset($_SESSION['logdb'])){
    $cfg = secure_load_local_config();
  if(!$cfg) return false;
    $_SESSION['logdb'] = $cfg;
  } else {
    $cfg = $_SESSION['logdb'];
  }
  try{ $mysqli = new mysqli($cfg['host'],$cfg['user'],$cfg['pass'],$cfg['name']); }
  catch(mysqli_sql_exception $e){ return false; }
  if($mysqli->connect_errno) return false;
  $mysqli->set_charset('utf8mb4');
  $schemaErrors = init_local_tables($mysqli,$cfg['prefix']);
  if(!empty($schemaErrors) && msw_debug_enabled()){
    msw_debug_write_log(array('id'=>'connect_local','stage'=>'schema_errors','errors'=>$schemaErrors));
  }
  seed_content_history_if_empty($mysqli,$cfg['prefix']);
  return $mysqli;
}

function msw_execute_schema($db,$sql,$label,$debug=null,&$errors=array()){
  $success = true;
  try{
    $result = $db->query($sql);
    if($result === false){
      $success = false;
      $error = $db->error;
      $errors[$label] = $error;
      if($debug instanceof MswDebugCollector){
        $debug->checkpoint('schema_query_failed',array('statement'=>$label,'error'=>$error));
      } elseif(msw_debug_enabled()){
        msw_debug_write_log(array('id'=>'schema','stage'=>'query_failed','statement'=>$label,'error'=>$error));
      }
    }
  }catch(mysqli_sql_exception $e){
    $success = false;
    $error = $e->getMessage();
    $errors[$label] = $error;
    if($debug instanceof MswDebugCollector){
      $debug->checkpoint('schema_query_exception',array('statement'=>$label,'error'=>$error));
    } elseif(msw_debug_enabled()){
      msw_debug_write_log(array('id'=>'schema','stage'=>'query_exception','statement'=>$label,'error'=>$error));
    }
  }
  return $success;
}

function msw_is_foreign_key_error($error){
  if(!is_string($error) || $error === ''){
    return false;
  }
  $err = strtolower($error);
  return strpos($err,'errno: 150') !== false ||
         strpos($err,'foreign key constraint') !== false ||
         strpos($err,'cannot add foreign key') !== false;
}

function msw_get_table_engine($db,$table){
  $stmt = $db->prepare("SELECT ENGINE FROM INFORMATION_SCHEMA.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ?");
  if(!$stmt){
    return null;
  }
  $stmt->bind_param('s',$table);
  if(!$stmt->execute()){
    $stmt->close();
    return null;
  }
  $stmt->bind_result($engine);
  $result = null;
  if($stmt->fetch()){
    $result = $engine;
  }
  $stmt->close();
  return $result;
}

function msw_is_safe_identifier($identifier){
  return is_string($identifier) && preg_match('/^[A-Za-z0-9_]+$/',$identifier);
}

function msw_ensure_innodb_table($db,$table,$debug=null){
  $engine = msw_get_table_engine($db,$table);
  if($engine === null || strtoupper($engine) === 'INNODB'){
    return;
  }
  if(!msw_is_safe_identifier($table)){
    return;
  }
  $sql = "ALTER TABLE `{$table}` ENGINE=InnoDB";
  try{
    $result = $db->query($sql);
    if($result === false){
      $error = $db->error;
      if($debug instanceof MswDebugCollector){
        $debug->checkpoint('schema_engine_update_failed',array('table'=>$table,'error'=>$error));
      } elseif(msw_debug_enabled()){
        msw_debug_write_log(array('id'=>'schema','stage'=>'engine_update_failed','table'=>$table,'error'=>$error));
      }
    }
  }catch(mysqli_sql_exception $e){
    $error = $e->getMessage();
    if($debug instanceof MswDebugCollector){
      $debug->checkpoint('schema_engine_update_exception',array('table'=>$table,'error'=>$error));
    } elseif(msw_debug_enabled()){
      msw_debug_write_log(array('id'=>'schema','stage'=>'engine_update_exception','table'=>$table,'error'=>$error));
    }
  }
}

function msw_get_column_type($db,$table,$column){
  $stmt = $db->prepare("SELECT COLUMN_TYPE FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND COLUMN_NAME = ?");
  if(!$stmt){
    return null;
  }
  $stmt->bind_param('ss',$table,$column);
  if(!$stmt->execute()){
    $stmt->close();
    return null;
  }
  $stmt->bind_result($type);
  $result = null;
  if($stmt->fetch()){
    $normalized = strtoupper((string)$type);
    $normalized = preg_replace('/\s+/',' ',$normalized);
    $result = trim($normalized);
  }
  $stmt->close();
  return $result;
}

function format_schema_error_message(array $schemaErrors){
  if(empty($schemaErrors)){
    return '';
  }
  $parts = array();
  foreach($schemaErrors as $label=>$error){
    $text = trim((string)$error);
    if($text === ''){
      continue;
    }
    if(is_string($label) && $label !== ''){
      $parts[] = $label.': '.$text;
    } else {
      $parts[] = $text;
    }
  }
  if(empty($parts)){
    return '';
  }
  $parts = array_values(array_unique($parts));
  $message = implode(' | ',$parts);
  return preg_replace('/\s+/u',' ',trim($message));
}

function init_local_tables($db,$prefix,$debug=null){
  $errors = array();
  $queries = array(
    'logs' => "CREATE TABLE IF NOT EXISTS {$prefix}logs (id INT AUTO_INCREMENT PRIMARY KEY, action VARCHAR(20), ip VARCHAR(45), ts DATETIME)",
    'roles' => "CREATE TABLE IF NOT EXISTS {$prefix}roles (id INT AUTO_INCREMENT PRIMARY KEY, name VARCHAR(191) UNIQUE, permissions TEXT)",
    'roles_seed' => "INSERT INTO {$prefix}roles(id,name,permissions) VALUES (1,'مدیر کل','all') ON DUPLICATE KEY UPDATE name='مدیر کل', permissions='all'",
    'users' => "CREATE TABLE IF NOT EXISTS {$prefix}users (id INT AUTO_INCREMENT PRIMARY KEY, username VARCHAR(191) UNIQUE, password_hash VARCHAR(255) NOT NULL, full_name VARCHAR(191), phone_number VARCHAR(20), role_id INT, status VARCHAR(20) DEFAULT 'active', created_at DATETIME, updated_at DATETIME, FOREIGN KEY (role_id) REFERENCES {$prefix}roles(id))",
    'clients' => "CREATE TABLE IF NOT EXISTS {$prefix}clients (id INT AUTO_INCREMENT PRIMARY KEY, client_name VARCHAR(191), api_key VARCHAR(191), client_secret VARCHAR(191), redirect_uri TEXT, status VARCHAR(20))",
    'user_logs' => "CREATE TABLE IF NOT EXISTS {$prefix}user_logs (id INT AUTO_INCREMENT PRIMARY KEY, user_id INT, action VARCHAR(50), timestamp DATETIME, ip_address VARCHAR(45), country VARCHAR(100), city VARCHAR(100), isp VARCHAR(191), FOREIGN KEY (user_id) REFERENCES {$prefix}users(id) ON DELETE CASCADE)",
    'product_assignments' => "CREATE TABLE IF NOT EXISTS {$prefix}product_assignments (id INT AUTO_INCREMENT PRIMARY KEY, user_id INT, product_id BIGINT, assigned_at DATETIME DEFAULT CURRENT_TIMESTAMP, UNIQUE KEY product_unique (product_id), KEY user_idx (user_id))",
    'assignment_modes' => "CREATE TABLE IF NOT EXISTS {$prefix}assignment_modes (user_id INT PRIMARY KEY, mode VARCHAR(20), quota_min INT, quota_max INT, category_id BIGINT, FOREIGN KEY (user_id) REFERENCES {$prefix}users(id) ON DELETE CASCADE)",
    'password_resets' => "CREATE TABLE IF NOT EXISTS {$prefix}password_resets (id INT AUTO_INCREMENT PRIMARY KEY, user_id INT, reset_token VARCHAR(255), expires_at DATETIME, FOREIGN KEY (user_id) REFERENCES {$prefix}users(id) ON DELETE CASCADE)",
    'settings' => "CREATE TABLE IF NOT EXISTS {$prefix}settings (id INT AUTO_INCREMENT PRIMARY KEY, name VARCHAR(191) UNIQUE, value TEXT)",
    'product_content_history' => "CREATE TABLE IF NOT EXISTS {$prefix}product_content_history (id BIGINT AUTO_INCREMENT PRIMARY KEY, product_id BIGINT, old_content LONGTEXT, new_content LONGTEXT, changed_by INT, changed_at DATETIME, version INT, FOREIGN KEY (changed_by) REFERENCES {$prefix}users(id) ON DELETE SET NULL)",
    'product_seo_scores' => "CREATE TABLE IF NOT EXISTS {$prefix}product_seo_scores (product_id BIGINT PRIMARY KEY, score INT, details LONGTEXT, analyzed_at DATETIME)",
    'processes' => "CREATE TABLE IF NOT EXISTS {$prefix}processes (id INT AUTO_INCREMENT PRIMARY KEY, name VARCHAR(191) UNIQUE, active TINYINT(1) DEFAULT 1, interval_hours INT, last_run DATETIME, timezone VARCHAR(50) DEFAULT 'Asia/Tehran')",
    'process_queue' => "CREATE TABLE IF NOT EXISTS {$prefix}process_queue (id INT AUTO_INCREMENT PRIMARY KEY, process_name VARCHAR(255), status ENUM('pending','running','completed','failed') DEFAULT 'pending', started_at DATETIME NULL, finished_at DATETIME NULL, result TEXT NULL)",
    'internal_links' => "CREATE TABLE IF NOT EXISTS {$prefix}internal_links (id INT AUTO_INCREMENT PRIMARY KEY, category VARCHAR(191) UNIQUE, url TEXT, title VARCHAR(191))",
    'external_links' => "CREATE TABLE IF NOT EXISTS {$prefix}external_links (id INT AUTO_INCREMENT PRIMARY KEY, url TEXT, title VARCHAR(191))",
    'search_console_daily' => "CREATE TABLE IF NOT EXISTS {$prefix}search_console_daily (
        id INT AUTO_INCREMENT PRIMARY KEY,
        date DATE,
        site_url VARCHAR(255),
        page VARCHAR(2083),
        query VARCHAR(255),
        device VARCHAR(20),
        country VARCHAR(10),
        clicks INT,
        impressions INT,
        ctr DECIMAL(5,2),
        position DECIMAL(8,2),
        search_appearance VARCHAR(50),
        sessions INT NULL,
        bounce_rate DECIMAL(5,2) NULL,
        avg_session_duration INT NULL,
        conversions INT NULL,
        lcp DECIMAL(6,3) NULL,
        cls DECIMAL(5,3) NULL,
        fid DECIMAL(6,3) NULL,
        ttfb DECIMAL(6,3) NULL,
        referring_domains INT NULL,
        anchors TEXT NULL,
        trends_interest INT NULL,
        UNIQUE KEY uniq (date,site_url(32),page(64),query(64),device,country,search_appearance(24))
  ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4"
  );
  foreach($queries as $label=>$sql){
    msw_execute_schema($db,$sql,$label,$debug,$errors);
  }
  $kpiErrors = ensure_kpi_tables($db,$prefix,$debug);
  if(!empty($kpiErrors)){
    $errors = array_merge($errors,$kpiErrors);
  }
  return $errors;
}

function ensure_kpi_tables($db,$prefix,$debug=null){
  $errors = array();
  $userTable = $prefix.'users';
  $historyTable = $prefix.'product_content_history';
  msw_ensure_innodb_table($db,$userTable,$debug);
  msw_ensure_innodb_table($db,$historyTable,$debug);

  $userIdType = msw_get_column_type($db,$userTable,'id');
  if(!$userIdType){
    $userIdType = 'INT';
  }
  $historyIdType = msw_get_column_type($db,$historyTable,'id');
  if(!$historyIdType){
    $historyIdType = 'BIGINT';
  }

  $tables = array(
    'user_kpi_events' => array(
      'primary' => sprintf(
        "CREATE TABLE IF NOT EXISTS {$prefix}user_kpi_events (
    id BIGINT AUTO_INCREMENT PRIMARY KEY,
    history_id %s UNIQUE,
    user_id %s,
    product_id BIGINT,
    assigned_user_id %s NULL,
    edited_at DATETIME,
    seo_before DECIMAL(5,2) NULL,
    seo_after DECIMAL(5,2) NULL,
    seo_improvement DECIMAL(5,2) NULL,
    words_before INT DEFAULT 0,
    words_after INT DEFAULT 0,
    words_delta INT DEFAULT 0,
    words_added INT DEFAULT 0,
    activity_minutes DECIMAL(10,2) DEFAULT 0,
    FOREIGN KEY (history_id) REFERENCES {$prefix}product_content_history(id) ON DELETE CASCADE,
    FOREIGN KEY (user_id) REFERENCES {$prefix}users(id) ON DELETE CASCADE,
    FOREIGN KEY (assigned_user_id) REFERENCES {$prefix}users(id) ON DELETE SET NULL,
    KEY idx_user_date (user_id, edited_at)
  ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",
        $historyIdType,
        $userIdType,
        $userIdType
      ),
      'fallback' => sprintf(
        "CREATE TABLE IF NOT EXISTS {$prefix}user_kpi_events (
    id BIGINT AUTO_INCREMENT PRIMARY KEY,
    history_id %s UNIQUE,
    user_id %s,
    product_id BIGINT,
    assigned_user_id %s NULL,
    edited_at DATETIME,
    seo_before DECIMAL(5,2) NULL,
    seo_after DECIMAL(5,2) NULL,
    seo_improvement DECIMAL(5,2) NULL,
    words_before INT DEFAULT 0,
    words_after INT DEFAULT 0,
    words_delta INT DEFAULT 0,
    words_added INT DEFAULT 0,
    activity_minutes DECIMAL(10,2) DEFAULT 0,
    KEY idx_user_date (user_id, edited_at),
    KEY idx_user_kpi_events_user (user_id),
    KEY idx_user_kpi_events_assigned (assigned_user_id)
  ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",
        $historyIdType,
        $userIdType,
        $userIdType
      )
    ),
    'user_kpi_daily' => array(
      'primary' => sprintf(
        "CREATE TABLE IF NOT EXISTS {$prefix}user_kpi_daily (
    id INT AUTO_INCREMENT PRIMARY KEY,
    date DATE,
    user_id %s,
    total_edits INT DEFAULT 0,
    assigned_edits INT DEFAULT 0,
    seo_before_sum DECIMAL(10,2) DEFAULT 0,
    seo_after_sum DECIMAL(10,2) DEFAULT 0,
    improvement_sum DECIMAL(10,2) DEFAULT 0,
    activity_minutes DECIMAL(10,2) DEFAULT 0,
    words_added_sum INT DEFAULT 0,
    words_total_sum INT DEFAULT 0,
    UNIQUE KEY uniq_date_user (date,user_id),
    FOREIGN KEY (user_id) REFERENCES {$prefix}users(id) ON DELETE CASCADE
  ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",
        $userIdType
      ),
      'fallback' => sprintf(
        "CREATE TABLE IF NOT EXISTS {$prefix}user_kpi_daily (
    id INT AUTO_INCREMENT PRIMARY KEY,
    date DATE,
    user_id %s,
    total_edits INT DEFAULT 0,
    assigned_edits INT DEFAULT 0,
    seo_before_sum DECIMAL(10,2) DEFAULT 0,
    seo_after_sum DECIMAL(10,2) DEFAULT 0,
    improvement_sum DECIMAL(10,2) DEFAULT 0,
    activity_minutes DECIMAL(10,2) DEFAULT 0,
    words_added_sum INT DEFAULT 0,
    words_total_sum INT DEFAULT 0,
    UNIQUE KEY uniq_date_user (date,user_id),
    KEY idx_user_kpi_daily_user (user_id)
  ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",
        $userIdType
      )
    )
  );

  foreach($tables as $label=>$definition){
    $created = msw_execute_schema($db,$definition['primary'],$label,$debug,$errors);
    if($created){
      continue;
    }
    $errorText = isset($errors[$label]) ? $errors[$label] : '';
    if(!isset($definition['fallback']) || !msw_is_foreign_key_error($errorText)){
      continue;
    }
    $fallbackLabel = $label.'_nofk';
    $fallbackCreated = msw_execute_schema($db,$definition['fallback'],$fallbackLabel,$debug,$errors);
    if($fallbackCreated){
      unset($errors[$label]);
      if($debug instanceof MswDebugCollector){
        $debug->checkpoint('schema_fk_fallback',array('statement'=>$label,'error'=>$errorText));
      } elseif(msw_debug_enabled()){
        msw_debug_write_log(array('id'=>'schema','stage'=>'fk_fallback','statement'=>$label,'error'=>$errorText));
      }
    }
  }

  return $errors;
}

function estimate_word_count($html){
  $text = trim(strip_tags($html));
  if($text === '') return 0;
  $parts = preg_split('/\s+/u',$text,-1,PREG_SPLIT_NO_EMPTY);
  return $parts ? count($parts) : 0;
}

function estimate_activity_minutes($wordsBefore,$wordsAfter){
  $wordsBefore = max(0,intval($wordsBefore));
  $wordsAfter = max(0,intval($wordsAfter));
  $delta = abs($wordsAfter - $wordsBefore);
  $base = max($wordsAfter,$delta);
  if($base <= 0){ return 0.25; }
  return round(max($base/120,0.25),2);
}

function seed_content_history_if_empty($db,$prefix){
  $cnt = $db->query("SELECT COUNT(*) c FROM {$prefix}product_content_history");
  $row = $cnt ? $cnt->fetch_assoc() : null;
  if($cnt){ $cnt->close(); }
  if(!$row || intval($row['c'])>0) return;
  // connect to WooCommerce database
  if(!isset($_SESSION['db'])){ $cfg = secure_load_config(); if(!$cfg) return; $_SESSION['db']=$cfg; } else { $cfg=$_SESSION['db']; }
  try{ $wdb = new mysqli($cfg['host'],$cfg['user'],$cfg['pass'],$cfg['name']); }
  catch(mysqli_sql_exception $e){ return; }
  if($wdb->connect_errno){ return; }
  $wdb->set_charset('utf8mb4');
  $wp = $cfg['prefix'];
  $res = $wdb->query("SELECT ID,post_content FROM {$wp}posts WHERE post_type='product' AND post_status='publish'");
  if($res){
    $stmt = $db->prepare("INSERT INTO {$prefix}product_content_history (product_id, old_content, new_content, changed_by, changed_at, version) VALUES (?,NULL,?,0,NOW(),1)");
    while($p = $res->fetch_assoc()){
      $pid = intval($p['ID']);
      $content = $p['post_content'];
      $stmt->bind_param('is',$pid,$content);
      $stmt->execute();
    }
    $stmt->close();
    $res->close();
  }
  $wdb->close();
}

function get_setting($db,$prefix,$name){
  $stmt = $db->prepare("SELECT value FROM {$prefix}settings WHERE name=?");
  if(!$stmt) return null;
  $stmt->bind_param('s',$name);
  $stmt->execute();
  $res = $stmt->get_result();
  $row = $res ? $res->fetch_assoc() : null;
  $stmt->close();
  return $row ? $row['value'] : null;
}

function save_setting($db,$prefix,$name,$value){
  $stmt = $db->prepare("INSERT INTO {$prefix}settings(name,value) VALUES(?,?) ON DUPLICATE KEY UPDATE value=VALUES(value)");
  if(!$stmt) return false;
  $stmt->bind_param('ss',$name,$value);
  $ok = $stmt->execute();
  $stmt->close();
  return $ok;
}

function secure_save_local_config($data){
  $json = json_encode($data);
  file_put_contents(__DIR__.'/local_config.secure', $json);
}

function secure_load_local_config(){
  $path = __DIR__.'/local_config.secure';
  if(!file_exists($path)) return false;
  $json = file_get_contents($path);
  return $json ? json_decode($json,true) : false;
}

function google_index_url($url){
  $token = '';
  $tokenPath = __DIR__.'/google_token.txt';
  if(file_exists($tokenPath)){
    $token = trim(file_get_contents($tokenPath));
  }
  if(!$token){
    $db = connect_local();
  if($db){
      $prefix = $_SESSION['logdb']['prefix'];
      $cid = get_setting($db,$prefix,'sc_client_id');
      $secret = get_setting($db,$prefix,'sc_client_secret');
      $refresh = get_setting($db,$prefix,'sc_refresh_token');
      $db->close();
      if($cid && $secret && $refresh){
        $ch = curl_init('https://oauth2.googleapis.com/token');
        curl_setopt_array($ch,array(
          CURLOPT_POST=>true,
          CURLOPT_POSTFIELDS=>http_build_query(array(
            'client_id'=>$cid,
            'client_secret'=>$secret,
            'refresh_token'=>$refresh,
            'grant_type'=>'refresh_token'
          )),
          CURLOPT_RETURNTRANSFER=>true
        ));
        $tok = curl_exec($ch);
        curl_close($ch);
        if($tok !== false){
          $tok = json_decode($tok,true);
          $token = $tok['access_token'] ?? '';
        }
      }
    }
  }
  if(!$token) return array(false,'token missing');
  $payload = json_encode(array('url'=>$url,'type'=>'URL_UPDATED'));
  $ch = curl_init('https://indexing.googleapis.com/v3/urlNotifications:publish');
  curl_setopt_array($ch,array(
    CURLOPT_POST=>true,
    CURLOPT_RETURNTRANSFER=>true,
    CURLOPT_HTTPHEADER=>array('Content-Type: application/json','Authorization: Bearer '.$token),
    CURLOPT_POSTFIELDS=>$payload
  ));
  $resp = curl_exec($ch);
  $code = curl_getinfo($ch,CURLINFO_HTTP_CODE);
  curl_close($ch);
  return array($code==200,$resp);
}

function log_event($action){
  try{
    $db = connect_local();
  }catch(Throwable $e){
    if(msw_debug_enabled()){
      msw_debug_write_log(array('id'=>'log_event','stage'=>'connect_exception','action'=>$action,'error'=>$e->getMessage()));
    }
    return;
  }
  if(!$db){
    if(msw_debug_enabled()){
      msw_debug_write_log(array('id'=>'log_event','stage'=>'connect_failed','action'=>$action));
    }
    return;
  }
  $prefix = $_SESSION['logdb']['prefix'];
  $ip = isset($_SERVER['REMOTE_ADDR']) ? $_SERVER['REMOTE_ADDR'] : '';
  $uid = isset($_SESSION['user_id']) ? intval($_SESSION['user_id']) : 0;
  $dt = new DateTime('now', new DateTimeZone('Asia/Tehran'));
  $ts = $dt->format('Y-m-d H:i:s');
  $geo = array('country'=>'','city'=>'','isp'=>'');
  try{
    $key = get_setting($db,$prefix,'ipify_key');
    if($key){
      $url = "https://geo.ipify.org/api/v2/country,city?apiKey={$key}&ip={$ip}";
      $resp = @file_get_contents($url);
      if($resp){
        $data = json_decode($resp,true);
        if($data){
          $geo['country'] = $data['location']['country'] ?? '';
          $geo['city'] = $data['location']['city'] ?? '';
          $geo['isp'] = $data['isp'] ?? '';
        }
      }
    }
  }catch(Throwable $geoEx){
    if(msw_debug_enabled()){
      msw_debug_write_log(array('id'=>'log_event','stage'=>'geo_lookup_failed','action'=>$action,'error'=>$geoEx->getMessage()));
    }
  }
  $stmt = $db->prepare("INSERT INTO {$prefix}user_logs(user_id, action, ip_address, country, city, isp, timestamp) VALUES (?,?,?,?,?,?,?)");
  if($stmt){
    try{
      $stmt->bind_param('issssss',$uid,$action,$ip,$geo['country'],$geo['city'],$geo['isp'],$ts);
      $stmt->execute();
    }catch(mysqli_sql_exception $e){
      if(msw_debug_enabled()){
        msw_debug_write_log(array('id'=>'log_event','stage'=>'insert_failed','action'=>$action,'error'=>$e->getMessage()));
      }
    }
    $stmt->close();
  } elseif(msw_debug_enabled()){
    msw_debug_write_log(array('id'=>'log_event','stage'=>'prepare_failed','action'=>$action,'error'=>$db->error));
  }
  $db->close();
}

?>