<?php

declare(strict_types=1);

namespace PHAPI\Telemetry;

use OpenTelemetry\API\Trace\Propagation\TraceContextPropagator;
use OpenTelemetry\API\Trace\SpanKind;
use OpenTelemetry\API\Trace\StatusCode;
use OpenTelemetry\API\Trace\TracerInterface;
use PHAPI\HTTP\Request;
use PHAPI\HTTP\Response;

/**
 * Global middleware that creates a root SERVER span for every inbound HTTP request.
 *
 * Extracts W3C TraceContext (traceparent / tracestate) from request headers so
 * distributed traces propagate correctly across service boundaries.
 */
final class TracingMiddleware
{
    private TracerInterface $tracer;
    private TraceContextPropagator $propagator;

    public function __construct(TracerInterface $tracer, TraceContextPropagator $propagator)
    {
        $this->tracer = $tracer;
        $this->propagator = $propagator;
    }

    /**
     * @param callable(Request): Response $next
     */
    public function __invoke(Request $request, callable $next): Response
    {
        // Extract parent context from incoming headers.
        $parentContext = $this->propagator->extract(
            $request->headers(),
            HeadersGetter::instance()
        );

        $spanName = $request->method() . ' ' . $request->path();

        $spanBuilder = $this->tracer->spanBuilder($spanName)
            ->setSpanKind(SpanKind::KIND_SERVER)
            ->setParent($parentContext)
            ->setAttribute('http.method', $request->method())
            ->setAttribute('http.target', $request->path())
            ->setAttribute('http.url', $this->buildUrl($request));

        $host = $request->host();
        if ($host !== null) {
            $spanBuilder->setAttribute('net.host.name', $host);
        }

        $userAgent = $request->header('user-agent');
        if (\is_string($userAgent) && $userAgent !== '') {
            $spanBuilder->setAttribute('http.user_agent', $userAgent);
        }

        $requestId = $request->header('x-request-id');
        if (\is_string($requestId) && $requestId !== '') {
            $spanBuilder->setAttribute('http.request_id', $requestId);
        }

        $span = $spanBuilder->startSpan();
        $scope = $span->activate();

        try {
            /** @var Response $response */
            $response = $next($request);

            $span->setAttribute('http.status_code', $response->status());

            if ($response->status() >= 500) {
                $span->setStatus(StatusCode::STATUS_ERROR);
            }

            return $response;
        } catch (\Throwable $e) {
            $span->recordException($e);
            $span->setStatus(StatusCode::STATUS_ERROR, $e->getMessage());
            throw $e;
        } finally {
            $scope->detach();
            $span->end();
        }
    }

    private function buildUrl(Request $request): string
    {
        $host = $request->host() ?? 'unknown';
        $path = $request->path();
        $query = $request->queryAll();

        $url = 'http://' . $host . $path;
        if ($query !== []) {
            $url .= '?' . http_build_query($query);
        }

        return $url;
    }
}
