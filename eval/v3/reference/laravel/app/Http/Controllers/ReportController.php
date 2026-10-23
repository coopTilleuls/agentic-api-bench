<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use LaravelJsonApi\Core\Document\Error;
use LaravelJsonApi\Core\Exceptions\JsonApiException;

class ReportController
{
    public function __invoke(Request $request): Response
    {
        $validator = Validator::make($request->query(), [
            'from' => ['required', 'string', 'date_format:Y-m-d'],
            'to' => ['required', 'string', 'date_format:Y-m-d', 'after_or_equal:from'],
        ]);

        if ($validator->fails()) {
            throw JsonApiException::make(collect($validator->errors()->messages())->flatMap(
                static fn (array $messages, string $parameter) => array_map(
                    static fn (string $message) => Error::make()->setStatus(400)->setTitle('Invalid query parameter')
                        ->setDetail($message)->setSourceParameter($parameter),
                    $messages,
                )
            )->all());
        }

        $rows = DB::select(
            'SELECT b.title, SUM(l.quantity)::int AS quantity, SUM(l.quantity * l.unit_price_cents)::bigint AS revenue
             FROM purchase_line l
             JOIN purchase p ON p.id = l.purchase_id
             JOIN book b ON b.id = l.book_id
             WHERE p.created_at >= ? AND p.created_at < ?
             GROUP BY b.id, b.title
             ORDER BY revenue DESC, b.title COLLATE "C", b.id',
            [
                Carbon::parse($request->query('from'), 'UTC')->startOfDay(),
                Carbon::parse($request->query('to'), 'UTC')->addDay()->startOfDay(),
            ],
        );

        $csv = "title,quantity,revenueCents\r\n";
        foreach ($rows as $row) {
            $title = preg_match('/[",\r\n]/', $row->title) ? '"'.str_replace('"', '""', $row->title).'"' : $row->title;
            $csv .= "{$title},{$row->quantity},{$row->revenue}\r\n";
        }

        return response($csv, 200, [
            'Content-Type' => 'text/csv; charset=utf-8',
            'Content-Disposition' => 'attachment; filename="sales.csv"',
        ]);
    }
}
