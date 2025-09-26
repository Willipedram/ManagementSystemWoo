<?php
class SEOAnalyzer {
    public static function analyze($title, $meta, $content, $keyword = '', $siteUrl = ''){
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
        } elseif($h1Count > 1){
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
            $href = trim($a->getAttribute('href'));
            if(self::isInternalLink($href,$siteUrl)) $internal++;
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
            $details[] = ['code'=>'B4','status'=>'warn','message'=>'جملات طولانی هستند؛ هر جمله را به حداکثر ۲۰ کلمه محدود کنید و با ویرگول یا نقطه آن‌ها را به چند جمله کوتاه تقسیم کنید.'];
        }
        $score = min(100,$score);
        return ['score'=>$score,'details'=>$details];
    }
    public static function suggestTitle($name){
        return "خرید {$name} با بهترین قیمت";
    }
    public static function suggestMeta($name){
        $base = "خرید {$name} از صفیر زمان با ضمانت اصالت، ارسال سریع و پشتیبانی تخصصی؛ همین امروز سفارش دهید و از تجربه خرید مطمئن و خدمات پس از فروش بهره‌مند شوید.";
        $len = mb_strlen($base);
        if($len > 170){
            $base = "خرید {$name} از صفیر زمان با ضمانت اصالت، ارسال سریع و پشتیبانی تخصصی؛ همین امروز سفارش دهید.";
        }
        if(mb_strlen($base) < 110){
            $base .= ' خریدی مطمئن با امکان بازگشت کالا و مشاوره تخصصی را تجربه کنید.';
        }
        return $base;
    }

    private static function isInternalLink($href,$siteUrl){
        if($href === '' || $href === null){
            return false;
        }
        if(stripos($href,'javascript:') === 0 || stripos($href,'mailto:') === 0 || stripos($href,'tel:') === 0){
            return false;
        }
        if($href[0] === '#' || $href[0] === '/' || strpos($href,'?') === 0){
            return true;
        }
        if(strpos($href,'//') === 0){
            $href = 'https:'.$href;
        }
        if(stripos($href,'http') !== 0){
            return true;
        }
        if(!$siteUrl){
            return false;
        }
        $baseHost = self::extractHost($siteUrl);
        if(!$baseHost){
            return false;
        }
        $linkHost = self::extractHost($href);
        return $linkHost && $linkHost === $baseHost;
    }

    private static function extractHost($url){
        if(!$url){
            return '';
        }
        if(stripos($url,'://') === false){
            $url = 'https://'.$url;
        }
        $host = parse_url($url,PHP_URL_HOST);
        if(!$host){
            return '';
        }
        return preg_replace('/^www\./i','',$host);
    }
}
?>
