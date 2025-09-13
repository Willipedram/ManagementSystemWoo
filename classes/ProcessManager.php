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
    // ensure table exists before any API call so manual runs have structure
    $check = $ldb->query("SHOW TABLES LIKE '{$lp}search_console_daily'");
    if(!$check || $check->num_rows === 0){
      $ldb->query("CREATE TABLE {$lp}search_console_daily (
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
        UNIQUE KEY uniq (date,site_url,page(191),query(191),device,country,search_appearance)
      ) CHARACTER SET utf8mb4");
    }
    if($check){ $check->close(); }

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
    // request detailed search analytics for last 7 days grouped by required dimensions
    // first request: max 5 dimensions (date,page,query,device,country)
    $payload=json_encode(array(
      'startDate'=>$from,
      'endDate'=>$to,
      'dimensions'=>array('date','page','query','device','country'),
      'rowLimit'=>25000
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
    $rows1=$resp['rows']??array();

    // second request for search appearance (date,page,query,device,searchAppearance)
    $payload=json_encode(array(
      'startDate'=>$from,
      'endDate'=>$to,
      'dimensions'=>array('date','page','query','device','searchAppearance'),
      'rowLimit'=>25000
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
    $rows2=array();
    if($resp===false || $http!=200){
      $steps[]='searchAppearance fetch failed';
    }else{
      $resp=json_decode($resp,true);
      $rows2=$resp['rows']??array();
    }

    $stmt=$ldb->prepare("INSERT INTO {$lp}search_console_daily
      (date,site_url,page,query,device,country,clicks,impressions,ctr,position,search_appearance,
       sessions,bounce_rate,avg_session_duration,conversions,lcp,cls,fid,ttfb,referring_domains,anchors,trends_interest)
      VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)
      ON DUPLICATE KEY UPDATE clicks=VALUES(clicks),impressions=VALUES(impressions),ctr=VALUES(ctr),position=VALUES(position),
        sessions=VALUES(sessions),bounce_rate=VALUES(bounce_rate),avg_session_duration=VALUES(avg_session_duration),
        conversions=VALUES(conversions),lcp=VALUES(lcp),cls=VALUES(cls),fid=VALUES(fid),ttfb=VALUES(ttfb),
        referring_domains=VALUES(referring_domains),anchors=VALUES(anchors),trends_interest=VALUES(trends_interest)");

    $data=array();
    $index=array();
    $sumClicks=0;$sumImpr=0;$sumPosWeighted=0;
    foreach($rows1 as $r){
      $keys=$r['keys'];
      $date=$keys[0]??''; $page=$keys[1]??''; $query=$keys[2]??''; $device=$keys[3]??''; $country=$keys[4]??'';
      $clicks=intval($r['clicks']??0); $impr=intval($r['impressions']??0); $pos=floatval($r['position']??0);
      $ctr=$impr>0?round($clicks/$impr*100,2):0;

      $key="$date|$page|$query|$device|$country";
      $base="$date|$page|$query|$device";
      $data[$key]=array(
        'date'=>$date,'page'=>$page,'query'=>$query,'device'=>$device,'country'=>$country,
        'clicks'=>$clicks,'impressions'=>$impr,'ctr'=>$ctr,'position'=>$pos,'appearance'=>''
      );
      $index[$base][]=$key;

      $sumClicks+=$clicks; $sumImpr+=$impr; $sumPosWeighted+=$pos*$impr;
    }

    foreach($rows2 as $r){
      $keys=$r['keys'];
      $date=$keys[0]??''; $page=$keys[1]??''; $query=$keys[2]??''; $device=$keys[3]??''; $appearance=$keys[4]??'';
      $base="$date|$page|$query|$device";
      if(isset($index[$base])){
        foreach($index[$base] as $k){ $data[$k]['appearance']=$appearance; }
      }
    }

    foreach($data as $row){
      // placeholders for external data; actual API integrations to be implemented
      $ga=self::fetchAnalyticsMetrics($row['page'],$row['date']);
      $ps=self::fetchPageSpeedMetrics($row['page']);
      $bl=self::fetchBacklinkData($row['page']);
      $tr=self::fetchTrendsInterest($row['query']);

      $sess=$ga['sessions'];
      $br=$ga['bounce_rate'];
      $dur=$ga['avg_session_duration'];
      $conv=$ga['conversions'];
      $lcp=$ps['lcp'];
      $cls=$ps['cls'];
      $fid=$ps['fid'];
      $ttfb=$ps['ttfb'];
      $refDom=$bl['referring_domains'];
      $anchors=$bl['anchors'];

      $stmt->bind_param('ssssssiiddsidiiddddisi',
        $row['date'],$site,$row['page'],$row['query'],$row['device'],$row['country'],$row['clicks'],$row['impressions'],$row['ctr'],$row['position'],$row['appearance'],
        $sess,$br,$dur,$conv,$lcp,$cls,$fid,$ttfb,$refDom,$anchors,$tr);
      $stmt->execute();
    }
    if($stmt){ $stmt->close(); }

    $avgCtr=$sumImpr>0?round($sumClicks/$sumImpr*100,2):0;
    $avgPos=$sumImpr>0?round($sumPosWeighted/$sumImpr,2):0;
    $summary=array('start'=>$from,'end'=>$to,'clicks'=>$sumClicks,'impressions'=>$sumImpr,'ctr'=>$avgCtr,'position'=>$avgPos);
    $json=json_encode($summary,JSON_UNESCAPED_UNICODE);
    $stmt=$ldb->prepare("INSERT INTO {$lp}settings(name,value) VALUES('sc_last_summary',?) ON DUPLICATE KEY UPDATE value=VALUES(value)");
    if($stmt){ $stmt->bind_param('s',$json); $stmt->execute(); $stmt->close(); }
    $steps[]='داده‌های سرچ کنسول ذخیره شد';
    $ldb->close();
  }

  // placeholder external data fetchers
  private static function fetchAnalyticsMetrics($page,$date){
    // TODO: integrate Google Analytics API
    return array('sessions'=>null,'bounce_rate'=>null,'avg_session_duration'=>null,'conversions'=>null);
  }

  private static function fetchPageSpeedMetrics($page){
    // TODO: integrate PageSpeed Insights or CrUX API
    return array('lcp'=>null,'cls'=>null,'fid'=>null,'ttfb'=>null);
  }

  private static function fetchBacklinkData($page){
    // TODO: integrate backlink provider API
    return array('referring_domains'=>null,'anchors'=>null);
  }

  private static function fetchTrendsInterest($query){
    // TODO: integrate Google Trends API
    return null;
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
