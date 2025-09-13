<?php
class ProcessManager {
  public static function getProcesses(&$steps=array()) {
    $ldb = connect_local();
    if(!$ldb){ $steps[] = 'عدم اتصال به پایگاه داده سامانه'; return array(); }
    $lp = $_SESSION['logdb']['prefix'];
    $rows = array();
    $res = $ldb->query("SELECT * FROM {$lp}processes");
    if($res && $res->num_rows == 0){
      $steps[] = 'هیچ فرایندی یافت نشد، مقداردهی اولیه';
      $ldb->query("INSERT INTO {$lp}processes (name,active,interval_hours,timezone) VALUES
        ('seo_score',1,24,'Asia/Tehran'),
        ('gsc_fetch',1,25,'Asia/Tehran')");
      $res = $ldb->query("SELECT * FROM {$lp}processes");
    }
    if($res){
      while($r = $res->fetch_assoc()){ $rows[] = $r; }
      $res->close();
    }
    $ldb->close();
    return $rows;
  }

  public static function saveProcesses($data){
    $ldb = connect_local();
    if(!$ldb) return false;
    $lp = $_SESSION['logdb']['prefix'];
    foreach($data as $p){
      $name = $ldb->real_escape_string($p['name']);
      $interval = intval($p['interval']);
      $active = intval($p['active']);
      $ldb->query("UPDATE {$lp}processes SET interval_hours=$interval, active=$active WHERE name='$name'");
    }
    $ldb->close();
    return true;
  }

  public static function run($name,&$steps=array()){
    date_default_timezone_set('Asia/Tehran');
    switch($name){
      case 'seo_score':
        self::runSeoScore($steps);
        break;
      case 'gsc_fetch':
        self::runGscFetch($steps);
        break;
      default:
        $steps[] = 'فرایند ناشناخته';
        break;
    }
    $ldb = connect_local();
    if($ldb){
      $lp = $_SESSION['logdb']['prefix'];
      $n = $ldb->real_escape_string($name);
      $ldb->query("UPDATE {$lp}processes SET last_run=NOW() WHERE name='$n'");
      $ldb->close();
    }
  }

  private static function runSeoScore(&$steps){
    $db = connect();
    if(!$db){ $steps[]='اتصال ووکامرس برقرار نشد'; return; }
    $steps[]='اتصال ووکامرس برقرار شد';
    $prefix = $_SESSION['db']['prefix'];
    $res = $db->query("SELECT ID,post_title,post_content FROM {$prefix}posts WHERE post_type='product' AND post_status='publish'");
    $count = 0;
    $ldb = connect_local();
    if(!$ldb){ $steps[]='اتصال پایگاه داده سامانه برقرار نشد'; }
    if($res){
      $steps[]='محصولات بازیابی شد: '.$res->num_rows;
      while($p=$res->fetch_assoc()){
        $id = intval($p['ID']);
        $titleRes = $db->query("SELECT meta_value FROM {$prefix}postmeta WHERE post_id=$id AND meta_key='_yoast_wpseo_title'");
        $titleRow = $titleRes ? $titleRes->fetch_assoc() : null; $seoTitle = ($titleRow['meta_value'] ?? '');
        $descRes = $db->query("SELECT meta_value FROM {$prefix}postmeta WHERE post_id=$id AND meta_key='_yoast_wpseo_metadesc'");
        $descRow = $descRes ? $descRes->fetch_assoc() : null; $seoDesc = ($descRow['meta_value'] ?? '');
        $focusRes = $db->query("SELECT meta_value FROM {$prefix}postmeta WHERE post_id=$id AND meta_key='_yoast_wpseo_focuskw'");
        $focusRow = $focusRes ? $focusRes->fetch_assoc() : null; $focus = ($focusRow['meta_value'] ?? $p['post_title']);
        $analysis = SEOAnalyzer::analyze($seoTitle ?: $p['post_title'], $seoDesc, $p['post_content'], $focus);
        if($ldb){
          $lp = $_SESSION['logdb']['prefix'];
          $stmt = $ldb->prepare("REPLACE INTO {$lp}product_seo_scores (product_id,score,details,analyzed_at) VALUES (?,?,?,NOW())");
          if($stmt){ $det=json_encode($analysis['details'],JSON_UNESCAPED_UNICODE); $stmt->bind_param('iis',$id,$analysis['score'],$det); $stmt->execute(); $stmt->close(); }
        }
        $count++;
      }
      $res->close();
    }else{
      $steps[]='هیچ محصولی یافت نشد';
    }
    if($ldb){ $ldb->close(); }
    $db->close();
    $steps[] = "محصولات پردازش شدند: $count";
  }

  public static function runDue(&$steps=array()){
    date_default_timezone_set('Asia/Tehran');
    $processes = self::getProcesses($steps);
    foreach($processes as $p){
      if(intval($p['active'])!==1) continue;
      $interval = intval($p['interval_hours']);
      $last = $p['last_run'];
      $due = !$last || strtotime($last) + $interval*3600 <= time();
      if($due) self::run($p['name'],$steps);
    }
  }
  
  private static function runGscFetch(&$steps){
    $ldb = connect_local();
    if(!$ldb){ $steps[]='اتصال پایگاه داده سامانه برقرار نشد'; return; }
    $lp = $_SESSION['logdb']['prefix'];
    $cid = self::getSetting($ldb,$lp,'sc_client_id');
    $secret = self::getSetting($ldb,$lp,'sc_client_secret');
    $refresh = self::getSetting($ldb,$lp,'sc_refresh_token');
    $site = self::getSetting($ldb,$lp,'sc_site');
    if(!$cid || !$secret || !$refresh || !$site){
      $steps[]='تنظیمات سرچ کنسول ناقص است';
      $ldb->close();
      return;
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
    curl_close($ch);
    if($tok===false){ $steps[]='token request failed'; $ldb->close(); return; }
    $tok=json_decode($tok,true);
    $acc=$tok['access_token']??'';
    if(!$acc){ $steps[]='access token missing'; $ldb->close(); return; }
    $from=date('Y-m-d',strtotime('-7 days'));
    $to=date('Y-m-d');
    $payload=json_encode(array(
      'startDate'=>$from,
      'endDate'=>$to,
      'dimensions'=>array('date'),
      'rowLimit'=>250
    ));
    $ch=curl_init('https://searchconsole.googleapis.com/webmasters/v3/sites/'.urlencode($site).'/searchAnalytics/query');
    curl_setopt_array($ch,array(
      CURLOPT_POST=>true,
      CURLOPT_HTTPHEADER=>array('Content-Type: application/json','Authorization: Bearer '.$acc),
      CURLOPT_POSTFIELDS=>$payload,
      CURLOPT_RETURNTRANSFER=>true
    ));
    $resp=curl_exec($ch);
    $http=curl_getinfo($ch,CURLINFO_HTTP_CODE);
    curl_close($ch);
    if($resp===false || $http!=200){ $steps[]='API request failed'; $ldb->close(); return; }
    $resp=json_decode($resp,true);
    $rows=$resp['rows']??array();
    $sumClicks=0;$sumImpr=0;$sumPosWeighted=0;
    foreach($rows as $r){
      $clicks=$r['clicks']??0; $impr=$r['impressions']??0; $pos=$r['position']??0;
      $sumClicks+=$clicks; $sumImpr+=$impr; $sumPosWeighted+=$pos*$impr;
    }
    $avgCtr=$sumImpr>0?round($sumClicks/$sumImpr*100,2):0;
    $avgPos=$sumImpr>0?round($sumPosWeighted/$sumImpr,2):0;
    $summary=array('start'=>$from,'end'=>$to,'clicks'=>$sumClicks,'impressions'=>$sumImpr,'ctr'=>$avgCtr,'position'=>$avgPos);
    $json=json_encode($summary,JSON_UNESCAPED_UNICODE);
    $stmt=$ldb->prepare("INSERT INTO {$lp}settings(name,value) VALUES('sc_last_summary',?) ON DUPLICATE KEY UPDATE value=VALUES(value)");
    if($stmt){ $stmt->bind_param('s',$json); $stmt->execute(); $stmt->close(); }
    $steps[]='داده‌های سرچ کنسول ذخیره شد';
    $ldb->close();
  }

  private static function getSetting($db,$prefix,$name){
    $stmt=$db->prepare("SELECT value FROM {$prefix}settings WHERE name=?");
    if(!$stmt) return null;
    $stmt->bind_param('s',$name);
    $stmt->execute();
    $res=$stmt->get_result();
    $row=$res?$res->fetch_assoc():null;
    $stmt->close();
    return $row?$row['value']:null;
  }
}
?>
