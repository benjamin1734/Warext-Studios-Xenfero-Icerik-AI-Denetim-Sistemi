<?php

$base = __DIR__ . '/../upload/src/addons/Warext/AIContentInspector/Provider/';
require_once $base . 'ProviderInterface.php';
require_once $base . 'AbstractJsonProvider.php';
require_once $base . 'OpenAIProvider.php';
require_once $base . 'GeminiProvider.php';
require_once $base . 'DeepSeekProvider.php';
require_once $base . 'AnthropicProvider.php';

use Warext\AIContentInspector\Provider\OpenAIProvider;
use Warext\AIContentInspector\Provider\GeminiProvider;
use Warext\AIContentInspector\Provider\DeepSeekProvider;
use Warext\AIContentInspector\Provider\AnthropicProvider;

class TestOpenAIProvider extends OpenAIProvider
{
    public function payload(string $message): array { return $this->buildPayload($message); }
    public function clean(string $message): string { return $this->sanitizeAuthoredText($message); }
    public function parse(string $content): array { return $this->parseJson($content); }
}
class TestGeminiProvider extends GeminiProvider
{
    public function payload(string $message): array { return $this->buildPayload($message); }
}
class TestDeepSeekProvider extends DeepSeekProvider
{
    public function payload(string $message): array { return $this->buildPayload($message); }
}
class TestAnthropicProvider extends AnthropicProvider
{
    public function payload(string $message): array { return $this->buildPayload($message); }
}

function assert_true($condition, string $message): void
{
    if (!$condition) { fwrite(STDERR, "FAIL: {$message}\n"); exit(1); }
}

$openai = new TestOpenAIProvider('demo-key', 'gpt-test', 8);
assert_true($openai->isConfigured(), 'OpenAI anahtar + model ile yapılandırılmış olmalı');
$clean = $openai->clean("Kendi metnim.\n[QUOTE=Ali]Başkasının metni[/QUOTE]\n[CODE]echo 1;[/CODE]\nDevam.");
assert_true(str_contains($clean, 'Kendi metnim.'), 'Kullanıcı metni korunmalı');
assert_true(str_contains($clean, 'Devam.'), 'Devam metni korunmalı');
assert_true(!str_contains($clean, 'Başkasının metni'), 'QUOTE temizlenmeli');
assert_true(!str_contains($clean, 'echo 1'), 'CODE temizlenmeli');
$parsed = $openai->parse("```json\n{\"risk\":61,\"confidence\":77,\"usage_type\":\"ai_assistance\"}\n```");
assert_true(($parsed['risk'] ?? null) === 61, 'Ortak JSON ayrıştırıcı markdown JSON okuyabilmeli');
$openaiPayload = $openai->payload('Deneme');
assert_true(($openaiPayload['model'] ?? '') === 'gpt-test', 'OpenAI model korunmalı');
assert_true(($openaiPayload['input'] ?? '') === 'Deneme', 'OpenAI Responses input doğru olmalı');
assert_true(isset($openaiPayload['instructions']), 'OpenAI sistem talimatı bulunmalı');

$gemini = new TestGeminiProvider('demo-key', 'gemini-test', 8);
$geminiPayload = $gemini->payload('Deneme');
assert_true(($geminiPayload['contents'][0]['parts'][0]['text'] ?? '') === 'Deneme', 'Gemini içerik payloadı doğru olmalı');
assert_true(($geminiPayload['generationConfig']['responseMimeType'] ?? '') === 'application/json', 'Gemini JSON cevap modu açık olmalı');
assert_true(isset($geminiPayload['systemInstruction']), 'Gemini sistem talimatı bulunmalı');

$deepseek = new TestDeepSeekProvider('demo-key', 'deepseek-test', 8);
$deepseekPayload = $deepseek->payload('Deneme');
assert_true(($deepseekPayload['response_format']['type'] ?? '') === 'json_object', 'DeepSeek JSON output açık olmalı');
assert_true(($deepseekPayload['thinking']['type'] ?? '') === 'disabled', 'DeepSeek sınıflandırma çağrısında thinking kapalı olmalı');
assert_true(($deepseekPayload['messages'][1]['content'] ?? '') === 'Deneme', 'DeepSeek kullanıcı mesajı doğru olmalı');

$anthropic = new TestAnthropicProvider('demo-key', 'claude-test', 8);
$anthropicPayload = $anthropic->payload('Deneme');
assert_true(($anthropicPayload['model'] ?? '') === 'claude-test', 'Claude model korunmalı');
assert_true(($anthropicPayload['messages'][0]['content'] ?? '') === 'Deneme', 'Claude kullanıcı mesajı doğru olmalı');
assert_true(isset($anthropicPayload['system']), 'Claude sistem talimatı bulunmalı');
assert_true(($anthropicPayload['max_tokens'] ?? 0) === 280, 'Claude çıktı limiti korunmalı');

$unconfigured = new TestOpenAIProvider('', 'gpt-test', 8);
assert_true(!$unconfigured->isConfigured(), 'Boş API anahtarı yapılandırılmış sayılmamalı');

fwrite(STDOUT, "Direct provider regression: OK\n");
