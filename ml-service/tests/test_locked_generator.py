import copy
from decimal import Decimal
import json
import tempfile
from pathlib import Path
import unittest

from experiments import generate_locked as locked
from experiments import generate as legacy


class LockedGeneratorTests(unittest.TestCase):
    @classmethod
    def setUpClass(cls):
        cls.spec = json.loads(locked.CONFIG.read_text(encoding='utf-8'))
        cls.dataset = locked.generate(cls.spec)

    def test_counts_and_final_borderline_distance(self):
        summary = locked.audit(self.dataset, self.spec)
        self.assertEqual([summary[s]['rows'] for s in ('train', 'validation', 'test', 'borderline')], [7000,1500,1500,2000])
        for row in self.dataset['borderline']:
            income = Decimal(row['monthly_income'])
            kind = row['anomaly_kind']
            if kind == locked.KINDS[0]:
                ratio = Decimal(row['monthly_expenses']) / income
                self.assertGreaterEqual(ratio - Decimal('.75'), Decimal('.05'))
                self.assertLessEqual(ratio, 1)
            elif kind == locked.KINDS[1]:
                ratio = Decimal(row['existing_installments']) / income
                self.assertGreaterEqual(ratio - Decimal('.25'), Decimal('.05'))
                self.assertLessEqual(ratio, Decimal('.5'))
            elif kind == locked.KINDS[2]:
                self.assertGreaterEqual(Decimal(row['loan_amount']) / income - 18, 1)

    def test_rounding_minimum_even_at_one_cent_boundary(self):
        # Many group seeds exercise final integer cents rather than configured bounds alone.
        for group in range(1, 1001):
            for kind, field, lower in [(locked.KINDS[0], 'monthly_expenses', Decimal('.8')),
                                       (locked.KINDS[1], 'existing_installments', Decimal('.3'))]:
                for row in legacy.group_rows(self.spec['borderline'], group, kind, namespace='borderline'):
                    self.assertGreaterEqual(Decimal(row[field]), Decimal(row['monthly_income']) * lower)

    def test_global_duplicate_and_group_leak_are_rejected(self):
        data = copy.deepcopy(self.dataset)
        for name in legacy.RAW_FEATURE_NAMES:
            data['borderline'][0][name] = data['train'][0][name]
        with self.assertRaisesRegex(ValueError, 'Duplikat'):
            locked.audit(data, self.spec)
        data = copy.deepcopy(self.dataset)
        data['borderline'][0]['customer_group_id'] = data['train'][0]['customer_group_id']
        with self.assertRaisesRegex(ValueError, 'bocor'):
            locked.audit(data, self.spec)

    def test_config_and_protocol_tamper_fail_closed(self):
        for patch in [{'protocol_sha256':'0'*64}, {'status':'draft'},
                      {'borderline':{**self.spec['borderline'], 'groups':999}}]:
            with self.assertRaises(ValueError):
                locked.generate({**self.spec, **patch})

    def test_explicit_lf_utf8_and_byte_determinism_without_overwrite(self):
        with tempfile.TemporaryDirectory() as temp:
            paths = [Path(temp)/n for n in ('a','b')]
            a,b = [locked.write_dataset(self.spec, p) for p in paths]
            self.assertEqual(a,b)
            for name in [*a['files'],'manifest.json']:
                raw = (paths[0]/name).read_bytes()
                raw.decode('utf-8')
                self.assertNotIn(b'\r', raw)
                self.assertEqual(raw, (paths[1]/name).read_bytes())
            with self.assertRaises(FileExistsError):
                locked.write_dataset(self.spec, paths[0])
