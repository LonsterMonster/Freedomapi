<?php
if (!defined('ABSPATH')) exit;

class APIPlatform_REST {

    const NAMESPACE = 'platform/v1';
    const ROUTE = '/api/(?P<endpoint>[a-z0-9-]+)';

    public function __construct(){
        add_action('rest_api_init', [$this, 'routes']);
    }

    public function routes(){
        register_rest_route(self::NAMESPACE, self::ROUTE, [
            'methods' => ['GET', 'POST', 'PUT', 'PATCH', 'DELETE'],
            'callback' => [$this, 'run'],
            'permission_callback' => '__return_true',
        ]);
    }

    public function run($req){
        if (class_exists('APIPlatform_Gateway')) {
            return APIPlatform_Gateway::instance()->handle_rest($req);
        }

        return new WP_Error('gateway_unavailable', 'Gateway runtime is unavailable.', ['status' => 500]);
    }
}
