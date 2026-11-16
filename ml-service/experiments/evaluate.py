"""Locked train/validation selection and separately gated, one-shot holdout report."""
import argparse
from collections import defaultdict
import csv
import importlib.metadata
import io
import json
from pathlib import Path
import platform
import re
import subprocess

import joblib
import numpy as np
from sklearn.metrics import average_precision_score, confusion_matrix, precision_recall_fscore_support, roc_auc_score

from app.model_pipeline import RAW_FEATURE_NAMES, build_pipeline
from experiments.generate import canonical, sha256
from experiments.generate_locked import ROOT, LOCK, protocol_digest

SEEDS = (17, 29, 43)
METRICS = ('precision', 'recall', 'f1', 'ap', 'roc_auc')


def read_verified(path, expected):
    raw = Path(path).read_bytes()
    if sha256(raw) != expected:
        raise ValueError(f'Hash tidak cocok: {Path(path).name}')
    return raw


def verify_lock():
    lock = json.loads(LOCK.read_text(encoding='utf-8'))
    protocol_digest()
    for section in ('pipeline', 'dependencies'):
        entry = lock[section]
        read_verified(ROOT / entry['path'], entry['sha256'])
    if platform.python_version() != lock['dependencies']['python_version']:
        raise ValueError('Versi Python berbeda dari lock.')
    for line in (ROOT / lock['dependencies']['path']).read_text().splitlines():
        if line and not line.startswith('#'):
            name, version = line.split('==')
            if importlib.metadata.version(name) != version:
                raise ValueError(f'Versi dependency tidak cocok: {name}')
    for path, digest in {**lock['dataset']['source_sha256'], **lock['evaluation_code']['sha256']}.items():
        read_verified(ROOT / path, digest)
        committed = subprocess.check_output(['git', 'show', lock['evaluation_code']['source_commit'] + ':' + path], cwd=ROOT)
        if sha256(committed) != digest:
            raise ValueError('Sumber belum cocok dengan commit evaluator.')
    data = lock['dataset']
    manifest_path = ROOT / data['path']
    manifest = json.loads(read_verified(manifest_path, data['sha256']))
    if manifest['protocol_sha256'] != lock['protocol']['sha256'] or manifest['training_data_version'] != data['training_data_version']:
        raise ValueError('Provenance dataset tidak cocok.')
    if manifest['source_sha256'] != data['source_sha256'] or manifest['config_sha256'] != data['config_sha256']:
        raise ValueError('Provenance kode/config tidak cocok.')
    read_verified(ROOT / data['config_path'], data['config_sha256'])
    for name, entry in manifest['files'].items():
        if Path(name).name != name:
            raise ValueError('Nama file manifest tidak aman.')
        read_verified(manifest_path.parent / name, entry['sha256'])
    return lock, manifest_path.parent, manifest


def _read_split(folder, name):
    with (folder / (name + '.csv')).open(encoding='utf-8', newline='') as handle:
        rows = list(csv.DictReader(handle))
    features = [{k: int(r[k]) if k == 'tenor_months' else float(r[k]) for k in RAW_FEATURE_NAMES} for r in rows]
    return rows, features, np.asarray([int(r['is_anomaly']) for r in rows])


def training_split(folder, name):
    if name not in ('train', 'validation'):
        raise ValueError('Holdout dilarang sebelum checkpoint selected.')
    return _read_split(folder, name)


def income_band(income):
    return 'low' if income < 6000000 else ('middle' if income < 20000000 else 'high')


def metrics(labels, decision):
    labels, decision = np.asarray(labels), np.asarray(decision)
    if len(labels) != len(decision) or not np.isfinite(decision).all():
        raise ValueError('Skor tidak valid.')
    predicted = (decision < 0).astype(int)
    p, r, f, _ = precision_recall_fscore_support(labels, predicted, average='binary', zero_division=0)
    both = len(set(labels.tolist())) == 2
    return dict(precision=float(p), recall=float(r), f1=float(f),
                ap=float(average_precision_score(labels, -decision)) if both else None,
                roc_auc=float(roc_auc_score(labels, -decision)) if both else None,
                confusion_matrix=confusion_matrix(labels, predicted, labels=[0,1]).tolist(),
                flags=int(predicted.sum()), anomaly_support=int(labels.sum()), total=len(labels),
                notes=[] if both and predicted.sum() else ['Kelas/flag kosong: periksa support; zero_division=0, ranking N/A bila satu kelas.'])


def cells(rows, decision):
    kinds = sorted({r['anomaly_kind'] for r in rows if r['is_anomaly'] in (1, '1')})
    result = []
    for kind in [None, *kinds]:
        for band in [None, 'low', 'middle', 'high']:
            selected = [(i,r) for i,r in enumerate(rows) if
                        (band is None or income_band(float(r['monthly_income'])) == band) and
                        (kind is None or r['anomaly_kind'] == kind)]
            positives = [(i,r) for i,r in selected if int(r['is_anomaly']) == 1]
            tp = sum(decision[i] < 0 for i,r in positives)
            support = len(positives)
            result.append(dict(kind=kind or 'all', band=band or 'all', tp=int(tp), fn=support-int(tp),
                               recall=float(tp/support) if support else None, anomaly_rows=support,
                               anomaly_groups=len({r['customer_group_id'] for _,r in positives}),
                               total_rows=len(selected), note='support rendah, deskriptif saja' if support < 30 else ''))
    return result


