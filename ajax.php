<?php
session_start();
header('Content-Type: application/json; charset=utf-8');

$AUTH_TOKEN = '09128334246';
$configFile = __DIR__.'/config.secure';
require_once __DIR__.'/prompt_template.php';
$action = isset($_POST['action']) ? $_POST['action'] : '';

if(!isset($_SESSION['auth']) && $action !== 'login'){
  http_response_code(401);
  echo json_encode(array('success'=>false,'message'=>'دسترسی غیرمجاز'));
  exit;
}

switch($action){
case 'login':
  $token = isset($_POST['token']) ? $_POST['token'] : '';
  if($token === $AUTH_TOKEN){
    $_SESSION['auth'] = true;
    $_SESSION['token'] = $token;
    echo json_encode(array('success'=>true));
  } else {
    echo json_encode(array('success'=>false,'message'=>'توکن نامعتبر است'));
  }
  break;
 case 'logout':
  session_destroy();
  echo json_encode(array('success'=>true));
  break;
case 'read_wp_config':
  $config_path = dirname(__DIR__).'/wp-config.php';
  if(file_exists($config_path)){
    $config = file_get_contents($config_path);
    preg_match("/define\(\s*'DB_NAME',\s*'([^']+)'\s*\)/", $config, $m); $name = isset($m[1]) ? $m[1] : '';
    preg_match("/define\(\s*'DB_USER',\s*'([^']+)'\s*\)/", $config, $m); $user = isset($m[1]) ? $m[1] : '';
    preg_match("/define\(\s*'DB_PASSWORD',\s*'([^']+)'\s*\)/", $config, $m); $pass = isset($m[1]) ? $m[1] : '';
    preg_match("/define\(\s*'DB_HOST',\s*'([^']+)'\s*\)/", $config, $m); $host = isset($m[1]) ? $m[1] : 'localhost';
    preg_match("/\$table_prefix\s*=\s*'([^']+)'/", $config, $m); $prefix = isset($m[1]) ? $m[1] : 'wp_';
    secure_save_config(compact('host','name','user','pass','prefix'));
    echo json_encode(array('success'=>true,'name'=>$name,'user'=>$user,'pass'=>$pass,'host'=>$host,'prefix'=>$prefix));
  } else {
    echo json_encode(array('success'=>false,'message'=>'فایل wp-config.php پیدا نشد'));
  }
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
case 'list_products':
  $db = connect(); if(!$db) break;
  $prefix = $_SESSION['db']['prefix'];
  $start = isset($_POST['start']) ? intval($_POST['start']) : 0;
  $length = isset($_POST['length']) ? intval($_POST['length']) : 100;
  $draw = isset($_POST['draw']) ? intval($_POST['draw']) : 0;
  try{
    $totalRes = $db->query("SELECT COUNT(*) c FROM {$prefix}posts WHERE post_type='product' AND post_status='publish'");
    $total = $totalRes ? intval($totalRes->fetch_assoc()['c']) : 0;
    $sql = "SELECT ID,post_title,post_content,post_name FROM {$prefix}posts WHERE post_type='product' AND post_status='publish' LIMIT $start,$length";
    $res = $db->query($sql);
    if(!$res){ throw new Exception($db->error); }
    $rows = array();
    $scheme = isset($_SERVER['REQUEST_SCHEME']) ? $_SERVER['REQUEST_SCHEME'] : 'http';
    $site = $scheme.'://'.$_SERVER['HTTP_HOST'];
    while($row = $res->fetch_assoc()){
        $id = $row['ID'];
        $imgRes = $db->query(
          "SELECT p2.guid FROM {$prefix}postmeta pm " .
          "JOIN {$prefix}posts p2 ON p2.ID = pm.meta_value " .
          "WHERE pm.post_id=$id AND pm.meta_key='_thumbnail_id' " .
          "ORDER BY pm.meta_id DESC LIMIT 1"
        );
        $imgRow = $imgRes ? $imgRes->fetch_assoc() : null; $image = ($imgRow && isset($imgRow['guid'])) ? $imgRow['guid'] : '';
        $priceRes = $db->query("SELECT meta_value FROM {$prefix}postmeta WHERE post_id=$id AND meta_key='_price'");
        $priceRow = $priceRes ? $priceRes->fetch_assoc() : null; $price = ($priceRow && isset($priceRow['meta_value'])) ? $priceRow['meta_value'] : '';
        $stockRes = $db->query("SELECT meta_value FROM {$prefix}postmeta WHERE post_id=$id AND meta_key='_stock_status'");
        $stockRow = $stockRes ? $stockRes->fetch_assoc() : null; $stock = ($stockRow && isset($stockRow['meta_value'])) ? $stockRow['meta_value'] : 'instock';
        $metaRes = $db->query("SELECT meta_key,meta_value FROM {$prefix}postmeta WHERE post_id=$id AND meta_key IN ('_yoast_wpseo_title','_yoast_wpseo_metadesc')");
        $seoTitle='';$seoDesc='';
        if($metaRes){ while($m=$metaRes->fetch_assoc()){ if($m['meta_key']=='_yoast_wpseo_title') $seoTitle=$m['meta_value']; elseif($m['meta_key']=='_yoast_wpseo_metadesc') $seoDesc=$m['meta_value']; }}
        $score = compute_seo_score($seoTitle ?: $row['post_title'], $seoDesc, $row['post_content'], $row['post_title']);
        $seoColor='secondary';
        $seoText='ندارد';
        if($row['post_content'] || $seoTitle || $seoDesc){
          $seoText=$score;
          if($score >= 70){ $seoColor='success'; }
          elseif($score >= 40){ $seoColor='warning'; }
          else { $seoColor='danger'; }
        }
        $priceDisplay = ($price && $price !== '0') ? $price : 'بدون قیمت';
        $stockDisplay = $stock=='instock' ? '<span class="badge bg-success">موجود</span>' : '<span class="badge bg-danger">ناموجود</span>';
        $productUrl = $site.'/product/'.$row['post_name'].'/';
        $rows[] = array(
          '<img data-src="'.$image.'" width="50" height="50" class="lazy-img rounded" loading="lazy" src="data:image/gif;base64,R0lGODlhAQABAIAAAAAAAP///ywAAAAAAQABAAACAUwAOw==" alt="">',
          $row['post_title'],
          $priceDisplay,
          $stockDisplay,
          '<span class="badge bg-'.$seoColor.'">'.$seoText.'</span>',
          '<button class="btn btn-sm btn-primary edit" data-id="'.$id.'">ویرایش</button>',
          '<a class="btn btn-sm btn-outline-secondary" href="'.$productUrl.'" target="_blank">نمایش</a>'
        );
    }
    echo json_encode(array('draw'=>$draw,'recordsTotal'=>$total,'recordsFiltered'=>$total,'data'=>$rows));
  }catch(Exception $e){
    echo json_encode(array('draw'=>$draw,'recordsTotal'=>0,'recordsFiltered'=>0,'data'=>array(),'error'=>$e->getMessage()));
  }finally{
    $db->close();
  }
  break;
case 'get_product':
  $db = connect(); if(!$db) break;
  $prefix = $_SESSION['db']['prefix'];
  $id = intval($_POST['id']);
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
  $name = $db->real_escape_string($_POST['name']);
  $slug = $db->real_escape_string($_POST['slug']);
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
    echo json_encode(array('success'=>false,'message'=>'عدم اتصال به پایگاه داده'));
    return false;
  }
  $cfg = $_SESSION['db'];
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
  if(!isset($_SESSION['token'])) return;
  $key = hash('sha256', $_SESSION['token'], true);
  $iv = random_bytes(16);
  $json = json_encode($data);
  $enc = openssl_encrypt($json, 'AES-256-CBC', $key, OPENSSL_RAW_DATA, $iv);
  file_put_contents(__DIR__.'/config.secure', base64_encode($iv.$enc));
}

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