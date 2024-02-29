<?php

namespace BitApps\WPClient\Feedback;

class Feedback
{
    private $client;

    public function __construct($client)
    {
        if (\is_object($client) && is_a($client, 'BitApps\WPClient\Client')) {
            $this->client = $client;
        }

        $this->init();
    }

    public function init()
    {
        add_action('wp_ajax_' . $this->client->prefix . 'deactivate_feedback', [$this, 'handleDeactivateFeedback']);

        add_action('current_screen', [$this, 'loadAllScripts']);
    }

    public function loadAllScripts()
    {
        if (!$this->is_plugins_screen()) {
            return;
        }

        add_action('admin_enqueue_scripts', [$this, 'enqueueFeedbackDialogScripts']);
    }

    /**
     * Enqueue feedback dialog scripts.
     *
     * Registers the feedback dialog scripts and enqueues them.
     *
     * @since 0.0.1
     */
    public function enqueueFeedbackDialogScripts()
    {
        add_action('admin_footer', [$this, 'printDeactivateFeedbackDialog']);
    }

    /**
     * Print deactivate feedback dialog.
     *
     * Display a dialog box to ask the user why he deactivated this plugin.
     *
     * @since 0.0.1
     */
    public function printDeactivateFeedbackDialog()
    {
        $this->loadDeactivationStyle();

        $deactivateReasons = $this->getDeactivateReasons();

        ?>
<div class="bitapps-dm-wrapper" id="<?php echo $this->client->slug; ?>-bitapps-dm-wrapper">
    <div class="bitapps-dm-dialog">
        <div class="bitapps-dm-header">
            <?php echo $this->client->logo; ?>
            <span class="bitapps-dm-header-title">
                <?php echo esc_html__('Quick Feedback', $this->client->slug); ?>
            </span>
            <svg class="bitapps-dm-close-svg" viewBox="0 0 14 14" xmlns="http://www.w3.org/2000/svg"><path fill="currentcolor" stroke="currentcolor" stroke-width="2" d="M14.5,1.5l-13,13m0-13,13,13" transform="translate(-1 -1)"></path></svg>
        </div>
        <form class="bitapps-dm-form" method="post">
            <?php wp_nonce_field($this->client->prefix . 'nonce', '_ajax_nonce'); ?>
            <input type="hidden" name="action" value="<?php echo $this->client->prefix . 'deactivate_feedback'; ?>" />

            <div class="bitapps-dm-form-caption">
                <?php echo esc_html__('If you have a moment, please let us know how "' . $this->client->title . '" can improve.', $this->client->slug); ?>
            </div>
            <div class="bitapps-dm-form-body">
                <?php foreach ($deactivateReasons as $reasonKey => $reason) { ?>
                <div class="bitapps-dm-input-wrapper">
                    <input
                        id="<?php echo $this->client->slug . '-deactivate-feedback-' . esc_attr($reasonKey); ?>"
                        class="bitapps-dm-input" type="radio" name="reason_key"
                        value="<?php echo esc_attr($reasonKey); ?>"
                        required />
                    <label
                        for="<?php echo $this->client->slug . '-deactivate-feedback-' . esc_attr($reasonKey); ?>"
                        class="bitapps-dm-label"><?php echo esc_html($reason['title']); ?></label>
                    <?php if (!empty($reason['placeholder'])) { ?>
                    <input class="bitapps-dm-feedback-text" type="text"
                        name="reason_<?php echo esc_attr($reasonKey); ?>"
                        placeholder="<?php echo esc_attr($reason['placeholder']); ?>" />
                    <?php } ?>
                    <?php if (!empty($reason['alert'])) { ?>
                    <div class="bitapps-dm-feedback-text">
                        <?php echo esc_html($reason['alert']); ?>
                    </div>
                    <?php } ?>
                </div>
                <?php } ?>
            </div>
            <div class="bitapps-dm-form-footer">
                <button type="submit" class="bitapps-dm-form-submit">
                    Submit & Deactivate
                </button>
                <button type="button" class="bitapps-dm-form-skip">
                    Skip & Deactivate
                </button>
            </div>
        </form>
    </div>
</div>
<?php
        $this->loadDeactivationScript();
    }

