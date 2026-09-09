#!/usr/bin/env python3
import json
import re
import xml.etree.ElementTree as ET
from pathlib import Path

ROOT = Path('upload/src/addons/Warext/AIContentInspector')
DATA = ROOT / '_data'
JS = Path('upload/js/warext/ai-content-inspector')


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

    parsed = {path.name: ET.parse(path).getroot() for path in sorted(DATA.glob('*.xml'))}

    allowed_formats = {
        'textbox', 'spinbox', 'onoff', 'onofftextbox', 'radio',
        'select', 'checkbox', 'template', 'callback', 'username'
    }
    options = parsed['options.xml']
    option_nodes = options.findall('option')
    if len(option_nodes) < 20:
        fail(f'Warext AI seçenek master-data eksik: yalnız {len(option_nodes)} option bulundu')

    invalid_formats = {
        o.attrib.get('option_id'): o.attrib.get('edit_format')
        for o in option_nodes
        if o.attrib.get('edit_format') not in allowed_formats
    }
    if invalid_formats:
        fail('Geçersiz XenForo option edit formatı: ' + repr(invalid_formats))

    required_option_ids = {
        'warextAiEnabled', 'warextAiMinChars', 'warextAiForums', 'warextAiExternalProvider',
        'warextAiOpenRouterKey', 'warextAiOpenRouterModel', 'warextAiDailyRequestLimit',
        'warextAiMonthlyRequestLimit', 'warextAiHistoryBatchSize'
    }
    actual_option_ids = {o.attrib.get('option_id') for o in option_nodes}
    missing_option_ids = sorted(required_option_ids - actual_option_ids)
    if missing_option_ids:
        fail('Zorunlu Warext AI seçenekleri eksik: ' + ', '.join(missing_option_ids))

    for option in option_nodes:
        relations = option.findall('relation')
        if not relations:
            fail(f"Option grup ilişkisi eksik: {option.attrib.get('option_id')}")
        if not any(r.attrib.get('group_id') == 'warextAi' for r in relations):
            fail(f"Option warextAi grubuna bağlı değil: {option.attrib.get('option_id')}")

    fallback = next((o for o in option_nodes if o.attrib.get('option_id') == 'warextAiOpenRouterFallbackModels'), None)
    if fallback is None or fallback.attrib.get('edit_format') != 'textbox' or 'rows=4' not in (fallback.findtext('edit_format_params') or ''):
        fail('OpenRouter fallback listesi textbox + rows=4 olmalı')

    forum_option = next((o for o in option_nodes if o.attrib.get('option_id') == 'warextAiForums'), None)
    if forum_option is None or (forum_option.findtext('edit_format_params') or '') != r'XF\Option\Forum::renderSelectMultiple':
        fail('Forum selector callback XenForo export biçiminde değil')

    option_groups = parsed['option_groups.xml']
    if option_groups.findall('option_group'):
        fail('option_groups.xml legacy <option_group> etiketi içeriyor; XenForo export şeması <group> kullanır')
    group_nodes = option_groups.findall('group')
    groups = {g.attrib.get('group_id') for g in group_nodes}
    if 'warextAi' not in groups:
        fail('Warext AI option group kanonik <group> öğesiyle tanımlı değil')
    warext_group = next(g for g in group_nodes if g.attrib.get('group_id') == 'warextAi')
    if int(warext_group.attrib.get('display_order', '0')) <= 0:
        fail('Warext AI option group display_order geçersiz')

    admin_nav = parsed['admin_navigation.xml']
    nav_by_id = {n.attrib.get('navigation_id'): n for n in admin_nav.findall('admin_navigation_entry')}
    if not {'warextAiAdmin', 'warextAiSettings'}.issubset(nav_by_id):
        fail('ACP navigation export şeması hatalı')
    if int(nav_by_id['warextAiAdmin'].attrib.get('display_order', '0')) < 1000:
        fail('Warext AI ACP kategorisi core menülerin üstüne taşınmamalı')
    if nav_by_id['warextAiSettings'].attrib.get('parent_navigation_id') != 'warextAiAdmin':
        fail('Warext AI ayarlar girdisi kendi ACP kategorisinin altında olmalı')
    if nav_by_id['warextAiSettings'].attrib.get('link') != 'add-ons/Warext-AIContentInspector/options':
        fail('Ayarlar bağlantısı add-on options route kullanmalı')
    if nav_by_id['warextAiAdmin'].attrib.get('link') != 'warext-ai-high-risk/':
        fail('Warext AI ACP ana kategorisi yüksek risk görünümüne gitmeli')

    public_navigation = (DATA / 'navigation.xml').read_text(encoding='utf-8')
    if "$xf.visitor->hasPermission" in public_navigation:
        fail('Public navigation PHP -> sözdizimi içeriyor')

    permissions = {p.attrib.get('permission_id') for p in parsed['permissions.xml'].findall('permission')}
    required_permissions = {'warextAiViewSimple', 'warextAiViewDetailed', 'warextAiReview', 'warextAiManage'}
    if not required_permissions.issubset(permissions):
        fail('Yetki tanımları eksik')

    phrases = {p.attrib.get('title') for p in parsed['phrases.xml'].findall('phrase')}
    for title in [
        'permission_interface.warextAi', 'option_group.warextAi',
        'admin_navigation.warextAiAdmin', 'admin_navigation.warextAiSettings', 'nav.warextAi'
    ]:
        if title not in phrases:
            fail('Kanonik XenForo phrase eksik: ' + title)

    templates = parsed['templates.xml'].findall('template')
    center = next((t for t in templates if t.attrib.get('type') == 'public' and t.attrib.get('title') == 'warext_ai_center'), None)
    if center is None:
        fail('public:warext_ai_center şablonu eksik')

    for template in templates:
        item_version = int(template.attrib.get('version_id', '0'))
        if item_version <= 0 or item_version > version_id:
            fail(f"Template version_id geçersiz: {template.attrib.get('title')} -> {item_version}")

    dangerous_simple_expressions = [
        r'\{\$[^{}\n]*\?:[^{}\n]*\}',
        r'\{\$[^{}\n]*\?[^{}\n]*:[^{}\n]*\}',
    ]
    for template in templates:
        body = template.text or ''
        for pattern in dangerous_simple_expressions:
            match = re.search(pattern, body)
            if match:
                fail(f"{template.attrib.get('title')} içinde geçersiz ifadeli {{$...}} kullanımı: {match.group(0)}")

    center_body = center.text or ''
    if re.search(r'<option\b[^>]*\{\{[^>]*>', center_body, flags=re.I):
        fail('warext_ai_center raw <option> üzerinde dinamik attribute üretmemeli')
    if '<xf:select name="state" value="{$filters.state}"' not in center_body:
        fail('Durum filtresi kanonik xf:select yapısında değil')
    try:
        ET.fromstring('<root xmlns:xf="urn:xenforo">' + center_body + '</root>')
    except ET.ParseError as exc:
        fail('warext_ai_center markup dengesi bozuk: ' + str(exc))

    modifications = (DATA / 'template_modifications.xml').read_text(encoding='utf-8')
    cache_key = f'?wai={version_id}'
    if cache_key not in modifications:
        fail(f'{version} JS cache anahtarı eksik: {cache_key}')
    for marker in ['data-thread-analyze-endpoint', 'thread-controls.js', 'warext-ai-review-capability']:
        if marker not in modifications:
            fail('Konu AI kontrol arayüzü eksik: ' + marker)

    routes = parsed['routes.xml'].findall('route')
    route_map = {(r.attrib.get('route_type'), r.attrib.get('route_prefix')): r.attrib.get('controller') for r in routes}
    if route_map.get(('public', 'warext-ai')) != r'Warext\AIContentInspector:Report':
        fail('Ana Warext AI public route eksik')
    if route_map.get(('public', 'warext-ai-thread')) != r'Warext\AIContentInspector:ThreadAnalyze':
        fail('Konu analiz public route eksik')
    if route_map.get(('admin', 'warext-ai-high-risk')) != r'Warext\AIContentInspector:HighRisk':
        fail('Yüksek riskli konular ACP route eksik')

    setup = (ROOT / 'Setup.php').read_text(encoding='utf-8')
    for marker in [
        'installStep1', 'installStep2', 'upgrade1000330Step1', 'upgrade1000340Step1',
        'upgrade1000350Step1', 'ensureOptionGroup', "'xf_option_group'", "'group_id' => 'warextAi'",
        'uninstallStep1', 'xf_warext_ai_analysis', 'xf_warext_ai_review_log', 'xf_warext_ai_usage'
    ]:
        if marker not in setup:
            fail('Install/upgrade self-heal zinciri eksik: ' + marker)
    install_step1 = setup.split('public function installStep2', 1)[0]
    if '$this->ensureOptionGroup();' not in install_step1:
        fail('Temiz kurulumda option group installStep1 içinde doğrulanmalı')

    high_risk_controller = ROOT / 'Admin/Controller/HighRisk.php'
    if not high_risk_controller.exists():
        fail('Yüksek riskli konular ACP controller eksik')
    high_risk_code = high_risk_controller.read_text(encoding='utf-8')
    for marker in ["router('public')", "buildLink('warext-ai'", "'risk_min' => 70"]:
        if marker not in high_risk_code:
            fail('Yüksek riskli konular ACP controller eksik: ' + marker)

    history_job = (ROOT / 'Job/HistoricalScan.php').read_text(encoding='utf-8')
    for marker in ["'thread_ids' => []", 'p.thread_id IN', "p.message_state = 'visible'"]:
        if marker not in history_job:
            fail('Konu geçmiş tarama desteği eksik: ' + marker)

    thread_controller = ROOT / 'Pub/Controller/ThreadAnalyze.php'
    thread_controls = JS / 'thread-controls.js'
    if not thread_controller.exists() or not thread_controls.exists():
        fail('Konu AI Analizi butonu/endpoint dosyaları eksik')
    for marker in ['warextAiReview', 'enqueueUnique', 'HistoricalScan', 'thread_ids']:
        if marker not in thread_controller.read_text(encoding='utf-8'):
            fail('Konu analiz controller eksik: ' + marker)
    for marker in ['AI Analizi', 'Konuyu analiz et', 'Eksik mesajları tara', 'threadAnalyzeEndpoint']:
        if marker not in thread_controls.read_text(encoding='utf-8'):
            fail('Konu AI Analizi arayüzü eksik: ' + marker)

    post = (ROOT / 'XF/Entity/Post.php').read_text(encoding='utf-8')
    for marker in ['Registry', 'UserProfile', 'Similarity', 'enqueueUnique', 'ExternalVerify', 'existingHash', 'hash_equals']:
        if marker not in post:
            fail('Post analiz zinciri eksik: ' + marker)

    provider = ROOT / 'Provider'
    for name in [
        'ProviderInterface.php', 'LocalProvider.php', 'Registry.php', 'OpenRouterProvider.php',
        'AbstractJsonProvider.php', 'OpenAICompatibleProvider.php', 'OpenAIProvider.php',
        'GeminiProvider.php', 'DeepSeekProvider.php', 'AnthropicProvider.php'
    ]:
        if not (provider / name).exists():
            fail('Provider mimarisi eksik: ' + name)

    print(json.dumps({
        'status': 'ok',
        'version': version,
        'versionId': version_id,
        'installerMasterDataValidated': True,
        'canonicalOptionGroupSchemaValidated': True,
        'optionRelationsValidated': True,
        'templateMarkupValidated': True,
        'optionGroupSelfHealValidated': True,
        'addonOptionsRouteValidated': True,
        'highRiskAdminEntryValidated': True,
        'threadAnalysisControlsValidated': True,
        'adminNavigationOrderProtected': True,
        'externalOptional': True
    }, ensure_ascii=False))


if __name__ == '__main__':
    main()
