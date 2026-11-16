import json
from pathlib import Path
import tempfile
import unittest
from unittest.mock import patch

from experiments import evaluate as e


class EvaluationTests(unittest.TestCase):
    def test_hash_checked_before_deserialization(self):
        with tempfile.TemporaryDirectory() as temp:
            path=Path(temp)/'model.joblib';path.write_bytes(b'not a model')
            with patch.object(e.joblib,'load') as load, self.assertRaises(ValueError):
                e.load_verified_model(dict(path=str(path),sha256='0'*64))
            load.assert_not_called()

    def test_ranking_sign_zero_threshold_and_single_class(self):
        result=e.metrics([0,0,1,1],[.4,0,-.2,-.5])
        self.assertEqual(result['ap'],1)
        self.assertEqual(result['roc_auc'],1)
        self.assertEqual(result['flags'],2)
        self.assertEqual(result['f1'],1)
        self.assertIsNone(e.metrics([0,0],[.2,.1])['roc_auc'])
        self.assertIsNone(e.metrics([1,1],[-.2,-.1])['ap'])

    def test_income_boundaries_and_support_29_30_empty(self):
        self.assertEqual([e.income_band(x) for x in [3000000,5999999,6000000,19999999,20000000,50000000]],
                         ['low','low','middle','middle','high','high'])
        for n in [29,30]:
            rows=[dict(is_anomaly=1,anomaly_kind='fixture',monthly_income=3000000,customer_group_id=f'g{i//2}') for i in range(n)]
            cells=e.cells(rows,[-1]*n)
            total=cells[0]
            self.assertEqual(total['anomaly_rows'],n)
            self.assertEqual(bool(total['note']),n<30)
            high=next(c for c in cells if c['kind']=='all' and c['band']=='high')
            self.assertIsNone(high['recall'])
            self.assertEqual(high['anomaly_rows'],0)

    def test_ap_auc_not_used_and_ties_choose_simpler_then_a(self):
        def candidate(v,n,c,ap):
            return dict(variant=v,n_estimators=n,contamination=c,aggregate={m:dict(mean=ap if m=='ap' else .8) for m in e.METRICS})
        best,primary=e.select([candidate(v,n,c,1 if n==200 else 0) for v in ('A','B') for n in (200,100) for c in (.15,.05)])
        self.assertEqual(primary,'A')
        self.assertEqual(best['A']['n_estimators'],100)
        self.assertEqual(best['A']['contamination'],.05)

    def test_training_cannot_parse_holdout(self):
        with patch.object(e,'_read_split') as read:
            with self.assertRaises(ValueError):e.training_split(Path('.'),'test')
            with self.assertRaises(ValueError):e.training_split(Path('.'),'borderline')
            read.assert_not_called()

    def test_holdout_requires_selected_tag_and_claim_prevents_second_read(self):
        with tempfile.TemporaryDirectory() as temp:
            folder=Path(temp)
            with patch.object(e,'verify_lock',return_value=({'selection':None},folder,{})), self.assertRaises(ValueError):
                e.holdout(folder/'out')
            selection=dict(path=str(folder/'report.json'),sha256='abc')
            lock=dict(selection=selection,evaluation_code={})
            report=dict(evaluation_code={},training_data_version='fixture',artifacts=[],primary='A')
            (folder/'HOLDOUT_OPENED.json').write_text('{}')
            with patch.object(e,'verify_lock',return_value=(lock,folder,dict(training_data_version='fixture'))), \
                 patch.object(e.subprocess,'check_output',return_value=json.dumps(lock).encode()), \
                 patch.object(e,'read_verified',return_value=json.dumps(report).encode()), \
                 patch.object(e,'_read_split') as read, self.assertRaises(FileExistsError):
                e.holdout(folder/'out')
            read.assert_not_called()
