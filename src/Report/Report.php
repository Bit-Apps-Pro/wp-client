<?php

namespace BitApps\WPClient\Report;

use BitApps\WPClient\Client;

class Report
{
    private $addPluginData = false;

    private $extraInfo = [];

    private $client;

    public function __construct(Client $client)
    {
        if (\is_object($client) && is_a($client, 'BitApps\WPClient\Client')) {
            $this->client = $client;
        }

        $this->init();
    }

    public function init()
    {
        $this->initCommonHooks();

        add_action($this->client->prefix . 'activate', [$this, 'activatePlugin']);

        add_action($this->client->prefix . 'deactivate', [$this, 'deactivatePlugin']);
    }

    public function addPluginData()
    {
        $this->addPluginData = true;

        return $this;
    }

    public function addExtraInfo($data = [])
    {
        $this->extraInfo = $data;

        return $this;
    }

    public function initCommonHooks()
    {
        if (!$this->isTrackingNoticeDismissed()) {
            add_action('admin_notices', [$this, 'adminNotice']);
        }

        add_action('admin_init', [$this, 'handleTrackingOptInOptOut']);

        add_filter('cron_schedules', [$this, 'addWeeklySchedule']);

        add_action($this->client->prefix . 'send_tracking_event', [$this, 'sendTrackingReport']);
    }

