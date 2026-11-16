"""Shared, explicit feature transformation for future train and serve paths."""
import math
from numbers import Real

import numpy as np
from sklearn.base import BaseEstimator, TransformerMixin
from sklearn.ensemble import IsolationForest
from sklearn.pipeline import Pipeline

RAW_FEATURE_NAMES = ('monthly_income', 'monthly_expenses', 'existing_installments',
                     'loan_amount', 'tenor_months')

RATIO_NAMES = ('installments_to_income', 'loan_to_income', 'expenses_to_income')


class LoanFeatures(TransformerMixin, BaseEstimator):
    def __init__(self, variant='A'):
        self.variant = variant

    def fit(self, X, y=None):
        self.transform(X)
        return self

    def transform(self, X):
        if self.variant not in ('A', 'B'):
            raise ValueError('Varian harus A atau B.')
        result = []
        for row in X:
            if set(row) != set(RAW_FEATURE_NAMES):
                raise ValueError('Masukan harus tepat lima fitur; metadata/label dilarang.')
            values = [row[k] for k in RAW_FEATURE_NAMES]
            if any(isinstance(v, bool) or not isinstance(v, Real) or not math.isfinite(v) for v in values):
                raise ValueError('Fitur harus angka finite, bukan boolean.')
            income, expenses, installments, loan, tenor = values
            if income <= 0 or loan <= 0 or expenses < 0 or installments < 0 or not isinstance(tenor, (int, np.integer)) or tenor <= 0:
                raise ValueError('Nilai fitur di luar kontrak.')
            result.append(values + ([installments/income, loan/income, expenses/income] if self.variant == 'B' else []))
        if not result:
            raise ValueError('Dataset kosong.')
        matrix = np.asarray(result, dtype=np.float64)
        if not np.isfinite(matrix).all():
            raise ValueError('Rasio tidak finite.')
        return matrix

    def get_feature_names_out(self, input_features=None):
        return np.asarray((*RAW_FEATURE_NAMES, *(RATIO_NAMES if self.variant == 'B' else ())), dtype=object)


def build_pipeline(variant, n_estimators, contamination, seed):
    if variant not in ('A', 'B') or type(n_estimators) is not int or n_estimators not in (100, 200):
        raise ValueError('Varian/estimator tidak termasuk grid terkunci.')
    if type(contamination) not in (int, float) or contamination not in (.05, .10, .15):
        raise ValueError('Contamination tidak termasuk grid terkunci.')
    if type(seed) is not int or seed not in (17, 29, 43):
        raise ValueError('Seed tidak termasuk protokol.')
    return Pipeline([('features', LoanFeatures(variant)), ('estimator', IsolationForest(
        n_estimators=n_estimators, max_samples=256, contamination=contamination,
        max_features=1.0, bootstrap=False, random_state=seed, n_jobs=1))])
