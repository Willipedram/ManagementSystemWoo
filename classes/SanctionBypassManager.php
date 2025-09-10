<?php
class SanctionBypassManager{
    private string $configFile;
    private string $logFile;
    public function __construct(?string $configFile = null, ?string $logFile = null){
        $base = dirname(__DIR__);
        $this->configFile = $configFile ? $configFile : $base.'/dns_config.json';
        $this->logFile = $logFile ? $logFile : $base.'/sanction.log';
        if(!file_exists($this->configFile)){
            file_put_contents($this->configFile,json_encode([]));
        }
    }
    private function log(string $msg): void{
        file_put_contents($this->logFile, '['.date('c')."] $msg\n", FILE_APPEND);
    }
    public function setDnsServers(array $dns): bool{
        $dns = array_filter(array_map('trim',$dns));
        file_put_contents($this->configFile, json_encode($dns, JSON_PRETTY_PRINT));
        $this->log('DNS servers updated: '.implode(',', $dns));
        return true;
    }
    public function getDnsServers(): array{
        $data = file_get_contents($this->configFile);
        $arr = json_decode($data, true);
        return is_array($arr) ? $arr : [];
    }
    public function callApi(string $url, array $options = []): array{
        $ch = curl_init($url);
        $dns = $this->getDnsServers();
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        if($dns){
            curl_setopt($ch, CURLOPT_DNS_SERVERS, implode(',', $dns));
        }
        foreach($options as $k=>$v){
            curl_setopt($ch, $k, $v);
        }
        $body = curl_exec($ch);
        $err = curl_error($ch);
        $info = curl_getinfo($ch);
        curl_close($ch);
        $this->log('callApi '.$url.' status '.($err?$err:$info['http_code']));
        if($err){
            throw new Exception($err);
        }
        return ['body'=>$body,'info'=>$info];
    }
    public function testDnsLeak(string $domain = 'api.openai.com'): array{
        $dns = $this->getDnsServers();
        $ch = curl_init('https://'.$domain);
        curl_setopt_array($ch,[
            CURLOPT_RETURNTRANSFER=>true,
            CURLOPT_TIMEOUT=>15
        ]);
        if($dns){
            curl_setopt($ch, CURLOPT_DNS_SERVERS, implode(',', $dns));
        }
        curl_exec($ch);
        $err = curl_error($ch);
        $ip = curl_getinfo($ch, CURLINFO_PRIMARY_IP);
        curl_close($ch);
        if($err){
            $this->log('testDnsLeak error '.$err);
            return ['success'=>false,'message'=>$err];
        }
        $geo = $this->callApi('https://ipinfo.io/'.urlencode($ip).'/json');
        $data = json_decode($geo['body'], true);
        $country = $data['country'] ?? 'Unknown';
        $warning = $country==='IR' ? 'هشدار: آی‌پی ایرانی است' : '';
        $res = ['success'=>true,'ip'=>$ip,'country'=>$country,'warning'=>$warning];
        $this->log('testDnsLeak '.$domain.' -> '.$ip.' ('.$country.')');
        return $res;
    }
}
?>
