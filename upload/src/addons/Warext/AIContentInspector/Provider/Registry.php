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
            'openai' => $this->openAI(),
            'gemini' => $this->gemini(),
            'deepseek' => $this->deepSeek(),
            'anthropic' => $this->anthropic(),
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

        return in_array($id, ['openai', 'gemini', 'deepseek', 'anthropic'], true);
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

        $models = [
            'openai' => (string)($options->warextAiOpenAIModel ?? 'gpt-5.6-luna'),
            'gemini' => (string)($options->warextAiGeminiModel ?? 'gemini-3.8-flash'),
            'deepseek' => (string)($options->warextAiDeepSeekModel ?? 'deepseek-v4-flash'),
            'anthropic' => (string)($options->warextAiAnthropicModel ?? 'claude-sonnet-5')
        ];

        if (isset($models[$id]))
        {
            return [
                'provider' => $id,
                'model' => $models[$id],
                'minimum_local_risk' => max(0, min(100, (int)($options->warextAiDirectMinRisk ?? 45))),
                'weight' => max(5, min(40, (int)($options->warextAiDirectWeight ?? 20))),
                'max_chars' => max(1000, min(50000, (int)($options->warextAiDirectMaxChars ?? 12000)))
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

    public function openAI(): ProviderInterface
    {
        $options = \XF::options();
        return new OpenAIProvider(
            (string)($options->warextAiOpenAIKey ?? ''),
            (string)($options->warextAiOpenAIModel ?? 'gpt-5.6-luna'),
            (int)($options->warextAiDirectTimeout ?? 8)
        );
    }

    public function gemini(): ProviderInterface
    {
        $options = \XF::options();
        return new GeminiProvider(
            (string)($options->warextAiGeminiKey ?? ''),
            (string)($options->warextAiGeminiModel ?? 'gemini-3.8-flash'),
            (int)($options->warextAiDirectTimeout ?? 8)
        );
    }

    public function deepSeek(): ProviderInterface
    {
        $options = \XF::options();
        return new DeepSeekProvider(
            (string)($options->warextAiDeepSeekKey ?? ''),
            (string)($options->warextAiDeepSeekModel ?? 'deepseek-v4-flash'),
            (int)($options->warextAiDirectTimeout ?? 8)
        );
    }

    public function anthropic(): ProviderInterface
    {
        $options = \XF::options();
        return new AnthropicProvider(
            (string)($options->warextAiAnthropicKey ?? ''),
            (string)($options->warextAiAnthropicModel ?? 'claude-sonnet-5'),
            (int)($options->warextAiDirectTimeout ?? 8)
        );
    }

    public function knownProviders(): array
    {
        return [
            'local' => ['label' => 'Warext Local Engine', 'mode' => 'local', 'implemented' => true],
            'openrouter' => ['label' => 'OpenRouter', 'mode' => 'openai_compatible', 'implemented' => true, 'recommended' => true],
            'openai' => ['label' => 'OpenAI / GPT', 'mode' => 'native', 'implemented' => true],
            'gemini' => ['label' => 'Google Gemini', 'mode' => 'native', 'implemented' => true],
            'deepseek' => ['label' => 'DeepSeek', 'mode' => 'openai_compatible', 'implemented' => true],
            'anthropic' => ['label' => 'Anthropic Claude', 'mode' => 'native', 'implemented' => true],
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
