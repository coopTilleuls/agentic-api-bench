<?php

namespace App\Http\Controllers;

use App\JsonApi\V1\Purchases\PurchaseQuery;
use App\JsonApi\V1\Purchases\PurchaseRequest;
use App\Models\Book;
use App\Models\Purchase;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\RequestException;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use LaravelJsonApi\Core\Document\Error;
use LaravelJsonApi\Core\Exceptions\JsonApiException;
use LaravelJsonApi\Core\Responses\DataResponse;
use LaravelJsonApi\Laravel\Http\Controllers\JsonApiController;

class PurchaseController extends JsonApiController
{
    public function creating(PurchaseRequest $request, PurchaseQuery $query): DataResponse
    {
        $customer = Auth::guard('customer')->user();
        $lines = collect($request->validated('lines'));
        $wanted = $lines->groupBy('bookId')->map(static fn ($group) => $group->sum('quantity'));

        $purchase = DB::transaction(function () use ($customer, $lines, $wanted) {
            $books = Book::query()->published()->whereKey($wanted->keys())->orderBy('id')->lockForUpdate()->get()->keyBy('id');

            foreach ($wanted as $bookId => $quantity) {
                if ($books[$bookId]->stock < $quantity) {
                    throw self::error(409, 'Insufficient stock', "Not enough copies of book {$bookId}.");
                }
            }

            $total = $lines->sum(static fn (array $line) => $line['quantity'] * $books[$line['bookId']]->price_cents);
            $this->charge($customer->id, $total);

            $purchase = Purchase::create(['customer_id' => $customer->id, 'total_cents' => $total]);
            foreach ($lines as $line) {
                $purchase->lines()->create([
                    'book_id' => $line['bookId'],
                    'quantity' => $line['quantity'],
                    'unit_price_cents' => $books[$line['bookId']]->price_cents,
                ]);
            }
            foreach ($wanted as $bookId => $quantity) {
                $books[$bookId]->decrement('stock', $quantity);
            }

            return $purchase->refresh();
        });

        return DataResponse::make($purchase)->withQueryParameters($query)->didCreate();
    }

    private function charge(int $customerId, int $amountCents): void
    {
        try {
            $status = Http::timeout(5)->acceptJson()
                ->post(config('services.payment.url'), ['customerId' => $customerId, 'amountCents' => $amountCents])
                ->throw()
                ->json('status');
        } catch (ConnectionException|RequestException $e) {
            throw self::error(502, 'Payment service failure', 'The payment service is unavailable.', $e);
        }

        match ($status) {
            'accepted' => null,
            'declined' => throw self::error(402, 'Payment declined', 'The payment was declined.'),
            default => throw self::error(502, 'Payment service failure', 'Unexpected answer from the payment service.'),
        };
    }

    private static function error(int $status, string $title, string $detail, ?\Throwable $previous = null): JsonApiException
    {
        return JsonApiException::make(
            Error::make()->setStatus($status)->setTitle($title)->setDetail($detail),
            $previous,
        );
    }
}
