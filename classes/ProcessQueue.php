<?php
class ProcessQueue {
  private $db;
  private $prefix;

  public function __construct(){
    $cfg = $this->loadConfig();
    if(!$cfg) throw new Exception('local config missing');
    $this->prefix = $cfg['prefix'];
    $this->db = new mysqli($cfg['host'],$cfg['user'],$cfg['pass'],$cfg['name']);
    if($this->db->connect_errno) throw new Exception($this->db->connect_error);
    $this->db->set_charset('utf8mb4');
    $this->db->query("CREATE TABLE IF NOT EXISTS {$this->prefix}process_queue (
      id INT AUTO_INCREMENT PRIMARY KEY,
      process_name VARCHAR(255),
      status ENUM('pending','running','completed','failed') DEFAULT 'pending',
      started_at DATETIME NULL,
      finished_at DATETIME NULL,
      result TEXT NULL
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
  }

  private function loadConfig(){
    $path = __DIR__.'/../local_config.secure';
    if(!file_exists($path)) return false;
    $cfg = json_decode(file_get_contents($path),true);
    return $cfg ?: false;
  }

  public function enqueue($name){
    $stmt = $this->db->prepare("INSERT INTO {$this->prefix}process_queue(process_name,status) VALUES (?, 'pending')");
    $stmt->bind_param('s',$name);
    $stmt->execute();
    $id = $stmt->insert_id;
    $stmt->close();
    return $id;
  }

  public function listAll(){
    $rows=[];
    $res = $this->db->query("SELECT id,process_name,status,started_at,finished_at,result FROM {$this->prefix}process_queue ORDER BY id DESC LIMIT 100");
    if($res){ while($r=$res->fetch_assoc()){ $rows[]=$r; } $res->close(); }
    return $rows;
  }

  public function claimPending(){
    $res = $this->db->query("SELECT id,process_name FROM {$this->prefix}process_queue WHERE status='pending' ORDER BY id ASC LIMIT 1");
    $row = $res ? $res->fetch_assoc() : null;
    if($row){
      $id = intval($row['id']);
      $ok = $this->db->query("UPDATE {$this->prefix}process_queue SET status='running', started_at=NOW() WHERE id=$id AND status='pending'");
      if($ok && $this->db->affected_rows>0) return $row;
    }
    return null;
  }

  public function finish($id,$status,$result=''){
    $stmt=$this->db->prepare("UPDATE {$this->prefix}process_queue SET status=?, finished_at=NOW(), result=? WHERE id=?");
    $stmt->bind_param('ssi',$status,$result,$id);
    $stmt->execute();
    $stmt->close();
  }

  public function __destruct(){ if($this->db) $this->db->close(); }
}
?>
