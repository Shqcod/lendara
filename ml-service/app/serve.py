"""Runtime entrypoint; legacy app.main remains the immutable stub implementation."""
from dataclasses import dataclass
import math
import os
from pathlib import Path
import secrets
from typing import Annotated

from fastapi import FastAPI, Depends, Header
from fastapi.responses import JSONResponse
from pydantic import Field, StrictInt

from app.main import Settings, PredictionRequest, PredictionResponse, create_app as stub_app, reject

ROOT = Path(__file__).resolve().parents[2]


class ModelRequest(PredictionRequest):
    anomaly_check_id: StrictInt = Field(gt=0)
    submission_id: StrictInt = Field(gt=0)


@dataclass(frozen=True)
class RuntimeSettings(Settings):
    registry_path: str = ''
    registry_sha256: str = ''
    artifact_root: str = str(ROOT)

    @classmethod
    def from_env(cls):
        return cls(**vars(Settings.from_env()), registry_path=os.getenv('ML_MODEL_REGISTRY',''),
                   registry_sha256=os.getenv('ML_MODEL_REGISTRY_SHA256',''),
                   artifact_root=os.getenv('ML_ARTIFACT_ROOT') or str(ROOT))


def create_app(settings=None):
    config = settings or RuntimeSettings.from_env()
    base = stub_app(config)
    if config.mode == 'stub':
        return base
    registry = None
    try:
        from app.model_registry import ModelRegistry, local_path
        path = Path(config.registry_path)
        if not path.is_absolute():
            path = local_path(Path(config.artifact_root), config.registry_path)
        registry = ModelRegistry.load(path, config.registry_sha256, config.artifact_root)
    except Exception:
        # Fail closed without exposing paths, artifact content or environment values.
        pass
    service = FastAPI(title='Lendara - Layanan Prediksi Internal', docs_url=None,redoc_url=None,openapi_url=None)
    service.exception_handlers.update(base.exception_handlers)
    service.state.model_registry = registry

    def authorize(key: Annotated[str | None, Header(alias='X-Internal-API-Key')] = None):
        if not config.api_key:
            reject(503,'SERVICE_NOT_READY','Kunci layanan belum dikonfigurasi.')
        if key is None or not secrets.compare_digest(key.encode(), config.api_key.encode()):
            reject(401,'INTERNAL_UNAUTHORIZED','Akses layanan internal ditolak.')

    @service.get('/health', dependencies=[Depends(authorize)])
    def health():
        return {'status':'ok'}

    @service.get('/ready', dependencies=[Depends(authorize)])
    def ready():
        versions = sorted(registry.entries) if registry else []
        return JSONResponse(status_code=200 if registry else 503,content=dict(
            ready=bool(registry),mode='model',loaded_model_versions=versions,
            loaded_models=[dict(model_version_code=v,artifact_hash=registry.entries[v][0]['artifact_hash']) for v in versions],
            default_model_version_code=registry.default if registry else None,contract_version='1'))

    @service.post('/predict',response_model=PredictionResponse,dependencies=[Depends(authorize)])
    def predict(payload: ModelRequest,scenario: Annotated[str | None, Header(alias='X-Stub-Scenario')]=None):
        if scenario is not None:
            reject(422,'VALIDATION_ERROR','Header stub tidak diizinkan dalam mode model.')
        if registry is None:
            reject(503,'SERVICE_NOT_READY','Model belum siap. Periksa konfigurasi lokal layanan.')
        if payload.contract_version != '1':
            reject(422,'CONTRACT_VERSION_UNSUPPORTED','Versi kontrak tidak didukung.')
        if payload.feature_schema != 'loan-finance-v1':
            reject(422,'FEATURE_SCHEMA_UNSUPPORTED','Skema fitur tidak didukung.')
        if payload.model_version_code not in registry.entries:
            reject(503,'MODEL_VERSION_UNAVAILABLE','Versi model yang diminta tidak tersedia.')
        meta,model = registry.entries[payload.model_version_code]
        try:
            score = float(model.decision_function([payload.features.model_dump()])[0])
            if not math.isfinite(score):
                raise ValueError('Nonfinite score')
        except Exception:
            reject(503,'PREDICTION_UNAVAILABLE','Layanan prediksi tidak dapat memproses masukan.')
        return PredictionResponse(contract_version='1',feature_schema='loan-finance-v1',
            anomaly_check_id=payload.anomaly_check_id,submission_id=payload.submission_id,
            model_version_code=meta['version_code'],source='model',score_method='decision_function',
            score=score,decision_threshold=0.0,is_anomaly=score<0)

    return service


app = create_app()
