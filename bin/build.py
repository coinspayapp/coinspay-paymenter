"""Builds dist/coinspay-paymenter-<version>.zip: the CoinsPay folder, as Extensions -> Upload of
Paymenter takes it (one folder with CoinsPay.php inside), plus the license.

    python bin/build.py
"""
import os
import re
import sys
import zipfile

ROOT = os.path.dirname(os.path.dirname(os.path.abspath(__file__)))
SOURCE = os.path.join(ROOT, 'CoinsPay')


def version():
    with open(os.path.join(SOURCE, 'Lib', 'Settings.php'), encoding='utf-8') as f:
        found = re.search(r"VERSION = '([^']+)'", f.read())
    with open(os.path.join(SOURCE, 'CoinsPay.php'), encoding='utf-8') as f:
        meta = re.search(r"version: '([^']+)'", f.read())
    if not found or not meta or found.group(1) != meta.group(1):
        sys.exit('Settings::VERSION and the version of #[ExtensionMeta] differ')
    return found.group(1)


def main():
    out_dir = os.path.join(ROOT, 'dist')
    os.makedirs(out_dir, exist_ok=True)
    target = os.path.join(out_dir, 'coinspay-paymenter-%s.zip' % version())
    with zipfile.ZipFile(target, 'w', zipfile.ZIP_DEFLATED) as archive:
        for folder, dirs, files in os.walk(SOURCE):
            dirs.sort()
            for name in sorted(files):
                path = os.path.join(folder, name)
                arcname = 'CoinsPay/' + os.path.relpath(path, SOURCE).replace(os.sep, '/')
                archive.write(path, arcname)
        archive.write(os.path.join(ROOT, 'LICENSE'), 'CoinsPay/LICENSE')
    print(target)


if __name__ == '__main__':
    main()