    public function getDeactivateReasons()
    {
        $reasons = [
            'found_a_better_plugin' => [
                'title'       => esc_html__('Found a better plugin', $this->client->slug),
                'placeholder' => esc_html__('Which plugin?', $this->client->slug),
            ],
            'missing_specific_feature' => [
                'title'       => esc_html__('Missing a specific feature', $this->client->slug),
                'placeholder' => esc_html__('Could you tell us more about that feature?', $this->client->slug),
            ],
            'not_working' => [
                'title'       => esc_html__('Not working', $this->client->slug),
                'placeholder' => esc_html__('Could you tell us what is not working?', $this->client->slug),
            ],
            'not_working_as_expected' => [
                'title'       => esc_html__('Not working as expected', $this->client->slug),
                'placeholder' => esc_html__('Could you tell us what do you expect?', $this->client->slug),
            ],
            'temporary_deactivation' => [
                'title'       => esc_html__('It\'s a temporary deactivation', $this->client->slug),
                'placeholder' => '',
            ],
            $this->client->prefix . 'pro' => [
                'title'       => esc_html__('I have ' . $this->client->title . ' Pro', $this->client->slug),
                'placeholder' => '',
                'alert'       => esc_html__('Wait! Don\'t deactivate ' . $this->client->title . '. You have to activate both ' . $this->client->title . ' and ' . $this->client->title . ' Pro in order to work the plugin.', $this->client->slug),
            ],
            'other' => [
                'title'       => esc_html__('Other', $this->client->slug),
                'placeholder' => esc_html__('Please share the reason', $this->client->slug),
            ],
        ];

        return apply_filters($this->client->prefix . 'deactivate_reasons', $reasons, $this->client);
    }

    /**
     * Ajax plugin deactivate feedback.
     *
     * Send the user feedback when plugin is deactivated.
     *
     * @since 0.0.1
     */
    public function handleDeactivateFeedback()
    {
        if (!isset($_POST['_ajax_nonce'])) {
            return;
        }

        if (!wp_verify_nonce(sanitize_key(wp_unslash($_POST['_ajax_nonce'])), $this->client->prefix . 'nonce')) {
            wp_send_json_error('Nonce verification failed');
        }

        if (!current_user_can('activate_plugins')) {
            wp_send_json_error('Permission denied');
        }

        $report = $this->client->report->getTrackingData();
        $report['site_lang'] = get_bloginfo('language');
        $report['feedback_key'] = sanitize_text_field(wp_unslash($_POST['reason_key'])) ?: null;
        $report['feedback'] = sanitize_text_field(wp_unslash($_POST["reason_{$report['feedback_key']}"])) ?: null;

        $this->client->sendReport('', $report);

        wp_send_json_success();
    }

    private function loadDeactivationScript()
    {
        ?>
<script type="text/javascript">
    (function($) {
        const <?php echo $this->client->prefix . 'AdminDialogApp'; ?> = {
            cacheElements: function() {
                this.cache = {
                    $deactivateLink: $('#the-list').find(
                        '[data-slug="<?php echo $this->client->slug; ?>"] span.deactivate a'
                    ),
                    $dialogWrapper: $(
                        '#<?php echo $this->client->slug; ?>-bitapps-dm-wrapper'
                    ),
                    $dialogDialog: $(
                        '#<?php echo $this->client->slug; ?>-bitapps-dm-wrapper'
                    ).find('.bitapps-dm-dialog'),
                    $dialogHeader: $(
                        '#<?php echo $this->client->slug; ?>-bitapps-dm-wrapper'
                    ).find('.bitapps-dm-header'),
                    $dialogForm: $(
                        '#<?php echo $this->client->slug; ?>-bitapps-dm-wrapper'
                    ).find('.bitapps-dm-form'),
                    $dialogSubmit: $(
                        '#<?php echo $this->client->slug; ?>-bitapps-dm-wrapper'
                    ).find('.bitapps-dm-form-submit'),
                    $dialogSkip: $(
                        '#<?php echo $this->client->slug; ?>-bitapps-dm-wrapper'
                    ).find('.bitapps-dm-form-skip'),
                    $dialogCloseBtn: $(
                        '#<?php echo $this->client->slug; ?>-bitapps-dm-wrapper'
                    ).find('.bitapps-dm-close-svg'),
                    $dialogOpen: false
                }
            },
            bindEvents: function() {
                this.cache.$deactivateLink.on('click', e => {
                    e.preventDefault()
                    this.showModal()
                })
                this.cache.$dialogForm.on('submit', e => {
                    e.preventDefault()
                    this.sendFeedback()
                })
                this.cache.$dialogSkip.on('click', e => {
                    e.preventDefault()
                    this.deactivate()
                })
                this.cache.$dialogCloseBtn.on('click', () => {
                    if (this.cache.$dialogOpen) this.hideModal()
                })
                $(document).mouseup(e => {
                    if (!this.cache.$dialogOpen) return
                    const container = this.cache.$dialogDialog
                    if (!container.is(e.target) && container.has(e.target).length === 0) {
                        this.hideModal()
                    }
                })
                $(document).keyup(e => {
                    if (!this.cache.$dialogOpen) return
                    if (e.keyCode === 27 && this.cache.$dialogOpen) {
                        this.hideModal()
                        this.cache.$dialogOpen = false
                        this.cache.$deactivateLink.focus()
                    }
                })
            },
            deactivate: function() {
                window.location.href = this.cache.$deactivateLink.attr('href')
            },
            hideModal: function() {
                this.cache.$dialogWrapper.hide()
                this.cache.$dialogOpen = false
            },
            showModal: function() {
                this.cache.$dialogWrapper.show()
                this.cache.$dialogOpen = true
            },
            showLoading: function() {
                this.cache.$dialogSubmit.addClass('bitapps-dm-loading')
            },
            hideLoading: function() {
                this.cache.$dialogSubmit.removeClass('bitapps-dm-loading')
            },
            sendFeedback: function() {
                this.showLoading()
                const formData = this.cache.$dialogForm.serialize()

                $.post(ajaxurl, formData, () => {
                    this.deactivate()
                }).always(() => {
                    this.hideLoading()
                });
            },
            init: function() {
                this.cacheElements()
                this.bindEvents()
            }
        }

        $(function() {
            <?php echo $this->client->prefix . 'AdminDialogApp.init()'; ?>
        })
    })(jQuery);
</script>
<?php
    }

