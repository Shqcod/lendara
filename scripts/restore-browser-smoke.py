"""Chrome workflow on an existing, verified restore; retains synthetic fixtures."""

import argparse
import json
import os
from pathlib import Path
import socket
import subprocess
import time
import urllib.error
import urllib.request
import uuid


def main():
    parser = argparse.ArgumentParser(description=__doc__)
    parser.add_argument('--restore-id', required=True, type=uuid.UUID)
    args = parser.parse_args()
    if os.name != 'nt':
        raise RuntimeError('Windows DPAPI required')
    root = Path(__file__).resolve().parents[1]
    ports = (8800, 8801, 5174)
    for port in ports:
        with socket.socket() as sock:
            if sock.connect_ex(('127.0.0.1', port)) == 0:
                raise RuntimeError('Restore port occupied; existing services will not be reused')
    # Private local value stays in process memory and ignored service logs.
    key = None
    for line in (root / 'backend/.env').read_text(encoding='utf-8-sig').splitlines():
        if line.startswith('ML_INTERNAL_API_KEY='):
            key = line.split('=', 1)[1].strip().strip('"').strip("'")
    if not key:
        raise RuntimeError('Local model key unavailable')
    env = {**os.environ, 'INTERNAL_API_KEY': key, 'ML_INTERNAL_API_KEY': key}
    runtimes = sorted((root / '.tools').glob('node-v22*-win-x64'),
                      key=lambda p: tuple(int(n) for n in p.name[6:-8].split('.')), reverse=True)
    if not runtimes:
        raise RuntimeError('Portable Node22 required')
    node = runtimes[0] / 'node.exe'
    logs = root / '.tools' / ('restore-browser-' + str(uuid.uuid4()))
    logs.mkdir()
    processes, handles = [], []
    base = ['powershell', '-NoProfile', '-ExecutionPolicy', 'Bypass', '-File',
            str(root / 'scripts/restore-demo.ps1'), '-RestoreId', str(args.restore_id)]

    def start(action):
        handle = (logs / (action.lower() + '.log')).open('wb')
        handles.append(handle)
        process = subprocess.Popen([*base, '-Action', action], cwd=root, env=env,
                                   stdout=handle, stderr=subprocess.STDOUT,
                                   creationflags=subprocess.CREATE_NO_WINDOW)
        processes.append(process)
        return process

    def ready(process, url, headers=None):
        deadline = time.monotonic() + 120
        while time.monotonic() < deadline:
            if process.poll() is not None:
                raise RuntimeError('Owned restore service exited; inspect ignored local logs')
            try:
                request = urllib.request.Request(url, headers=headers or {})
                with urllib.request.urlopen(request, timeout=3) as response:
                    if response.status == 200:
                        return
            except (urllib.error.URLError, TimeoutError, ConnectionResetError):
                pass
            time.sleep(.2)
        raise RuntimeError('Restore startup deadline exceeded')

    try:
        model = start('Model')
        ready(model, 'http://127.0.0.1:8801/ready', {'X-Internal-API-Key': key})
        backend = start('Backend')
        ready(backend, 'http://127.0.0.1:8800/up')
        frontend = start('Frontend')
        ready(frontend, 'http://127.0.0.1:5174')
        browser_env = {**os.environ, 'RESTORE_BROWSER_TEST': '1', 'RESTORE_WORKSPACE_ID': str(args.restore_id)}
        for name in ('DB_USERNAME', 'DB_PASSWORD', 'DB_URL', 'APP_KEY', 'INTERNAL_API_KEY', 'ML_INTERNAL_API_KEY'):
            browser_env.pop(name, None)
        result = subprocess.run([str(node), 'node_modules/@playwright/test/cli.js', 'test',
                                 '--config', 'playwright.restore.config.ts'],
                                cwd=root / 'frontend', env=browser_env, timeout=480)
        if result.returncode:
            raise RuntimeError('Restore browser smoke failed')
        print(json.dumps({'restore_browser_smoke_passed': True, 'existing_services_reused': False}))
    finally:
        for process in reversed(processes):
            if process.poll() is None:
                subprocess.run(['taskkill', '/PID', str(process.pid), '/T', '/F'],
                               capture_output=True, timeout=20)
                process.wait(timeout=15)
        for handle in handles:
            handle.close()
        print(json.dumps({'owned_restore_services_stopped': all(p.poll() is not None for p in processes)}))


if __name__ == '__main__':
    main()
