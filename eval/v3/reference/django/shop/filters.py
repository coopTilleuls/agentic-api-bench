import re

from django import forms
from django_filters import CharFilter, ChoiceFilter, DateFilter, NumberFilter
from django_filters.rest_framework import FilterSet
from rest_framework_json_api import django_filters
from rest_framework_json_api import filters as json_api_filters

from shop.errors import QueryParameterError
from shop.models import Book

FAMILIES = r"sort|include|filter|fields|page"


class QueryParameterValidationFilter(json_api_filters.QueryParameterValidationFilter):
    query_regex = re.compile(
        rf"^(sort|include)$|^(?P<type>filter|fields|page)(\[[\w\.\-]+\])?$"
        rf"|^(?!(?:{FAMILIES})(?:\[|$))"
    )


class OrderingFilter(json_api_filters.OrderingFilter):
    def remove_invalid_fields(self, queryset, fields, view, request):
        try:
            return super().remove_invalid_fields(queryset, fields, view, request)
        except QueryParameterError:
            raise
        except Exception as exc:
            raise QueryParameterError("sort", exc.detail[0]) from exc

    def filter_queryset(self, request, queryset, view):
        ordered = super().filter_queryset(request, queryset, view)
        terms = [term for term in ordered.query.order_by if term.lstrip("-") != "id"]
        return ordered.order_by(*terms, "id")


class DjangoFilterBackend(django_filters.DjangoFilterBackend):
    def _validate_filter(self, keys, filterset_class):
        pass

    def get_filterset_kwargs(self, request, queryset, view):
        for name, values in request.query_params.lists():
            if self.filter_regex.match(name) and name != self.search_param and not all(values):
                raise QueryParameterError(name, f"missing value for query parameter {name}")
        return super().get_filterset_kwargs(request, queryset, view)


class IntegerFilter(NumberFilter):
    field_class = forms.IntegerField


class BookFilter(FilterSet):
    title = CharFilter(lookup_expr="icontains")
    author = CharFilter(field_name="author__name")
    min_price = IntegerFilter(field_name="price_cents", lookup_expr="gte")
    max_price = IntegerFilter(field_name="price_cents", lookup_expr="lte")
    published_after = DateFilter(
        field_name="published_at", lookup_expr="gte", input_formats=["%Y-%m-%d"]
    )
    published_before = DateFilter(
        field_name="published_at", lookup_expr="lte", input_formats=["%Y-%m-%d"]
    )
    in_stock = ChoiceFilter(
        choices=[("true", "true"), ("false", "false")], method="filter_in_stock"
    )

    class Meta:
        model = Book
        fields = []

    def filter_in_stock(self, queryset, name, value):
        if value == "true":
            return queryset.filter(stock__gt=0)
        return queryset.filter(stock=0)
