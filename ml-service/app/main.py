"""Deterministic internal stub; no trained model or business workflow here."""
import asyncio
import math
import os
import secrets
from dataclasses import dataclass
from typing import Annotated

from fastapi import Depends, FastAPI, Header, HTTPException
from fastapi.exceptions import RequestValidationError
from fastapi.responses import JSONResponse
from pydantic import BaseModel, ConfigDict, Field, StrictInt, field_validator


RAW_FEATURE_NAMES = (
    'monthly_income', 'monthly_expenses', 'existing_installments',
    'loan_amount', 'tenor_months',
)
STUB_VERSION = 'stub-v0'


@dataclass(frozen=True)
class Settings:
    environment: str = 'local'
    mode: str = 'stub'
    api_key: str = ''
    timeout_delay: float = 25.0

    def __post_init__(self):
        if self.environment not in {'local', 'testing', 'demo', 'production'}:
            raise ValueError('Unsupported ML_ENV')
        if self.mode not in {'stub', 'model'}:
            raise ValueError('Unsupported ML_MODE')
        if not math.isfinite(self.timeout_delay) or self.timeout_delay < 0:
            raise ValueError('ML_STUB_TIMEOUT_SECONDS must be finite and nonnegative')

    @classmethod
    def from_env(cls):
        return cls(
            environment=os.getenv('ML_ENV', 'local'),
            mode=os.getenv('ML_MODE', 'stub'),
            api_key=os.getenv('INTERNAL_API_KEY', ''),
            timeout_delay=float(os.getenv('ML_STUB_TIMEOUT_SECONDS', '25')),
        )


class Features(BaseModel):
    model_config = ConfigDict(extra='forbid', allow_inf_nan=False)
    monthly_income: float = Field(gt=0)
    monthly_expenses: float = Field(ge=0)
    existing_installments: float = Field(ge=0)
    loan_amount: float = Field(gt=0)
    tenor_months: StrictInt = Field(gt=0)

    @field_validator(
        'monthly_income', 'monthly_expenses', 'existing_installments',
        'loan_amount', mode='before',
    )
    @classmethod
    def numbers_only(cls, value):
        if isinstance(value, bool) or not isinstance(value, (int, float)):
            raise ValueError('A JSON number is required')
        return value


class PredictionRequest(BaseModel):
    model_config = ConfigDict(extra='forbid', strict=True)
    contract_version: str
    feature_schema: str
    anomaly_check_id: StrictInt = Field(gt=0)
    submission_id: StrictInt = Field(gt=0)
    model_version_code: str
    features: Features


class PredictionResponse(BaseModel):
    model_config = ConfigDict(extra='forbid', allow_inf_nan=False)
    contract_version: str
    feature_schema: str
    anomaly_check_id: int
    submission_id: int
    model_version_code: str
    source: str
    score_method: str
    score: float
    decision_threshold: float
    is_anomaly: bool


def reject(status, code, message):
    raise HTTPException(status_code=status, detail={'code': code, 'message': message})


def create_app(settings: Settings | None = None) -> FastAPI:
    config = settings if settings is not None else Settings.from_env()
    service = FastAPI(
        title='Lendara — Layanan Prediksi Internal (Stub)',
        description='Stub deteksi anomali internal Lendara untuk pengujian lokal; belum menggunakan model terlatih.',
        docs_url=None, redoc_url=None, openapi_url=None,
    )

    @service.exception_handler(HTTPException)
    async def http_error(_request, exc):
        detail = exc.detail if isinstance(exc.detail, dict) else {'message': str(exc.detail)}
        return JSONResponse(status_code=exc.status_code, content={
            'message': detail.get('message', 'Permintaan gagal.'),
            'code': detail.get('code', 'HTTP_ERROR'), 'errors': {},
        })

    @service.exception_handler(RequestValidationError)
    async def validation_error(_request, exc):
        fields = {}
        for error in exc.errors():
            path = '.'.join(str(x) for x in error['loc'] if x != 'body') or 'body'
            fields.setdefault(path, []).append(error['msg'])
        return JSONResponse(status_code=422, content={
            'message': 'Masukan prediksi tidak valid.',
            'code': 'VALIDATION_ERROR', 'errors': fields,
        })

    async def authorize(key: Annotated[str | None, Header(alias='X-Internal-API-Key')] = None):
        if not config.api_key:
            reject(503, 'SERVICE_NOT_READY', 'Kunci layanan belum dikonfigurasi.')
        if key is None or not secrets.compare_digest(key.encode(), config.api_key.encode()):
            reject(401, 'INTERNAL_UNAUTHORIZED', 'Akses layanan internal ditolak.')

    def stub_ready():
        return config.mode == 'stub' and config.environment in {'local', 'testing'}

    @service.get('/health', dependencies=[Depends(authorize)])
    async def health():
        return {'status': 'ok'}

    @service.get('/ready', dependencies=[Depends(authorize)])
    async def ready():
        available = stub_ready()
        return JSONResponse(status_code=200 if available else 503, content={
            'ready': available, 'mode': config.mode,
            'loaded_model_versions': [STUB_VERSION] if available else [],
            'default_model_version_code': STUB_VERSION if available else None,
            'contract_version': '1',
        })

    @service.post('/predict', response_model=PredictionResponse, dependencies=[Depends(authorize)])
    async def predict(
        payload: PredictionRequest,
        scenario: Annotated[str | None, Header(alias='X-Stub-Scenario')] = None,
    ):
        if config.mode == 'model' and scenario is not None:
            reject(422, 'VALIDATION_ERROR', 'Header stub tidak diizinkan dalam mode model.')
        if not stub_ready():
            reject(503, 'SERVICE_NOT_READY', 'Stub hanya tersedia pada local/testing; model asli belum diimplementasikan.')
        if payload.contract_version != '1':
            reject(422, 'CONTRACT_VERSION_UNSUPPORTED', 'Versi kontrak tidak didukung.')
        if payload.feature_schema != 'loan-finance-v1':
            reject(422, 'FEATURE_SCHEMA_UNSUPPORTED', 'Skema fitur tidak didukung.')
        if payload.model_version_code != STUB_VERSION:
            reject(503, 'MODEL_VERSION_UNAVAILABLE', 'Versi model yang diminta tidak tersedia.')
        selected = 'normal' if scenario is None else scenario
        if selected not in {'normal', 'anomaly', 'failure', 'timeout'}:
            reject(422, 'VALIDATION_ERROR', 'Skenario stub tidak dikenal.')
        if selected == 'failure':
            reject(503, 'PREDICTION_UNAVAILABLE', 'Simulasi kegagalan layanan prediksi.')
        if selected == 'timeout':
            await asyncio.sleep(config.timeout_delay)
        # Deliberately independent of business values. No training/evaluation occurs here.
        score = -0.12 if selected == 'anomaly' else 0.08
        return PredictionResponse(
            contract_version='1', feature_schema='loan-finance-v1',
            anomaly_check_id=payload.anomaly_check_id, submission_id=payload.submission_id,
            model_version_code=STUB_VERSION, source='stub',
            score_method='decision_function', score=score,
            decision_threshold=0.0, is_anomaly=score < 0,
        )

    return service


app = create_app()

