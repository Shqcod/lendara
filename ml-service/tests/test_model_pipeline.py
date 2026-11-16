import unittest
import numpy as np

from app.model_pipeline import LoanFeatures, RATIO_NAMES, build_pipeline
from app.main import RAW_FEATURE_NAMES


def row():
    return dict(monthly_income=8000000, monthly_expenses=3000000,
                existing_installments=1000000, loan_amount=20000000, tenor_months=12)


class ModelPipelineTests(unittest.TestCase):
    def test_feature_order_ratios_and_no_mutation(self):
        data = row()
        inverted = dict(reversed(list(data.items())))
        np.testing.assert_array_equal(LoanFeatures('B').transform([data]), LoanFeatures('B').transform([inverted]))
        np.testing.assert_array_equal(LoanFeatures('B').transform([data])[0,-3:], [.125,2.5,.375])
        self.assertEqual(tuple(LoanFeatures('B').get_feature_names_out()), (*RAW_FEATURE_NAMES,*RATIO_NAMES))
        self.assertEqual(LoanFeatures('A').transform([data]).shape,(1,5))
        self.assertEqual(data,row())

    def test_invalid_or_label_features_rejected(self):
        for patch in [dict(is_anomaly=1),dict(monthly_income=0),dict(tenor_months=True),
                      dict(monthly_expenses=-1),dict(loan_amount=float('nan'))]:
            with self.assertRaises(ValueError):
                LoanFeatures().transform([{**row(),**patch}])

    def test_fixture_fit_is_deterministic_without_holdout(self):
        data = [{**row(),'monthly_income':3000000+i*10000,'loan_amount':9000000+i*17000} for i in range(300)]
        models = [build_pipeline('B',100,.1,17).fit(data) for _ in range(2)]
        np.testing.assert_array_equal(models[0].decision_function(data), models[1].decision_function(data))
        self.assertEqual(models[0]['estimator'].n_features_in_,8)

    def test_grid_rejects_auto_contamination_or_unapproved_seed(self):
        for args in [('B',100,'auto',17),('C',100,.1,17),('A',100,.1,99)]:
            with self.assertRaises(ValueError):build_pipeline(*args)
