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
        $steps[] = 'درخواست به Google API هنوز پیاده‌سازی نشده است';
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
    $prefix = $_SESSION['db']['prefix'];
    $res = $db->query("SELECT ID,post_title,post_content FROM {$prefix}posts WHERE post_type='product' AND post_status='publish'");
    $count = 0;
    $ldb = connect_local();
    if($res){
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
}
?>
