<?php

use PHPUnit\Framework\TestCase;

final class SEOAnalyzerTest extends TestCase
{
    public function testAnalyzeReturnsHighScoreForOptimizedContent(): void
    {
        $title = 'راهنمای کامل بهینه‌سازی محصول نمونه برای سئو فروشگاه';
        $keyword = 'محصول نمونه';
        $meta = 'راهنمای کامل خرید محصول نمونه با بررسی ویژگی‌ها، مزایا و نکات مهم برای بهینه‌سازی سئو و افزایش نرخ تبدیل فروشگاه اینترنتی شما.';
        $content = '<h1>عنوان محصول نمونه</h1>';
        $content .= '<p>' . str_repeat('این یک جمله کوتاه است. ', 80) . '</p>';
        $content .= '<p><img src="product.jpg" alt="محصول نمونه"></p>';
        $content .= '<p>برای اطلاعات بیشتر <a href="/product/sample">اینجا کلیک کنید</a>.</p>';

        $result = SEOAnalyzer::analyze($title, $meta, $content, $keyword, 'https://example.com');

        $this->assertSame(80, $result['score']);
        $this->assertEmpty($result['details']);
    }

    public function testAnalyzeReturnsWarningsForPoorContent(): void
    {
        $title = 'محصول';
        $meta = '';
        $longSentence = 'این جمله بسیار طولانی است زیرا شامل تعداد زیادی کلمه می‌شود تا بررسی کند که تحلیلگر هشدار متوسط طول جمله را فعال می‌کند و همچنان ادامه دارد تا از حد مجاز فراتر برود و شرایط آزمون مهیا شود.';
        $content = '<h1>اولین عنوان</h1><h1>دومین عنوان</h1><p>' . $longSentence . '</p><img src="no-alt.jpg">';

        $result = SEOAnalyzer::analyze($title, $meta, $content, '');

        $codes = array_column($result['details'], 'code');

        $this->assertContains('A1', $codes, 'Title length warning expected.');
        $this->assertContains('A2', $codes, 'Missing meta description warning expected.');
        $this->assertContains('A3', $codes, 'Missing keyword warning expected.');
        $this->assertContains('B3', $codes, 'Short content warning expected.');
        $this->assertContains('B1', $codes, 'Multiple H1 warning expected.');
        $this->assertContains('C1', $codes, 'Missing alt text warning expected.');
        $this->assertContains('D1', $codes, 'Missing internal link warning expected.');
        $this->assertContains('B4', $codes, 'Long sentence warning expected.');
    }

    public function testSuggestHelpersIncludeProductName(): void
    {
        $name = 'ساعت هوشمند';

        $this->assertStringContainsString($name, SEOAnalyzer::suggestTitle($name));
        $meta = SEOAnalyzer::suggestMeta($name);

        $this->assertStringContainsString($name, $meta);
        $length = mb_strlen($meta);
        $this->assertGreaterThanOrEqual(110, $length);
        $this->assertLessThanOrEqual(170, $length);
    }
}
