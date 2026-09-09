#!/usr/bin/env python3
import json
import re
import xml.etree.ElementTree as ET
from pathlib import Path

ROOT = Path('upload/src/addons/Warext/AIContentInspector')
DATA = ROOT / '_data'


def fail(message: str) -> None:
    raise SystemExit(message)


def main() -> None:
    addon = json.loads((ROOT / 'addon.json').read_text(encoding='utf-8'))
    version = str(addon.get('version_string', '')).strip()
    version_id = int(addon.get('version_id', 0))

    if not re.fullmatch(r'\d+\.\d+\.\d+', version):
        fail(f'Geçersiz Stable sürüm kimliği: {version!r}')
    if version_id <= 0:
        fail('version_id geçersiz')
    if int(addon.get('require', {}).get('XF', [0])[0]) < 2030070:
        fail('XenForo 2.3+ gereksinimi eksik')
    if 'Warext/TurkishSpellCheck' in json.dumps(addon, ensure_ascii=False):
        fail('Writing Checker zorunlu bağımlılık olamaz')

    parsed = {}
    for path in sorted(DATA.glob('*.xml')):
        parsed[path.name] = ET.parse(path).getroot()

    allowed_formats = {
        'textbox', 'spinbox', 'onoff', 'onofftextbox', 'radio',
        'select', 'checkbox', 'template', 'callback', 'username'
    }
    options = parsed['options.xml']
    invalid_formats = {
        o.attrib.get('option_id'): o.attrib.get('edit_format')
        for o in options.findall('option')
        if o.attrib.get('edit_format') not in allowed_formats
    }
    if invalid_formats:
        fail('Geçersiz XenForo option edit formatı: ' + repr(invalid_formats))
    if any(o.attrib.get('edit_format') == 'textarea' for o in options.findall('option')):
        fail('edit_format=textarea XenForo tarafından kabul edilmez')

    fallback = next((o for o in options.findall('option') if o.attrib.get('option_id') == 'warextAiOpenRouterFallbackModels'), None)
    if fallback is None or fallback.attrib.get('edit_format') != 'textbox' or 'rows=4' not in (fallback.findtext('edit_format_params') or ''):
        fail('OpenRouter fallback listesi textbox + rows=4 olmalı')

    forum_option = next((o for o in options.findall('option') if o.attrib.get('option_id') == 'warextAiForums'), None)
    if forum_option is None or (forum_option.findtext('edit_format_params') or '') != r'XF\Option\Forum::renderSelectMultiple':
        fail('Forum selector callback XenForo export biçiminde değil')

    required_options = {
        'warextAiEnabled', 'warextAiMinChars', 'warextAiForums', 'warextAiExternalProvider',
        'warextAiDailyRequestLimit', 'warextAiMonthlyRequestLimit',
        'warextAiDailyBudgetUsd', 'warextAiMonthlyBudgetUsd',
        'warextAiEstimatedInputUsdPerMillion', 'warextAiEstimatedOutputUsdPerMillion',
        'warextAiUsageRetentionDays', 'warextAiHistoryBatchSize'
    }
    option_ids = {o.attrib.get('option_id') for o in options.findall('option')}
    if not required_options.issubset(option_ids):
        fail('Zorunlu ayarlar eksik: ' + ', '.join(sorted(required_options - option_ids)))

    groups = {g.attrib.get('group_id') for g in parsed['option_groups.xml'].findall('option_group')}
    if 'warextAi' not in groups:
        fail('Warext AI option group eksik')

    admin_nav = parsed['admin_navigation.xml']
    entries = admin_nav.findall('admin_navigation_entry')
    nav_by_id = {n.attrib.get('navigation_id'): n for n in entries}
    if not {'warextAiAdmin', 'warextAiSettings'}.issubset(nav_by_id):
        fail('ACP navigation export şeması hatalı')
    if admin_nav.findall('nav'):
        fail('Legacy <nav> ACP navigation yapısı kullanılamaz')
    root_order = int(nav_by_id['warextAiAdmin'].attrib.get('display_order', '0'))
    if root_order < 1000:
        fail('Warext AI ACP kategorisi core menülerin üstüne taşınmamalı (display_order >= 1000)')
    if nav_by_id['warextAiSettings'].attrib.get('parent_navigation_id') != 'warextAiAdmin':
        fail('Warext AI ayarlar girdisi kendi ACP kategorisinin altında olmalı')

    public_navigation = (DATA / 'navigation.xml').read_text(encoding='utf-8')
    if "$xf.visitor->hasPermission" in public_navigation:
        fail('Public navigation PHP -> sözdizimi içeriyor')
    if "$xf.visitor.hasPermission('general', 'warextAiViewSimple')" not in public_navigation:
        fail('Public navigation XenForo template yetki koşulu eksik')

    permissions = parsed['permissions.xml']
    actual_permissions = {p.attrib.get('permission_id') for p in permissions.findall('permission')}
    required_permissions = {'warextAiViewSimple', 'warextAiViewDetailed', 'warextAiReview', 'warextAiManage'}
    if not required_permissions.issubset(actual_permissions):
        fail('Yetki tanımları eksik')

    phrase_titles = {p.attrib.get('title') for p in parsed['phrases.xml'].findall('phrase')}
    required_phrases = {
        'permission_interface.warextAi',
        'permission.general_warextAiViewSimple', 'permission.general_warextAiViewDetailed',
        'permission.general_warextAiReview', 'permission.general_warextAiManage',
        'option_group.warextAi', 'option_group_description.warextAi',
        'option.warextAiEnabled', 'option_explain.warextAiEnabled',
        'option.warextAiDailyRequestLimit', 'option.warextAiMonthlyRequestLimit',
        'option.warextAiDailyBudgetUsd', 'option.warextAiMonthlyBudgetUsd',
        'option.warextAiEstimatedInputUsdPerMillion', 'option.warextAiEstimatedOutputUsdPerMillion',
        'option.warextAiUsageRetentionDays', 'option.warextAiHistoryBatchSize',
        'admin_navigation.warextAiAdmin', 'admin_navigation.warextAiSettings', 'nav.warextAi'
    }
    if not required_phrases.issubset(phrase_titles):
        fail('Kanonik XenForo phrase kayıtları eksik: ' + ', '.join(sorted(required_phrases - phrase_titles)))

    legacy = sorted(
        t for t in phrase_titles
        if t.startswith('option_warext')
        or t.startswith('option_group_warext')
        or t.startswith('permission_interface_')
        or t.startswith('permission_general_')
    )
    if legacy:
        fail('Legacy underscore master phrase anahtarları kaldı: ' + ', '.join(legacy[:10]))

    cron_ids = {e.attrib.get('cron_entry_id') for e in parsed['cron_entries.xml'].findall('cron_entry')}
    if 'warextAiUsagePrune' not in cron_ids:
        fail('Usage prune cron eksik')

    templates_root = parsed['templates.xml']
    templates = templates_root.findall('template')
    center = next((t for t in templates if t.attrib.get('type') == 'public' and t.attrib.get('title') == 'warext_ai_center'), None)
    if center is None:
        fail('public:warext_ai_center şablonu eksik')

    for template in templates:
        if int(template.attrib.get('version_id', '0')) != version_id:
            fail(f"Template version_id addon ile eşleşmiyor: {template.attrib.get('title')}")
        if template.attrib.get('version_string') != version:
            fail(f"Template version_string addon ile eşleşmiyor: {template.attrib.get('title')}")

    center_body = center.text or ''
    # XenForo'da {$...} doğrudan değer interpolasyonudur. Operatörlü ifadeler {{ ... }}
    # veya xf:if gibi expression alanlarında kullanılmalıdır. v1.0.1'i bozan sınıfı blokla.
    dangerous_simple_expressions = [
        r'\{\$[^{}\n]*\?:[^{}\n]*\}',
        r'\{\$[^{}\n]*\?[^{}\n]*:[^{}\n]*\}',
    ]
    for pattern in dangerous_simple_expressions:
        match = re.search(pattern, center_body)
        if match:
            fail('warext_ai_center içinde geçersiz ifadeli {$...} kullanımı: ' + match.group(0))

    if re.search(r'<option\b[^>]*\{\{[^>]*>', center_body, flags=re.I):
        fail('warext_ai_center raw <option> üzerinde dinamik attribute üretmemeli; xf:select/xf:option kullanın')
    if '<xf:select name="state" value="{$filters.state}"' not in center_body:
        fail('Durum filtresi kanonik xf:select yapısında değil')

    # CDATA içindeki markup XML olarak da dengeli olmalı. Bu XenForo compiler'ın yerini
    # tutmaz fakat kapanmayan xf:if/foreach ve bozuk tag yapısını release öncesi yakalar.
    try:
        ET.fromstring('<root xmlns:xf="urn:xenforo">' + center_body + '</root>')
    except ET.ParseError as exc:
        fail('warext_ai_center markup dengesi bozuk: ' + str(exc))

    modifications = (DATA / 'template_modifications.xml').read_text(encoding='utf-8')
    cache_key = f'?wai={version_id}'
    if cache_key not in modifications:
        fail(f'{version} JS cache anahtarı eksik: {cache_key}')

    setup = (ROOT / 'Setup.php').read_text(encoding='utf-8')
    analysis_block = setup.split("$this->createReviewLogTable();", 1)[0]
    if '$table->checkExists(true);' not in analysis_block:
        fail('Başarısız kurulum sonrası analysis table retry koruması eksik')
    for marker in [
        'installStep1', 'uninstallStep1', 'xf_warext_ai_analysis',
        'xf_warext_ai_review_log', 'xf_warext_ai_usage', 'similarity_metrics',
        'cost_source', 'forum_date'
    ]:
        if marker not in setup:
            fail('Install/uninstall şeması eksik: ' + marker)
    for drop in [
        "dropTable('xf_warext_ai_usage')",
        "dropTable('xf_warext_ai_review_log')",
        "dropTable('xf_warext_ai_analysis')"
    ]:
        if drop not in setup:
            fail('Uninstall temizliği eksik: ' + drop)

    post = (ROOT / 'XF/Entity/Post.php').read_text(encoding='utf-8')
    for marker in ['Registry', 'UserProfile', 'Similarity', 'enqueueUnique', 'ExternalVerify', 'existingHash', 'hash_equals']:
        if marker not in post:
            fail('Post analiz zinciri eksik: ' + marker)
    if '(new ExternalVerifier())->enrich' in post:
        fail('Harici provider mesaj kaydı sırasında senkron çalışmamalı')

    provider = ROOT / 'Provider'
    for name in [
        'ProviderInterface.php', 'LocalProvider.php', 'Registry.php', 'OpenRouterProvider.php',
        'AbstractJsonProvider.php', 'OpenAICompatibleProvider.php', 'OpenAIProvider.php',
        'GeminiProvider.php', 'DeepSeekProvider.php', 'AnthropicProvider.php'
    ]:
        if not (provider / name).exists():
            fail('Provider mimarisi eksik: ' + name)

    registry = (provider / 'Registry.php').read_text(encoding='utf-8')
    for marker in ['openrouter', 'openai', 'gemini', 'deepseek', 'anthropic', 'xai', 'mistral', 'qwen', 'ollama', 'custom_openai']:
        if marker not in registry:
            fail('Provider registry eksik: ' + marker)

    usage = (ROOT / 'Service/UsageTracker.php').read_text(encoding='utf-8')
    for marker in ['canRequest', 'record', 'summary', 'prune', 'daily_request_limit', 'monthly_request_limit', 'cost_source']:
        if marker not in usage:
            fail('Kullanım/bütçe katmanı eksik: ' + marker)

    print(json.dumps({
        'status': 'ok',
        'version': version,
        'versionId': version_id,
        'installerMasterDataValidated': True,
        'templateMarkupValidated': True,
        'dangerousSimpleTemplateExpressionsBlocked': True,
        'canonicalAdminNavigation': True,
        'adminNavigationOrderProtected': True,
        'canonicalPhrases': True,
        'failedInstallRetryGuard': True,
        'externalOptional': True,
        'externalAsyncJob': True
    }, ensure_ascii=False))


if __name__ == '__main__':
    main()
