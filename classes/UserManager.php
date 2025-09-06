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
        $res = $this->db->query("SELECT id, username, role, status, DATE_FORMAT(created_at,'%Y-%m-%d %H:%i') as created_at FROM {$this->prefix}users ORDER BY id DESC");
        if($res){ while($r = $res->fetch_assoc()){ $rows[] = $r; } }
        return $rows;
    }
    public function get($id){
        $stmt = $this->db->prepare("SELECT id, username, full_name, phone_number, role, status, permissions FROM {$this->prefix}users WHERE id=?");
        $stmt->bind_param('i',$id);
        $stmt->execute();
        $res = $stmt->get_result();
        $data = $res ? $res->fetch_assoc() : null;
        $stmt->close();
        return $data;
    }
    public function create($data){
        $hash = password_hash($data['password'], PASSWORD_BCRYPT);
        $stmt = $this->db->prepare("INSERT INTO {$this->prefix}users(username,password_hash,full_name,phone_number,role,status,permissions,created_at,updated_at) VALUES (?,?,?,?,?,?,?,NOW(),NOW())");
        $stmt->bind_param('sssssss', $data['username'],$hash,$data['full_name'],$data['phone_number'],$data['role'],$data['status'],$data['permissions']);
        $ok = $stmt->execute();
        $stmt->close();
        return $ok;
    }
    public function update($id,$data){
        if(!empty($data['password'])){
            $hash = password_hash($data['password'], PASSWORD_BCRYPT);
            $stmt = $this->db->prepare("UPDATE {$this->prefix}users SET username=?,password_hash=?,full_name=?,phone_number=?,role=?,status=?,permissions=?,updated_at=NOW() WHERE id=?");
            $stmt->bind_param('sssssssi',$data['username'],$hash,$data['full_name'],$data['phone_number'],$data['role'],$data['status'],$data['permissions'],$id);
        }else{
            $stmt = $this->db->prepare("UPDATE {$this->prefix}users SET username=?,full_name=?,phone_number=?,role=?,status=?,permissions=?,updated_at=NOW() WHERE id=?");
            $stmt->bind_param('ssssssi',$data['username'],$data['full_name'],$data['phone_number'],$data['role'],$data['status'],$data['permissions'],$id);
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
