"""Export operator-reviewed registry from selected, hashed metadata; no training."""
import argparse
import json
from pathlib import Path
import subprocess
from experiments.generate import canonical, sha256
from experiments.generate_locked import ROOT, LOCK


def export(output):
    lock_raw = LOCK.read_bytes()
    if subprocess.check_output(['git','show','ml-config-v1-selected:ml-service/experiments/LOCK.json'],cwd=ROOT) != lock_raw:
        raise ValueError('Lock differs from selected checkpoint')
    lock = json.loads(lock_raw)
    selection = lock['selection']
    raw = (ROOT/selection['metadata_path']).read_bytes()
    if sha256(raw) != selection['metadata_sha256']:
        raise ValueError('Metadata hash mismatch')
    meta = json.loads(raw)
    artifact = (ROOT/meta['artifact_path']).read_bytes()
    if sha256(artifact) != meta['artifact_hash']:
        raise ValueError('Artifact hash mismatch')
    registry = dict(schema_version=1,default_model_version_code=meta['version_code'],
                    pipeline_sha256=lock['pipeline']['sha256'],models=[meta])
    payload = canonical(registry)
    with Path(output).open('xb') as handle:
        handle.write(payload)
    return sha256(payload)


if __name__ == '__main__':
    parser=argparse.ArgumentParser()
    parser.add_argument('--output',type=Path,required=True)
    args=parser.parse_args()
    print('ML_MODEL_REGISTRY_SHA256='+export(args.output))
