<?php

declare(strict_types=1);

namespace Challenge;

use Challenge\Database\ConnectionFactory;
use Challenge\Http\JsonResponder;
use Challenge\Http\Validator;
use Challenge\Repositories\VisitorAnalyticsRepository;
use Challenge\Segment\SegmentRules;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use Psr\Http\Server\RequestHandlerInterface as Handler;
use Slim\App;
use Slim\Factory\AppFactory as SlimAppFactory;

final class AppFactory
{
    public static function create(): App
    {
        $app = SlimAppFactory::create();
        $app->addBodyParsingMiddleware();

        $app->add(static function (Request $request, Handler $handler): Response {
            return $handler->handle($request)
                ->withHeader('X-Content-Type-Options', 'nosniff')
                ->withHeader('X-Frame-Options', 'DENY')
                ->withHeader('Referrer-Policy', 'no-referrer');
        });

        $pdo        = ConnectionFactory::createFromEnvironment();
        $repository = new VisitorAnalyticsRepository($pdo);

        $app->get('/health', function (Request $request, Response $response) use ($pdo): Response {
            $pdo->query('SELECT 1')->fetchColumn();

            return JsonResponder::json($response, [
                'status'   => 'ok',
                'database' => 'connected',
            ]);
        });

        $app->get('/api/accounts/{accountId:[0-9]+}/visitors/active', function (Request $request, Response $response, array $args) use ($repository): Response {
            $accountId = (int) $args['accountId'];
            $query     = $request->getQueryParams();

            $from = $query['from'] ?? null;
            $to   = $query['to'] ?? null;

            $validator = (new Validator())
                ->date('from', $from)
                ->date('to', $to)
                ->dateRange('from', $from, $to);

            if ($validator->fails()) {
                return JsonResponder::json($response, [
                    'error'  => 'validation_failed',
                    'fields' => $validator->errors(),
                ], 422);
            }

            $visitors = $repository->activeVisitors($accountId, (string) $from, (string) $to);

            return JsonResponder::json($response, ['data' => $visitors]);
        });

        $app->post('/api/accounts/{accountId:[0-9]+}/segments/preview', function (Request $request, Response $response, array $args) use ($repository): Response {
            $accountId  = (int) $args['accountId'];
            $parsedBody = $request->getParsedBody();

            if (!is_array($parsedBody)) {
                return JsonResponder::json($response, ['error' => 'invalid_request_body'], 400);
            }

            $rules = isset($parsedBody['rules']) && is_array($parsedBody['rules']) ? $parsedBody['rules'] : [];
            $limit = $parsedBody['limit'] ?? 25;

            $validator = (new Validator())
                ->nonEmptyString('rules.visited_path', $rules['visited_path'] ?? null)
                ->intMin('rules.min_page_views', $rules['min_page_views'] ?? null, 1)
                ->bool('rules.identified_only', $rules['identified_only'] ?? null)
                ->date('rules.from', $rules['from'] ?? null)
                ->date('rules.to', $rules['to'] ?? null)
                ->dateRange('rules.from', $rules['from'] ?? null, $rules['to'] ?? null)
                ->intBetween('limit', $limit, 1, 100);

            if ($validator->fails()) {
                return JsonResponder::json($response, [
                    'error'  => 'validation_failed',
                    'fields' => $validator->errors(),
                ], 422);
            }

            $segmentRules = new SegmentRules(
                visitedPath:    (string) $rules['visited_path'],
                minPageViews:   (int) $rules['min_page_views'],
                identifiedOnly: (bool) $rules['identified_only'],
                from:           (string) $rules['from'],
                to:             (string) $rules['to'],
            );

            $result = $repository->segmentPreview($accountId, $segmentRules, (int) $limit);

            return JsonResponder::json($response, $result);
        });

        $env   = getenv('APP_ENV') ?: 'local';
        $debug = $env === 'local';
        $app->addErrorMiddleware($debug, $debug, $debug);

        return $app;
    }
}
