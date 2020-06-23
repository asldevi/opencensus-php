<?php
/**
 * Copyright 2017 OpenCensus Authors
 *
 * Licensed under the Apache License, Version 2.0 (the "License");
 * you may not use this file except in compliance with the License.
 * You may obtain a copy of the License at
 *
 *      http://www.apache.org/licenses/LICENSE-2.0
 *
 * Unless required by applicable law or agreed to in writing, software
 * distributed under the License is distributed on an "AS IS" BASIS,
 * WITHOUT WARRANTIES OR CONDITIONS OF ANY KIND, either express or implied.
 * See the License for the specific language governing permissions and
 * limitations under the License.
 */

namespace OpenCensus\Trace;

use OpenCensus\Core\Scope;
use OpenCensus\Trace\Exporter\ExporterInterface;
use OpenCensus\Trace\Propagator\ArrayHeaders;
use OpenCensus\Trace\Sampler\SamplerInterface;
use OpenCensus\Trace\Tracer\ContextTracer;
use OpenCensus\Trace\Tracer\ExtensionTracer;
use OpenCensus\Trace\Tracer\NullTracer;
use OpenCensus\Trace\Tracer\TracerInterface;
use OpenCensus\Trace\Propagator\PropagatorInterface;

/**
 * This class manages the logic for sampling and reporting a trace within a
 * single request. It is not meant to be used directly -- instead, it should
 * be managed by the Tracer as its singleton instance.
 */
class RequestHandler
{
    const DEFAULT_ROOT_SPAN_NAME = 'main';
    const ATTRIBUTE_MAP = [
        Span::ATTRIBUTE_HOST => ['HTTP_HOST', 'SERVER_NAME'],
        Span::ATTRIBUTE_PORT => ['SERVER_PORT'],
        Span::ATTRIBUTE_METHOD => ['REQUEST_METHOD'],
        Span::ATTRIBUTE_PATH => ['REQUEST_URI'],
        Span::ATTRIBUTE_USER_AGENT => ['HTTP_USER_AGENT']
    ];

    /**
     * @var ExporterInterface The reported to use at the end of the request
     */
    private $exporter;

    /**
     * @var TracerInterface The tracer to use for this request
     */
    private $tracer;


    //added now
    private $propagator;    

    /**
     * @var Span The primary span for this request
     */
    private $rootSpan;

    /**
     * @var Scope
     */
    private $scope;

    /**
     * @var ArrayHeaders Keeps the provided headers and has a fallback to $_SERVER if none were given.
     */
    private $headers;

    /**
     * Create a new RequestHandler.
     *
     * @param ExporterInterface $exporter How to report the trace at the end of the request
     * @param SamplerInterface $sampler Which sampler to use for sampling requests
     * @param PropagatorInterface $propagator SpanContext propagator
     * @param array $options [optional] {
     *      Configuration options. See
     *      <a href="Span.html#method___construct">OpenCensus\Trace\Span::__construct()</a>
     *      for the other available options.
     *
     *      @type array $headers Optional array of headers to use in place of $_SERVER
     *      @type bool $skipReporting If true, skips registering of onExit handler.
     * }
     */
    public function __construct(
        ExporterInterface $exporter,
        SamplerInterface $sampler,
        PropagatorInterface $propagator,
        array $options = []
    ) {
        $this->exporter = $exporter;
        $this->propagator = $propagator;
        $this->headers = new ArrayHeaders($options['headers'] ?? $_SERVER);

        $spanContext = $propagator->extract($this->headers);

        // If the context force disables tracing, don't consult the $sampler.
        if ($spanContext->enabled() !== false) {
            $spanContext->setEnabled($spanContext->enabled() || $sampler->shouldSample());
        }

        // If the request was provided with a trace context header, we need to send it back with the response
        // including whether the request was sampled or not.

        if ($spanContext->fromHeader()) {
            $propagator->inject($spanContext, $this->headers);
        }

        if ($spanContext->enabled()) {
            $this->tracer = extension_loaded('opencensus') ?
                new ExtensionTracer($spanContext) :
                new ContextTracer($spanContext);
        } else {
            $this->tracer = new NullTracer();
        }

        $rootSpanName = $this->nameFromOptions($options) ?? $this->nameFromHeaders($this->headers->toArray());
        $rootSpanAttrs = $this->spanAttrsFromOptions($options);
        unset($options['root_span_options']);

        $spanOptions = $options + [
            'startTime' => $this->startTimeFromHeaders($this->headers->toArray()),
            'name' => $rootSpanName,
            'attributes' => $rootSpanAttrs,
            'kind' => Span::KIND_SERVER,
            'sameProcessAsParentSpan' => false
        ];
        $this->rootSpan = $this->tracer->startSpan($spanOptions);
        $this->scope = $this->tracer->withSpan($this->rootSpan);

        if (!array_key_exists('skipReporting', $options) || !$options['skipReporting']) {
            register_shutdown_function([$this, 'onExit']);
        }
    }

    /**
     * The function registered as the shutdown function. Cleans up the trace and
     * reports using the provided ExporterInterface. Adds additional attributes to
     * the root span detected from the response.
     */
    public function onExit()
    {
        $this->addCommonRequestAttributes($this->headers->toArray());

        $this->scope->close();

        $this->exporter->export($this->tracer->spans());
    }

    /**
     * Return the tracer used for this request.
     *
     * @return TracerInterface
     */
    public function tracer(): TracerInterface
    {
        return $this->tracer;
    }

