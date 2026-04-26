<?php

declare(strict_types=1);

namespace WPHeroColor;

use RuntimeException;
use WP_Error;
use WP_REST_Request;
use WP_REST_Response;
use WP_REST_Server;

final class RestController
{
    private Service $service;

    public function __construct(Service $service)
    {
        $this->service = $service;
    }

    public function register_routes(): void
    {
        register_rest_route(
            'sr-hero-color/v1',
            '/compute',
            [
                [
                    'methods' => WP_REST_Server::CREATABLE,
                    'callback' => [$this, 'compute'],
                    'permission_callback' => [$this, 'can_compute'],
                    'args' => [
                        'post_id' => ['type' => 'integer', 'required' => true],
                        'attachment_id' => ['type' => 'integer', 'required' => false],
                        'mode' => ['type' => 'string', 'required' => false],
                        'linear_dir' => ['type' => 'string', 'required' => false],
                    ],
                ],
            ]
        );

        register_rest_route(
            'sr-hero-color/v1',
            '/post/(?P<id>\\d+)',
            [
                [
                    'methods' => WP_REST_Server::READABLE,
                    'callback' => [$this, 'read_post'],
                    'permission_callback' => [$this, 'can_read_post'],
                ],
            ]
        );
    }

    public function can_compute(WP_REST_Request $request): bool
    {
        $post_id = (int) $request->get_param('post_id');
        if ($post_id < 1) {
            return false;
        }

        return current_user_can('edit_post', $post_id);
    }

    public function can_read_post(WP_REST_Request $request): bool
    {
        $post_id = (int) $request->get_param('id');
        $post = get_post($post_id);
        if (!$post) {
            return false;
        }

        if ($post->post_status === 'publish') {
            return true;
        }

        return current_user_can('edit_post', $post_id);
    }

    /**
     * @return WP_REST_Response|WP_Error
     */
    public function compute(WP_REST_Request $request)
    {
        $post_id = (int) $request->get_param('post_id');
        $attachment_id = (int) ($request->get_param('attachment_id') ?? 0);
        $mode = $request->get_param('mode');
        $linearDir = $request->get_param('linear_dir');

        try {
            $payload = $this->service->recompute_for_post(
                $post_id,
                $attachment_id > 0 ? $attachment_id : null,
                is_string($mode) ? $mode : null,
                is_string($linearDir) ? $linearDir : null
            );
        } catch (RuntimeException $e) {
            return new WP_Error('sr_hero_color_compute_error', $e->getMessage(), ['status' => 400]);
        }

        if ($payload === null) {
            return new WP_Error(
                'sr_hero_color_no_attachment',
                'The post does not have a valid featured image.',
                ['status' => 400]
            );
        }

        return new WP_REST_Response(['payload' => $payload], 200);
    }

    /**
     * @return WP_REST_Response|WP_Error
     */
    public function read_post(WP_REST_Request $request)
    {
        $post_id = (int) $request->get_param('id');
        $payload = $this->service->get_payload($post_id);
        if ($payload === null) {
            return new WP_Error('sr_hero_color_not_found', 'No hero color data found for post.', ['status' => 404]);
        }

        return new WP_REST_Response(
            [
                'post_id' => $post_id,
                'payload' => $payload,
            ],
            200
        );
    }
}
