<?php
class SEOAnalyzer {
    public static function analyze($title, $meta, $content, $keyword = ''){
        $details = [];
        $score = 0;
        $keyword = trim(mb_strtolower($keyword));
        $titleLen = mb_strlen($title);
        if($titleLen >= 45 && $titleLen <= 70){
            $score += 10;
            $details[] = ['code'=>'A1','status'=>'ok','message'=>'طول عنوان مناسب است'];
        } else {
            $details[] = ['code'=>'A1','status'=>'error','message'=>"طول عنوان {$titleLen} کاراکتر است؛ حد مناسب 45 تا 70 کاراکتر می‌باشد"];
        }
        if($keyword){
            if(mb_stripos($title,$keyword)!==false){
                $score += 5;
            } else {
                $details[] = ['code'=>'A1','status'=>'warn','message'=>'کلمهٔ کلیدی در عنوان دیده نشد'];
            }
        } else {
            $details[] = ['code'=>'A3','status'=>'warn','message'=>'کلمهٔ کلیدی مشخص نشده'];
        }
        $metaLen = mb_strlen($meta);
        if($metaLen >= 110 && $metaLen <= 170){
            $score += 10;
        } elseif($metaLen == 0){
            $details[] = ['code'=>'A2','status'=>'error','message'=>'توضیحات متا خالی است'];
        } else {
            $details[] = ['code'=>'A2','status'=>'warn','message'=>"طول توضیحات متا {$metaLen} کاراکتر است؛ بین 110 تا 170 کاراکتر بنویسید"];
        }
        if($keyword && mb_stripos($meta,$keyword)!==false){
            $score += 5;
        }
        $text = trim(strip_tags($content));
        $wordCount = $text ? count(preg_split('/\s+/u',$text)) : 0;
        if($wordCount >= 300){
            $score += 10;
        } else {
            $details[] = ['code'=>'B3','status'=>'warn','message'=>"توضیحات تنها {$wordCount} کلمه دارد؛ حداقل 300 کلمه پیشنهاد می‌شود"];
        }
        $dom = new DOMDocument();
        @$dom->loadHTML('<meta charset="utf-8">'.$content);
        $h1s = $dom->getElementsByTagName('h1');
        $h1Count = $h1s->length;
        if($h1Count == 1){
            $score += 10;
        } elseif($h1Count == 0){
            $details[] = ['code'=>'B1','status'=>'error','message'=>'هیچ تگ H1 یافت نشد'];
        } else {
            $details[] = ['code'=>'B1','status'=>'warn','message'=>"{$h1Count} تگ H1 یافت شد؛ تنها یک مورد نیاز است"];
        }
        $imgs = $dom->getElementsByTagName('img');
        $missingAlt = 0;
        foreach($imgs as $img){ if(!$img->getAttribute('alt')) $missingAlt++; }
        if($imgs->length > 0 && $missingAlt == 0){
            $score += 10;
        } elseif($missingAlt > 0){
            $details[] = ['code'=>'C1','status'=>'warn','message'=>"{$missingAlt} تصویر بدون متن جایگزین است"];
        }
        $links = $dom->getElementsByTagName('a');
        $internal = 0;
        foreach($links as $a){
            $href = $a->getAttribute('href');
            if($href && strpos($href,'http') !== 0) $internal++;
        }
        if($internal > 0){
            $score += 10;
        } else {
            $details[] = ['code'=>'D1','status'=>'warn','message'=>'هیچ لینک داخلی در متن وجود ندارد'];
        }
        $sentences = preg_split('/[.!?؟]+/u',$text,-1,PREG_SPLIT_NO_EMPTY);
        $avg = count($sentences) ? $wordCount / count($sentences) : $wordCount;
        if($avg <= 20){
            $score += 10;
        } else {
            $details[] = ['code'=>'B4','status'=>'warn','message'=>'جملات طولانی هستند؛ بهتر است کوتاه‌تر نوشته شوند'];
        }
        $score = min(100,$score);
        return ['score'=>$score,'details'=>$details];
    }
    public static function suggestTitle($name){
        return "خرید {$name} با بهترین قیمت";
    }
    public static function suggestMeta($name){
        return "خرید آنلاین {$name} با ضمانت اصالت و ارسال سریع";
    }
}
?>
