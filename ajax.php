<?php
session_start();
header('Content-Type: application/json; charset=utf-8');

$configFile = __DIR__.'/config.secure';
require_once __DIR__.'/prompt_template.php';
require_once __DIR__.'/classes/UserManager.php';
$action = isset($_POST['action']) ? $_POST['action'] : '';

function has_perm($p){
  $perm = $_SESSION['permissions'] ?? '';
  return $perm === 'all' || strpos($perm,$p) !== false;
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
  $username = trim($_POST['username'] ?? '');
  $password = $_POST['password'] ?? '';
  $cfg = secure_load_local_config();
  if(!$cfg){ echo json_encode(array('success'=>false,'message'=>'تنظیمات پایگاه داده سامانه موجود نیست')); break; }
  try{ $db = new mysqli($cfg['host'],$cfg['user'],$cfg['pass'],$cfg['name']); }
  catch(mysqli_sql_exception $e){ echo json_encode(array('success'=>false,'message'=>$e->getMessage())); break; }
  if($db->connect_errno){ echo json_encode(array('success'=>false,'message'=>$db->connect_error)); break; }
  $db->set_charset('utf8mb4');
  init_local_tables($db,$cfg['prefix']);
  $stmt = $db->prepare("SELECT u.id,u.username,u.full_name,u.password_hash,r.permissions FROM {$cfg['prefix']}users u JOIN {$cfg['prefix']}roles r ON u.role_id=r.id WHERE u.username=? AND u.status='active'");
  $stmt->bind_param('s',$username);
  $stmt->execute();
  $res = $stmt->get_result();
  $row = $res ? $res->fetch_assoc() : null;
  $stmt->close();
  if($row && password_verify($password,$row['password_hash'])){
    $_SESSION['auth'] = true;
    $_SESSION['user_id'] = intval($row['id']);
    $_SESSION['username'] = $row['username'];
    $_SESSION['full_name'] = $row['full_name'] ?? '';
    $_SESSION['permissions'] = $row['permissions'];
    $_SESSION['logdb'] = $cfg;
    $mainCfg = secure_load_config();
    if($mainCfg){ $_SESSION['db'] = $mainCfg; }
    log_event('login');
    echo json_encode(array('success'=>true));
  } else {
    echo json_encode(array('success'=>false,'message'=>'ورود نامعتبر'));
  }
  $db->close();
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
  init_local_tables($mysqli,$prefix);
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
  $stmt = $db->prepare("SELECT action, ip_address, country, city, isp, timestamp FROM {$prefix}user_logs WHERE user_id=? ORDER BY id DESC LIMIT 100");
  if($stmt){
    $stmt->bind_param('i',$uid);
    $stmt->execute();
    $res=$stmt->get_result();
    while($r=$res->fetch_assoc()){ $rows[] = array('action'=>$r['action'],'ip'=>$r['ip_address'],'country'=>$r['country'],'city'=>$r['city'],'isp'=>$r['isp'],'ts'=>$r['timestamp']); }
    $stmt->close();
  }
  $counts=array();
  foreach($rows as $r){ $key=$r['country'] ?: 'نامشخص'; $counts[$key] = ($counts[$key] ?? 0) + 1; }
  $db->close();
  echo json_encode(array('success'=>true,'data'=>$rows,'counts'=>$counts));
  break;
case 'logs_list':
  $db = connect_local();
  if(!$db){ echo json_encode(array('success'=>false,'message'=>'عدم اتصال به پایگاه داده سامانه')); break; }
  $prefix = $_SESSION['logdb']['prefix'];
  $rows = array();
  $res = $db->query("SELECT user_id, action, ip_address, country, city, isp, timestamp FROM {$prefix}user_logs ORDER BY id DESC LIMIT 200");
  if($res){ while($r=$res->fetch_assoc()){ $rows[]=$r; } }
  $db->close();
  echo json_encode(array('success'=>true,'data'=>$rows));
  break;
case 'fetch_search_console':
  $ldb = connect_local();
  if(!$ldb){ echo json_encode(array('success'=>false,'message'=>'عدم اتصال به پایگاه داده سامانه')); break; }
  $lp = $_SESSION['logdb']['prefix'];
  $cid = get_setting($ldb,$lp,'sc_client_id');
  $secret = get_setting($ldb,$lp,'sc_client_secret');
  $refresh = get_setting($ldb,$lp,'sc_refresh_token');
  $site = get_setting($ldb,$lp,'sc_site');
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
  $payload = json_encode(array(
    'startDate'=>date('Y-m-d',strtotime('-7 days')),
    'endDate'=>date('Y-m-d'),
    'dimensions'=>array('query'),
    'rowLimit'=>250
  ));
  $ch = curl_init('https://searchconsole.googleapis.com/webmasters/v3/sites/'.urlencode($site).'/searchAnalytics/query');
  curl_setopt_array($ch,array(
    CURLOPT_POST=>true,
    CURLOPT_HTTPHEADER=>array('Content-Type: application/json','Authorization: Bearer '.$acc),
    CURLOPT_POSTFIELDS=>$payload,
    CURLOPT_RETURNTRANSFER=>true
  ));
  $resp = curl_exec($ch);
  if($resp === false){ echo json_encode(array('success'=>false,'message'=>'API error')); break; }
  $resp = json_decode($resp,true);
  $rows = $resp['rows'] ?? array();
  $data = array();
  foreach($rows as $r){
    $data[] = array(
      'query'=>$r['keys'][0] ?? '',
      'clicks'=>$r['clicks'] ?? 0,
      'impressions'=>$r['impressions'] ?? 0,
      'ctr'=>isset($r['ctr'])?round($r['ctr']*100,2).'%' : '0%',
      'position'=>$r['position'] ?? 0
    );
  }
  if(empty($data)){
    echo json_encode(array('success'=>false,'message'=>'داده‌ای برای بازه زمانی انتخاب نشده'));
  }else{
    echo json_encode(array('success'=>true,'data'=>$data));
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
      $query = "SELECT ID,post_title,post_content,post_name FROM {$prefix}posts WHERE ID IN (".implode(',',$ids).")";
    }
    // اگر هیچ تخصیصی وجود نداشته باشد همه محصولات نمایش داده می‌شوند
  }
  try{
    $res = $db->query($query);
    if(!$res){ throw new Exception($db->error); }
    $rows = array();
    $scheme = isset($_SERVER['REQUEST_SCHEME']) ? $_SERVER['REQUEST_SCHEME'] : 'http';
    $site = $scheme.'://'.$_SERVER['HTTP_HOST'];
    while($row = $res->fetch_assoc()){
        $id = $row['ID'];
        $imgRes = $db->query("SELECT p2.guid FROM {$prefix}postmeta pm JOIN {$prefix}posts p2 ON p2.ID = pm.meta_value WHERE pm.post_id=$id AND pm.meta_key='_thumbnail_id' ORDER BY pm.meta_id DESC LIMIT 1");
        $imgRow = $imgRes ? $imgRes->fetch_assoc() : null; $image = ($imgRow && isset($imgRow['guid'])) ? $imgRow['guid'] : '';
        $priceRes = $db->query("SELECT meta_value FROM {$prefix}postmeta WHERE post_id=$id AND meta_key='_price'");
        $priceRow = $priceRes ? $priceRes->fetch_assoc() : null; $price = ($priceRow && isset($priceRow['meta_value'])) ? $priceRow['meta_value'] : '';
        $stockRes = $db->query("SELECT meta_value FROM {$prefix}postmeta WHERE post_id=$id AND meta_key='_stock_status'");
        $stockRow = $stockRes ? $stockRes->fetch_assoc() : null; $stock = ($stockRow && isset($stockRow['meta_value'])) ? $stockRow['meta_value'] : 'instock';
        $metaRes = $db->query("SELECT meta_key,meta_value FROM {$prefix}postmeta WHERE post_id=$id AND meta_key IN ('_yoast_wpseo_title','_yoast_wpseo_metadesc')");
        $seoTitle='';$seoDesc='';
        if($metaRes){ while($m=$metaRes->fetch_assoc()){ if($m['meta_key']=='_yoast_wpseo_title') $seoTitle=$m['meta_value']; elseif($m['meta_key']=='_yoast_wpseo_metadesc') $seoDesc=$m['meta_value']; }}
        $score = compute_seo_score($seoTitle ?: $row['post_title'], $seoDesc, $row['post_content'], $row['post_title']);
        $priceDisplay = ($price && $price !== '0') ? $price : 'بدون قیمت';
        $stockDisplay = $stock=='instock' ? 'موجود' : 'ناموجود';
        $productUrl = $site.'/product/'.$row['post_name'].'/';
        $rows[] = array(
          'id'=>$id,
          'image'=>$image,
          'name'=>$row['post_title'],
          'price'=>$priceDisplay,
          'stock'=>$stockDisplay,
          'seo'=>$score,
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
  if(!empty($selected)){ $categoryUrl = $site.'/product-category/'.$selected[0]['slug'].'/'; }
  $productUrl = $site.'/product/'.$p['post_name'].'/';
  $categoriesList = implode('، ', array_column($selected,'name'));
  $catLinksHtml = '<ul>'; foreach($selected as $c){ $catLinksHtml.='<li><a href="'.$site.'/product-category/'.$c['slug'].'/">'.$c['name'].'</a></li>'; } $catLinksHtml.='</ul>';
  $internal1 = $site; $internal2 = $categoryUrl ?: $site;
  $external1='https://fa.wikipedia.org'; $external2='https://www.google.com'; $shippingNotes='';
  $seo_prompt = build_prompt(array(
    '{{PRODUCT_TITLE}}'=>$p['post_title'],
    '{{BRAND_OR_SERIES}}'=>'',
    '{{MODEL_CODE}}'=>$model,
    '{{PRODUCT_URL}}'=>$productUrl,
    '{{PRODUCT_IMAGE_URL}}'=>$image,
    '{{CATEGORIES_LIST}}'=>$categoriesList,
    '{{PRIMARY_KEYWORD}}'=>$primaryKeyword ?: $p['post_title'],
    '{{INTERNAL_LINK_1_URL}}'=>$internal1,
    '{{INTERNAL_LINK_2_URL}}'=>$internal2,
    '{{CATEGORY_LINKS_HTML}}'=>$catLinksHtml,
    '{{EXTERNAL_LINK_1_URL}}'=>$external1,
    '{{EXTERNAL_LINK_2_URL}}'=>$external2,
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
  ));
  $seoScore = compute_seo_score($seoTitle ?: $p['post_title'], $seoDesc, $p['post_content'], $p['post_title']);
  echo json_encode(array(
    'success'=>true,
    'product'=>array('id'=>$id,'name'=>$p['post_title'],'slug'=>$p['post_name'],'description'=>$p['post_content'],'price'=>$price),
    'categories_html'=>$catsHtml,
    'seo_prompt'=>$seo_prompt,
    'seo_title'=>$seoTitle,
    'seo_desc'=>$seoDesc,
    'seo_score'=>$seoScore,
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
  $name = $db->real_escape_string($_POST['name']);
  $slug = $db->real_escape_string($_POST['slug']);
  $old_slug = isset($_POST['old_slug']) ? $db->real_escape_string($_POST['old_slug']) : '';
  $desc = $db->real_escape_string($_POST['description']);
  $price = $db->real_escape_string($_POST['price']);
  $stock = $db->real_escape_string($_POST['stock_status']);
  $db->query("UPDATE {$prefix}posts SET post_title='$name', post_name='$slug', post_content='$desc' WHERE ID=$id");
  $meta = $db->query("SELECT meta_id FROM {$prefix}postmeta WHERE post_id=$id AND meta_key='_price'");
  if($meta && $meta->num_rows){
    $db->query("UPDATE {$prefix}postmeta SET meta_value='$price' WHERE post_id=$id AND meta_key='_price'");
  }else{
    $db->query("INSERT INTO {$prefix}postmeta(post_id,meta_key,meta_value) VALUES ($id,'_price','$price')");
  }
  $meta = $db->query("SELECT meta_id FROM {$prefix}postmeta WHERE post_id=$id AND meta_key='_stock_status'");
  if($meta && $meta->num_rows){
    $db->query("UPDATE {$prefix}postmeta SET meta_value='$stock' WHERE post_id=$id AND meta_key='_stock_status'");
  }else{
    $db->query("INSERT INTO {$prefix}postmeta(post_id,meta_key,meta_value) VALUES ($id,'_stock_status','$stock')");
  }
  $db->query("DELETE FROM {$prefix}postmeta WHERE post_id=$id AND meta_key IN ('_yoast_wpseo_title','_yoast_wpseo_metadesc')");
  if(isset($_POST['seo_title'])){
    $st = $db->real_escape_string($_POST['seo_title']);
    $db->query("INSERT INTO {$prefix}postmeta(post_id,meta_key,meta_value) VALUES ($id,'_yoast_wpseo_title','$st')");
  }
  if(isset($_POST['seo_desc'])){
    $sd = $db->real_escape_string($_POST['seo_desc']);
    $db->query("INSERT INTO {$prefix}postmeta(post_id,meta_key,meta_value) VALUES ($id,'_yoast_wpseo_metadesc','$sd')");
  }
  $db->query("DELETE FROM {$prefix}postmeta WHERE post_id=$id AND meta_key='_yoast_wpseo_focuskw'");
  if(isset($_POST['focus_kw'])){
    $fk = $db->real_escape_string($_POST['focus_kw']);
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
     }
  }
  $redirect_success = false;
  if($old_slug && $old_slug !== $slug){
    $check = $db->query("SHOW TABLES LIKE '{$prefix}yoast_redirects'");
    if($check && $check->num_rows){
      $oldPath = '/product/'.$old_slug.'/';
      $newPath = '/product/'.$slug.'/';
      if($db->query("INSERT INTO {$prefix}yoast_redirects (origin,target,type) VALUES ('$oldPath','$newPath','301')")){
        $redirect_success = true;
      }
    }
  }
  echo json_encode(array('success'=>true,'redirect'=>$redirect_success));
  $db->close();
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
  if($products){
    while($p=$products->fetch_assoc()){
      $id = intval($p['ID']);
      $title = $db->real_escape_string($p['post_title']);
      $db->query("DELETE FROM {$prefix}postmeta WHERE post_id=$id AND meta_key IN ('_yoast_wpseo_metakeywords','_yoast_wpseo_focuskw')");
      $db->query("INSERT INTO {$prefix}postmeta(post_id,meta_key,meta_value) VALUES ($id,'_yoast_wpseo_metakeywords','$title'),($id,'_yoast_wpseo_focuskw','$title')");
      if($updateIndex){
        $db->query("UPDATE {$prefix}yoast_indexable SET primary_focus_keyword='$title', meta_keywords='$title' WHERE object_id=$id AND object_type='post'");
      }
    }
  }
  echo json_encode(array('success'=>true));
  $db->close();
  break;

case 'bulk_seo_desc':
  $db = connect(); if(!$db) break;
  $prefix = $_SESSION['db']['prefix'];
  $hasIndexTable = $db->query("SHOW TABLES LIKE '{$prefix}yoast_indexable'");
  $updateIndex = $hasIndexTable && $hasIndexTable->num_rows > 0;
  $products = $db->query("SELECT ID,post_title FROM {$prefix}posts WHERE post_type='product'");
  if($products){
    while($p=$products->fetch_assoc()){
      $id = intval($p['ID']);
      $title = $db->real_escape_string($p['post_title']);
      $desc  = $db->real_escape_string("خرید $title با بهترین قیمت از فروشگاه ما.");
      $db->query("DELETE FROM {$prefix}postmeta WHERE post_id=$id AND meta_key='_yoast_wpseo_metadesc'");
      $db->query("INSERT INTO {$prefix}postmeta(post_id,meta_key,meta_value) VALUES ($id,'_yoast_wpseo_metadesc','$desc')");
      if($updateIndex){
        $db->query("UPDATE {$prefix}yoast_indexable SET description='$desc' WHERE object_id=$id AND object_type='post'");
      }
    }
  }
  echo json_encode(array('success'=>true));
  $db->close();
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
  init_local_tables($mysqli,$cfg['prefix']);
  return $mysqli;
}

function init_local_tables($db,$prefix){
  $db->query("CREATE TABLE IF NOT EXISTS {$prefix}logs (id INT AUTO_INCREMENT PRIMARY KEY, action VARCHAR(20), ip VARCHAR(45), ts DATETIME)");
  $db->query("CREATE TABLE IF NOT EXISTS {$prefix}roles (id INT AUTO_INCREMENT PRIMARY KEY, name VARCHAR(191) UNIQUE, permissions TEXT)");
  $db->query("INSERT INTO {$prefix}roles(id,name,permissions) VALUES (1,'مدیر کل','all') ON DUPLICATE KEY UPDATE name='مدیر کل', permissions='all'");
  $db->query("CREATE TABLE IF NOT EXISTS {$prefix}users (id INT AUTO_INCREMENT PRIMARY KEY, username VARCHAR(191) UNIQUE, password_hash VARCHAR(255) NOT NULL, full_name VARCHAR(191), phone_number VARCHAR(20), role_id INT, status VARCHAR(20) DEFAULT 'active', created_at DATETIME, updated_at DATETIME, FOREIGN KEY (role_id) REFERENCES {$prefix}roles(id))");
  $db->query("CREATE TABLE IF NOT EXISTS {$prefix}sessions (id INT AUTO_INCREMENT PRIMARY KEY, user_id INT, token VARCHAR(255), ip_address VARCHAR(45), device_info VARCHAR(191), expires_at DATETIME, FOREIGN KEY (user_id) REFERENCES {$prefix}users(id) ON DELETE CASCADE)");
  $db->query("CREATE TABLE IF NOT EXISTS {$prefix}clients (id INT AUTO_INCREMENT PRIMARY KEY, client_name VARCHAR(191), api_key VARCHAR(191), client_secret VARCHAR(191), redirect_uri TEXT, status VARCHAR(20))");
  $db->query("CREATE TABLE IF NOT EXISTS {$prefix}user_logs (id INT AUTO_INCREMENT PRIMARY KEY, user_id INT, action VARCHAR(50), timestamp DATETIME, ip_address VARCHAR(45), country VARCHAR(100), city VARCHAR(100), isp VARCHAR(191), FOREIGN KEY (user_id) REFERENCES {$prefix}users(id) ON DELETE CASCADE)");
  $db->query("CREATE TABLE IF NOT EXISTS {$prefix}product_assignments (id INT AUTO_INCREMENT PRIMARY KEY, user_id INT, product_id BIGINT, assigned_at DATETIME DEFAULT CURRENT_TIMESTAMP, UNIQUE KEY product_unique (product_id), KEY user_idx (user_id))");
  $db->query("CREATE TABLE IF NOT EXISTS {$prefix}assignment_modes (user_id INT PRIMARY KEY, mode VARCHAR(20), quota_min INT, quota_max INT, category_id BIGINT, FOREIGN KEY (user_id) REFERENCES {$prefix}users(id) ON DELETE CASCADE)");
  $db->query("CREATE TABLE IF NOT EXISTS {$prefix}password_resets (id INT AUTO_INCREMENT PRIMARY KEY, user_id INT, reset_token VARCHAR(255), expires_at DATETIME, FOREIGN KEY (user_id) REFERENCES {$prefix}users(id) ON DELETE CASCADE)");
  $db->query("CREATE TABLE IF NOT EXISTS {$prefix}settings (id INT AUTO_INCREMENT PRIMARY KEY, name VARCHAR(191) UNIQUE, value TEXT)");
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

function log_event($action){
  $db = connect_local();
  if(!$db) return;
  $prefix = $_SESSION['logdb']['prefix'];
  $ip = isset($_SERVER['REMOTE_ADDR']) ? $_SERVER['REMOTE_ADDR'] : '';
  $uid = isset($_SESSION['user_id']) ? intval($_SESSION['user_id']) : 0;
  $dt = new DateTime('now', new DateTimeZone('Asia/Tehran'));
  $ts = $dt->format('Y-m-d H:i:s');
  $geo = array('country'=>'','city'=>'','isp'=>'');
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
  $stmt = $db->prepare("INSERT INTO {$prefix}user_logs(user_id, action, ip_address, country, city, isp, timestamp) VALUES (?,?,?,?,?,?,?)");
  if($stmt){
    $stmt->bind_param('issssss',$uid,$action,$ip,$geo['country'],$geo['city'],$geo['isp'],$ts);
    $stmt->execute();
    $stmt->close();
  }
  $db->close();
}

function compute_seo_score($title,$meta,$content,$keyword){
  $title = strtolower($title);
  $meta = strtolower($meta);
  $contentText = strtolower(strip_tags($content));
  $keyword = strtolower($keyword);
  $words = preg_split('/\\s+/', trim($contentText));
  $words = array_filter($words); $wordCount = count($words);
  $score=0;
  if(strlen($title)>=50 && strlen($title)<=65) $score+=10;
  if($keyword && strpos($title,$keyword)!==false) $score+=10;
  if(strlen($meta)>=120 && strlen($meta)<=155) $score+=10;
  if($keyword && strpos($meta,$keyword)!==false) $score+=10;
  if($wordCount>=300) $score+=10;
  if($keyword){
    $occ = substr_count($contentText,$keyword);
    $density = $wordCount ? ($occ/$wordCount)*100 : 0;
    if($density>=0.5 && $density<=3) $score+=10;
    $paras = preg_split('/\\n+/', $contentText);
    $first = isset($paras[0]) ? $paras[0] : '';
    if(strpos($first,$keyword)!==false) $score+=10;
  }
  preg_match_all('/<a\\s+[^>]*href=["\']([^"\']+)["\']/', $content, $m);
  $internal=0;$external=0;
  if(isset($m[1])){
    foreach($m[1] as $url){ if(strpos($url,'http')===0) $external++; else $internal++; }
  }
  if($internal>0) $score+=10;
  if($external>0) $score+=10;
  $sentences = preg_split('/[.!?؟]+/', $contentText, -1, PREG_SPLIT_NO_EMPTY);
  $avg = count($sentences)? $wordCount/count($sentences):$wordCount;
  if($avg<=20) $score+=10;
  return (int)$score;
}
?>