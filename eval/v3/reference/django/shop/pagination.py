from django.core.paginator import Paginator
from drf_spectacular_jsonapi.schemas.pagination import JsonApiPageNumberPagination

from shop.errors import QueryParameterError


class LenientPaginator(Paginator):
    def validate_number(self, number):
        return int(number)


class Pagination(JsonApiPageNumberPagination):
    max_page_size = 50
    django_paginator_class = LenientPaginator

    def get_page_size(self, request):
        raw = request.query_params.get(self.page_size_query_param)
        if raw is None:
            return self.page_size
        if not (raw.isascii() and raw.isdigit() and 1 <= int(raw) <= self.max_page_size):
            raise QueryParameterError(
                self.page_size_query_param,
                f"page[size] must be an integer between 1 and {self.max_page_size}.",
            )
        return int(raw)

    def paginate_queryset(self, queryset, request, view=None):
        raw = request.query_params.get(self.page_query_param)
        if raw is not None and not (raw.isascii() and raw.isdigit() and int(raw) >= 1):
            raise QueryParameterError(
                self.page_query_param, "page[number] must be a positive integer."
            )
        return super().paginate_queryset(queryset, request, view)

    def get_schema_operation_parameters(self, view):
        parameters = super().get_schema_operation_parameters(view)
        for parameter in parameters:
            if parameter["name"] == self.page_size_query_param:
                parameter["schema"].update(minimum=1, maximum=self.max_page_size)
            if parameter["name"] == self.page_query_param:
                parameter["schema"].update(minimum=1)
        return parameters
