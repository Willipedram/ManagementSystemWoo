<?php
class ChatGPTManager{
    private string $configFile;
    private string $logFile;
    private string $cipherKey;

    public function __construct(?string $configFile=null, ?string $logFile=null){
        $base = dirname(__DIR__);
        $this->configFile = $configFile ?: $base.'/chatgpt_config.json';
        $this->logFile = $logFile ?: $base.'/chatgpt.log';
        $this->cipherKey = hash('sha256', 'msw_chatgpt_secret');
        if(!file_exists($this->configFile)){
            file_put_contents($this->configFile, json_encode([]));
        }
    }
    private function log(string $msg): void{
        file_put_contents($this->logFile,'['.date('c')."] $msg\n", FILE_APPEND);
    }
    private function encrypt(string $plain): string{
        $iv = random_bytes(16);
        $cipher = openssl_encrypt($plain, 'AES-256-CBC', $this->cipherKey, 0, $iv);
        return base64_encode($iv.$cipher);
    }
    private function decrypt(string $enc): string{
        $data = base64_decode($enc);
        if(!$data || strlen($data) < 17) return '';
        $iv = substr($data,0,16);
        $cipher = substr($data,16);
        $plain = openssl_decrypt($cipher, 'AES-256-CBC', $this->cipherKey, 0, $iv);
        return $plain===false?'' : $plain;
    }
    public function getConfig(): array{
        $data = json_decode(@file_get_contents($this->configFile), true) ?: [];
        if(isset($data['api_key'])){
            $data['api_key'] = $this->decrypt($data['api_key']);
        }
        return $data;
    }
    public function saveConfig(array $cfg): bool{
        if(isset($cfg['api_key'])){
            $cfg['api_key'] = $this->encrypt($cfg['api_key']);
        }
        file_put_contents($this->configFile, json_encode($cfg, JSON_PRETTY_PRINT|JSON_UNESCAPED_UNICODE));
        $this->log('settings saved');
        return true;
    }
    public function testConnection(): array{
        $cfg = $this->getConfig();
        $apiKey = $cfg['api_key'] ?? '';
        $model = $cfg['model'] ?? 'gpt-3.5-turbo';
        if(!$apiKey){
            return ['success'=>false,'message'=>'API Key تنظیم نشده است'];
        }
        $ch = curl_init('https://api.openai.com/v1/chat/completions');
        $payload = json_encode([
            'model'=>$model,
            'messages'=>[['role'=>'system','content'=>'ping']],
            'max_tokens'=>1
        ]);
        curl_setopt_array($ch,[
            CURLOPT_RETURNTRANSFER=>true,
            CURLOPT_POST=>true,
            CURLOPT_HTTPHEADER=>[
                'Content-Type: application/json',
                'Authorization: Bearer '.$apiKey
            ],
            CURLOPT_POSTFIELDS=>$payload
        ]);
        $body = curl_exec($ch);
        $err = curl_error($ch);
        $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);
        if($err){
            $this->log('testConnection error '.$err);
            return ['success'=>false,'message'=>$err];
        }
        if($code>=200 && $code<300){
            $this->log('testConnection success');
            return ['success'=>true,'message'=>'اتصال با موفقیت برقرار شد. ChatGPT آماده تعامل است.'];
        }
        $this->log('testConnection failed HTTP '.$code.' '.$body);
        return ['success'=>false,'message'=>'خطا در اتصال. لطفاً API Key و تنظیمات خود را بررسی کنید.'];
    }
    public function chat(array $messages): array{
        $cfg = $this->getConfig();
        $apiKey = $cfg['api_key'] ?? '';
        $model = $cfg['model'] ?? 'gpt-3.5-turbo';
        $temp = isset($cfg['temperature']) ? floatval($cfg['temperature']) : 1.0;
        $max = isset($cfg['max_tokens']) ? intval($cfg['max_tokens']) : 256;
        if(!$apiKey){ throw new Exception('API Key تنظیم نشده است'); }
        $ch = curl_init('https://api.openai.com/v1/chat/completions');
        $payload = json_encode([
            'model'=>$model,
            'messages'=>$messages,
            'temperature'=>$temp,
            'max_tokens'=>$max
        ]);
        curl_setopt_array($ch,[
            CURLOPT_RETURNTRANSFER=>true,
            CURLOPT_POST=>true,
            CURLOPT_HTTPHEADER=>[
                'Content-Type: application/json',
                'Authorization: Bearer '.$apiKey
            ],
            CURLOPT_POSTFIELDS=>$payload
        ]);
        $body = curl_exec($ch);
        $err = curl_error($ch);
        $code = curl_getinfo($ch,CURLINFO_HTTP_CODE);
        curl_close($ch);
        $this->log('chat status '.($err?$err:$code));
        if($err){ throw new Exception($err); }
        $data = json_decode($body,true);
        return $data ?: [];
    }
}
?>
