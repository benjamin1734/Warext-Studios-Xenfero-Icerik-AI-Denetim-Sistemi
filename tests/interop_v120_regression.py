#!/usr/bin/env python3
import json
import xml.etree.ElementTree as ET
from pathlib import Path

ROOT = Path(__file__).resolve().parents[1]
ADDON = ROOT / 'upload/src/addons/Warext/AIContentInspector'
PROVIDER = ADDON / 'Provider'
SERVICE = ADDON / 'Service'


def require(condition: bool, message: str) -> None:
    if not condition:
        raise SystemExit('FAIL: ' + message)


addon = json.loads((ADDON / 'addon.json').read_text(encoding='utf-8'))
require(addon['version_string'] == '1.2.0', 'addon version must be 1.2.0')
require(addon['version_id'] == 1020000, 'addon version_id must be 1020000')
require('Warext/TurkishSpellCheck' not in json.dumps(addon, ensure_ascii=False), 'hard dependency on spell checker is forbidden')

routes = ET.parse(ADDON / '_data/routes.xml').getroot()
route_map = {(r.attrib.get('route_type'), r.attrib.get('route_prefix')): r.attrib.get('controller') for r in routes.findall('route')}
require(route_map.get(('public', 'warext-ai-interop')) == r'Warext\AIContentInspector:Interop', 'interop public route missing')

options = ET.parse(ADDON / '_data/options.xml').getroot()
option_ids = {o.attrib.get('option_id') for o in options.findall('option')}
for option in ['warextAiInteropEnabled', 'warextAiInteropWritingMaxChars', 'warextAiInteropCacheSeconds']:
    require(option in option_ids, f'missing interop option: {option}')

for relative in [
    'Pub/Controller/Interop.php',
    'Service/InteropGateway.php',
    'Service/InteropResultCache.php'
]:
    require((ADDON / relative).is_file(), f'missing interop file: {relative}')

gateway = (SERVICE / 'InteropGateway.php').read_text(encoding='utf-8')
for marker in [
    "'tasks' => ['moderation', 'writing']",
    "'writing_context' => $localWriting",
    "'combined_request' => true",
    'new InteropResultCache()',
    'UsageTracker'
]:
    require(marker in gateway, f'InteropGateway missing: {marker}')
require('TurkishSpellCheck' not in gateway, 'AI add-on must not directly depend on spell-check classes')

cache = (SERVICE / 'InteropResultCache.php').read_text(encoding='utf-8')
for marker in ['MAX_ENTRIES = 24', 'simpleCache()->getValue', 'simpleCache()->setValue', 'canonicalText', 'shared_reuse']:
    require(marker in cache, f'bounded result cache missing: {marker}')

external = (SERVICE / 'ExternalVerifier.php').read_text(encoding='utf-8')
for marker in ['InteropResultCache', "'request_avoided' => true", "'shared_result_reuse'", "'shared_provider_result_reused'"]:
    require(marker in external, f'ExternalVerifier reuse path missing: {marker}')

abstract = (PROVIDER / 'AbstractJsonProvider.php').read_text(encoding='utf-8')
for marker in ['normalizeRequestContext', 'outputTokenLimit', "['moderation', 'writing']", 'normalizeWriting', "'local_supported'"]:
    require(marker in abstract, f'combined provider contract missing: {marker}')
require("return in_array('writing', $tasks, true) ? 1200 : 280;" in abstract, 'task-aware token budget missing')

for name in ['OpenAIProvider.php', 'GeminiProvider.php', 'DeepSeekProvider.php', 'AnthropicProvider.php', 'OpenAICompatibleProvider.php', 'OpenRouterProvider.php']:
    source = (PROVIDER / name).read_text(encoding='utf-8')
    require('outputTokenLimit()' in source, f'{name} does not use task-aware output budget')

openrouter = (PROVIDER / 'OpenRouterProvider.php').read_text(encoding='utf-8')
require('extends AbstractJsonProvider' in openrouter, 'OpenRouter must use shared provider contract')
require('fallback_models' in openrouter and 'response_cache' in openrouter, 'OpenRouter routing metadata must remain available')

controller = (ADDON / 'Pub/Controller/Interop.php').read_text(encoding='utf-8')
for marker in ['assertPostOnly', "filter('message', 'str')", "filter('local_context', 'str')", 'use JsonResponder;', 'new InteropGateway()']:
    require(marker in controller, f'interop controller missing: {marker}')

print('AI Content Inspector V1.2.0 interop regression: OK')
