"""Loopback smoke with ephemeral credentials; never evaluates held-out datasets."""
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
    probe.bind(('127.0.0.1',0))
    port=probe.getsockname()[1]
env={**os.environ,'ML_ENV':'testing','ML_MODE':'model','INTERNAL_API_KEY':key,
     'ML_MODEL_REGISTRY':str(registry),'ML_MODEL_REGISTRY_SHA256':hashlib.sha256(registry.read_bytes()).hexdigest(),
     'ML_ARTIFACT_ROOT':str(ROOT)}
process=subprocess.Popen([sys.executable,'-m','uvicorn','app.serve:app','--host','127.0.0.1','--port',str(port)],
                         cwd=ROOT/'ml-service',env=env,stdout=subprocess.DEVNULL,stderr=subprocess.DEVNULL)


def request(path, data=None, auth=True, extra=None):
    headers={'Content-Type':'application/json',**({'X-Internal-API-Key':key} if auth else {}),**(extra or {})}
    req=urllib.request.Request(f'http://127.0.0.1:{port}'+path,headers=headers,
                               data=json.dumps(data).encode() if data is not None else None)
    try:
        with urllib.request.urlopen(req,timeout=5) as response:return response.status,json.load(response)
    except urllib.error.HTTPError as error:return error.code,json.load(error)


try:
    ready=None
    for _ in range(300):
        if process.poll() is not None:raise RuntimeError('Uvicorn exited before readiness')
        try:
            status,body=request('/ready')
            if status==200:ready=body;break
            raise RuntimeError('Model readiness refused')
        except (ConnectionError,urllib.error.URLError):time.sleep(.1)
    if ready is None:raise RuntimeError('Readiness timed out')
    data=dict(contract_version='1',feature_schema='loan-finance-v1',anomaly_check_id=73,submission_id=28,
              model_version_code=ready['default_model_version_code'],features=dict(monthly_income=8000000,
              monthly_expenses=3000000,existing_installments=1000000,loan_amount=20000000,tenor_months=12))
    status,prediction=request('/predict',data)
    assert status==200 and prediction['source']=='model'
    assert prediction['is_anomaly']==(prediction['score']<0)
    codes=dict(anonymous=request('/ready',auth=False)[0],stub_header=request('/predict',data,extra={'X-Stub-Scenario':'normal'})[0],
               missing_model=request('/predict',{**data,'model_version_code':'not-loaded'})[0])
    assert codes==dict(anonymous=401,stub_header=422,missing_model=503)
    print(json.dumps(dict(ready=ready,prediction=prediction,codes=codes,transport='real HTTP loopback',heldout_evaluated=False),indent=2))
finally:
    process.terminate()
    try:process.wait(timeout=5)
    except subprocess.TimeoutExpired:process.kill();process.wait(timeout=5)