    private function loadDeactivationStyle()
    {
        ?>
<style type="text/css">
    .bitapps-dm-wrapper {
        content: '';
        position: fixed;
        inset: 0;
        background-color: rgba(0, 0, 0, 70%);
        z-index: 99999;
        display: none;
    }

    .bitapps-dm-dialog {
        width: 550px;
        position: absolute;
        top: 50%;
        left: 50%;
        transform: translate(-50%, -50%);
        z-index: 999999;
        background-color: #fff;
        border-radius: 12px;
        box-shadow:
            0 0 0 1px rgba(0, 0, 0, 5%),
            0 6px 16px 0 rgba(0, 0, 0, 8%),
            0 3px 6px -4px rgba(0, 0, 0, 12%),
            0 9px 28px 8px rgba(0, 0, 0, 5%);
        font-size: 0.875rem;
    }

    .bitapps-dm-feedback-text {
        margin-inline-start: 30px;
        margin-block-end: 4px;
    }

    input.bitapps-dm-feedback-text {
        padding: 4px 11px;
        color: rgba(0, 0, 0, 88%);
        line-height: 1.57;
        width: 100%;
        min-width: 0;
        background-color: #fff;
        border: 1px solid #d9d9d9;
        border-radius: 8px;
        transition: all 0.2s;
    }

    div.bitapps-dm-feedback-text {
        color: orange;
    }

    .bitapps-dm-input-wrapper {
        display: flex;
        align-items: center;
        flex-wrap: wrap;
        column-gap: 10px;
        line-height: 2;
    }

    .bitapps-dm-input:not(:checked)~.bitapps-dm-feedback-text {
        display: none;
    }

    .bitapps-dm-label {
        white-space: nowrap;
    }

    .bitapps-dm-header {
        border-bottom: 1px solid #d9d9d9;
        padding: 20px 24px;
        text-align: start;
        display: flex;
        gap: 8px;
        align-items: center;
    }

    .bitapps-dm-close-svg {
        box-sizing: border-box;
        height: 30px;
        cursor: pointer;
        margin-left: auto;
        padding: 8px;
        border-radius: 50%;
        background: #f3f3f3;
        flex-shrink: 0;
    }

    .bitapps-dm-close-svg:hover {
        background: #ecebeb;
    }

    .bitapps-dm-form {
        padding: 20px 24px;
    }

    .bitapps-dm-form-footer {
        display: flex;
        justify-content: space-between;
        padding-top: 20px;
    }

    .bitapps-dm-header-title {
        text-transform: uppercase;
        font-size: 0.9375rem;
        font-weight: 500;
    }

    .bitapps-dm-form-caption {
        margin-bottom: 20px;
        font-size: 0.9375rem;
        font-weight: 500;
    }

    .bitapps-dm-form-footer button {
        padding: 4px 15px;
        border-radius: 8px;
        box-shadow: none;
        border: 1px solid transparent;
        cursor: pointer;
        height: 32px;
        color: rgba(0, 0, 0, 88%);
    }

    button.bitapps-dm-form-submit {
        background: #1c1c1c;
        color: #fff;
        position: relative;
        display: flex;
        align-items: center;
    }

    .bitapps-dm-loading::after {
        content: '';
        opacity: 1;
        border-top: 0.1563rem solid #a3a3a3;
        border-right: 0.1563rem solid #a3a3a3;
        border-bottom: 0.1563rem solid #a3a3a3;
        border-left: 0.1563rem solid #1c1c1c;
        border-radius: 50%;
        display: inline-block;
        width: 1.25rem;
        height: 1.25rem;
        margin-left: 6px;
        transform: translateZ(0);
        animation: bitapps-dm-loader 1.1s infinite linear;
    }

    @keyframes bitapps-dm-loader {
        from {
            transform: rotate(0);
        }

        to {
            transform: rotate(360deg);
        }
    }

    button.bitapps-dm-form-submit:hover {
        background: #1c1c1c;
    }

    button.bitapps-dm-form-skip {
        background: transparent;
    }

    button.bitapps-dm-form-skip:hover {
        background-color: #efefef;
    }
</style>
<?php
    }

    /**
     * @since 0.0.1
     */
    private function is_plugins_screen()
    {
        return \in_array(get_current_screen()->id, ['plugins', 'plugins-network']);
    }
}
?>