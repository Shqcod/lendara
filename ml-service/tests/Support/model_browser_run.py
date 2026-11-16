"""Controlled test-only activation/browser run/restore; credentials stay in process env."""
import hashlib
import json
import os
from pathlib import Path
import secrets
import socket
import subprocess
import sys
import time
import urllib.request
import urllib.error
import uuid

ROOT=Path(__file__).resolve().parents[3]
registry=ROOT/'docs/validation/ml-runtime-registry-v1.json'
run=str(uuid.uuid4());key=secrets.token_urlsafe(32)
def free_port():
    with socket.socket() as probe:
        probe.bind(('127.0.0.1',0));return probe.getsockname()[1]
port=free_port();closed_port=free_port()
env={**os.environ,'APP_ENV':'testing','ML_ENV':'testing','ML_MODE':'model',
     'INTERNAL_API_KEY':key,'ML_INTERNAL_API_KEY':key,'ML_SERVICE_URL':f'http://127.0.0.1:{port}',
     'ML_MODEL_REGISTRY':str(registry),'ML_MODEL_REGISTRY_SHA256':hashlib.sha256(registry.read_bytes()).hexdigest(),
     'ML_ARTIFACT_ROOT':str(ROOT),'MODEL_BROWSER_RUN':run,'MODEL_BROWSER_FAILURE_URL':f'http://127.0.0.1:{closed_port}',
     'PLAYWRIGHT_JSON_OUTPUT_FILE':str(ROOT/'.tools/model-browser-report.json')}
server=None;restored=False;broker=None
restricted = sys.argv[1:] == ['--runtime-grants']
account_smoke = sys.argv[1:] == ['--runtime-account-smoke']
local_preflight = sys.argv[1:] == ['--local-preflight']
if sys.argv[1:] and not (restricted or account_smoke or local_preflight):raise RuntimeError('Unknown runner option')
runtime_env=None

def restricted_env(credentials):
    return {**env, 'RUNTIME_DB_USERNAME':credentials['username'], 'RUNTIME_DB_PASSWORD':credentials['password'],
            'RUNTIME_DB_PORT':str(credentials['port']), 'RUNTIME_GRANTS_TEST':'1'}

def runtime_php_env():
    return {**runtime_env, 'DB_CONNECTION':'mysql', 'DB_HOST':'127.0.0.1', 'DB_PORT':runtime_env['RUNTIME_DB_PORT'],
            'DB_DATABASE':'kkp_pinjaman_test', 'DB_USERNAME':runtime_env['RUNTIME_DB_USERNAME'],
            'DB_PASSWORD':runtime_env['RUNTIME_DB_PASSWORD'], 'DB_URL':'', 'CACHE_STORE':'database',
            'SESSION_CONNECTION':'mysql', 'DB_QUEUE_CONNECTION':'mysql'}
def php(op):
    r=subprocess.run(['php','tests/Support/model-browser.php',op],cwd=ROOT/'backend',env=env,input='{}',capture_output=True,text=True,timeout=90)
    if r.returncode:raise RuntimeError('Guarded browser fixture failed: '+op)
    return json.loads(r.stdout)

if account_smoke:
    # Exercise the real pipe lifecycle, including Windows CRLF conversion, without rerunning Chrome.
    broker=subprocess.Popen(['php','tests/Support/runtime-account.php','--pipe'],cwd=ROOT/'backend',env=env,
                            stdin=subprocess.PIPE,stdout=subprocess.PIPE,stderr=subprocess.PIPE,text=True)
    try:
        credentials=json.loads(broker.stdout.readline())
        runtime_env=restricted_env(credentials);del credentials
        check=subprocess.run(['php','tests/Support/runtime-context.php'],cwd=ROOT/'backend',env=runtime_php_env(),capture_output=True,text=True,timeout=30)
        if check.returncode or not json.loads(check.stdout)['restricted_identity_verified']:
            raise RuntimeError('Restricted identity smoke failed')
    finally:
        broker.communicate('cleanup\n',timeout=90)
        if broker.returncode:raise RuntimeError('Temporary runtime account smoke cleanup failed')
    print(json.dumps({'restricted_identity_verified':True,'temporary_runtime_account_removed':True,'account_lifecycle_exit_code':0}),flush=True)
    sys.exit(0)
