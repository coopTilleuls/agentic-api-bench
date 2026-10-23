# OpenAPI and JSON:API schema validators for eval/v3/validate.py. Run without
# network: the scorer mounts the documents the assertions saved and eval/v3.
#   docker build -t llmapi-eval-validator eval/docker/validator
FROM python:3.13-alpine
RUN pip install --no-cache-dir openapi-spec-validator==0.9.0 jsonschema==4.26.0
ENV PYTHONDONTWRITEBYTECODE=1 PYTHONUNBUFFERED=1
WORKDIR /v3