    /**
     * Instrument a callable by creating a Span that manages the startTime
     * and endTime. If an exception is thrown while executing the callable, the
     * exception will be caught, the span will be closed, and the exception will
     * be re-thrown.
     *
     * @param array $spanOptions Options for the span. See
     *        <a href="Span.html#method___construct">OpenCensus\Trace\Span::__construct()</a>
     * @param callable $callable The callable to instrument.
     * @param array $arguments
     * @return mixed Returns whatever the callable returns
     */
    public function inSpan(array $spanOptions, callable $callable, array $arguments = [])
    {
        return $this->tracer->inSpan($spanOptions, $callable, $arguments);
    }

    /**
     * Explicitly start a new Span. You will need to manage finishing the Span,
     * including handling any thrown exceptions.
     *
     * @param array $spanOptions [optional] Options for the span. See
     *        <a href="Span.html#method___construct">OpenCensus\Trace\Span::__construct()</a>
     * @return Span
     */
    public function startSpan(array $spanOptions = []): Span
    {
        return $this->tracer->startSpan($spanOptions);
    }

    /**
     * Attaches the provided span as the current span and returns a Scope
     * object which must be closed.
     *
     * @param Span $span
     * @return Scope
     */
    public function withSpan(Span $span): Scope
    {
        return $this->tracer->withSpan($span);
    }

    /**
     * Add an attribute to the provided Span
     *
     * @param string $attribute
     * @param string $value
     * @param array $options [optional] Configuration options.
     *
     *      @type Span $span The span to add the attribute to.
     */
    public function addAttribute($attribute, $value, $options = [])
    {
        $this->tracer->addAttribute($attribute, $value, $options);
    }

    /**
     * Add an annotation to the provided Span
     *
     * @param string $description
     * @param array $options [optional] Configuration options.
     *
     *      @type Span $span The span to add the annotation to.
     *      @type array $attributes Attributes for this annotation.
     *      @type \DateTimeInterface|int|float $time The time of this event.
     */
    public function addAnnotation($description, $options = [])
    {
        $this->tracer->addAnnotation($description, $options);
    }

    /**
     * Add a link to the provided Span
     *
     * @param string $traceId
     * @param string $spanId
     * @param array $options [optional] Configuration options.
     *
     *      @type Span $span The span to add the link to.
     *      @type string $type The relationship of the current span relative to
     *            the linked span: child, parent, or unspecified.
     *      @type array $attributes Attributes for this annotation.
     *      @type \DateTimeInterface|int|float $time The time of this event.
     */
    public function addLink($traceId, $spanId, $options = [])
    {
        $this->tracer->addLink($traceId, $spanId, $options);
    }

    /**
     * Add an message event to the provided Span
     *
     * @param string $type
     * @param string $id
     * @param array $options [optional] Configuration options.
     *
     *      @type Span $span The span to add the message event to.
     *      @type int $uncompressedSize The number of uncompressed bytes sent or
     *            received.
     *      @type int $compressedSize The number of compressed bytes sent or
     *            received. If missing assumed to be the same size as
     *            uncompressed.
     *      @type \DateTimeInterface|int|float $time The time of this event.
     */
    public function addMessageEvent($type, $id, $options)
    {
        $this->tracer->addMessageEvent($type, $id, $options);
    }

    private function addCommonRequestAttributes(array $headers)
    {
        if ($responseCode = http_response_code()) {
            $this->rootSpan->setStatus($responseCode, "HTTP status code: $responseCode");
            $this->tracer->addAttribute(Span::ATTRIBUTE_STATUS_CODE, $responseCode, [
                'spanId' => $this->rootSpan->spanId()
            ]);
            if ($responseCode >= 400) {
                $this->tracer->addAttribute('error', 'true', [
                    'spanId' => $this->rootSpan->spanId()
                ]);
            }
        }

        foreach (self::ATTRIBUTE_MAP as $attributeKey => $headerKeys) {
            if ($val = $this->detectKey($headerKeys, $headers)) {
                $this->tracer->addAttribute($attributeKey, $val, [
                    'spanId' => $this->rootSpan->spanId()
                ]);
            }
        }

        // add all query parameters as tags
        parse_str($headers['QUERY_STRING'], $queryParams);

        foreach ($queryParams as $key => $value) {
            if(is_array($value)){
                $value = implode(', ', $value);
            }
            $this->tracer->addAttribute($key, $value, [
                'spanId' => $this->rootSpan->spanId()
            ]);
        }
    }

    private function startTimeFromHeaders(array $headers)
    {
        if (array_key_exists('REQUEST_TIME_FLOAT', $headers)) {
            return $headers['REQUEST_TIME_FLOAT'];
        }
        if (array_key_exists('REQUEST_TIME', $headers)) {
            return $headers['REQUEST_TIME'];
        }
        return null;
    }

    private function nameFromOptions(array $options): string
    {
        $rootSpanOptions = array_key_exists('root_span_options', $options)
                            ? $options['root_span_options']
                            : array();

        return array_key_exists('name', $rootSpanOptions) ? $rootSpanOptions['name'] : null;
    }

    private function spanAttrsFromOptions(array $options): array
    {
        $rootSpanOptions = array_key_exists('root_span_options', $options)
                            ? $options['root_span_options']
                            : array();
        return array_key_exists('attributes', $rootSpanOptions) ? $rootSpanOptions['attributes'] : array();
    }

    private function nameFromHeaders(array $headers): string
    {
        if (array_key_exists('REQUEST_URI', $headers)) {
            return strtok($headers['REQUEST_URI'], '?');
        }
        else {
            return self::DEFAULT_ROOT_SPAN_NAME;
        }
    }

    private function detectKey(array $keys, array $array)
    {
        foreach ($keys as $key) {
            if (array_key_exists($key, $array)) {
                return $array[$key];
            }
        }
        return null;
    }

    public function inject(SpanContext $context, ArrayHeaders $headers)
    {
        $this->propagator->inject($context, $headers);
    }
}