try:
    # Refuse reuse of any existing backend/frontend writer rather than altering its settings.
    for local_port in [8000,5173]:
        with socket.socket() as probe:
            if probe.connect_ex(('127.0.0.1',local_port))==0:raise RuntimeError('Browser port already in use')
    server=subprocess.Popen([sys.executable,'-m','uvicorn','app.serve:app','--host','127.0.0.1','--port',str(port)],cwd=ROOT/'ml-service',env=env,stdout=subprocess.DEVNULL,stderr=subprocess.DEVNULL)
    for _ in range(300):
        if server.poll() is not None:raise RuntimeError('Model server exited')
        try:
            req=urllib.request.Request(env['ML_SERVICE_URL']+'/ready',headers={'X-Internal-API-Key':key})
            with urllib.request.urlopen(req,timeout=5) as response:ready=json.load(response)
            break
        except urllib.error.URLError:time.sleep(.1)
    else:raise RuntimeError('Model readiness timed out')
    for _ in range(15):
        if php('expire')['processing']==0:break
        print('Waiting for existing test processing deadlines before handover',flush=True);time.sleep(10)
    else:raise RuntimeError('Existing test processing did not drain')
    print('Prepared real model:',php('prepare')['active'],flush=True)
    if local_preflight:
        checked=subprocess.run(['php','artisan','system:local-demo-readiness','--env=testing'],cwd=ROOT/'backend',
            env={**env,'CACHE_STORE':'database','SESSION_DRIVER':'database','QUEUE_CONNECTION':'database'},capture_output=True,text=True,timeout=60)
        if checked.returncode:raise RuntimeError('Real-model local preflight failed; no configuration changed')
        print(checked.stdout.strip(),flush=True)
        print(json.dumps({'real_model_preflight_exit_code':0,'browser_rerun':False}),flush=True)
    else:
        browser_env=env
        config='playwright.model.config.ts'
        report='model-browser'
        if restricted:
            broker=subprocess.Popen(['php','tests/Support/runtime-account.php','--pipe'],cwd=ROOT/'backend',env=env,
                                    stdin=subprocess.PIPE,stdout=subprocess.PIPE,stderr=subprocess.PIPE,text=True)
            credentials=json.loads(broker.stdout.readline())
            runtime_env=restricted_env(credentials);del credentials
            browser_env=runtime_env
            config='playwright.runtime.config.ts';report='runtime-browser'
            browser_env['PLAYWRIGHT_JSON_OUTPUT_FILE']=str(ROOT/'.tools/runtime-browser-report.json')
            check=subprocess.run(['php','tests/Support/runtime-context.php'],cwd=ROOT/'backend',env=runtime_php_env(),capture_output=True,text=True,timeout=30)
            if check.returncode:raise RuntimeError('Restricted identity check failed')
            print(check.stdout.strip(),flush=True)
        command=f"& './scripts/frontend.ps1' -NpmArgs @('run','test:e2e','--','--config={config}','--reporter=list,json')"
        with (ROOT/f'.tools/{report}.log').open('wb') as log:
            result=subprocess.run(['powershell','-NoProfile','-ExecutionPolicy','Bypass','-Command',command],cwd=ROOT,env=browser_env,stdout=log,stderr=subprocess.STDOUT,timeout=1200)
        if result.returncode:raise RuntimeError('Browser regression failed; inspect local report/log')
        stats=json.loads((ROOT/f'.tools/{report}-report.json').read_text(encoding='utf-8'))['stats']
        print(json.dumps({'playwright':stats,'browser_exit_code':0,'model_version':ready['default_model_version_code']}),flush=True)
        if restricted:
            check=subprocess.run(['php','tests/Support/runtime-context.php','scheduler'],cwd=ROOT/'backend',env=runtime_php_env(),capture_output=True,text=True,timeout=90)
            if check.returncode:raise RuntimeError('Restricted scheduler check failed')
            print(check.stdout.strip(),flush=True)
finally:
    if server is not None:
        server.terminate()
        try:server.wait(timeout=5)
        except subprocess.TimeoutExpired:server.kill();server.wait(timeout=5)
    try:
        restored=php('restore').get('restored',False) if (ROOT/('.tools/model-browser-'+run+'.json')).exists() else True
        print(json.dumps({'test_active_model_restored':restored,'development_database_changed':False}),flush=True)
    finally:
        if broker is not None:
            broker.communicate('cleanup\n',timeout=90)
            if broker.returncode:raise RuntimeError('Temporary runtime account cleanup failed')
            print(json.dumps({'temporary_runtime_account_removed':True}),flush=True)
