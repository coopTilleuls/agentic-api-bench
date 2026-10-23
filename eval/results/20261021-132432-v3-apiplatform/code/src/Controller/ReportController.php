<?php

namespace App\Controller;

use App\Http\ApiException;
use App\JsonApi\Query;
use App\Repository\SalesRepository;
use App\Service\Csv;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

final class ReportController
{
    public function __construct(private readonly SalesRepository $sales)
    {
    }

    #[Route('/reports/sales', name: 'reports_sales', methods: ['GET'])]
    public function sales(Request $request): Response
    {
        $from = Query::requiredDate($request, 'from');
        $to = Query::requiredDate($request, 'to');
        if ($from > $to) {
            throw ApiException::error(400, 'from must not be after to.', parameter: 'from');
        }

        $csv = Csv::line(['title', 'quantity', 'revenueCents']);
        foreach ($this->sales->soldBetween($from, $to) as $row) {
            $csv .= Csv::line([$row['title'], (int) $row['quantity'], (int) $row['revenue']]);
        }

        return new Response($csv, 200, [
            'Content-Type' => 'text/csv',
            'Content-Disposition' => 'attachment; filename="sales.csv"',
        ]);
    }
}
