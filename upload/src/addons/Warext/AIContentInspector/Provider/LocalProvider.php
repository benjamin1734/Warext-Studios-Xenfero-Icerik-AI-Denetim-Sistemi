<?php

namespace Warext\AIContentInspector\Provider;

use Warext\AIContentInspector\Service\Analyzer;
use Warext\AIContentInspector\Service\LocalCalibration;

class LocalProvider implements ProviderInterface
{
    public function getId(): string
    {
        return 'local';
    }

    public function getLabel(): string
    {
        return 'Warext Local Engine';
    }

    public function isConfigured(): bool
    {
        return true;
    }

    public function analyze(string $message, array $context = []): array
    {
        $behavior = is_array($context['behavior'] ?? null) ? $context['behavior'] : [];
        $writing = is_array($context['writing'] ?? null) ? $context['writing'] : [];

        $result = (new Analyzer())->analyze($message, $behavior, $writing);
        $result = LocalCalibration::apply($result);
        $result['provider'] = [
            'id' => $this->getId(),
            'label' => $this->getLabel(),
            'external' => false,
            'engine_version' => LocalCalibration::ENGINE_VERSION
        ];

        return $result;
    }
}
