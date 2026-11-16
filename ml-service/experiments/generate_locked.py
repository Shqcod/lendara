"""Locked-protocol dataset including a separate, unevaluated borderline set."""
import argparse
from collections import Counter
from decimal import Decimal
import json
from pathlib import Path
import platform
import subprocess

from experiments import generate as legacy

ROOT = Path(__file__).resolve().parents[2]
KINDS = ('borderline_expense_pressure', 'borderline_installment_pressure', 'borderline_loan_mismatch')
CONFIG = ROOT / 'ml-service/experiments/generator-v1.locked.json'
LOCK = ROOT / 'ml-service/experiments/LOCK.json'


def protocol_digest():
    lock = json.loads(LOCK.read_text(encoding='utf-8'))['protocol']
    blob = subprocess.check_output(['git', 'show', lock['commit'] + ':' + lock['path']], cwd=ROOT)
    if legacy.sha256(blob) != lock['sha256'] or (ROOT / lock['path']).read_bytes() != blob:
        raise ValueError('Hash protokol committed/working tree tidak cocok.')
    return lock['sha256']


def validate(spec):
    if set(spec) != {'status', 'generator_version', 'protocol_sha256', 'main', 'borderline'}:
        raise ValueError('Field konfigurasi revisi2 tidak sesuai.')
    if spec['status'] != 'locked' or spec['generator_version'] != 'synthetic-finance-v1-r2':
        raise ValueError('Konfigurasi bukan revisi terkunci.')
    if spec['protocol_sha256'] != protocol_digest():
        raise ValueError('Konfigurasi tidak terikat protokol terkunci.')
    legacy.validate_config(spec['main'])
    expected_main = dict(status='draft', generator_version='synthetic-finance-v1', groups=5000,
                         rows_per_group=2, data_seed=20261115, split_seed=20261116,
                         injection_seed=20261117, split_percent=dict(train=70, validation=15, test=15), anomaly_percent=10)
    expected_border = dict(groups=1000, rows_per_group=2, anomaly_percent=10, data_seed=20261118,
                           injection_seed=20261119, row_seed=20261120)
    # Canonical bytes also distinguish bool/float from the approved integer values.
    if legacy.canonical(spec['main']) != legacy.canonical(expected_main) or legacy.canonical(spec['borderline']) != legacy.canonical(expected_border):
        raise ValueError('Parameter berbeda dari protokol yang disetujui.')


def audit(dataset, spec):
    summary = legacy.audit({s: dataset[s] for s in legacy.SPLITS}, spec['main'])
    signatures, ids, groups = set(), set(), set()
    for split, rows in dataset.items():
        current_groups = set()
        for row in rows:
            signature = tuple(row[k] for k in legacy.RAW_FEATURE_NAMES)
            if signature in signatures or row['row_id'] in ids:
                raise ValueError('Duplikat global pada utama/borderline.')
            signatures.add(signature)
            ids.add(row['row_id'])
            current_groups.add(row['customer_group_id'])
        if groups & current_groups:
            raise ValueError('Kelompok bocor antara utama/borderline.')
        groups.update(current_groups)
    rows = dataset['borderline']
    counts = Counter(r['anomaly_kind'] for r in rows)
    if counts != Counter(dict(normal=1800, borderline_expense_pressure=68,
                               borderline_installment_pressure=66, borderline_loan_mismatch=66)):
        raise ValueError('Komposisi borderline tidak sesuai.')
    grouped = {}
    for row in rows:
        if set(row) != set(legacy.COLUMNS) or not row['customer_group_id'].startswith('borderline-20261118-'):
            raise ValueError('Provenance borderline tidak sesuai.')
        group = grouped.setdefault(row['customer_group_id'], [])
        group.append(row)
        if row['is_anomaly'] != int(row['anomaly_kind'] != 'normal'):
            raise ValueError('Label borderline tidak sesuai.')
        income, expense, installment, loan = (Decimal(row[k]) for k in legacy.RAW_FEATURE_NAMES[:-1])
        for name in legacy.RAW_FEATURE_NAMES[:-1]:
            if legacy.cents_text(int(Decimal(row[name]) * 100)) != row[name]:
                raise ValueError('Format sen tidak sesuai.')
        if not Decimal(3000000) <= income <= Decimal(50000000) or not legacy.integer(row['tenor_months'], 1, 60):
            raise ValueError('Pendapatan/tenor borderline tidak sesuai.')
        kind = row['anomaly_kind']
        bounds = {'normal': ((Decimal('.35'), Decimal('.75')), (0, Decimal('.25')), (2, 18)),
                  KINDS[0]: ((Decimal('.8'), 1), (0, Decimal('.25')), (2, 18)),
                  KINDS[1]: ((Decimal('.35'), Decimal('.75')), (Decimal('.3'), Decimal('.5')), (2, 18)),
                  KINDS[2]: ((Decimal('.35'), Decimal('.75')), (0, Decimal('.25')), (19, 30))}[kind]
        for value, (low, high) in zip((expense, installment, loan), bounds):
            # Ordinary normal lower bound can lose <1 cent due to floor rounding.
            tolerance = Decimal('.009999') if kind == 'normal' or low == Decimal('.35') else Decimal(0)
            if value + tolerance < income * low or value > income * high:
                raise ValueError('Batas rasio final setelah pembulatan tidak sesuai.')
    if len(grouped) != 1000 or any(len(v) != 2 or len({r['anomaly_kind'] for r in v}) != 1 or
                                 {r['row_id'] for r in v} != {f'{k}-01', f'{k}-02'} for k, v in grouped.items()):
        raise ValueError('Variasi kelompok borderline tidak sesuai.')
    summary['borderline'] = dict(groups=1000, rows=2000, normal=1800, anomaly=200, anomaly_kinds=dict(sorted(counts.items())))
    return summary


