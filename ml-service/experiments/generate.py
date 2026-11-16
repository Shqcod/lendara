"""Generate a review dataset, without fitting a model or connecting to a DB.

Run from ml-service: python -m experiments.generate --config ... --output ...
Distributions are versioned code, described in docs/ML_EXPERIMENT_PLAN.md.
"""
import argparse
from collections import Counter
import csv
from decimal import Decimal
import hashlib
import io
import json
from pathlib import Path
import platform
import random
import re

RAW_FEATURE_NAMES = (
    'monthly_income', 'monthly_expenses', 'existing_installments',
    'loan_amount', 'tenor_months',
)
VERSION = 'synthetic-finance-v1'
SPLITS = ('train', 'validation', 'test')
ANOMALY_KINDS = (
    'expense_pressure', 'installment_pressure', 'loan_mismatch',
    'low_expense_large_loan',
)
COLUMNS = ('row_id', 'customer_group_id', *RAW_FEATURE_NAMES, 'is_anomaly', 'anomaly_kind')
CONFIG_KEYS = {
    'status', 'generator_version', 'groups', 'rows_per_group', 'data_seed',
    'split_seed', 'injection_seed', 'split_percent', 'anomaly_percent',
}


def integer(value, low, high):
    return type(value) is int and low <= value <= high


def validate_config(config):
    if not isinstance(config, dict) or set(config) != CONFIG_KEYS:
        raise ValueError('Konfigurasi harus memiliki tepat field generator v1.')
    if config['status'] != 'draft' or config['generator_version'] != VERSION:
        raise ValueError('Generator ini hanya menyiapkan rancangan v1, belum dikunci untuk training.')
    for key, low, high in [('groups', 400, 20000), ('rows_per_group', 2, 5),
                           ('anomaly_percent', 1, 50), ('data_seed', 0, 2**32 - 1),
                           ('split_seed', 0, 2**32 - 1), ('injection_seed', 0, 2**32 - 1)]:
        if not integer(config[key], low, high):
            raise ValueError(f'{key} harus integer dalam rentang {low} sampai {high}.')
    ratios = config['split_percent']
    if not isinstance(ratios, dict) or set(ratios) != set(SPLITS):
        raise ValueError('Split harus tepat train, validation, test.')
    if not all(integer(v, 1, 98) for v in ratios.values()) or sum(ratios.values()) != 100:
        raise ValueError('Persentase split harus integer positif dan berjumlah 100.')
    for split in SPLITS:
        if config['groups'] * ratios[split] % 100:
            raise ValueError('Jumlah kelompok per split harus bulat tanpa pembulatan.')
        groups = config['groups'] * ratios[split] // 100
        if groups * config['anomaly_percent'] % 100:
            raise ValueError('Jumlah kelompok anomali per split harus bulat.')
        if groups * config['anomaly_percent'] // 100 < len(ANOMALY_KINDS):
            raise ValueError('Setiap split harus memuat keempat jenis anomali.')
    return config


def canonical(value):
    return (json.dumps(value, sort_keys=True, indent=2, ensure_ascii=True, allow_nan=False) + '\n').encode()


def sha256(value):
    return hashlib.sha256(value).hexdigest()


def rng_for(seed, namespace):
    return random.Random(int(sha256(f'{seed}:{namespace}'.encode()), 16))


def cents_text(cents):
    return f'{cents // 100}.{cents % 100:02d}'


