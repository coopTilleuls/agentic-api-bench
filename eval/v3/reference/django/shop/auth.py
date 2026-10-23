import re

from rest_framework.authentication import BaseAuthentication
from rest_framework.exceptions import AuthenticationFailed

from shop.models import Customer


class CustomerAuthentication(BaseAuthentication):
    def authenticate_header(self, request):
        return "Customer-Id"

    def authenticate(self, request):
        raw = request.headers.get("Customer-Id", "")
        customer = None
        if re.fullmatch(r"[0-9]{1,9}", raw):
            customer = Customer.objects.filter(pk=int(raw)).first()
        if customer is None:
            raise AuthenticationFailed("Missing or unknown Customer-Id header.")
        return customer, None
