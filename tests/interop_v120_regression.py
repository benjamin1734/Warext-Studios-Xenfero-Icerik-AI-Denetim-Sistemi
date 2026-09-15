#!/usr/bin/env python3
import json
import re
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
route_prefixes = {r.attrib.get('route_prefix') for r in routes.findall('route')}
require('warext-ai-interop' not in route_prefixes, 'shared provider interop must not expose a public browser endpoint')
require(not (ADDON / 'Pub/Controller/Interop.php').exists(), 'unused public interop controller must not ship')

options = ET.parse(ADDON / '_data/options.xml').getroot()
option_ids = {o.attrib.get('option_id') for o in options.findall('option')}
for option in ['warextAiInteropEnabled', 'warextAiInteropWritingMaxChars', 'warextAiInteropCacheSeconds']:
    require(option in option_ids, f'missing interop option: {option}')

for relative in ['Service/InteropGateway.php', 'Service/InteropResultCache.php']:
    require((ADDON / relative).is_file(), f'missing interop file: {relative}')

gateway = (SERVICE / 'InteropGateway.php').read_text(encoding='utf-8')
for marker in [
    'normalizeTasks',
    "'writing_context' => $localWriting",
    "$moderationRequested && $writingRequested",
    'new InteropResultCache()',
    'UsageTracker',
    "implode(',', $tasks)"
]:
    require(marker in gateway, f'InteropGateway missing: {marker}')
require(not re.search(r'\\?Warext\\+TurkishSpellCheck\\+', gateway), 'AI add-on must not reference spell-check PHP classes')
require('class_exists(\'Warext\\\\TurkishSpellCheck' not in gateway, 'AI add-on must not discover the spell checker directly')

cache = (SERVICE / 'InteropResultCache.php').read_text(encoding='utf-8')
for marker in [
    "TABLE = 'xf_warext_ai_interop_cache'",
    'SELECT payload, expires_date',
    'payload = VALUES(payload)',
    'canonicalText',
    'shared_reuse',
    'public function prune()'
]:
    require(marker in cache, f'database result cache missing: {marker}')
require('simpleCache()' not in cache, 'high-churn interop results must not use XenForo global SimpleCache')

setup = (ADDON / 'Setup.php').read_text(encoding='utf-8')
for marker in [
    'createInteropCacheTable()',
    'upgrade1020000Step1',
    "createTable('xf_warext_ai_interop_cache'",
    "addPrimaryKey('cache_key')",
    "dropTable('xf_warext_ai_interop_cache')"
]:
    require(marker in setup, f'interop cache schema missing: {marker}')

cron = (ADDON / 'Cron/UsagePrune.php').read_text(encoding='utf-8')
require('InteropResultCache' in cron and '->prune()' in cron, 'expired interop cache must be pruned by cron')

external = (SERVICE / 'ExternalVerifier.php').read_text(encoding='utf-8')
for marker in ['InteropResultCache', "'request_avoided' => true", "'shared_result_reuse'", "'shared_provider_result_reused'"]:
    require(marker in external, f'ExternalVerifier reuse path missing: {marker}')

abstract = (PROVIDER / 'AbstractJsonProvider.php').read_text(encoding='utf-8')
for marker in ['normalizeRequestContext', 'outputTokenLimit', 'normalizeWriting', "'local_supported'", '$moderation = in_array', '$writing = in_array']:
    require(marker in abstract, f'task-aware provider contract missing: {marker}')
require("return in_array('writing', $tasks, true) ? 1200 : 280;" in abstract, 'task-aware token budget missing')
require('tam düzeltilmiş metin kopyası döndürme' in abstract, 'writing prompt must forbid full rewritten-text output')
require("'corrected_text'" not in abstract, 'provider normalization should not retain redundant full corrected text')

for name in ['OpenAIProvider.php', 'GeminiProvider.php', 'DeepSeekProvider.php', 'AnthropicProvider.php', 'OpenAICompatibleProvider.php', 'OpenRouterProvider.php']:
    source = (PROVIDER / name).read_text(encoding='utf-8')
    require('outputTokenLimit()' in source, f'{name} does not use task-aware output budget')

openrouter = (PROVIDER / 'OpenRouterProvider.php').read_text(encoding='utf-8')
require('extends AbstractJsonProvider' in openrouter, 'OpenRouter must use shared provider contract')
require('fallback_models' in openrouter and 'response_cache' in openrouter, 'OpenRouter routing metadata must remain available')

print('AI Content Inspector V1.2.0 server-only interop regression: OK')
