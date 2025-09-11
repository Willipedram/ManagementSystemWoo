<?php
class SanctionBypassManager{
    private string $configFile;
    private string $logFile;
    private array $steps = [];

    public function __construct(?string $configFile = null, ?string $logFile = null){
        $base = dirname(__DIR__);
        $this->configFile = $configFile ? $configFile : $base.'/dns_config.json';
        $this->logFile = $logFile ? $logFile : $base.'/sanction.log';
        if(!file_exists($this->configFile)){
            file_put_contents($this->configFile,json_encode([]));
        }
    }

    private function log(string $flag, string $msg): void{
        file_put_contents($this->logFile, '['.date('c')."] [$flag] $msg\n", FILE_APPEND);
    }

    private function step(string $flag, string $msg): void{
        $this->steps[] = ['flag'=>$flag,'message'=>$msg];
        $this->log($flag,$msg);
    }

    private function resetSteps(): void{ $this->steps = []; }

    public function getSteps(): array{ return $this->steps; }

    public function setDnsServers(array $dns): bool{
        $this->resetSteps();
        $dns = array_filter(array_map('trim',$dns));
        file_put_contents($this->configFile, json_encode($dns, JSON_PRETTY_PRINT));
        $this->step('DNS_UPDATE','DNS servers updated: '.implode(',', $dns));
        return true;
    }

    public function getDnsServers(): array{
        $data = file_get_contents($this->configFile);
        $arr = json_decode($data, true);
        return is_array($arr) ? $arr : [];
    }

    public function callApi(string $url, array $options = []): array{
        $this->step('API_REQUEST','Requesting '.$url);
        $ch = curl_init($url);
        $dns = $this->getDnsServers();
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        if($dns){
            curl_setopt($ch, CURLOPT_DNS_SERVERS, implode(',', $dns));
            $this->step('API_DNS',implode(',', $dns));
        }
        foreach($options as $k=>$v){
            curl_setopt($ch, $k, $v);
        }
        $body = curl_exec($ch);
        $err = curl_error($ch);
        $info = curl_getinfo($ch);
        curl_close($ch);
        $this->step('API_RESPONSE', $err ? $err : (string)$info['http_code']);
        if($err){
            throw new Exception($err);
        }
        return ['body'=>$body,'info'=>$info];
    }

    public function testDnsLeak(string $domain = 'api.openai.com'): array{
        $this->resetSteps();
        $this->step('TEST_INIT','domain '.$domain);
        $dns = $this->getDnsServers();
        $ch = curl_init('https://'.$domain);
        curl_setopt_array($ch,[
            CURLOPT_RETURNTRANSFER=>true,
            CURLOPT_TIMEOUT=>15
        ]);
        if($dns){
            curl_setopt($ch, CURLOPT_DNS_SERVERS, implode(',', $dns));
            $this->step('TEST_DNS',implode(',', $dns));
        }
        curl_exec($ch);
        $err = curl_error($ch);
        $ip = curl_getinfo($ch, CURLINFO_PRIMARY_IP);
        curl_close($ch);
        if($err){
            $this->step('TEST_ERROR',$err);
            return ['success'=>false,'message'=>$err,'steps'=>$this->getSteps()];
        }
        $this->step('TEST_IP',$ip);
        $geo = $this->callApi('https://ipinfo.io/'.urlencode($ip).'/json');
        $data = json_decode($geo['body'], true);
        $country = $data['country'] ?? 'Unknown';
        $warning = $country==='IR' ? 'هشدار: آی‌پی ایرانی است' : '';
        $this->step('TEST_GEO',$country);
        $res = ['success'=>true,'ip'=>$ip,'country'=>$country,'warning'=>$warning,'steps'=>$this->getSteps()];
        return $res;
    }
}
?>