def generate(spec):
    validate(spec)
    dataset = legacy.generate(spec['main'])
    config = spec['borderline']
    groups = list(range(1, config['groups'] + 1))
    order = groups.copy()
    legacy.rng_for(config['injection_seed'], 'borderline').shuffle(order)
    kinds = {group: KINDS[i % 3] for i, group in enumerate(order[:100])}
    rows = [r for group in groups for r in legacy.group_rows(config, group, kinds.get(group, 'normal'), namespace='borderline')]
    legacy.rng_for(config['row_seed'], 'borderline:rows').shuffle(rows)
    dataset['borderline'] = rows
    audit(dataset, spec)
    return dataset


def write_dataset(spec, output):
    dataset = generate(spec)
    sources = {p: legacy.sha256((ROOT / p).read_bytes()) for p in
               ('ml-service/experiments/generate.py', 'ml-service/experiments/generate_locked.py')}
    provenance = dict(protocol_sha256=spec['protocol_sha256'], config_sha256=legacy.sha256(legacy.canonical(spec)),
                      source_sha256=sources, python_version=platform.python_version())
    manifest = dict(**provenance, status='locked', trained=False, evaluated=False,
                    training_data_version='synthetic-finance-v1-r2-' + legacy.sha256(legacy.canonical(provenance))[:16],
                    raw_input_names=list(legacy.RAW_FEATURE_NAMES), feature_schema='loan-finance-v1',
                    split_summary=audit(dataset, spec), files={})
    payloads = {'config.json': legacy.canonical(spec), **{s + '.csv': legacy.csv_bytes(rows) for s, rows in dataset.items()}}
    for name, raw in payloads.items():
        manifest['files'][name] = dict(sha256=legacy.sha256(raw), bytes=len(raw))
    output = Path(output)
    output.mkdir(parents=True, exist_ok=False)
    for name, raw in {**payloads, 'manifest.json': legacy.canonical(manifest)}.items():
        with (output / name).open('xb') as handle:
            handle.write(raw)
    return manifest


def main():
    parser = argparse.ArgumentParser(description='Generator revisi terkunci, tanpa evaluasi holdout.')
    parser.add_argument('--output', required=True, type=Path)
    args = parser.parse_args()
    manifest = write_dataset(json.loads(CONFIG.read_text(encoding='utf-8')), args.output)
    print(json.dumps(manifest, indent=2))


if __name__ == '__main__':
    main()
