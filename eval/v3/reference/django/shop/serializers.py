from collections import Counter
import json
import urllib.error
import urllib.request

from django.conf import settings
from django.db import transaction
from django.db.models import IntegerField, TextField
from django.db.models import F
from rest_framework import serializers
from rest_framework.exceptions import ValidationError
from rest_framework_json_api import serializers as json_api_serializers
from rest_framework_json_api.exceptions import Conflict
from rest_framework_json_api.relations import ResourceRelatedField

from shop.errors import PaymentDeclined, PaymentUnavailable
from shop.models import Author, Book, Purchase, PurchaseLine


class StrictIntegerField(serializers.IntegerField):
    def to_internal_value(self, data):
        if not isinstance(data, int) or isinstance(data, bool):
            self.fail("invalid")
        return super().to_internal_value(data)


class StrictCharField(serializers.CharField):
    def to_internal_value(self, data):
        if not isinstance(data, str):
            self.fail("invalid")
        return super().to_internal_value(data)


class StrictPrimaryKeyRelatedField(serializers.PrimaryKeyRelatedField):
    def to_internal_value(self, data):
        if not isinstance(data, int) or isinstance(data, bool):
            self.fail("incorrect_type", data_type=type(data).__name__)
        return super().to_internal_value(data)


class StrictModelSerializer(json_api_serializers.HyperlinkedModelSerializer):
    serializer_field_mapping = {
        **json_api_serializers.HyperlinkedModelSerializer.serializer_field_mapping,
        IntegerField: StrictIntegerField,
        TextField: StrictCharField,
    }


class BookSerializer(StrictModelSerializer):
    author = ResourceRelatedField(read_only=True)
    included_serializers = {"author": "shop.serializers.AuthorSerializer"}

    class Meta:
        model = Book
        resource_name = "books"
        fields = ["url", "title", "price_cents", "published_at", "stock", "author"]
        read_only_fields = ["published_at"]
        extra_kwargs = {
            "title": {"allow_null": False, "allow_blank": False},
            "price_cents": {"min_value": 0},
            "stock": {"min_value": 0},
        }


class AuthorSerializer(json_api_serializers.HyperlinkedModelSerializer):
    published_books = serializers.IntegerField(read_only=True)
    books = ResourceRelatedField(many=True, read_only=True)
    included_serializers = {"books": BookSerializer}

    class Meta:
        model = Author
        resource_name = "authors"
        fields = ["url", "name", "published_books", "books"]


class LineSerializer(serializers.Serializer):
    bookId = StrictPrimaryKeyRelatedField(
        source="book", queryset=Book.objects.filter(published=True)
    )
    title = serializers.CharField(source="book.title", read_only=True)
    quantity = StrictIntegerField(min_value=1)
    unitPriceCents = serializers.IntegerField(source="unit_price_cents", read_only=True)


class PurchaseSerializer(json_api_serializers.HyperlinkedModelSerializer):
    lines = LineSerializer(many=True, allow_empty=False)

    class Meta:
        model = Purchase
        resource_name = "purchases"
        fields = ["url", "created_at", "total_cents", "lines"]
        read_only_fields = ["created_at", "total_cents"]

    def create(self, validated_data):
        customer = self.context["request"].user
        wanted = Counter()
        for line in validated_data["lines"]:
            wanted[line["book"].pk] += line["quantity"]

        with transaction.atomic():
            books = {
                book.pk: book
                for book in Book.objects.select_for_update()
                .filter(pk__in=wanted, published=True)
                .order_by("pk")
            }
            for pk, quantity in wanted.items():
                if books[pk].stock < quantity:
                    raise Conflict(f"Not enough stock for book {pk}.")
            total = sum(
                books[line["book"].pk].price_cents * line["quantity"]
                for line in validated_data["lines"]
            )
            self.charge(customer.pk, total)
            purchase = Purchase.objects.create(customer=customer, total_cents=total)
            PurchaseLine.objects.bulk_create(
                PurchaseLine(
                    purchase=purchase,
                    book_id=line["book"].pk,
                    quantity=line["quantity"],
                    unit_price_cents=books[line["book"].pk].price_cents,
                )
                for line in validated_data["lines"]
            )
            for pk, quantity in wanted.items():
                Book.objects.filter(pk=pk).update(stock=F("stock") - quantity)
        purchase.refresh_from_db()
        return purchase

    @staticmethod
    def charge(customer_id, amount_cents):
        request = urllib.request.Request(
            settings.PAYMENT_URL,
            data=json.dumps({"customerId": customer_id, "amountCents": amount_cents}).encode(),
            headers={"Content-Type": "application/json"},
        )
        try:
            with urllib.request.urlopen(request, timeout=5) as response:
                status = json.load(response).get("status")
        except (OSError, ValueError, AttributeError) as exc:
            raise PaymentUnavailable() from exc
        if status == "declined":
            raise PaymentDeclined()
        if status != "accepted":
            raise PaymentUnavailable()
