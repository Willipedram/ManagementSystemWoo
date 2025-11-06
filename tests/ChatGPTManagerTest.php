<?php

use PHPUnit\Framework\TestCase;

final class ChatGPTManagerTest extends TestCase
{
    private string $configFile;
    private string $logFile;

    protected function setUp(): void
    {
        $this->configFile = tempnam(sys_get_temp_dir(), 'cfg');
        $this->logFile = tempnam(sys_get_temp_dir(), 'log');
        // Ensure files start empty
        file_put_contents($this->configFile, json_encode([]));
        file_put_contents($this->logFile, '');
    }

    protected function tearDown(): void
    {
        @unlink($this->configFile);
        @unlink($this->logFile);
    }

    public function testSaveAndLoadConfigEncryptsApiKey(): void
    {
        $manager = new ChatGPTManager($this->configFile, $this->logFile);

        $manager->saveConfig([
            'api_key' => 'test-secret-key',
            'model' => 'gpt-4o-mini',
            'temperature' => 0.8,
        ]);

        $raw = json_decode(file_get_contents($this->configFile), true);
        $this->assertArrayHasKey('api_key', $raw);
        $this->assertNotSame('test-secret-key', $raw['api_key'], 'API key should be stored encrypted.');

        $loaded = $manager->getConfig();
        $this->assertSame('test-secret-key', $loaded['api_key']);
        $this->assertSame('gpt-4o-mini', $loaded['model']);
        $this->assertSame(0.8, $loaded['temperature']);
    }

    public function testTestConnectionFailsWhenApiKeyMissing(): void
    {
        $manager = new ChatGPTManager($this->configFile, $this->logFile);

        $result = $manager->testConnection();

        $this->assertFalse($result['success']);
        $this->assertStringContainsString('API Key', $result['message']);
        $lastStep = end($result['steps']);
        $this->assertSame('ERROR', $lastStep['flag']);
    }

    public function testChatThrowsExceptionWithoutApiKey(): void
    {
        $manager = new ChatGPTManager($this->configFile, $this->logFile);

        $this->expectExceptionMessage('API Key تنظیم نشده است');
        $manager->chat([
            ['role' => 'system', 'content' => 'test'],
        ]);
    }
}