def aggregate(runs):
    return {key: dict(mean=float(np.mean([r[key] for r in runs])), std=float(np.std([r[key] for r in runs], ddof=0)))
            for key in METRICS if all(r[key] is not None for r in runs)}


def rank(candidate):
    a = candidate['aggregate']
    return (a['f1']['mean'], a['precision']['mean'], a['recall']['mean'],
            -candidate['n_estimators'], -candidate['contamination'])


def select(candidates):
    best = {v:max((c for c in candidates if c['variant'] == v), key=rank) for v in ('A','B')}
    # Cross-variant tie-break uses metrics only; exact ties favor A.
    primary = max(('A','B'), key=lambda v: tuple(best[v]['aggregate'][m]['mean'] for m in ('f1','precision','recall')))
    return best, primary


def validation(output):
    lock, folder, manifest = verify_lock()
    if lock['selection'] is not None:
        raise ValueError('Selection sudah ada; jangan ulangi diam-diam.')
    output.mkdir(parents=True, exist_ok=False)
    train_rows, train, _ = training_split(folder, 'train')
    val_rows, val, labels = training_split(folder, 'validation')
    candidates, models = [], {}
    for variant in ('A','B'):
        for estimators in (100,200):
            for contamination in (.05,.10,.15):
                runs = []
                for seed in SEEDS:
                    model = build_pipeline(variant, estimators, contamination, seed).fit(train)
                    decision = model.decision_function(val)
                    runs.append(dict(seed=seed, **metrics(labels, decision), cells=cells(val_rows, decision)))
                    models[(variant,estimators,contamination,seed)] = model
                candidates.append(dict(variant=variant,n_estimators=estimators,contamination=contamination,
                                       runs=runs,aggregate=aggregate(runs)))
                print(f'Validation selesai {variant}/{estimators}/{contamination}', flush=True)
    best, primary = select(candidates)
    artifacts = []
    for variant, config in best.items():
        for seed in SEEDS:
            path = output / f'{variant}-{seed}.joblib'
            joblib.dump(models[(variant,config['n_estimators'],config['contamination'],seed)], path)
            artifacts.append(dict(variant=variant,seed=seed,path=path.relative_to(ROOT).as_posix(),sha256=sha256(path.read_bytes())))
    report = dict(protocol_sha256=lock['protocol']['sha256'], training_data_version=manifest['training_data_version'],
                  evaluation_code=lock['evaluation_code'], candidates=candidates, primary=primary,
                  selected={v:{k:c[k] for k in ('variant','n_estimators','contamination','aggregate')} for v,c in best.items()},
                  artifacts=artifacts, fit_split='train', fit_rows=len(train_rows), holdout_opened=False,
                  seed_std_note='Std populasi tiga seed pada dataset tetap; bukan ketidakpastian sampel.')
    path = output / 'validation.json'
    path.write_bytes(canonical(report))
    return path, report


def load_verified_model(entry):
    return joblib.load(io.BytesIO(read_verified(ROOT / entry['path'], entry['sha256'])))


def holdout(output):
    lock, folder, manifest = verify_lock()
    selected = lock['selection']
    if not selected:
        raise ValueError('Belum ada selection.')
    tagged = json.loads(subprocess.check_output(['git','show','ml-config-v1-selected:ml-service/experiments/LOCK.json'],cwd=ROOT))
    if tagged != lock:
        raise ValueError('Lock berbeda dari tag selected.')
    report = json.loads(read_verified(ROOT/selected['path'],selected['sha256']))
    if report['evaluation_code'] != lock['evaluation_code'] or report['training_data_version'] != manifest['training_data_version']:
        raise ValueError('Laporan selection tidak cocok dengan lock.')
    output.mkdir(parents=True,exist_ok=False)
    # Fixed claim under dataset directory, independent of chosen output path.
    with (folder/'HOLDOUT_OPENED.json').open('xb') as handle:
        handle.write(canonical(dict(selection=selected,output=output.relative_to(ROOT).as_posix())))
    results = {}
    try:
        datasets = {name:_read_split(folder,name) for name in ('test','borderline')}
        for name,(rows,features,labels) in datasets.items():
            variants = {}
            for variant in ('A','B'):
                runs=[]
                for entry in report['artifacts']:
                    if entry['variant'] != variant:continue
                    model=load_verified_model(entry)
                    decision=model.decision_function(features)
                    runs.append(dict(seed=entry['seed'],**metrics(labels,decision),cells=cells(rows,decision)))
                variants[variant]=dict(runs=runs,aggregate=aggregate(runs))
            results[name]=variants
        result=dict(selection=selected,primary=report['primary'],results=results,
                    note='Konfigurasi tidak diubah dari validation; std hanya variasi estimator. Bootstrap tidak dilakukan.')
        (output/'holdout.json').write_bytes(canonical(result))
        return result
    except Exception as error:
        (output/'failure.json').write_bytes(canonical(dict(error_type=type(error).__name__,message=str(error))))
        raise


def main():
    parser=argparse.ArgumentParser()
    parser.add_argument('stage',choices=['validation','holdout'])
    parser.add_argument('--output',type=Path,required=True)
    args=parser.parse_args()
    output=args.output.resolve()
    if not output.is_relative_to(ROOT):raise ValueError('Output harus dalam workspace.')
    if args.stage=='validation':validation(output)
    else:holdout(output)


if __name__=='__main__':main()