def group_rows(config, group, kind, namespace='syn'):
    rng = rng_for(config['data_seed'], group)
    band = rng.randrange(100)
    low, high = (3000, 6000) if band < 20 else ((6000, 20000) if band < 90 else (20000, 50000))
    base_income = rng.randint(low, high) * 100000  # thousand rupiah -> cents
    expense_bps = rng.randint(3500, 7500)
    installment_bps = rng.randint(0, 2500)
    loan_bps = rng.randint(20000, 180000)
    customer = f'{namespace}-{config["data_seed"]}-{group:06d}'
    for variant in range(config['rows_per_group']):
        income = min(5000000000, max(300000000, base_income * rng.randint(9500, 10500) // 10000))
        expense_ratio = min(7500, max(3500, expense_bps * rng.randint(9500, 10500) // 10000))
        installment_ratio = min(2500, installment_bps * rng.randint(9500, 10500) // 10000)
        loan_ratio = min(180000, max(20000, loan_bps * rng.randint(9500, 10500) // 10000))
        if kind == 'expense_pressure':
            expense_ratio = rng.randint(14000, 24000)
        elif kind == 'installment_pressure':
            installment_ratio = rng.randint(9000, 18000)
        elif kind == 'loan_mismatch':
            loan_ratio = rng.randint(600000, 1000000)
        elif kind == 'low_expense_large_loan':
            expense_ratio, loan_ratio = rng.randint(100, 500), rng.randint(240000, 400000)
        elif kind == 'borderline_expense_pressure':
            expense_ratio = rng.randint(8000, 10000)
        elif kind == 'borderline_installment_pressure':
            installment_ratio = rng.randint(3000, 5000)
        elif kind == 'borderline_loan_mismatch':
            loan_ratio = rng.randint(190000, 300000)
        expenses = income * expense_ratio // 10000
        installments = income * installment_ratio // 10000
        loan = income * loan_ratio // 10000
        # Enforce inclusive minimum on final cents, not only the sampled ratio.
        if kind == 'borderline_expense_pressure':
            expenses = max(expenses, (income * 8000 + 9999) // 10000)
        elif kind == 'borderline_installment_pressure':
            installments = max(installments, (income * 3000 + 9999) // 10000)
        tenors = (36, 48, 60) if loan_ratio > 120000 else ((18, 24, 36) if loan_ratio > 60000 else (6, 12, 18))
        yield dict(zip(COLUMNS, (
            f'{customer}-{variant + 1:02d}', customer,
            cents_text(income), cents_text(expenses),
            cents_text(installments), cents_text(loan), rng.choice(tenors),
            int(kind != 'normal'), kind,
        )))


def generate(config):
    validate_config(config)
    group_ids = list(range(1, config['groups'] + 1))
    rng_for(config['split_seed'], 'split').shuffle(group_ids)
    result, offset = {}, 0
    for split in SPLITS:
        size = config['groups'] * config['split_percent'][split] // 100
        selected = group_ids[offset:offset + size]
        offset += size
        injection_order = selected.copy()
        rng_for(config['injection_seed'], split).shuffle(injection_order)
        anomaly_count = size * config['anomaly_percent'] // 100
        kinds = {group: ANOMALY_KINDS[i % len(ANOMALY_KINDS)] for i, group in enumerate(injection_order[:anomaly_count])}
        rows = [row for group in selected for row in group_rows(config, group, kinds.get(group, 'normal'))]
        rng_for(config['split_seed'], f'{split}:rows').shuffle(rows)
        result[split] = rows
    audit(result, config)
    return result


def audit(dataset, config):
    """Reject group leakage, duplicate financial vectors and invalid provenance."""
    validate_config(config)
    if set(dataset) != set(SPLITS):
        raise ValueError('Dataset harus memiliki tiga split.')
    seen_groups, signatures, row_ids, summary = set(), set(), set(), {}
    for split in SPLITS:
        rows = dataset[split]
        groups = Counter(row['customer_group_id'] for row in rows)
        expected_groups = config['groups'] * config['split_percent'][split] // 100
        if len(groups) != expected_groups or set(groups.values()) != {config['rows_per_group']}:
            raise ValueError('Jumlah kelompok atau variasi per kelompok tidak sesuai.')
        if seen_groups.intersection(groups):
            raise ValueError('Kelompok pelanggan bocor antar split.')
        seen_groups.update(groups)
        group_kinds = {}
        for row in rows:
            if set(row) != set(COLUMNS):
                raise ValueError('Kolom dataset tidak sesuai kontrak.')
            for name in RAW_FEATURE_NAMES[:-1]:
                value = row[name]
                if not isinstance(value, str) or not re.fullmatch(r'(0|[1-9][0-9]{0,15})\.[0-9]{2}', value):
                    raise ValueError('Uang harus string DECIMAL(18,2) kanonik.')
                if name in ('monthly_income', 'loan_amount') and Decimal(value) <= 0:
                    raise ValueError('Pendapatan dan pinjaman harus positif.')
            if not integer(row['tenor_months'], 1, 2**32 - 1):
                raise ValueError('Tenor harus integer positif UINT32.')
            if row['row_id'] not in {f'{row["customer_group_id"]}-{i + 1:02d}' for i in range(config['rows_per_group'])}:
                raise ValueError('ID variasi tidak sesuai kelompok pelanggan.')
            signature = tuple(row[name] for name in RAW_FEATURE_NAMES)
            if signature in signatures or row['row_id'] in row_ids:
                raise ValueError('Duplikat baris atau vektor keuangan ditemukan.')
            signatures.add(signature)
            row_ids.add(row['row_id'])
            kind = row['anomaly_kind']
            if kind not in ('normal', *ANOMALY_KINDS) or row['is_anomaly'] != int(kind != 'normal'):
                raise ValueError('Label atau jenis injeksi tidak konsisten.')
            previous = group_kinds.setdefault(row['customer_group_id'], kind)
            if previous != kind:
                raise ValueError('Variasi satu pelanggan harus memiliki jenis injeksi yang sama.')
        kinds = Counter(row['anomaly_kind'] for row in rows)
        anomalies = sum(row['is_anomaly'] for row in rows)
        if anomalies * 100 != len(rows) * config['anomaly_percent'] or any(kinds[k] == 0 for k in ANOMALY_KINDS):
            raise ValueError('Komposisi label tidak sesuai konfigurasi.')
        summary[split] = {'groups': len(groups), 'rows': len(rows), 'normal': len(rows) - anomalies,
                          'anomaly': anomalies, 'anomaly_kinds': dict(sorted(kinds.items()))}
    return summary


def csv_bytes(rows):
    stream = io.StringIO(newline='')
    writer = csv.DictWriter(stream, fieldnames=COLUMNS, lineterminator='\n')
    writer.writeheader()
    writer.writerows(rows)
    return stream.getvalue().encode('utf-8')


def write_dataset(config, output):
    dataset = generate(config)
    summary = audit(dataset, config)
    root = Path(__file__).resolve().parents[1]
    sources = {name: sha256((root / name).read_bytes()) for name in ('experiments/generate.py',)}
    config_bytes = canonical(config)
    provenance = {'config_sha256': sha256(config_bytes), 'source_sha256': sources,
                  'python_version': platform.python_version(), 'generator_version': VERSION}
    version = VERSION + '-' + sha256(canonical(provenance))[:16]
    manifest = {**provenance, 'training_data_version': version, 'status': 'draft', 'trained': False,
                'feature_schema': 'loan-finance-v1', 'raw_input_names': list(RAW_FEATURE_NAMES),
                'excluded_from_features': ['row_id', 'customer_group_id', 'is_anomaly', 'anomaly_kind'],
                'split_summary': summary, 'files': {}}
    payloads = {'config.json': config_bytes, **{f'{split}.csv': csv_bytes(dataset[split]) for split in SPLITS}}
    for name, data in payloads.items():
        manifest['files'][name] = {'sha256': sha256(data), 'bytes': len(data)}
    # Exclusive directory and files: existing outputs are never overwritten or deleted.
    output = Path(output)
    output.mkdir(parents=True, exist_ok=False)
    for name, data in payloads.items():
        with (output / name).open('xb') as handle:
            handle.write(data)
    # Written last; absence means an incomplete output, not a usable dataset.
    with (output / 'manifest.json').open('xb') as handle:
        handle.write(canonical(manifest))
    return manifest


def main():
    parser = argparse.ArgumentParser(description='Siapkan data sintetis rancangan; tidak melatih model.')
    parser.add_argument('--config', type=Path, required=True)
    parser.add_argument('--output', type=Path, required=True)
    args = parser.parse_args()
    try:
        config = json.loads(args.config.read_text(encoding='utf-8'))
        manifest = write_dataset(config, args.output)
    except (OSError, ValueError) as error:
        parser.exit(2, f'Gagal membuat dataset: {error}\n')
    print(json.dumps({'training_data_version': manifest['training_data_version'],
                      'status': manifest['status'], 'split_summary': manifest['split_summary']}, indent=2))


if __name__ == '__main__':
    main()
