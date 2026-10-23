from http import HTTPStatus

from rest_framework import status
from rest_framework.exceptions import APIException, ParseError, ValidationError
from rest_framework_json_api.exceptions import exception_handler as json_api_exception_handler


class QueryParameterError(ValidationError):
    def __init__(self, parameter, detail):
        super().__init__(detail)
        self.parameter = parameter


class PaymentDeclined(APIException):
    status_code = status.HTTP_402_PAYMENT_REQUIRED
    default_detail = "Payment declined."


class PaymentUnavailable(APIException):
    status_code = status.HTTP_502_BAD_GATEWAY
    default_detail = "Payment service unavailable."


def exception_handler(exc, context):
    response = json_api_exception_handler(exc, context)
    if response is None:
        return response

    is_read = context["request"].method in ("GET", "HEAD")
    is_query_error = isinstance(exc, (QueryParameterError, ParseError)) and is_read
    if isinstance(exc, ValidationError) and not is_read:
        response.status_code = status.HTTP_422_UNPROCESSABLE_ENTITY

    for error in response.data:
        error["status"] = str(response.status_code)
        error["title"] = HTTPStatus(response.status_code).phrase
        source = error.get("source", {})
        pointer = source.pop("pointer", None)
        if pointer:
            pointer = pointer.removesuffix("/non_field_errors")
        if isinstance(exc, QueryParameterError):
            source["parameter"] = exc.parameter
        elif is_read and isinstance(exc, ValidationError) and pointer:
            source["parameter"] = "filter[%s]" % pointer.rsplit("/", 1)[-1]
        elif is_query_error:
            source["parameter"] = "include"
        else:
            if pointer is not None:
                source["pointer"] = pointer
        if source:
            error["source"] = source
        else:
            error.pop("source", None)
    return response
