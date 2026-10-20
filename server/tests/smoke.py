"""Real HTTP integration test against a disposable SQLite database.
Usage: python3 server/tests/smoke.py [php executable] [extra PHP arguments ...]
"""
from pathlib import Path
import hashlib
import http.cookiejar
import json
import os
import re
import socket
import subprocess
import sys
import tempfile
import time
import urllib.error
import urllib.parse
import urllib.request
import xml.etree.ElementTree as ET

SERVER = Path(__file__).resolve().parents[1]
PHP = sys.argv[1:] or ['php']
PASSWORD = '71MC-local-test-password'
checks = 0

def check(value, message):
    global checks
    assert value, message
    checks += 1

with tempfile.TemporaryDirectory(prefix='71mc-test-') as directory:
    env = dict(os.environ, APP_DATABASE=str(Path(directory) / 'test.sqlite'), ADMIN_PASSWORD=PASSWORD)
    subprocess.run(PHP + [str(SERVER / 'bin/console.php'), 'install', 'admin'], env=env, check=True, capture_output=True)
    with socket.socket() as sock:
        sock.bind(('127.0.0.1', 0))
        port = sock.getsockname()[1]
    base = f'http://127.0.0.1:{port}'
    with open(Path(directory) / 'server.log', 'w+') as log:
        process = subprocess.Popen(PHP + ['-S', f'127.0.0.1:{port}', '-t', str(SERVER / 'public'), str(SERVER / 'public/router.php')], env=env, stdout=log, stderr=log)
        cookies = http.cookiejar.CookieJar()
        client = urllib.request.build_opener(urllib.request.HTTPCookieProcessor(cookies))

        def request(path, data=None):
            payload = urllib.parse.urlencode(data).encode() if data is not None else None
            try:
                with client.open(base + path, data=payload, timeout=8) as response:
                    return response.status, response.read().decode(), response.headers
            except urllib.error.HTTPError as error:
                return error.code, error.read().decode(), error.headers

        def token(html):
            return re.search(r'name="csrf" value="([a-f0-9]+)"', html).group(1)

        try:
            for attempt in range(50):
                try:
                    if request('/api/v1/health')[0] == 200: break
                except OSError: pass
                time.sleep(0.1)
            else: raise RuntimeError('PHP did not start')
            status, body, headers = request('/api/v1/releases/latest?current_code=10000')
            check(status == 200 and json.loads(body)['latest'] is None, 'fresh install has no fake download')
            check(request('/notice.cfg')[1] == '1|10000|10000|1|1', 'legacy baseline')
            check(request('/ServerConfig.json')[0] == 200, 'legacy config')
            check(request('/minecraft/download.json')[1] == '[]', 'game supplement array')
            check(request('/api/v1/telemetry')[0] == 204, 'telemetry compatibility')
            check(request('/api/v1/releases/latest?current_code=bad')[0] == 400, 'invalid version code')
            check(request('/api/v1/releases/latest', {'anything': 'x'})[0] == 405, 'public APIs read only')
            for path in ('/storage/71mc.sqlite', '/config/local.php', '/bin/console.php', '/app/bootstrap.php'):
                check(request(path)[0] == 404, 'private file unavailable: ' + path)
            status, body, headers = request('/admin', {'action': 'release'})
            check(status == 403, 'CSRF required: ' + str(status) + ' ' + body[:2000])
            check('httponly' in str(headers).lower() and 'samesite=strict' in str(headers).lower(), 'session cookie flags')
            status, body, headers = request('/admin')
            csrf = token(body)
            _, body, _ = request('/admin', {'action': 'login', 'csrf': csrf, 'username': 'admin', 'password': 'wrong'})
            check('用户名或密码错误' in body, 'invalid login rejected')
            status, body, _ = request('/admin', {'action': 'login', 'csrf': token(body), 'username': 'admin', 'password': PASSWORD})
            check(status == 200 and '发布中心' in body, 'admin login')
            csrf = token(body)
            digest = hashlib.sha256(b'test build').hexdigest()
            release = dict(action='release', csrf=csrf, version='1.0.1', version_code='10001', download_url='https://example.com/71MC.zip?file=1', sha256=digest, notes='<script>alert(1)</script>\n更新说明', published='1')
            _, body, _ = request('/admin', dict(release, download_url='http://example.com/bad.zip'))
            check('下载链接必须为 HTTPS' in body, 'HTTP update rejected')
            _, body, _ = request('/admin', dict(release, sha256='bad'))
            check('64 位 SHA-256' in body, 'missing checksum rejected')
            status, body, _ = request('/admin', release)
            check(status == 200 and '版本已保存' in body, 'release published')
            check('<script>alert(1)</script>' not in body, 'HTML escaped')
            public = json.loads(request('/api/v1/releases/latest?current_code=10000')[1])
            check(public['update_available'] and public['latest']['sha256'] == digest, 'exact release and hash')
            check(not json.loads(request('/api/v1/releases/latest?current_code=10001')[1])['update_available'], 'equal no update')
            check(not json.loads(request('/api/v1/releases/latest?current_code=10002')[1])['update_available'], 'no downgrade')
            check('2|10001|10001|1|2' == request('/notice.cfg')[1], 'legacy versions')
            compat = json.loads(request('/ServerConfig.json')[1])
            check(compat['Update']['Release']['Sha256'] == digest, 'legacy checksum')
            notices = json.loads(request('/notice.json')[1])
            check(notices[-1]['buttons'][0]['actions'] == {'update': True}, 'update action')
            check(notices[-1]['requirements']['versioncode <='] == 10000, 'older clients targeted')
            draft = dict(release, version='1.0.2', version_code='10002')
            draft.pop('published')
            request('/admin', draft)
            check(json.loads(request('/api/v1/releases/latest')[1])['latest']['version_code'] == 10001, 'draft hidden')
            request('/admin', dict(release, id='1', download_url='https://example.com/updated.zip'))
            check(json.loads(request('/api/v1/releases/latest')[1])['latest']['download_url'].endswith('/updated.zip'), 'download link edit')
            _, body, _ = request('/admin', {'action': 'notice', 'csrf': csrf, 'title': '新公告', 'body': '公告 <& " 内容', 'level': '1', 'published': '1'})
            check('新公告' in body, 'notice saved')
            check(any(n['title'] == '新公告' for n in json.loads(request('/notice.json')[1])), 'legacy notice')
            request('/admin', {'action': 'settings', 'csrf': csrf, 'site_title': '71MC <测试>', 'homepage_text': '内容 <& "\n换行'})
            xaml = request('/homepage.xaml')[1]
            ET.fromstring(xaml)
            check('71MC &lt;测试&gt;' in xaml, 'XAML escaped')
            _, body, _ = request('/admin', {'action': 'password', 'csrf': csrf, 'old_password': PASSWORD, 'new_password': PASSWORD + '2', 'confirm_password': PASSWORD + '2'})
            check('密码已修改' in body, 'password update')
            request('/admin', {'action': 'logout', 'csrf': token(body)})
            check('登录后台' in request('/admin')[1], 'logout')
            for _ in range(8):
                _, body, _ = request('/admin')
                request('/admin', {'action': 'login', 'csrf': token(body), 'username': 'admin', 'password': 'wrong'})
            _, body, _ = request('/admin')
            _, body, _ = request('/admin', {'action': 'login', 'csrf': token(body), 'username': 'admin', 'password': PASSWORD + '2'})
            check('尝试过于频繁' in body, 'login rate limiting')
            print(f'PHP HTTP integration checks passed: {checks}')
        except Exception:
            log.flush()
            log.seek(0)
            print(log.read(), file=sys.stderr)
            raise
        finally:
            process.terminate()
            process.wait(timeout=10)
