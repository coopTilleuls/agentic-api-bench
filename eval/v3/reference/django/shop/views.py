import csv
import io
import re
from datetime import datetime, time, timedelta, timezone

from django.db.models import Count, F, Prefetch, Q, Sum
from django.http import HttpResponse
from drf_spectacular.openapi import AutoSchema as PlainAutoSchema
from drf_spectacular.types import OpenApiTypes
from drf_spectacular.utils import OpenApiParameter, OpenApiResponse, extend_schema, extend_schema_view
from rest_framework import mixins
from rest_framework.views import APIView
from rest_framework_json_api import views as json_api_views

from shop.auth import CustomerAuthentication
from shop.errors import QueryParameterError
from shop.filters import BookFilter, QueryParameterValidationFilter
from shop.models import Author, Book, Purchase, PurchaseLine
from shop.schema import CUSTOMER_ID, error
from shop.serializers import AuthorSerializer, BookSerializer, PurchaseSerializer


def authors_with_published_books():
    return (
        Author.objects.annotate(published_books=Count("books", filter=Q(books__published=True)))
        .prefetch_related(
            Prefetch("books", queryset=Book.objects.filter(published=True).order_by("id"))
        )
        .order_by("id")
    )


@extend_schema_view(
    list=extend_schema(responses={200: AuthorSerializer(many=True), 400: error("Invalid query parameter")}),
    retrieve=extend_schema(responses={200: AuthorSerializer(), 400: error("Invalid include"), 404: error("Unknown author")}),
)
class AuthorViewSet(json_api_views.ReadOnlyModelViewSet):
    serializer_class = AuthorSerializer
    filter_backends = [QueryParameterValidationFilter]
    queryset = authors_with_published_books()


@extend_schema_view(
    list=extend_schema(responses={200: BookSerializer(many=True), 400: error("Invalid query parameter")}),
    retrieve=extend_schema(responses={200: BookSerializer(), 400: error("Invalid include"), 404: error("Unknown or unpublished book")}),
    partial_update=extend_schema(
        responses={
            200: BookSerializer(),
            204: OpenApiResponse(description="Book updated (unpublished book, no content)"),
            404: error("Unknown book"),
            409: error("data.id differs from the path id"),
            415: error("Content-Type is not application/vnd.api+json"),
            422: error("Invalid attribute"),
        }
    ),
)
class BookViewSet(mixins.UpdateModelMixin, json_api_views.ReadOnlyModelViewSet):
    serializer_class = BookSerializer
    filterset_class = BookFilter
    ordering_fields = ["price_cents", "title", "published_at"]
    ordering = ["id"]
    http_method_names = ["get", "head", "options", "patch"]

    def get_queryset(self):
        books = Book.objects.prefetch_related(
            Prefetch("author", queryset=authors_with_published_books())
        )
        if self.action == "partial_update":
            return books
        return books.filter(published=True)

    def update(self, request, *args, **kwargs):
        response = super().update(request, *args, **kwargs)
        if not self.get_object().published:
            response.status_code = 204
            response.data = None
        return response


@extend_schema_view(
    list=extend_schema(
        parameters=[CUSTOMER_ID], responses={200: PurchaseSerializer(many=True), 400: error("Invalid query parameter"), 401: error("Missing or unknown customer")}
    ),
    retrieve=extend_schema(
        parameters=[CUSTOMER_ID], responses={200: PurchaseSerializer(), 401: error("Missing or unknown customer"), 404: error("Unknown purchase")}
    ),
    create=extend_schema(
        parameters=[CUSTOMER_ID],
        responses={
            201: PurchaseSerializer(),
            401: error("Missing or unknown customer"),
            402: error("Payment declined"),
            409: error("Insufficient stock"),
            415: error("Content-Type is not application/vnd.api+json"),
            422: error("Invalid lines"),
            502: error("Payment service failure"),
        },
    ),
)
class PurchaseViewSet(
    mixins.CreateModelMixin, json_api_views.ReadOnlyModelViewSet
):
    serializer_class = PurchaseSerializer
    authentication_classes = [CustomerAuthentication]
    filter_backends = [QueryParameterValidationFilter]
    http_method_names = ["get", "head", "options", "post"]

    def get_queryset(self):
        return (
            Purchase.objects.filter(customer=self.request.user)
            .prefetch_related(
                Prefetch("lines", PurchaseLine.objects.select_related("book").order_by("id"))
            )
            .order_by("-created_at", "-id")
        )


DATE = re.compile(r"\d{4}-\d{2}-\d{2}")


def parse_day(request, name):
    raw = request.query_params.get(name)
    try:
        if raw is None or not DATE.fullmatch(raw):
            raise ValueError
        return datetime.strptime(raw, "%Y-%m-%d").date()
    except ValueError:
        raise QueryParameterError(name, f"{name} is required and must be a YYYY-MM-DD date.")


class SalesReportView(APIView):
    schema = PlainAutoSchema()
    authentication_classes = []
    permission_classes = []

    @extend_schema(
        request=None,
        parameters=[
            OpenApiParameter("from", OpenApiTypes.DATE, required=True),
            OpenApiParameter("to", OpenApiTypes.DATE, required=True),
        ],
        responses={
            (200, "text/csv"): OpenApiTypes.STR,
            400: error("Missing or malformed from/to, or from after to"),
        },
    )
    def get(self, request):
        first, last = parse_day(request, "from"), parse_day(request, "to")
        if first > last:
            raise QueryParameterError("from", "from must not be after to.")
        rows = (
            PurchaseLine.objects.filter(
                purchase__created_at__gte=datetime.combine(first, time.min, timezone.utc),
                purchase__created_at__lt=datetime.combine(last + timedelta(days=1), time.min, timezone.utc),
            )
            .values("book_id", "book__title")
            .annotate(quantity_sold=Sum("quantity"), revenue=Sum(F("quantity") * F("unit_price_cents")))
            .order_by("-revenue", "book__title", "book_id")
        )
        out = io.StringIO()
        writer = csv.writer(out, lineterminator="\r\n")
        writer.writerow(["title", "quantity", "revenueCents"])
        for row in rows:
            writer.writerow([row["book__title"], row["quantity_sold"], row["revenue"]])
        return HttpResponse(out.getvalue(), content_type="text/csv; charset=utf-8")
