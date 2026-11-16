"""Load only operator-pinned registries and verified, trusted local artifacts."""
import hashlib
import importlib.metadata
import io
import json
from pathlib import Path
import platform
import re


def digest(raw):
    return hashlib.sha256(raw).hexdigest()


def verified(path, expected):
    if not isinstance(expected, str) or not re.fullmatch('[0-9a-f]{64}', expected):
        raise ValueError('Invalid expected hash')
    raw = Path(path).read_bytes()
    if digest(raw) != expected:
        raise ValueError('Hash mismatch')
    return raw


def local_path(root, name):
    if not isinstance(name, str) or Path(name).is_absolute():
        raise ValueError('Artifact paths must be relative')
    path = (root / name).resolve()
    if not path.is_relative_to(root.resolve()):
        raise ValueError('Path outside artifact root')
    return path


class ModelRegistry:
    def __init__(self, default, entries):
        self.default = default
        self.entries = entries

    @classmethod
    def load(cls, path, expected, root):
        root = Path(root)
        registry = json.loads(verified(path, expected))
        if set(registry) != {'schema_version', 'default_model_version_code', 'pipeline_sha256', 'models'} or registry['schema_version'] != 1:
            raise ValueError('Invalid registry schema')
        verified(local_path(root, 'ml-service/app/model_pipeline.py'), registry['pipeline_sha256'])
        if not isinstance(registry['models'], list) or not registry['models']:
            raise ValueError('Empty registry')
        pending = {}
        for meta in registry['models']:
            version = meta['version_code']
            if not isinstance(version, str) or not re.fullmatch('[a-zA-Z0-9][a-zA-Z0-9._-]{0,99}', version) or version.startswith('stub-') or version in pending:
                raise ValueError('Invalid/duplicate version')
            if meta['source'] != 'model' or meta['trained'] is not True or meta['algorithm'] != 'IsolationForest' or meta['decision_threshold'] != 0 or meta['score_method'] != 'decision_function':
                raise ValueError('Invalid model contract')
            if type(meta['contamination']) not in (int,float) or not 0 < meta['contamination'] <= .5:
                raise ValueError('Invalid contamination')
            if meta['parameters']['feature_schema'] != 'loan-finance-v1':
                raise ValueError('Unsupported features')
            deps = meta['dependencies']
            if platform.python_version() != deps['python_version']:
                raise ValueError('Python version mismatch')
            dependency_bytes = verified(local_path(root, deps['path']), deps['sha256'])
            for line in dependency_bytes.decode('utf-8').splitlines():
                if line and not line.startswith('#'):
                    name, version_pin = line.split('==')
                    if importlib.metadata.version(name) != version_pin:
                        raise ValueError('Dependency version mismatch')
            # All artifacts/dependencies checked before any deserialization.
            pending[version] = (meta, verified(local_path(root, meta['artifact_path']), meta['artifact_hash']))
        if registry['default_model_version_code'] not in pending:
            raise ValueError('Default model not loaded')
        import joblib
        from sklearn.pipeline import Pipeline
        from sklearn.ensemble import IsolationForest
        from app.model_pipeline import LoanFeatures, RAW_FEATURE_NAMES
        entries = {}
        for version, (meta, raw) in pending.items():
            model = joblib.load(io.BytesIO(raw))
            if type(model) is not Pipeline or tuple(model.named_steps) != ('features','estimator'):
                raise ValueError('Unexpected pipeline')
            features, estimator = model['features'], model['estimator']
            if type(features) is not LoanFeatures or type(estimator) is not IsolationForest:
                raise ValueError('Unexpected estimator')
            params = meta['parameters']
            if params['raw_input_names'] != list(RAW_FEATURE_NAMES) or features.variant != params['variant'] or features.get_feature_names_out().tolist() != meta['feature_names']:
                raise ValueError('Feature metadata mismatch')
            expected_params = {k:params[k] for k in ('n_estimators','max_samples','max_features','bootstrap','n_jobs')}
            expected_params.update(random_state=params['seed'], contamination=meta['contamination'])
            if any(estimator.get_params()[k] != v for k,v in expected_params.items()) or estimator.n_features_in_ != len(meta['feature_names']):
                raise ValueError('Estimator metadata mismatch')
            entries[version] = (meta, model)
        return cls(registry['default_model_version_code'], entries)
