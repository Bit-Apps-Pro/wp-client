<?php

namespace BitApps\WPClient;

use BitApps\WPClient\Feedback\Feedback;
use BitApps\WPClient\Report\Report;

class Client
{
    public $report;

    public $feedback;

    public $title;

    public $slug;

    public $prefix;

    public $version;

    public $logo;

    // public $apiBaseUrl = 'https://wp-api.bitapps.pro/public/';
    public $apiBaseUrl = 'https://app.webhook.is/test/callback/1ee9e5fd9b4264a08303067f2def955d';

    private $clientVersion = '0.0.1';

    public function __construct($title, $slug, $prefix, $version)
    {
        $this->title = $title;

        $this->slug = $slug;

        $this->prefix = $prefix;

        $this->version = $version;
    }

    public function setLogo($logo)
    {
        $this->logo = $logo;
    }

    public function report()
    {
        if (!$this->report) {
            $this->report = new Report($this);
        }

        return $this->report;
    }

    public function feedback()
    {
        if (!$this->feedback) {
            $this->feedback = new Feedback($this);
        }

        return $this->feedback;
    }

    public function endpoint()
    {
        $endpoint = apply_filters('bit_apps_client_endpoint', $this->apiBaseUrl);

        return trailingslashit($endpoint);
    }

    public function sendReport($prefix, $data, $blocking = false)
    {
        $apiUrl = $this->endpoint();

        $headers = [
            'host-user'    => 'BitApps/' . md5(esc_url(home_url())),
            'Content-Type' => 'application/json',
        ];

        return wp_remote_post(
            $apiUrl,
            [
                'method'      => 'POST',
                'timeout'     => 30,
                'redirection' => 5,
                'httpversion' => '1.0',
                'blocking'    => $blocking,
                'headers'     => $headers,
                'body'        => wp_json_encode(array_merge($data, ['wp_client' => $this->clientVersion])),
                'cookies'     => [],
            ]
        );
    }
}
