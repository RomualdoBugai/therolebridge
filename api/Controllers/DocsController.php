<?php

declare(strict_types=1);

class DocsController
{
    public function index(Request $_request, array $_params = []): void
    {
        Response::json([
            'success' => true,
            'api'     => 'Bugai API',
            'auth'    => 'Bearer token via Authorization header',
            'authorization' => [
                'model'        => 'Each token has an "abilities" list. A request is allowed when the token '
                                . 'holds the ability required by the route.',
                'grammar'      => [
                    '*'                => 'Full access to every endpoint.',
                    'resource:*'       => 'Every action on that resource (e.g. "leads:*").',
                    'resource:action'  => 'That exact ability only (e.g. "leads:read").',
                ],
                'responses'    => [
                    '401' => 'Missing, invalid or expired token.',
                    '403' => 'Valid token, but it lacks the ability required by the route.',
                ],
                'required_abilities' => [
                    'GET /campaigns-report' => 'campaigns:read',
                    'GET /leads'            => 'leads:read',
                    'POST /leads'           => 'leads:write',
                    'GET /revenue'          => 'revenue:read',
                    'GET /traffic-split'    => 'traffic-split:read',
                    'PUT /traffic-split'    => 'traffic-split:write',
                    'GET /esps'             => 'esps:read',
                    'GET /esp-schedule'     => 'esp-schedule:read',
                    'GET /clicks'           => 'clicks:read',
                    'GET /lead-clicks'      => 'lead-clicks:read',
                    'POST /tokens'          => 'tokens:write',
                    'GET /docs'             => 'docs:read',
                ],
            ],
            'routes'  => [

                [
                    'method'      => 'GET',
                    'route'       => '/campaigns-report',
                    'description' => 'Email campaigns report with performance metrics.',
                    'filters'     => [
                        'date_from'       => ['type' => 'string', 'format' => 'Y-m-d', 'description' => 'Send date start'],
                        'date_to'         => ['type' => 'string', 'format' => 'Y-m-d', 'description' => 'Send date end'],
                        'esp_id'          => ['type' => 'int|list', 'example' => '1,2,3', 'description' => 'Filter by one or more ESP IDs'],
                        'search'          => ['type' => 'string', 'description' => 'Search in name, subject, sender, esp'],
                        'template_id'     => ['type' => 'int', 'description' => 'Template number extracted from campaign name after #'],
                        'esp_campaign_id' => ['type' => 'int', 'description' => 'Campaign ID in the ESP'],
                        'weekday'         => ['type' => 'string', 'enum' => ['monday','tuesday','wednesday','thursday','friday','saturday','sunday']],
                        'page'            => ['type' => 'int', 'default' => 1],
                        'per_page'        => ['type' => 'int', 'default' => 25, 'max' => 200],
                        'sort_by'         => ['type' => 'string', 'enum' => ['id','name','esp','template_id','esp_campaign_id','scheduled_at','total_sent','delivered','unique_opened','unique_clicked','open_rate','ctr','ctor','unsubs','bounced']],
                        'sort_dir'        => ['type' => 'string', 'enum' => ['ASC','DESC'], 'default' => 'DESC'],
                    ],
                    'returns' => 'data[], summary{}, meta{}',
                ],

                [
                    'method'      => 'GET',
                    'route'       => '/leads',
                    'description' => 'List leads from record_leads.',
                    'filters'     => [
                        'date_from'        => ['type' => 'string', 'format' => 'Y-m-d', 'description' => 'created_at start'],
                        'date_to'          => ['type' => 'string', 'format' => 'Y-m-d', 'description' => 'created_at end'],
                        'provider_data_id' => ['type' => 'int|list', 'example' => '1,2', 'description' => 'Filter by data provider'],
                        'state'            => ['type' => 'string', 'example' => 'TX', 'description' => '2-letter state code'],
                        'is_valid'         => ['type' => 'int', 'enum' => [0, 1]],
                        'search'           => ['type' => 'string', 'description' => 'Search in email, first_name, last_name, job_keyword'],
                        'page'             => ['type' => 'int', 'default' => 1],
                        'per_page'         => ['type' => 'int', 'default' => 25, 'max' => 200],
                        'sort_by'          => ['type' => 'string', 'enum' => ['id','email','state','provider_data_id','created_at']],
                        'sort_dir'         => ['type' => 'string', 'enum' => ['ASC','DESC'], 'default' => 'DESC'],
                    ],
                    'returns' => 'data[], summary{}, meta{}',
                ],

                [
                    'method'      => 'POST',
                    'route'       => '/leads',
                    'description' => 'Insert a new lead into record_leads.',
                    'body'        => [
                        'provider_data_id' => ['type' => 'int', 'required' => true],
                        'email'            => ['type' => 'string', 'required' => true],
                        'first_name'       => ['type' => 'string', 'required' => true, 'max' => 100],
                        'last_name'        => ['type' => 'string', 'required' => true, 'max' => 100],
                        'job_keyword'      => ['type' => 'string', 'required' => true, 'max' => 100],
                        'city'             => ['type' => 'string', 'required' => true, 'max' => 100],
                        'state'            => ['type' => 'string', 'required' => true, 'format' => '2-letter code e.g. TX'],
                        'zip'              => ['type' => 'string', 'required' => true, 'format' => '12345 or 12345-6789'],
                    ],
                    'returns' => '201 {success, message, id} | 409 duplicate | 422 validation errors',
                ],

                [
                    'method'      => 'GET',
                    'route'       => '/revenue',
                    'description' => 'Revenue report from earnings_daily with daily series and per-provider breakdown.',
                    'filters'     => [
                        'date_from'       => ['type' => 'string', 'format' => 'Y-m-d', 'default' => 'last 30 days'],
                        'date_to'         => ['type' => 'string', 'format' => 'Y-m-d', 'default' => 'today'],
                        'provider_job_id' => ['type' => 'int|list', 'example' => '1,2', 'description' => 'Filter by provider_job_id'],
                    ],
                    'returns' => 'summary{}, daily[], by_provider[]',
                ],

                [
                    'method'      => 'GET',
                    'route'       => '/traffic-split',
                    'description' => 'Traffic split weights + EPC metrics per provider.',
                    'filters'     => [
                        'date_from' => ['type' => 'string', 'format' => 'Y-m-d', 'default' => 'last 30 days'],
                        'date_to'   => ['type' => 'string', 'format' => 'Y-m-d', 'default' => 'today'],
                    ],
                    'returns' => 'summary{}, providers[]',
                ],

                [
                    'method'      => 'PUT',
                    'route'       => '/traffic-split',
                    'description' => 'Update traffic split weights for one or more providers.',
                    'body'        => [
                        'weights' => ['type' => 'object', 'required' => true, 'example' => '{"1": 60, "2": 40}', 'description' => 'Keys = provider_job_id, values = weight (0-100000)'],
                    ],
                    'returns' => '{success, message, data{providers_updated}}',
                ],

                [
                    'method'      => 'GET',
                    'route'       => '/esps',
                    'description' => 'List all ESPs.',
                    'filters'     => [
                        'search'   => ['type' => 'string', 'description' => 'Search in name and domain_name'],
                        'sort_dir' => ['type' => 'string', 'enum' => ['ASC','DESC'], 'default' => 'ASC'],
                    ],
                    'returns' => 'data[] with schedule_count',
                ],

                [
                    'method'      => 'GET',
                    'route'       => '/esp-schedule',
                    'description' => 'List ESP sending schedule.',
                    'filters'     => [
                        'esp_id'    => ['type' => 'int|list', 'example' => '1,2', 'description' => 'Filter by ESP'],
                        'weekday'   => ['type' => 'string', 'enum' => ['monday','tuesday','wednesday','thursday','friday','saturday','sunday']],
                        'is_active' => ['type' => 'int', 'enum' => [0, 1]],
                        'page'      => ['type' => 'int', 'default' => 1],
                        'per_page'  => ['type' => 'int', 'default' => 50, 'max' => 200],
                        'sort_by'   => ['type' => 'string', 'enum' => ['id','esp','weekday','slot_index','time','is_active']],
                        'sort_dir'  => ['type' => 'string', 'enum' => ['ASC','DESC'], 'default' => 'ASC'],
                    ],
                    'returns' => 'data[], meta{}',
                ],

                [
                    'method'      => 'GET',
                    'route'       => '/clicks',
                    'description' => 'Clicks funnel: link clicks (job_clicks), button clicks (job_clicks_out), blocked (job_clicks_suspicious). Includes daily series, hourly distribution and per-provider breakdown.',
                    'filters'     => [
                        'date_from' => ['type' => 'string', 'format' => 'Y-m-d', 'default' => 'last 30 days'],
                        'date_to'   => ['type' => 'string', 'format' => 'Y-m-d', 'default' => 'today'],
                    ],
                    'returns' => 'summary{}, daily[], hourly[], by_provider[]',
                ],

                [
                    'method'      => 'GET',
                    'route'       => '/lead-clicks',
                    'description' => 'Individual click records from job_clicks joined with job_clicks_out. Supports filter by email to answer "how many clicks did X lead make". Returns paginated rows + summary.',
                    'filters'     => [
                        'email'        => ['type' => 'string', 'example' => 'atz19@hotmail.com', 'description' => 'Exact email match'],
                        'date_from'    => ['type' => 'string', 'format' => 'Y-m-d', 'description' => 'created_at start'],
                        'date_to'      => ['type' => 'string', 'format' => 'Y-m-d', 'description' => 'created_at end'],
                        'provider'     => ['type' => 'string', 'description' => 'Exact provider name match'],
                        'state'        => ['type' => 'string', 'example' => 'TX', 'description' => '2-letter state code'],
                        'utm_source'   => ['type' => 'string'],
                        'utm_campaign' => ['type' => 'string'],
                        'page'         => ['type' => 'int', 'default' => 1],
                        'per_page'     => ['type' => 'int', 'default' => 50, 'max' => 200],
                        'sort_dir'     => ['type' => 'string', 'enum' => ['ASC','DESC'], 'default' => 'DESC'],
                    ],
                    'returns' => 'data[], summary{total_link_clicks, total_button_clicks, conversion_rate, unique_emails, unique_campaigns, first_click, last_click}, meta{}',
                ],

                [
                    'method'      => 'POST',
                    'route'       => '/tokens',
                    'description' => 'Create a new API token with a set of abilities. The plain-text token is '
                                   . 'returned only once in the response; only its SHA-256 hash is stored.',
                    'requires'    => 'tokens:write',
                    'body'        => [
                        'name'       => ['type' => 'string', 'required' => true, 'max' => 100, 'description' => 'Human-readable label'],
                        'abilities'  => ['type' => 'array', 'required' => true, 'example' => '["leads:read","campaigns:read"]', 'description' => 'Ability strings: "*", "resource:*" or "resource:action"'],
                        'expires_at' => ['type' => 'string', 'required' => false, 'format' => 'Y-m-d H:i:s', 'description' => 'Optional expiration; omit for a token that never expires'],
                    ],
                    'returns' => '201 {success, message, data{id, name, abilities, expires_at, token}} | 422 validation errors',
                ],

                [
                    'method'      => 'GET',
                    'route'       => '/docs',
                    'description' => 'This documentation.',
                    'filters'     => [],
                    'returns'     => 'routes[]',
                ],

            ],
        ]);
    }
}
