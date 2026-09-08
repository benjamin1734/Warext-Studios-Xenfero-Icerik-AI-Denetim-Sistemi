<?php

require_once __DIR__ . '/../upload/src/addons/Warext/AIContentInspector/Provider/ProviderInterface.php';
require_once __DIR__ . '/../upload/src/addons/Warext/AIContentInspector/Provider/OpenRouterProvider.php';

use Warext\AIContentInspector\Provider\OpenRouterProvider;

class TestOpenRouterProvider extends OpenRouterProvider
{
    public function clean(string $message): string
    {
        return $this->sanitizeAuthoredText($message);
    }

    public function parse(string $content): array
    {
        return $this->parseJson($content);
    }

    public function payload(string $message): array
    {
        return $this->buildPayload($message);
    }
}

function assert_true($condition, string $message): void
{
    if (!$condition)
    {
        fwrite(STDERR, "FAIL: {$message}\n");
        exit(1);
    }
}

$provider = new TestOpenRouterProvider('test-key', 'openai/gpt-test', 8, ['deepseek/deepseek-test', 'google/gemini-test'], true);

$clean = $provider->clean("Kullanıcının kendi cümlesi.\n[QUOTE=Ali]Başkasının uzun metni burada.[/QUOTE]\n[CODE]secret = 123[/CODE]\nDevam cümlesi.");
assert_true(str_contains($clean, 'Kullanıcının kendi cümlesi.'), 'Kullanıcı metni korunmalı');
assert_true(str_contains($clean, 'Devam cümlesi.'), 'Kullanıcının devam metni korunmalı');
assert_true(!str_contains($clean, 'Başkasının uzun metni'), 'QUOTE içeriği dış API metninden çıkarılmalı');
assert_true(!str_contains($clean, 'secret = 123'), 'CODE içeriği dış API metninden çıkarılmalı');

$payload = $provider->payload('Deneme metni');
assert_true(($payload['model'] ?? '') === 'openai/gpt-test', 'Birincil model payload içinde korunmalı');
assert_true(($payload['models'] ?? []) === ['openai/gpt-test', 'deepseek/deepseek-test', 'google/gemini-test'], 'Fallback zinciri sıralı models alanına dönüşmeli');
assert_true(($payload['provider']['zdr'] ?? false) === true, 'ZDR açıkken provider.zdr true olmalı');
assert_true(($payload['provider']['allow_fallbacks'] ?? false) === true, 'Provider fallback açık kalmalı');

$parsed = $provider->parse("```json\n{\"risk\":72,\"confidence\":81,\"usage_type\":\"ai_assistance\",\"signals\":[\"düzenli yapı\"],\"note\":\"örnek\"}\n```");
assert_true(($parsed['risk'] ?? null) === 72, 'Markdown JSON bloğu parse edilmeli');
assert_true(($parsed['usage_type'] ?? null) === 'ai_assistance', 'usage_type korunmalı');

$parsedWithText = $provider->parse("Sonuç: {\"risk\":31,\"confidence\":54,\"usage_type\":\"human_likely\",\"signals\":[],\"note\":\"\"} teşekkürler");
assert_true(($parsedWithText['risk'] ?? null) === 31, 'JSON çevresindeki ek model metni tolere edilmeli');

$unconfigured = new TestOpenRouterProvider('', 'openrouter/auto', 8);
assert_true(!$unconfigured->isConfigured(), 'API anahtarı yokken provider yapılandırılmış sayılmamalı');

fwrite(STDOUT, "OpenRouter provider regression: OK\n");
