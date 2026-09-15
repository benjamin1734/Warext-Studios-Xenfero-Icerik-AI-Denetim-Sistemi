<?php

$base = __DIR__ . '/../upload/src/addons/Warext/AIContentInspector/Provider/';
require_once $base . 'ProviderInterface.php';
require_once $base . 'AbstractJsonProvider.php';
require_once $base . 'OpenRouterProvider.php';

use Warext\AIContentInspector\Provider\OpenRouterProvider;

class TestOpenRouterProvider extends OpenRouterProvider
{
    public function clean(string $message): string { return $this->sanitizeAuthoredText($message); }
    public function parse(string $content): array { return $this->parseJson($content); }
    public function payload(string $message): array { return $this->buildPayload($message); }
    public function writingPayload(string $message): array
    {
        $this->requestContext = $this->normalizeRequestContext([
            'tasks' => ['moderation', 'writing'],
            'writing_mode' => 'hybrid',
            'writing_context' => [
                'issues' => [[
                    'start' => 0,
                    'end' => 5,
                    'original' => 'yanliş',
                    'suggestions' => ['yanlış'],
                    'rule' => 'spelling',
                    'confidence' => 95
                ]]
            ]
        ]);
        return $this->buildPayload($message);
    }
    public function writingOnlyPayload(string $message): array
    {
        $this->requestContext = $this->normalizeRequestContext(['tasks' => ['writing']]);
        return $this->buildPayload($message);
    }
}

function assert_true($condition, string $message): void
{
    if (!$condition) { fwrite(STDERR, "FAIL: {$message}\n"); exit(1); }
}

$provider = new TestOpenRouterProvider(
    'demo-value', 'openai/gpt-test', 8,
    ['deepseek/deepseek-test', 'google/gemini-test'],
    true, 'price', true, true
);

$clean = $provider->clean("Kullanıcının kendi cümlesi.\n[QUOTE=Ali]Başkasının uzun metni burada.[/QUOTE]\n[CODE]örnek kod[/CODE]\nDevam cümlesi.");
assert_true(str_contains($clean, 'Kullanıcının kendi cümlesi.'), 'Kullanıcı metni korunmalı');
assert_true(str_contains($clean, 'Devam cümlesi.'), 'Kullanıcının devam metni korunmalı');
assert_true(!str_contains($clean, 'Başkasının uzun metni'), 'QUOTE içeriği dış API metninden çıkarılmalı');
assert_true(!str_contains($clean, 'örnek kod'), 'CODE içeriği dış API metninden çıkarılmalı');

$payload = $provider->payload('Deneme metni');
assert_true(($payload['model'] ?? '') === 'openai/gpt-test', 'Birincil model korunmalı');
assert_true(($payload['models'] ?? []) === ['openai/gpt-test', 'deepseek/deepseek-test', 'google/gemini-test'], 'Fallback sırası korunmalı');
assert_true(($payload['provider']['zdr'] ?? false) === true, 'ZDR true olmalı');
assert_true(($payload['provider']['allow_fallbacks'] ?? false) === true, 'Provider fallback açık olmalı');
assert_true(($payload['provider']['sort'] ?? '') === 'price', 'Maliyet rotası aktarılmalı');
assert_true(($payload['provider']['data_collection'] ?? '') === 'deny', 'Data collection deny olmalı');
assert_true(($payload['usage']['include'] ?? false) === true, 'Usage ölçümü açık olmalı');
assert_true(($payload['max_tokens'] ?? 0) === 280, 'Sadece moderasyon çağrısı düşük çıktı bütçesini korumalı');

$writingPayload = $provider->writingPayload('yanliş bir metin');
assert_true(($writingPayload['max_tokens'] ?? 0) === 1200, 'Birleşik yazım çağrısı genişletilmiş çıktı bütçesi kullanmalı');
assert_true(str_contains((string)($writingPayload['messages'][0]['content'] ?? ''), 'Türkçe yazım denetimi'), 'Birleşik prompt yazım görevini içermeli');
assert_true(str_contains((string)($writingPayload['messages'][0]['content'] ?? ''), 'yanlış'), 'Hybrid prompt yerel öneriyi içermeli');
assert_true(!str_contains((string)($writingPayload['messages'][0]['content'] ?? ''), 'corrected_text'), 'Birleşik prompt gereksiz tam metin çıktısı istememeli');

$writingOnlyPayload = $provider->writingOnlyPayload('Deneme');
$writingOnlySystem = (string)($writingOnlyPayload['messages'][0]['content'] ?? '');
assert_true(str_contains($writingOnlySystem, 'Türkçe yazım denetimi'), 'Writing-only prompt yazım görevini içermeli');
assert_true(!str_contains($writingOnlySystem, 'forum moderasyon destek analizörüsün'), 'Writing-only prompt gereksiz moderasyon talimatı taşımamalı');

$parsed = $provider->parse("```json\n{\"risk\":72,\"confidence\":81,\"usage_type\":\"ai_assistance\",\"signals\":[\"düzenli yapı\"],\"note\":\"örnek\"}\n```");
assert_true(($parsed['risk'] ?? null) === 72, 'Markdown JSON parse edilmeli');
assert_true(($parsed['usage_type'] ?? null) === 'ai_assistance', 'usage_type korunmalı');

$invalidSort = new TestOpenRouterProvider('demo-value', 'openrouter/auto', 8, [], false, 'invalid');
$invalidPayload = $invalidSort->payload('Deneme');
assert_true(!isset($invalidPayload['provider']['sort']), 'Geçersiz rota payload içine girmemeli');

$unconfigured = new TestOpenRouterProvider('', 'openrouter/auto', 8);
assert_true(!$unconfigured->isConfigured(), 'Boş kimlik değeri yapılandırılmış sayılmamalı');

fwrite(STDOUT, "OpenRouter provider regression: OK\n");