    public function adminNotice()
    {
        if ($this->isTrackingNoticeDismissed() || $this->isTrackingAllowed() || !current_user_can('manage_options')) {
            return;
        }

        $policy_url = 'https://bit-social.com/privacy-policy/';
        $optInUrl = wp_nonce_url(add_query_arg($this->client->prefix . 'tracking_opt_in', 'true'), '_wpnonce');
        $optOutUrl = wp_nonce_url(add_query_arg($this->client->prefix . 'tracking_opt_out', 'true'), '_wpnonce');

        $notice = sprintf('Want to help make <strong>%1$s</strong> even more awesome? Allow %1$s to collect diagnostic data and usage information.', $this->client->title);
        $notice .= ' (<a class="' . $this->client->prefix . 'show_collect_data_info' . '" href="#">what we collect</a>)';
        $notice .= '<p class="description" style="display:none;">' . implode(', ', $this->dataWeCollect()) . '. ';
        $notice .= 'We are using bit social to collect your data. <a href="' . $policy_url . '" target="_blank">Learn more</a> about how bit social collects and handle your data.</p>';

        echo '<div class="updated"><p>';
        echo $notice;
        echo '</p><p>';
        echo '&nbsp;<a href="' . esc_url($optInUrl) . '" class="button-primary button-large">Allow</a>';
        echo '&nbsp;<a href="' . esc_url($optOutUrl) . '" class="button-secondary button-large">No thanks</a>';
        echo '</p></div>';

        echo "<script type='text/javascript'>jQuery('." . $this->client->prefix . 'show_collect_data_info' . "').on('click', function(e) {
                e.preventDefault(); jQuery(this).parents('.updated').find('p.description').slideToggle('fast');
            });</script>";
    }

    public function handleTrackingOptInOptOut()
    {
        if (
            !isset($_GET['_wpnonce'])
            || !wp_verify_nonce(sanitize_key($_GET['_wpnonce']), '_wpnonce')
            || !current_user_can('manage_options')
        ) {
            return;
        }

        if (isset($_GET[$this->client->prefix . 'tracking_opt_in']) && $_GET[$this->client->prefix . 'tracking_opt_in'] === 'true') {
            $this->trackingOptIn();
            wp_safe_redirect(remove_query_arg($this->client->prefix . 'tracking_opt_in'));
            exit;
        }

        if (isset($_GET[$this->client->prefix . 'tracking_opt_out'], $_GET[$this->client->prefix . 'tracking_opt_out']) && $_GET[$this->client->prefix . 'tracking_opt_out'] === 'true') {
            $this->trackingOptOut();
            wp_safe_redirect(remove_query_arg($this->client->prefix . 'tracking_opt_out'));
            exit;
        }
    }

    public function trackingOptIn()
    {
        update_option($this->client->prefix . 'allow_tracking', true);
        update_option($this->client->prefix . 'tracking_notice_dismissed', true);

        $this->clearScheduleEvent();
        $this->scheduleEvent();
        $this->sendTrackingReport();

        do_action($this->client->prefix . 'tracking_opt_in', $this->getTrackingData());
    }

    public function trackingOptOut()
    {
        update_option($this->client->prefix . 'allow_tracking', false);
        update_option($this->client->prefix . 'tracking_notice_dismissed', true);

        $this->trackingSkippedRequest();

        $this->clearScheduleEvent();

        do_action($this->client->prefix . 'tracking_opt_out');
    }

    public function addWeeklySchedule($schedules)
    {
        $schedules['weekly'] = [
            'interval' => 604800, // 1 week in seconds
            'display'  => __('Once Weekly')
        ];

        return $schedules;
    }

    public function activatePlugin()
    {
        if (!$this->isTrackingAllowed()) {
            return;
        }

        $this->scheduleEvent();

        $this->sendTrackingReport();
    }

    public function deactivatePlugin()
    {
        $this->clearScheduleEvent();

        delete_option($this->client->prefix . 'tracking_notice_dismissed');
    }

    public function isTrackingAllowed()
    {
        return get_option($this->client->prefix . 'allow_tracking');
    }

    public function isTrackingNoticeDismissed()
    {
        return get_option($this->client->prefix . 'tracking_notice_dismissed');
    }

    public function sendTrackingReport()
    {
        if (!$this->isTrackingAllowed() || $this->isSendedWithinWeek()) {
            return;
        }

        $trackingData = $this->getTrackingData();

        $this->client->sendReport('plugin-track-create', $trackingData);

        $this->updateLastSendedAt();
    }

    public function getTrackingData()
    {
        $reportInfo = new ReportInfo();

        $allPlugins = $reportInfo->getAllPlugins();

        $user_name = $reportInfo->getUserName();

        $data = [
            'url'              => esc_url(home_url()),
            'site'             => $reportInfo->getSiteName(),
            'admin_email'      => get_option('admin_email'),
            'first_name'       => $user_name['firstName'],
            'last_name'        => $user_name['lastName'],
            'server'           => $reportInfo->getServerInfo(),
            'wp'               => $reportInfo->getWpInfo(),
            'users'            => $reportInfo->getUserCounts(),
            'active_plugins'   => \count($allPlugins['activePlugins']),
            'inactive_plugins' => \count($allPlugins['inactivePlugins']),
            'ip_address'       => $reportInfo->getUserIpAddress(),
            'plugin_slug'      => $this->client->prefix,
            'plugin_version'   => $this->client->version,
            'is_local'         => $reportInfo->isLocalServer(),
            'skipped'          => false
        ];

        if ($this->addPluginData) {
            $data['plugins'] = $reportInfo->getPluginInfo($allPlugins['activePlugins']);
        }

        if (\is_array($this->extraInfo) && !empty($this->extraInfo)) {
            $data['extra'] = $this->extraInfo;
        }

        if (get_option($this->client->prefix . 'tracking_skipped')) {
            delete_option($this->client->prefix . 'tracking_skipped');
            $data['previously_skipped'] = true;
        }

        return apply_filters($this->client->prefix . 'tracker_data', $data);
    }

    protected function dataWeCollect()
    {
        $collectList = [
            'Server environment details (php, mysql, server, WordPress versions)',
            'Number of users in your site',
            'Site language',
            'Number of active and inactive plugins',
            'Site name and URL',
            'Your name and email address',
        ];

        if ($this->addPluginData) {
            array_splice($collectList, 4, 0, ["active plugins' name"]);
        }

        return $collectList;
    }

    private function trackingSkippedRequest()
    {
        $previouslySkipped = get_option($this->client->prefix . 'tracking_skipped');

        if (!$previouslySkipped) {
            update_option($this->client->prefix . 'tracking_skipped', true);
        }

        $data = [
            'skipped'            => true,
            'previously_skipped' => $previouslySkipped,
        ];

        $this->client->sendReport('plugin-track-create', $data);
    }

    private function scheduleEvent()
    {
        $hook_name = $this->client->prefix . 'send_tracking_event';

        if (!wp_next_scheduled($hook_name)) {
            wp_schedule_event(time(), 'weekly', $hook_name);
        }
    }

    private function clearScheduleEvent()
    {
        return wp_clear_scheduled_hook($this->client->prefix . 'send_tracking_event');
    }

    private function isSendedWithinWeek()
    {
        $lastSendedAt = $this->lastSendedAt();

        return $lastSendedAt && $lastSendedAt > strtotime('-1 week');
    }

    private function lastSendedAt()
    {
        return get_option($this->client->prefix . 'tracking_last_sended_at');
    }

    private function updateLastSendedAt()
    {
        return update_option($this->client->prefix . 'tracking_last_sended_at', time());
    }
}
