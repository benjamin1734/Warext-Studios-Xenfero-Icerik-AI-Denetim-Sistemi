<?php

$base = __DIR__ . '/../upload/src/addons/Warext/AIContentInspector/Provider/';
require_once $base . 'ProviderInterface.php';
require_once $base . 'AbstractJsonProvider.php';
require_once $base . 'OpenAICompatibleProvider.php';

use Warext\AIContentInspector\Provider\OpenAICompatibleProvider;

class TestCompatibleProvider extends OpenAICompatibleProvider
{
    public function payload(string $message): array { return $this->buildPayload($message); }
    public function endpoint(): string { return $this->chatCompletionsUrl(); }
}

function assert_true($condition, string $message): void
{
    if (!$condition) { fwrite(STDERR, "FAIL: {$message}\n"); exit(1); }
}

$xai = new TestCompatibleProvider('xai', 'xAI / Grok', 'https://api.x.ai/v1/', 'demo', 'grok-4.6', 8);
assert_true($xai->isConfigured(), 'xAI yapılandırılmış olmalı');
assert_true($xai->endpoint() === 'https://api.x.ai/v1/chat/completions', 'Chat completions yolu doğru eklenmeli');
$xaiPayload = $xai->payload('Deneme');
assert_true(($xaiPayload['model'] ?? '') === 'grok-4.6', 'Model korunmalı');
assert_true(($xaiPayload['messages'][0]['role'] ?? '') === 'system', 'Sistem mesajı bulunmalı');
assert_true(($xaiPayload['messages'][1]['content'] ?? '') === 'Deneme', 'Kullanıcı mesajı korunmalı');
assert_true(($xaiPayload['temperature'] ?? null) === 0, 'Sınıflandırma sıcaklığı sıfır olmalı');

$fullUrl = new TestCompatibleProvider('custom_openai', 'Custom', 'https://example.com/v1/chat/completions', '', 'demo-model', 8, false);
assert_true($fullUrl->endpoint() === 'https://example.com/v1/chat/completions', 'Tam endpoint iki kez eklenmemeli');
assert_true($fullUrl->isConfigured(), 'Anahtarsız custom endpoint desteklenmeli');

$ollama = new TestCompatibleProvider('ollama', 'Ollama', 'http://127.0.0.1:11434/v1', '', 'llama3.2', 8, false);
assert_true($ollama->isConfigured(), 'Ollama API anahtarı olmadan yapılandırılabilmeli');
assert_true($ollama->endpoint() === 'http://127.0.0.1:11434/v1/chat/completions', 'Ollama endpoint doğru olmalı');

$badScheme = new TestCompatibleProvider('custom_openai', 'Custom', 'file:///etc/passwd', '', 'demo', 8, false);
assert_true(!$badScheme->isConfigured(), 'HTTP/HTTPS dışı endpoint reddedilmeli');

$embeddedCredentials = new TestCompatibleProvider('custom_openai', 'Custom', 'https://user:pass@example.com/v1', '', 'demo', 8, false);
assert_true(!$embeddedCredentials->isConfigured(), 'URL içine gömülü kimlik bilgisi reddedilmeli');

$missingModel = new TestCompatibleProvider('custom_openai', 'Custom', 'https://example.com/v1', '', '', 8, false);
assert_true(!$missingModel->isConfigured(), 'Model boşsa provider yapılandırılmış sayılmamalı');

$keyRequired = new TestCompatibleProvider('mistral', 'Mistral AI', 'https://api.mistral.ai/v1', '', 'mistral-small-latest', 8, true);
assert_true(!$keyRequired->isConfigured(), 'Anahtar zorunlu provider boş anahtarla etkinleşmemeli');

fwrite(STDOUT, "OpenAI-compatible provider regression: OK\n");
