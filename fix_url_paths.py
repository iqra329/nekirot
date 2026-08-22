from pathlib import Path
import re

root = Path('.')
php_files = sorted(root.rglob('*.php'))
for path in php_files:
    if path.parts[0] == 'config' and path.name == 'config.php':
        continue
    text = path.read_text(encoding='utf-8')
    original = text
    dirname = path.parent
    # include config in every file except config itself
    rel = None
    if path.name != 'config.php':
        if path.parent == root:
            rel = "__DIR__ . '/config/config.php'"
        else:
            rel = "__DIR__ . '/../config/config.php'"
    if rel and 'config/config.php' not in text:
        if text.startswith('<?php'):
            lines = text.splitlines(True)
            if lines:
                if lines[0].strip() == '<?php':
                    lines.insert(1, f"include_once {rel};\n")
                    text = ''.join(lines)
    # Replace header redirects with BASE_URL constants
    text = re.sub(r"header\(\s*(['\"])Location:\s*/nekirot-php/", r"header(\1Location: ' . BASE_URL . '", text)
    # Replace href, action, src, link references
    text = re.sub(r'href="/nekirot-php/([^"']*)"', r'href="<?= BASE_URL ?>\1"', text)
    text = re.sub(r"href='/nekirot-php/([^"']*)'", r"href='<?= BASE_URL ?>\1'", text)
    text = re.sub(r'action="/nekirot-php/([^"']*)"', r'action="<?= BASE_URL ?>\1"', text)
    text = re.sub(r"action='/nekirot-php/([^"']*)'", r"action='<?= BASE_URL ?>\1'", text)
    text = re.sub(r'src="/nekirot-php/([^"']*)"', r'src="<?= BASE_URL ?>\1"', text)
    text = re.sub(r"src='/nekirot-php/([^"']*)'", r"src='<?= BASE_URL ?>\1'", text)
    text = re.sub(r'link href="/nekirot-php/([^"']*)"', r'link href="<?= BASE_URL ?>\1"', text)
    text = re.sub(r"link href='/nekirot-php/([^"']*)'", r"link href='<?= BASE_URL ?>\1'", text)
    # Replace direct /nekirot-php in scripts if any left
    text = re.sub(r'"/nekirot-php/([^"']*)"', r'"<?= BASE_URL ?>\1"', text)
    text = re.sub(r"'/nekirot-php/([^"']*)'", r"'<?= BASE_URL ?>\1'", text)
    # Add __DIR__ for any require/include without it if needed
    text = re.sub(r"require_once\s*['\"]([^'\"]+)['\"]", lambda m: f"require_once __DIR__ . '/{m.group(1)}'" if not m.group(1).startswith('__DIR__') and not m.group(1).startswith('/') else m.group(0), text)
    text = re.sub(r"include_once\s*['\"]([^'\"]+)['\"]", lambda m: f"include_once __DIR__ . '/{m.group(1)}'" if not m.group(1).startswith('__DIR__') and not m.group(1).startswith('/') else m.group(0), text)
    text = re.sub(r"require\s*\(\s*['\"]([^'\"]+)['\"]\s*\)", lambda m: f"require(__DIR__ . '/{m.group(1)}')" if not m.group(1).startswith('__DIR__') and not m.group(1).startswith('/') else m.group(0), text)
    text = re.sub(r"include\s*\(\s*['\"]([^'\"]+)['\"]\s*\)", lambda m: f"include(__DIR__ . '/{m.group(1)}')" if not m.group(1).startswith('__DIR__') and not m.group(1).startswith('/') else m.group(0), text)
    if text != original:
        path.write_text(text, encoding='utf-8')
        print(f'patched {path}')
