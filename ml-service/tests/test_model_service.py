import copy
import json
from pathlib import Path
import tempfile
import unittest
from unittest.mock import patch

from fastapi.testclient import TestClient

from app.serve import RuntimeSettings, create_app, ROOT
from app.model_registry import ModelRegistry, digest
from experiments.export_registry import export
from test_stub import payload


class ModelServiceTests(unittest.TestCase):
    @classmethod
    def setUpClass(cls):
        cls.temp = tempfile.TemporaryDirectory()
        cls.registry_path=Path(cls.temp.name)/'registry.json'
        cls.hash=export(cls.registry_path)
        cls.registry=json.loads(cls.registry_path.read_text())
        cls.key='fixture-only-model-service-key'

    @classmethod
    def tearDownClass(cls):cls.temp.cleanup()

    def client(self, **extra):
        return TestClient(create_app(RuntimeSettings(environment='demo',mode='model',api_key=self.key,
                         registry_path=str(self.registry_path),registry_sha256=self.hash,**extra)))

    def headers(self):return {'X-Internal-API-Key':self.key}

    def data(self):
        data=payload();data['model_version_code']=self.registry['default_model_version_code'];return data

    def test_ready_and_real_pipeline_score_identity_no_fit(self):
        client=self.client()
        ready=client.get('/ready',headers=self.headers())
        self.assertEqual(ready.status_code,200)
        self.assertEqual(ready.json()['loaded_models'],[dict(model_version_code=self.data()['model_version_code'],artifact_hash=self.registry['models'][0]['artifact_hash'])])
        model=client.app.state.model_registry.entries[self.data()['model_version_code']][1]
        expected=float(model.decision_function([self.data()['features']])[0])
        with patch.object(model,'fit',side_effect=AssertionError('serve must never fit')):
            response=client.post('/predict',json=self.data(),headers=self.headers())
        body=response.json();self.assertEqual(response.status_code,200)
        self.assertEqual(body['source'],'model');self.assertEqual(body['score'],expected)
        self.assertEqual(body['is_anomaly'],expected<0);self.assertEqual(body['anomaly_check_id'],73)
        self.assertEqual(body['submission_id'],28)
        inverted=self.data();inverted['features']=dict(reversed(list(inverted['features'].items())))
        self.assertEqual(client.post('/predict',json=inverted,headers=self.headers()).json(),body)

    def test_authentication_contract_validation_and_no_fallback(self):
        client=self.client()
        for path in ['/ready','/health']:self.assertEqual(client.get(path).status_code,401)
        self.assertEqual(client.post('/predict',json=self.data()).status_code,401)
        for field,value,expected in [('model_version_code','missing',503),('contract_version','2',422),('feature_schema','bad',422)]:
            data=self.data();data[field]=value
            self.assertEqual(client.post('/predict',json=data,headers=self.headers()).status_code,expected)
        for field,value in [('monthly_income',False),('loan_amount',0),('tenor_months',1.5),('extra',2)]:
            data=self.data();data['features'][field]=value
            self.assertEqual(client.post('/predict',json=data,headers=self.headers()).status_code,422)
        self.assertEqual(client.post('/predict',json=self.data(),headers={**self.headers(),'X-Stub-Scenario':'normal'}).status_code,422)
        for field in ['anomaly_check_id','submission_id']:
            data=self.data();data[field]=0
            self.assertEqual(client.post('/predict',json=data,headers=self.headers()).status_code,422)

    def test_multiple_pinned_versions_and_missing_version_do_not_fallback(self):
        registry=copy.deepcopy(self.registry)
        meta=copy.deepcopy(registry['models'][0]);meta['version_code']='fixture-second-version';registry['models'].append(meta)
        path=Path(self.temp.name)/'multi.json';raw=json.dumps(registry).encode();path.write_bytes(raw)
        client=TestClient(create_app(RuntimeSettings(mode='model',api_key=self.key,registry_path=str(path),registry_sha256=digest(raw))))
        self.assertEqual(len(client.get('/ready',headers=self.headers()).json()['loaded_models']),2)
        data=self.data();data['model_version_code']=meta['version_code']
        self.assertEqual(client.post('/predict',json=data,headers=self.headers()).json()['model_version_code'],meta['version_code'])

    def test_registry_hash_failure_does_not_deserialize_and_hides_paths(self):
        with patch('joblib.load') as load:
            app=create_app(RuntimeSettings(mode='model',api_key=self.key,registry_path=str(self.registry_path),registry_sha256='0'*64))
        load.assert_not_called()
        client=TestClient(app);ready=client.get('/ready',headers=self.headers())
        self.assertEqual(ready.status_code,503);self.assertEqual(ready.json()['loaded_models'],[])
        self.assertNotIn(str(self.registry_path),ready.text)
        self.assertEqual(client.post('/predict',json=self.data(),headers=self.headers()).json()['code'],'SERVICE_NOT_READY')

    def test_bad_artifact_pipeline_dependency_or_metadata_refused(self):
        cases=[]
        for field,value in [('artifact_hash','0'*64),('artifact_path','../outside.joblib'),('version_code','stub-bad')]:
            r=copy.deepcopy(self.registry);r['models'][0][field]=value;cases.append(r)
        r=copy.deepcopy(self.registry);r['pipeline_sha256']='0'*64;cases.append(r)
        r=copy.deepcopy(self.registry);r['models'][0]['dependencies']['python_version']='0.0.0';cases.append(r)
        for i,r in enumerate(cases):
            path=Path(self.temp.name)/f'bad{i}.json';raw=json.dumps(r).encode();path.write_bytes(raw)
            with patch('joblib.load') as load,self.assertRaises(ValueError):ModelRegistry.load(path,digest(raw),ROOT)
            load.assert_not_called()
        r=copy.deepcopy(self.registry);r['models'][0]['parameters']['seed']=29
        path=Path(self.temp.name)/'wrong-seed.json';raw=json.dumps(r).encode();path.write_bytes(raw)
        with self.assertRaisesRegex(ValueError,'metadata mismatch'):ModelRegistry.load(path,digest(raw),ROOT)

    def test_missing_model_config_fails_closed_and_stub_stays_local_only(self):
        for env in ['local','testing','demo','production']:
            client=TestClient(create_app(RuntimeSettings(environment=env,mode='model',api_key=self.key)))
            self.assertEqual(client.get('/ready',headers=self.headers()).status_code,503)
        for env in ['demo','production']:
            client=TestClient(create_app(RuntimeSettings(environment=env,mode='stub',api_key=self.key)))
            self.assertEqual(client.get('/ready',headers=self.headers()).status_code,503)
        self.assertEqual(self.client().get('/docs').status_code,404)

    def test_prediction_failure_is_sanitized(self):
        client=self.client();model=client.app.state.model_registry.entries[self.data()['model_version_code']][1]
        for result in [float('nan'),float('inf')]:
            with patch.object(model,'decision_function',return_value=[result]):
                response=client.post('/predict',json=self.data(),headers=self.headers())
            self.assertEqual(response.status_code,503);self.assertEqual(response.json()['code'],'PREDICTION_UNAVAILABLE')

    def test_same_loaded_bytes_survive_later_registry_file_change(self):
        client=self.client();before=client.get('/ready',headers=self.headers()).json()
        raw=self.registry_path.read_bytes()
        try:
            self.registry_path.write_bytes(b'changed after startup')
            self.assertEqual(client.get('/ready',headers=self.headers()).json(),before)
        finally:self.registry_path.write_bytes(raw)
