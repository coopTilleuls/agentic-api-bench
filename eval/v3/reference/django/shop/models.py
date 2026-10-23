from django.db import models
from django.db.models.functions import Now


class Author(models.Model):
    name = models.TextField(null=True)

    class Meta:
        managed = False
        db_table = "author"


class Book(models.Model):
    title = models.TextField()
    author = models.ForeignKey(Author, models.DO_NOTHING, related_name="books")
    published = models.BooleanField(default=False)
    price_cents = models.IntegerField()
    published_at = models.DateField(null=True)
    stock = models.IntegerField(default=0)

    class Meta:
        managed = False
        db_table = "book"


class Customer(models.Model):
    name = models.TextField()
    email = models.TextField(unique=True)

    class Meta:
        managed = False
        db_table = "customer"


class Purchase(models.Model):
    customer = models.ForeignKey(Customer, models.DO_NOTHING)
    created_at = models.DateTimeField(db_default=Now())
    total_cents = models.IntegerField()

    class Meta:
        managed = False
        db_table = "purchase"


class PurchaseLine(models.Model):
    purchase = models.ForeignKey(Purchase, models.DO_NOTHING, related_name="lines")
    book = models.ForeignKey(Book, models.DO_NOTHING)
    quantity = models.IntegerField()
    unit_price_cents = models.IntegerField()

    class Meta:
        managed = False
        db_table = "purchase_line"
