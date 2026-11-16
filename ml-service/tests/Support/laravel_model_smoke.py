"""Real database queue -> worker -> loopback model. Guarded testing fixtures only."""
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

ROOT=Path(__file__).resolve().parents[3]
registry=ROOT/'docs/validation/ml-runtime-registry-v1.json'
key=secrets.token_urlsafe(32)
with socket.socket() as probe:
    probe.bind(('127.0.0.1',0));port=probe.getsockname()[1]
env={**os.environ,'APP_ENV':'testing','ML_ENV':'testing','ML_MODE':'model',
     'INTERNAL_API_KEY':key,'ML_INTERNAL_API_KEY':key,'ML_SERVICE_URL':f'http://127.0.0.1:{port}',
     'ML_MODEL_REGISTRY':str(registry),'ML_MODEL_REGISTRY_SHA256':hashlib.sha256(registry.read_bytes()).hexdigest(),
     'ML_ARTIFACT_ROOT':str(ROOT)}
server=subprocess.Popen([sys.executable,'-m','uvicorn','app.serve:app','--host','127.0.0.1','--port',str(port)],
                        cwd=ROOT/'ml-service',env=env,stdout=subprocess.DEVNULL,stderr=subprocess.DEVNULL)


def php(*args):
    result=subprocess.run(['php',*args],cwd=ROOT/'backend',env=env,capture_output=True,text=True,timeout=180)
    if result.returncode:raise RuntimeError('PHP smoke step failed: '+args[0])
    return result.stdout


try:
    for _ in range(300):
        if server.poll() is not None:raise RuntimeError('Uvicorn exited')
        try:
            req=urllib.request.Request(f'http://127.0.0.1:{port}/ready',headers={'X-Internal-API-Key':key})
            with urllib.request.urlopen(req,timeout=5) as response:ready=json.load(response)
            break
        except urllib.error.URLError:time.sleep(.1)
    else:raise RuntimeError('Readiness timeout')
    php('artisan','system:assert-test-database','--env=testing')
    fixture=json.loads(php('tests/Support/model-queue-smoke.php','prepare'))
    worker=php('artisan','queue:work','--env=testing','--queue='+fixture['queue'],'--stop-when-empty',
               '--max-jobs=1','--max-time=60','--tries=1','--timeout=0')
    result=json.loads(php('tests/Support/model-queue-smoke.php','inspect',str(fixture['check_id'])))
    report=dict(fixture=fixture,result=result,ready=ready,worker_exit_code=0,database='kkp_pinjaman_test',
                active_model_replaced=False,heldout_evaluated=False)
    output=ROOT/'docs/validation/laravel-model-queue-smoke-2026-11-15.json'
    with output.open('xb') as handle:handle.write((json.dumps(report,sort_keys=True,indent=2)+'\n').encode())
    print(json.dumps(report,indent=2))
finally:
    server.terminate()
    try:server.wait(timeout=5)
    except subprocess.TimeoutExpired:server.kill();server.wait(timeout=5)
