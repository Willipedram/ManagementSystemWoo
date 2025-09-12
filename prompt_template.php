<?php
function build_prompt(array $vars){
    $template = file_get_contents(__DIR__.'/prompt_template.txt');
    list($db,$prefix) = connect_local_prompt();
    if($db){
        $il='';
        $res=$db->query("SELECT title,url FROM {$prefix}internal_links");
        if($res){ while($r=$res->fetch_assoc()){ $il.="<li><a href=\"{$r['url']}\">{$r['title']}</a></li>"; } $res->close(); }
        $vars['{INTERNAL_LINKS}']=$il;
        $el='';
        $res=$db->query("SELECT title,url FROM {$prefix}external_links");
        if($res){ while($r=$res->fetch_assoc()){ $el.="<li><a href=\"{$r['url']}\" rel=\"nofollow noopener\" target=\"_blank\">{$r['title']}</a></li>"; } $res->close(); }
        $vars['{EXTERNAL_LINKS}']=$el;
        $db->close();
    } else {
        $vars['{INTERNAL_LINKS}']='';
        $vars['{EXTERNAL_LINKS}']='';
    }
    return strtr($template,$vars);
}

function connect_local_prompt(){
    $cfgPath=__DIR__.'/local_config.secure';
    if(!file_exists($cfgPath)) return array(null,null);
    $cfg=json_decode(file_get_contents($cfgPath),true);
    if(!$cfg) return array(null,null);
    $db=@new mysqli($cfg['host'],$cfg['user'],$cfg['pass'],$cfg['name']);
    if($db->connect_errno) return array(null,null);
    $db->set_charset('utf8mb4');
    return array($db,$cfg['prefix']);
}
?>