<?php

namespace Warext\AIContentInspector\Provider;

interface ProviderInterface
{
    public function getId(): string;

    public function getLabel(): string;

    public function isConfigured(): bool;

    public function analyze(string $message, array $context = []): array;
}
