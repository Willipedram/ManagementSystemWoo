<?php
class UserManager {
    private $db;
    private $prefix;
    public function __construct($db,$prefix){
        $this->db = $db;
        $this->prefix = $prefix;
    }
    public function all(){
        $rows = [];
        $res = $this->db->query("SELECT u.id, u.username, r.name AS role, u.status, DATE_FORMAT(u.created_at,'%Y-%m-%d %H:%i') as created_at FROM {$this->prefix}users u LEFT JOIN {$this->prefix}roles r ON u.role_id=r.id ORDER BY u.id DESC");
        if($res){ while($r = $res->fetch_assoc()){ $rows[] = $r; } }
        return $rows;
    }
    public function get($id){
        $stmt = $this->db->prepare("SELECT id, username, full_name, phone_number, role_id, status FROM {$this->prefix}users WHERE id=?");
        $stmt->bind_param('i',$id);
        $stmt->execute();
        $res = $stmt->get_result();
        $data = $res ? $res->fetch_assoc() : null;
        $stmt->close();
        return $data;
    }
    public function create($data){
        $hash = password_hash($data['password'], PASSWORD_BCRYPT);
        $stmt = $this->db->prepare("INSERT INTO {$this->prefix}users(username,password_hash,full_name,phone_number,role_id,status,created_at,updated_at) VALUES (?,?,?,?,?,?,NOW(),NOW())");
        $stmt->bind_param('ssssis', $data['username'],$hash,$data['full_name'],$data['phone_number'],$data['role_id'],$data['status']);
        $ok = $stmt->execute();
        $stmt->close();
        return $ok;
    }
    public function update($id,$data){
        if(!empty($data['password'])){
            $hash = password_hash($data['password'], PASSWORD_BCRYPT);
            $stmt = $this->db->prepare("UPDATE {$this->prefix}users SET username=?,password_hash=?,full_name=?,phone_number=?,role_id=?,status=?,updated_at=NOW() WHERE id=?");
            $stmt->bind_param('ssssisi',$data['username'],$hash,$data['full_name'],$data['phone_number'],$data['role_id'],$data['status'],$id);
        }else{
            $stmt = $this->db->prepare("UPDATE {$this->prefix}users SET username=?,full_name=?,phone_number=?,role_id=?,status=?,updated_at=NOW() WHERE id=?");
            $stmt->bind_param('sssisi',$data['username'],$data['full_name'],$data['phone_number'],$data['role_id'],$data['status'],$id);
        }
        $ok = $stmt->execute();
        $stmt->close();
        return $ok;
    }
    public function delete($id){
        $stmt = $this->db->prepare("DELETE FROM {$this->prefix}users WHERE id=?");
        $stmt->bind_param('i',$id);
        $stmt->execute();
        $aff = $stmt->affected_rows;
        $stmt->close();
        return $aff>0;
    }
}
?>
