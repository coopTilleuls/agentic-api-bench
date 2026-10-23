<?php

namespace App\State;

use ApiPlatform\Metadata\Operation;
use ApiPlatform\State\ProviderInterface;
use App\Entity\PurchaseLine;
use DateTimeImmutable;
use DateTimeZone;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\BadRequestHttpException;

final readonly class SalesReportProvider implements ProviderInterface
{
    public function __construct(private EntityManagerInterface $entityManager)
    {
    }

    public function provide(Operation $operation, array $uriVariables = [], array $context = []): Response
    {
        $query = $context['request']->query;
        $utc = new DateTimeZone('UTC');
        $from = new DateTimeImmutable($query->getString('from').' 00:00:00', $utc);
        $to = new DateTimeImmutable($query->getString('to').' 00:00:00', $utc);
        if ($from > $to) {
            throw new BadRequestHttpException('"from" must not be after "to".');
        }

        $rows = $this->entityManager->createQueryBuilder()
            ->select('b.title AS title', 'SUM(l.quantity) AS quantity', 'SUM(l.quantity * l.unitPriceCents) AS revenueCents')
            ->from(PurchaseLine::class, 'l')
            ->join('l.book', 'b')
            ->join('l.purchase', 'p')
            ->where('p.createdAt >= :from AND p.createdAt < :end')
            ->setParameter('from', $from)
            ->setParameter('end', $to->modify('+1 day'))
            ->groupBy('b.id')
            ->orderBy('revenueCents', 'DESC')
            ->addOrderBy('b.title')
            ->addOrderBy('b.id')
            ->getQuery()
            ->getArrayResult();

        $csv = "title,quantity,revenueCents\r\n";
        foreach ($rows as $row) {
            $title = preg_match('/[",\r\n]/', $row['title']) ? '"'.str_replace('"', '""', $row['title']).'"' : $row['title'];
            $csv .= \sprintf("%s,%d,%d\r\n", $title, $row['quantity'], $row['revenueCents']);
        }

        return new Response($csv, headers: ['Content-Type' => 'text/csv; charset=utf-8']);
    }
}
