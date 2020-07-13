<?php
// from https://github.com/census-instrumentation/opencensus-php/pull/236/files
// https://github.com/nrk/predis
/**
 * Copyright 2019 OpenCensus Authors
 *
 * Licensed under the Apache License, Version 2.0 (the "License");
 * you may not use this file except in compliance with the License.
 * You may obtain a copy of the License at
 *
 *     http://www.apache.org/licenses/LICENSE-2.0
 *
 * Unless required by applicable law or agreed to in writing, software
 * distributed under the License is distributed on an "AS IS" BASIS,
 * WITHOUT WARRANTIES OR CONDITIONS OF ANY KIND, either express or implied.
 * See the License for the specific language governing permissions and
 * limitations under the License.
 */

namespace OpenCensus\Trace\Integrations;

use OpenCensus\Trace\Span;

/**
 * This class handles instrumenting Redis requests using the opencensus extension.
 *
 * Example:
 * ```
 * use OpenCensus\Trace\Integrations\Redis;
 *
 * Redis::load();
 * ```
 */

class Redis implements IntegrationInterface
{
    /**
     * Static method to add instrumentation to redis requests
     */
    public static function load()
    {
        if (!extension_loaded('opencensus')) {
            trigger_error('opencensus extension required to load Redis integrations.', E_USER_WARNING);
        }

        opencensus_trace_method('Predis\Client', '__construct', [static::class, 'handleConstruct']);

        opencensus_trace_method('Predis\Client', 'set', function($predis, $key, $value){
                                return ['name' => 'redis/set',
                                        'attributes' => ['key' => $key],
                                        'kind' => Span::KIND_CLIENT
                                    ];
                            }
        );

        opencensus_trace_method('Predis\Client', 'get', function($predis, $key){
                                return ['name' => 'redis/get',
                                        'attributes' => ['key' => $key],
                                        'kind' => Span::KIND_CLIENT
                                    ];
                            }
        );

        opencensus_trace_method('Predis\Client', 'flushDB');
    }

    /**
     * Trace Construct Options
     *
     * @param $predis
     * @param  $params
     * @return array
     */
    public static function handleConstruct($predis, $params)
    {
        $attributes = [
                'peer.hostname' => $params['host'],
                'peer.port' => $params['port'],
                'db.type' => 'redis'
        ];

        return [
            'name' => 'redis/construct',
            'attributes' => $attributes,
            'kind' => Span::KIND_CLIENT
        ];
    }
}
