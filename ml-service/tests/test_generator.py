"""Dataset invariants, reproducibility and service-input compatibility, not ML accuracy."""
import copy
import csv
from decimal import Decimal
import hashlib
import json
from pathlib import Path
import subprocess
import sys
import tempfile
import unittest

from app.main import Features, RAW_FEATURE_NAMES as SERVICE_RAW_FEATURE_NAMES
from experiments.generate import COLUMNS, RAW_FEATURE_NAMES, audit, generate, validate_config, write_dataset


ROOT = Path(__file__).resolve().parents[1]
CONFIG = ROOT / 'experiments/generator-v1.draft.json'


class GeneratorTests(unittest.TestCase):
    def config(self):
        config = json.loads(CONFIG.read_text(encoding='utf-8'))
        config['groups'] = 400
        return config

    def test_full_draft_counts_group_separation_no_duplicates_and_contract(self):
        self.assertEqual(RAW_FEATURE_NAMES, SERVICE_RAW_FEATURE_NAMES)
        config = json.loads(CONFIG.read_text(encoding='utf-8'))
        dataset = generate(config)
        summary = audit(dataset, config)
        self.assertEqual([summary[s]['rows'] for s in ('train', 'validation', 'test')], [7000, 1500, 1500])
        self.assertEqual([summary[s]['anomaly'] for s in ('train', 'validation', 'test')], [700, 150, 150])
        groups = [set(row['customer_group_id'] for row in rows) for rows in dataset.values()]
        self.assertFalse(groups[0] & groups[1] or groups[0] & groups[2] or groups[1] & groups[2])
        self.assertEqual(len(set.union(*groups)), 5000)
        for rows in dataset.values():
            for row in rows:
                features = {k: (row[k] if k == 'tenor_months' else float(row[k])) for k in RAW_FEATURE_NAMES}
                validated = Features.model_validate(features)
                self.assertEqual(validated.tenor_months, row['tenor_months'])
                self.assertEqual(row['is_anomaly'], int(row['anomaly_kind'] != 'normal'))
        self.assertEqual(len(COLUMNS) - len(RAW_FEATURE_NAMES), 4)

    def test_injection_semantics_and_normal_correlations(self):
        for rows in generate(self.config()).values():
            for row in rows:
                income, expenses, installments, loan = (Decimal(row[k]) for k in RAW_FEATURE_NAMES[:-1])
                kind = row['anomaly_kind']
                self.assertGreaterEqual(income, 3000000)
                self.assertLessEqual(income, 50000000)
                if kind == 'normal':
                    self.assertLessEqual(expenses, income * Decimal('.75'))
                    self.assertGreaterEqual(expenses + Decimal('.01'), income * Decimal('.35'))
                    self.assertLessEqual(installments, income * Decimal('.25'))
                    self.assertGreaterEqual(loan + Decimal('.01'), income * 2)
                    self.assertLessEqual(loan, income * 18)
                elif kind == 'expense_pressure':
                    self.assertGreaterEqual(expenses + Decimal('.01'), income * Decimal('1.4'))
                elif kind == 'installment_pressure':
                    self.assertGreaterEqual(installments + Decimal('.01'), income * Decimal('.9'))
                elif kind == 'loan_mismatch':
                    self.assertGreaterEqual(loan + Decimal('.01'), income * 60)
                elif kind == 'low_expense_large_loan':
                    self.assertLessEqual(expenses, income * Decimal('.05'))
                    self.assertGreaterEqual(loan + Decimal('.01'), income * 24)
                else:
                    self.fail('Unknown injection')
                choices = (36, 48, 60) if loan > income * 12 else ((18, 24, 36) if loan > income * 6 else (6, 12, 18))
                self.assertIn(row['tenor_months'], choices)

    def test_outputs_are_byte_reproducible_and_hashes_match(self):
        with tempfile.TemporaryDirectory() as directory:
            paths = [Path(directory) / name for name in ('first', 'second')]
            manifests = [write_dataset(self.config(), p) for p in paths]
            self.assertEqual(manifests[0], manifests[1])
            self.assertFalse(manifests[0]['trained'])
            self.assertEqual(manifests[0]['status'], 'draft')
            for name, entry in manifests[0]['files'].items():
                raw = (paths[0] / name).read_bytes()
                self.assertEqual(raw, (paths[1] / name).read_bytes())
                self.assertEqual(hashlib.sha256(raw).hexdigest(), entry['sha256'])
                self.assertEqual(len(raw), entry['bytes'])
            with (paths[0] / 'train.csv').open(newline='', encoding='utf-8') as handle:
                self.assertEqual(tuple(csv.DictReader(handle).fieldnames), COLUMNS)

    def test_seeds_change_dataset_but_not_composition(self):
        original = self.config()
        before = generate(original)
        for key in ('data_seed', 'split_seed', 'injection_seed'):
            config = {**original, key: original[key] + 1}
            after = generate(config)
            self.assertNotEqual(before, after)
            self.assertEqual(audit(before, original), audit(after, config))

    def test_strict_config_rejects_invalid_seed_ratios_unknown_keys_and_empty_classes(self):
        invalid = [
            {'data_seed': True}, {'data_seed': -1}, {'split_seed': 1.5},
            {'groups': 401}, {'rows_per_group': 0}, {'anomaly_percent': 'auto'},
            {'anomaly_percent': 0}, {'anomaly_percent': 51}, {'anomaly_percent': 1},
            {'status': 'locked'}, {'generator_version': 'unknown'}, {'unknown': 1},
            {'split_percent': {'train': 70, 'validation': 15, 'test': 14}},
            {'split_percent': {'train': 70, 'validation': 15, 'other': 15}},
        ]
        for patch in invalid:
            with self.subTest(patch=patch), self.assertRaises(ValueError):
                validate_config({**self.config(), **patch})
        with self.assertRaises(ValueError):
            validate_config({})

    def test_audit_rejects_group_leakage_duplicates_bad_labels_and_invalid_money(self):
        config = self.config()
        original = generate(config)
        leaked = copy.deepcopy(original)
        customer = leaked['train'][0]['customer_group_id']
        target = leaked['validation'][0]['customer_group_id']
        for row in leaked['validation']:
            if row['customer_group_id'] == target:
                row['customer_group_id'] = customer
        with self.assertRaisesRegex(ValueError, 'bocor'):
            audit(leaked, config)
        duplicate = copy.deepcopy(original)
        for key in RAW_FEATURE_NAMES:
            duplicate['test'][1][key] = duplicate['train'][0][key]
        with self.assertRaisesRegex(ValueError, 'Duplikat'):
            audit(duplicate, config)
        for key, value in [('is_anomaly', 99), ('monthly_income', '-1.00'), ('loan_amount', '0.00'),
                           ('monthly_expenses', 'NaN'), ('tenor_months', True)]:
            changed = copy.deepcopy(original)
            changed['train'][0][key] = value
            with self.subTest(key=key), self.assertRaises(ValueError):
                audit(changed, config)

    def test_existing_output_is_untouched_and_invalid_config_creates_no_directory(self):
        with tempfile.TemporaryDirectory() as directory:
            output = Path(directory) / 'dataset'
            manifest = write_dataset(self.config(), output)
            raw = (output / 'manifest.json').read_bytes()
            with self.assertRaises(FileExistsError):
                write_dataset(self.config(), output)
            self.assertEqual(raw, (output / 'manifest.json').read_bytes())
            self.assertEqual(json.loads(raw), manifest)
            bad = Path(directory) / 'bad'
            with self.assertRaises(ValueError):
                write_dataset({**self.config(), 'groups': True}, bad)
            self.assertFalse(bad.exists())

    def test_cli_generates_new_dataset_and_refuses_second_run(self):
        with tempfile.TemporaryDirectory() as directory:
            path = Path(directory)
            config = path / 'config.json'
            config.write_text(json.dumps(self.config()), encoding='utf-8')
            args = [sys.executable, '-m', 'experiments.generate', '--config', str(config), '--output', str(path / 'output')]
            result = subprocess.run(args, cwd=ROOT, capture_output=True, text=True, timeout=30)
            self.assertEqual(result.returncode, 0, result.stderr)
            self.assertEqual(json.loads(result.stdout)['status'], 'draft')
            second = subprocess.run(args, cwd=ROOT, capture_output=True, timeout=30)
            self.assertEqual(second.returncode, 2)


if __name__ == '__main__':
    unittest.main()
