<?php
/**
 * Ticketbud OAuth 2.0 Authentication Provider
 *
 * @package DataMachineEvents\Steps\EventImport\Handlers\Ticketbud
 */

namespace DataMachineEvents\Steps\EventImport\Handlers\Ticketbud;

use DataMachine\Core\HttpClient;

if (!defined('ABSPATH')) {
    exit;
}

class TicketbudAuth extends \DataMachine\Core\OAuth\BaseOAuth2Provider {

    private const AUTH_URL = 'https://api.ticketbud.com/oauth/authorize';
    private const TOKEN_URL = 'https://api.ticketbud.com/oauth/token';
    private const ME_URL = 'https://api.ticketbud.com/me.json';

    public function __construct() {
        parent::__construct('ticketbud');
    }

    public function get_config_fields(): array {
        return [
            'client_id' => [
                'label' => __('Client ID', 'datamachine-events'),
                'type' => 'text',
                'required' => true,
                'description' => __('Your Ticketbud OAuth Client ID', 'datamachine-events'),
            ],
            'client_secret' => [
                'label' => __('Client Secret', 'datamachine-events'),
                'type' => 'password',
                'required' => true,
                'description' => __('Your Ticketbud OAuth Client Secret', 'datamachine-events'),
            ],
        ];
    }

    public function get_authorization_url(): string {
        $config = $this->get_config();
        $client_id = $config['client_id'] ?? '';

        if ($client_id === '') {
            return '';
        }

        $state = $this->oauth2->create_state('ticketbud');

        return $this->oauth2->get_authorization_url(self::AUTH_URL, [
            'response_type' => 'code',
            'client_id' => $client_id,
            'redirect_uri' => $this->get_callback_url(),
            'state' => $state,
        ]);
    }

    public function handle_oauth_callback() {
        $config = $this->get_config();

        $client_id = $config['client_id'] ?? '';
        $client_secret = $config['client_secret'] ?? '';

        if ($client_id === '' || $client_secret === '') {
            wp_safe_redirect(add_query_arg([
                'page' => 'datamachine-settings',
                'auth_error' => 'missing_config',
                'provider' => 'ticketbud',
            ], admin_url('admin.php')));
            exit;
        }

        $code = isset($_GET['code']) ? sanitize_text_field(wp_unslash($_GET['code'])) : '';

        $this->oauth2->handle_callback(
            'ticketbud',
            self::TOKEN_URL,
            [
                'client_id' => $client_id,
                'client_secret' => $client_secret,
                'code' => $code,
                'grant_type' => 'authorization_code',
                'redirect_uri' => $this->get_callback_url(),
            ],
            function(array $token_data) {
                return $this->build_account_from_token($token_data);
            },
            null,
            [$this, 'save_account']
        );
    }

    private function build_account_from_token(array $token_data): array|
\WP_Error {
        $access_token = $token_data['access_token'] ?? '';

        if ($access_token === '') {
            return new \WP_Error('ticketbud_oauth_missing_access_token', __('Ticketbud did not return an access token.', 'datamachine-events'));
        }

        $expires_in = isset($token_data['expires_in']) ? (int) $token_data['expires_in'] : 0;
        $account = [
            'access_token' => $access_token,
            'token_type' => $token_data['token_type'] ?? 'bearer',
            'refresh_token' => $token_data['refresh_token'] ?? null,
            'token_expires_at' => $expires_in > 0 ? time() + $expires_in : null,
            'authenticated_at' => time(),
        ];

        $me_result = HttpClient::get(add_query_arg(['access_token' => $access_token], self::ME_URL), [
            'headers' => [
                'Accept' => 'application/json',
            ],
            'context' => 'Ticketbud Authentication',
        ]);

        if ($me_result['success'] && ($me_result['status_code'] ?? 0) === 200) {
            $me = json_decode($me_result['data'] ?? '', true);
            if (is_array($me)) {
                if (!empty($me['id'])) {
                    $account['id'] = $me['id'];
                }

                if (!empty($me['email'])) {
                    $account['email'] = $me['email'];
                }

                if (!empty($me['full_name'])) {
                    $account['name'] = $me['full_name'];
                }

                if (!empty($me['default_subdomain'])) {
                    $account['default_subdomain'] = $me['default_subdomain'];
                }
            }
        }

        return $account;
    }
}
