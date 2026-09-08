<?php

namespace Warext\AIContentInspector\Provider;

class Registry
{
    public function local(): ProviderInterface
    {
        return new LocalProvider();
    }

    public function selectedExternalId(): string
    {
        $id = trim((string)(\XF::options()->warextAiExternalProvider ?? 'openrouter'));
        return $id !== '' ? $id : 'openrouter';
    }

    public function external(): ?ProviderInterface
    {
        $id = $this->selectedExternalId();
        if ($id === 'none') return null;

        return match ($id)
        {
            'openrouter' => $this->openRouter(),
            default => null
        };
    }

    public function isExternalEnabled(): bool
    {
        $id = $this->selectedExternalId();
        if ($id === 'none') return false;

        if ($id === 'openrouter')
        {
            return !empty(\XF::options()->warextAiOpenRouterEnabled);
        }

        return false;
    }

    public function externalConfig(): array
    {
        $id = $this->selectedExternalId();
        $options = \XF::options();

        if ($id === 'openrouter')
        {
            return [
                'provider' => 'openrouter',
                'model' => (string)($options->warextAiOpenRouterModel ?? 'openrouter/auto'),
                'minimum_local_risk' => max(0, min(100, (int)($options->warextAiOpenRouterMinRisk ?? 45))),
                'weight' => max(5, min(40, (int)($options->warextAiOpenRouterWeight ?? 20))),
                'max_chars' => max(1000, min(50000, (int)($options->warextAiOpenRouterMaxChars ?? 12000)))
            ];
        }

        return [
            'provider' => $id,
            'model' => '',
            'minimum_local_risk' => 100,
            'weight' => 0,
            'max_chars' => 12000
        ];
    }

    public function openRouter(): ProviderInterface
    {
        $options = \XF::options();
        return new OpenRouterProvider(
            (string)($options->warextAiOpenRouterKey ?? ''),
            (string)($options->warextAiOpenRouterModel ?? 'openrouter/auto'),
            (int)($options->warextAiOpenRouterTimeout ?? 8),
            $this->parseModelList((string)($options->warextAiOpenRouterFallbackModels ?? '')),
            !empty($options->warextAiOpenRouterZdrOnly),
            (string)($options->warextAiOpenRouterRoutingSort ?? ''),
            !isset($options->warextAiOpenRouterDenyDataCollection) || !empty($options->warextAiOpenRouterDenyDataCollection),
            !empty($options->warextAiOpenRouterResponseCache)
        );
    }

    public function knownProviders(): array
    {
        return [
            'local' => ['label' => 'Warext Local Engine', 'mode' => 'local', 'implemented' => true],
            'openrouter' => ['label' => 'OpenRouter', 'mode' => 'openai_compatible', 'implemented' => true, 'recommended' => true],
            'openai' => ['label' => 'OpenAI / GPT', 'mode' => 'native', 'implemented' => false],
            'gemini' => ['label' => 'Google Gemini', 'mode' => 'native', 'implemented' => false],
            'deepseek' => ['label' => 'DeepSeek', 'mode' => 'openai_compatible', 'implemented' => false],
            'anthropic' => ['label' => 'Anthropic Claude', 'mode' => 'native', 'implemented' => false],
            'xai' => ['label' => 'xAI / Grok', 'mode' => 'openai_compatible', 'implemented' => false],
            'mistral' => ['label' => 'Mistral AI', 'mode' => 'openai_compatible', 'implemented' => false],
            'qwen' => ['label' => 'Qwen', 'mode' => 'openai_compatible', 'implemented' => false],
            'ollama' => ['label' => 'Ollama', 'mode' => 'local_http', 'implemented' => false],
            'custom_openai' => ['label' => 'Özel OpenAI-Compatible API', 'mode' => 'openai_compatible', 'implemented' => false]
        ];
    }

    protected function parseModelList(string $raw): array
    {
        $models = preg_split('/[\r\n,;]+/', $raw) ?: [];
        return array_values(array_filter(array_map('trim', $models), fn(string $model) => $model !== ''));
    }
}
