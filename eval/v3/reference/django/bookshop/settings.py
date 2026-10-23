import os

import dj_database_url

BASE_DIR = os.path.dirname(os.path.dirname(os.path.abspath(__file__)))

SECRET_KEY = "reference-not-secret"
DEBUG = False
ALLOWED_HOSTS = ["*"]
ROOT_URLCONF = "bookshop.urls"
WSGI_APPLICATION = "bookshop.wsgi.application"
DEFAULT_AUTO_FIELD = "django.db.models.AutoField"

INSTALLED_APPS = [
    "rest_framework",
    "django_filters",
    "rest_framework_json_api",
    "drf_spectacular",
    "shop",
]

MIDDLEWARE = ["django.middleware.common.CommonMiddleware"]

DATABASES = {
    "default": dj_database_url.parse(
        os.environ.get(
            "DATABASE_URL",
            "postgres://eval_rw:eval_rw@postgres:5432/llmapi_eval",
        )
    )
}

USE_TZ = True
TIME_ZONE = "UTC"

PAYMENT_URL = os.environ.get("PAYMENT_URL", "http://127.0.0.1:18090/payments")

JSON_API_FORMAT_FIELD_NAMES = "camelize"
JSON_API_UNIFORM_EXCEPTIONS = True

REST_FRAMEWORK = {
    "PAGE_SIZE": 10,
    "DEFAULT_PAGINATION_CLASS": "shop.pagination.Pagination",
    "DEFAULT_PARSER_CLASSES": ["rest_framework_json_api.parsers.JSONParser"],
    "DEFAULT_RENDERER_CLASSES": ["rest_framework_json_api.renderers.JSONRenderer"],
    "DEFAULT_METADATA_CLASS": "rest_framework_json_api.metadata.JSONAPIMetadata",
    "DEFAULT_SCHEMA_CLASS": "shop.schema.AutoSchema",
    "DEFAULT_FILTER_BACKENDS": [
        "shop.filters.QueryParameterValidationFilter",
        "shop.filters.OrderingFilter",
        "shop.filters.DjangoFilterBackend",
    ],
    "EXCEPTION_HANDLER": "shop.errors.exception_handler",
    "SEARCH_PARAM": "filter[search]",
    "DEFAULT_AUTHENTICATION_CLASSES": [],
    "DEFAULT_PERMISSION_CLASSES": [],
    "UNAUTHENTICATED_USER": None,
}

SPECTACULAR_SETTINGS = {
    "TITLE": "Bookshop",
    "VERSION": "1.0.0",
    "OAS_VERSION": "3.1.0",
    "SERVE_INCLUDE_SCHEMA": False,
    "COMPONENT_SPLIT_REQUEST": True,
}
