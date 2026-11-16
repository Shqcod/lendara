"""Smoke the Windows launcher with a process-only key; never log credentials."""
import hashlib
import json
import os
from pathlib import Path
import secrets
import socket
import subprocess
import time
import urllib.error
import urllib.request

ROOT = Path(__file__).resolve().parents[3]
REGISTRY = ROOT / "docs/validation/ml-runtime-registry-v1.json"
PIN = "d0a0de0d02838762ca1bbef501b32e93d56ecd2d067c7f1d0b23926404fe789c"
if os.name != "nt" or hashlib.sha256(REGISTRY.read_bytes()).hexdigest() != PIN:
    raise RuntimeError("Windows and reviewed registry pin required")
with socket.socket() as probe:
    if probe.connect_ex(("127.0.0.1", 8001)) == 0:
        raise RuntimeError("Refusing reuse of an existing model service")
key = secrets.token_urlsafe(32)
environment = {**os.environ, "INTERNAL_API_KEY": key}
process = subprocess.Popen(
    ["powershell", "-NoProfile", "-ExecutionPolicy", "Bypass", "-File",
     str(ROOT / "scripts/start-local-model.ps1"), "-RegistrySha256", PIN],
    cwd=ROOT, env=environment, stdout=subprocess.DEVNULL, stderr=subprocess.DEVNULL,
    creationflags=subprocess.CREATE_NO_WINDOW,
)
try:
    for _ in range(300):
        if process.poll() is not None:
            raise RuntimeError("Owned launcher exited before readiness")
        try:
            request = urllib.request.Request("http://127.0.0.1:8001/ready", headers={"X-Internal-API-Key": key})
            with urllib.request.urlopen(request, timeout=3) as response:
                ready = json.load(response)
            break
        except urllib.error.URLError:
            time.sleep(0.1)
    else:
        raise RuntimeError("Launcher readiness timed out")
    if ready.get("mode") != "model" or ready.get("ready") is not True:
        raise RuntimeError("Launcher did not enforce real model mode")
    try:
        urllib.request.urlopen("http://127.0.0.1:8001/ready", timeout=3)
    except urllib.error.HTTPError as error:
        if error.code != 401:
            raise
    else:
        raise RuntimeError("Anonymous readiness unexpectedly allowed")
    print(json.dumps({"launcher_ready": True, "mode": "model", "anonymous_status": 401,
                      "environment_files_written": False, "registry_pin_verified": True}), flush=True)
finally:
    if process.poll() is None:
        # Only the exact process tree created by this invocation, never a name-wide kill.
        stopped = subprocess.run(["taskkill", "/PID", str(process.pid), "/T", "/F"], capture_output=True, timeout=15)
        if stopped.returncode and process.poll() is None:
            raise RuntimeError("Owned launcher tree could not be stopped")
        process.wait(timeout=10)
    with socket.socket() as probe:
        if probe.connect_ex(("127.0.0.1", 8001)) == 0:
            raise RuntimeError("Owned launcher port was not released")
    print(json.dumps({"owned_launcher_stopped": True}), flush=True)
