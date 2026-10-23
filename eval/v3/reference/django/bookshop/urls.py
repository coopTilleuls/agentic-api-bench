from django.http import JsonResponse
from django.urls import path
from rest_framework.routers import SimpleRouter
from drf_spectacular.views import SpectacularJSONAPIView

from shop.views import AuthorViewSet, BookViewSet, PurchaseViewSet, SalesReportView

router = SimpleRouter(trailing_slash=False)
router.register("authors", AuthorViewSet, basename="author")
router.register("books", BookViewSet, basename="book")
router.register("purchases", PurchaseViewSet, basename="purchase")

urlpatterns = [
    path("reports/sales", SalesReportView.as_view()),
    path("openapi.json", SpectacularJSONAPIView.as_view()),
    *router.urls,
]


def not_found(request, exception=None):
    return JsonResponse(
        {"errors": [{"status": "404", "title": "Not Found", "detail": "Not found."}]},
        status=404,
        content_type="application/vnd.api+json",
    )


handler404 = not_found
