<?php

namespace App\JsonApi;

class OpenApiDocument
{
    private const ERRORS = [
        '402' => 'Payment declined',
        '409' => 'Conflict',
        '415' => 'Unsupported Media Type',
        '422' => 'Validation failed',
        '502' => 'Payment service failure',
    ];

    public static function patch(array $document): array
    {
        $document['components']['responses'] ??= [];
        foreach (self::ERRORS as $status => $description) {
            $document['components']['responses'][$status] = [
                'description' => $description,
                'content' => ['application/vnd.api+json' => ['schema' => ['$ref' => '#/components/schemas/helper.errors']]],
            ];
        }

        foreach ($document['paths']['/books']['get']['parameters'] as &$parameter) {
            if ('filter[author]' === $parameter['name']) {
                $parameter['description'] = 'Exact author name';
                $parameter['schema'] = ['type' => 'string'];
            }
            if ('sort' === $parameter['name']) {
                $parameter['schema']['items']['enum'] = array_values(array_filter(
                    $parameter['schema']['items']['enum'],
                    static fn (string $field) => !in_array(ltrim($field, '-'), ['id'], true),
                ));
            }
        }
        unset($parameter);
        foreach (['/books', '/books/{book}'] as $path) {
            $document['paths'][$path]['get']['parameters'][] = self::query('include', 'Related resources to include', ['type' => 'string', 'enum' => ['author']]);
        }

        foreach (['/purchases' => ['get', 'post'], '/purchases/{purchase}' => ['get']] as $path => $methods) {
            foreach ($methods as $method) {
                $document['paths'][$path][$method]['parameters'][] = [
                    'name' => 'Customer-Id',
                    'in' => 'header',
                    'required' => true,
                    'schema' => ['type' => 'integer'],
                ];
            }
        }

        $post = &$document['paths']['/purchases']['post']['responses'];
        $created = $post['200'];
        unset($post['200'], $post['404']);
        $post['201'] = ['description' => 'Purchase created'] + $created;
        $post['201']['headers']['Location'] = ['schema' => ['type' => 'string']];
        foreach (['402', '409', '422', '502'] as $status) {
            $post[$status] = ['$ref' => "#/components/responses/{$status}"];
        }
        ksort($post);
        unset($post);

        $patch = &$document['paths']['/books/{book}']['patch']['responses'];
        $patch['204'] = ['description' => 'Book updated (unpublished book)'];
        foreach (['409', '415', '422'] as $status) {
            $patch[$status] = ['$ref' => "#/components/responses/{$status}"];
        }
        ksort($patch);
        unset($patch);

        $document['paths']['/reports/sales'] = ['get' => [
            'summary' => 'Sales report',
            'operationId' => 'reports.sales',
            'parameters' => [
                self::query('from', 'First day, YYYY-MM-DD', ['type' => 'string', 'format' => 'date'], true),
                self::query('to', 'Last day, YYYY-MM-DD', ['type' => 'string', 'format' => 'date'], true),
            ],
            'responses' => [
                '200' => ['description' => 'CSV report', 'content' => ['text/csv' => ['schema' => ['type' => 'string']]]],
                '400' => ['$ref' => '#/components/responses/400'],
            ],
        ]];

        return $document;
    }

    private static function query(string $name, string $description, array $schema, bool $required = false): array
    {
        return ['name' => $name, 'in' => 'query', 'description' => $description, 'required' => $required, 'schema' => $schema];
    }
}
