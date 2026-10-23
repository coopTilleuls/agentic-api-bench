from drf_spectacular.extensions import OpenApiAuthenticationExtension
from drf_spectacular.types import OpenApiTypes
from drf_spectacular.utils import OpenApiParameter, OpenApiResponse
from drf_spectacular_jsonapi.schemas.openapi import DjangoJsonApiFilterExtension, JsonApiAutoSchema
from rest_framework import serializers
from rest_framework_json_api.utils import format_field_name

CUSTOMER_ID = OpenApiParameter(
    "Customer-Id",
    OpenApiTypes.INT,
    OpenApiParameter.HEADER,
    required=True,
    description="Id of the customer making the request.",
)


class AutoSchema(JsonApiAutoSchema):
    def _get_response_for_code(self, serializer, status_code, media_types=None, direction="response"):
        if int(status_code) >= 400 or int(status_code) == 204:
            return super(JsonApiAutoSchema, self)._get_response_for_code(
                serializer, status_code, media_types, direction
            )
        return super()._get_response_for_code(serializer, status_code, media_types, direction)


class CamelizedDjangoFilterExtension(DjangoJsonApiFilterExtension):
    priority = 2

    def resolve_filter_field(self, *args, **kwargs):
        result = super().resolve_filter_field(*args, **kwargs)
        for item in result:
            name = item["name"].removeprefix("filter[").removesuffix("]")
            item["name"] = f"filter[{format_field_name(name)}]"
        return result


class ErrorSource(serializers.Serializer):
    pointer = serializers.CharField(required=False)
    parameter = serializers.CharField(required=False)


class ErrorObject(serializers.Serializer):
    status = serializers.CharField()
    title = serializers.CharField()
    detail = serializers.CharField()
    source = ErrorSource(required=False)


class ErrorDocument(serializers.Serializer):
    errors = ErrorObject(many=True)


def error(description):
    return OpenApiResponse(ErrorDocument, description=description)


class CustomerAuthenticationScheme(OpenApiAuthenticationExtension):
    target_class = "shop.auth.CustomerAuthentication"
    name = "CustomerId"

    def get_security_definition(self, auto_schema):
        return {"type": "apiKey", "in": "header", "name": "Customer-Id"}
