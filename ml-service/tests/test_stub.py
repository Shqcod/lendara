"""Core contract tests. Laravel deadlines/transactions are NOT tested here."""
import time
import unittest

from fastapi.testclient import TestClient

from app.main import RAW_FEATURE_NAMES, Settings, create_app


def payload():
    return {
        'contract_version': '1', 'feature_schema': 'loan-finance-v1',
        'anomaly_check_id': 73, 'submission_id': 28, 'model_version_code': 'stub-v0',
        'features': {
            'monthly_income': 8000000, 'monthly_expenses': 3000000,
            'existing_installments': 1000000, 'loan_amount': 20000000, 'tenor_months': 12,
        },
    }


class StubContractTests(unittest.TestCase):
    def setUp(self):
        self.key = 'testing-only-key-not-for-deployment'
        self.client = TestClient(create_app(Settings(environment='testing', api_key=self.key, timeout_delay=0.1)))
        self.headers = {'X-Internal-API-Key': self.key}

    def predict(self, data=None, scenario=None):
        headers = dict(self.headers)
        if scenario is not None:
            headers['X-Stub-Scenario'] = scenario
        return self.client.post('/predict', json=payload() if data is None else data, headers=headers)

    def test_all_routes_require_internal_key(self):
        for path in ['/health', '/ready']:
            self.assertEqual(self.client.get(path).status_code, 401)
        response = self.client.post('/predict', json=payload())
        self.assertEqual(response.status_code, 401)
        self.assertEqual(response.json()['code'], 'INTERNAL_UNAUTHORIZED')
        self.assertEqual(self.client.get('/health', headers={'X-Internal-API-Key': 'wrong'}).status_code, 401)

    def test_missing_configuration_fails_closed(self):
        client = TestClient(create_app(Settings(api_key='')))
        self.assertEqual(client.get('/ready').status_code, 503)

    def test_health_and_machine_readiness(self):
        self.assertEqual(self.client.get('/health', headers=self.headers).json(), {'status': 'ok'})
        body = self.client.get('/ready', headers=self.headers).json()
        self.assertTrue(body['ready'])
        self.assertEqual(body['mode'], 'stub')
        self.assertEqual(body['loaded_model_versions'], ['stub-v0'])

    def test_normal_contract(self):
        response = self.predict()
        self.assertEqual(response.status_code, 200)
        body = response.json()
        self.assertEqual(body['source'], 'stub')
        self.assertEqual(body['anomaly_check_id'], 73)
        self.assertEqual(body['submission_id'], 28)
        self.assertEqual(body['model_version_code'], 'stub-v0')
        self.assertEqual(body['score_method'], 'decision_function')
        self.assertEqual(body['decision_threshold'], 0.0)
        self.assertEqual(body['score'], 0.08)
        self.assertIs(body['is_anomaly'], False)

    def test_anomaly_is_deterministic_not_business_trigger(self):
        data = payload()
        data['features']['loan_amount'] = 1
        self.assertEqual(self.predict(data).json()['score'], 0.08)
        body = self.predict(data, 'anomaly').json()
        self.assertEqual(body['score'], -0.12)
        self.assertTrue(body['is_anomaly'])

    def test_failure(self):
        response = self.predict(scenario='failure')
        self.assertEqual(response.status_code, 503)
        self.assertEqual(response.json()['code'], 'PREDICTION_UNAVAILABLE')

    def test_timeout_scenario_delays_response_not_http_client_test(self):
        # Allow Windows timer granularity while still detecting a missing delay.
        start = time.perf_counter()
        self.assertEqual(self.predict(scenario='timeout').status_code, 200)
        self.assertGreaterEqual(time.perf_counter() - start, 0.08)

    def test_schema_and_requested_model_are_not_silently_changed(self):
        for field, value, code, status in [
            ('contract_version', '2', 'CONTRACT_VERSION_UNSUPPORTED', 422),
            ('feature_schema', 'wrong', 'FEATURE_SCHEMA_UNSUPPORTED', 422),
            ('model_version_code', 'if-v1', 'MODEL_VERSION_UNAVAILABLE', 503),
        ]:
            data = payload()
            data[field] = value
            response = self.predict(data)
            self.assertEqual(response.status_code, status)
            self.assertEqual(response.json()['code'], code)

    def test_invalid_input(self):
        for field, value in [('monthly_income', 0), ('monthly_expenses', -1), ('tenor_months', 1.5), ('tenor_months', True), ('loan_amount', '10'), ('monthly_income', False)]:
            with self.subTest(field=field, value=value):
                data = payload()
                data['features'][field] = value
                self.assertEqual(self.predict(data).status_code, 422)
        data = payload()
        del data['features']['monthly_income']
        self.assertEqual(self.predict(data).status_code, 422)
        data = payload()
        data['features']['extra'] = 1
        self.assertEqual(self.predict(data).status_code, 422)
        data = payload()
        data['extra'] = 'not allowed'
        self.assertEqual(self.predict(data).status_code, 422)

    def test_nonfinite_rejected(self):
        for value in ['NaN', 'Infinity', '-Infinity']:
            text = '{"contract_version":"1","feature_schema":"loan-finance-v1","anomaly_check_id":73,"submission_id":28,"model_version_code":"stub-v0","features":{"monthly_income":'+value+',"monthly_expenses":1,"existing_installments":1,"loan_amount":2,"tenor_months":12}}'
            response = self.client.post('/predict', content=text, headers={**self.headers, 'Content-Type': 'application/json'})
            self.assertEqual(response.status_code, 422)

    def test_feature_key_order_not_a_scenario_trigger(self):
        data = payload()
        data['features'] = dict(reversed(list(data['features'].items())))
        self.assertEqual(self.predict(data).json(), self.predict().json())
        self.assertEqual(len(RAW_FEATURE_NAMES), 5)

    def test_unknown_scenario(self):
        self.assertEqual(self.predict(scenario='other').status_code, 422)

    def test_stub_cannot_run_as_demo_or_fake_real_model(self):
        for settings in [Settings(environment='demo', api_key=self.key), Settings(environment='production', api_key=self.key), Settings(mode='model', api_key=self.key)]:
            client = TestClient(create_app(settings))
            ready = client.get('/ready', headers=self.headers)
            self.assertEqual(ready.status_code, 503)
            self.assertFalse(ready.json()['ready'])
            self.assertEqual(client.post('/predict', json=payload(), headers=self.headers).status_code, 503)
        model = TestClient(create_app(Settings(mode='model', api_key=self.key)))
        self.assertEqual(model.post('/predict', json=payload(), headers={**self.headers,'X-Stub-Scenario':'normal'}).status_code, 422)


if __name__ == '__main__':
    unittest.main()

