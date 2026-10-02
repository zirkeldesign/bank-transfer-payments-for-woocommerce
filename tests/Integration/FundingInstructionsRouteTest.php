<?php

declare(strict_types=1);

/**
 * Drives the real /funding-instructions route with real WordPress users.
 *
 * This has to be an integration test. The hole it guards against came from
 * WordPress's own behaviour: `current_user_can('edit_user', $own_id)` is TRUE
 * for every logged-in user, because everyone may edit their own profile. A
 * stubbed capability check in a unit test would have returned whatever the stub
 * was told to return and proved nothing.
 */

use ZirkelDesign\BankTransfersForWooCommerce\Admin\CustomerBalanceDisplay;

const BTPW_ROUTE = '/'.CustomerBalanceDisplay::REST_NAMESPACE.'/funding-instructions';

beforeEach(function (): void {
    // The plugin registers its routes on rest_api_init; just make sure the
    // server exists so they are in place before dispatching.
    rest_get_server();
});

afterEach(function (): void {
    wp_set_current_user(0);
});

/** Creates a real user; this suite runs against a real WordPress, not the WP test case. */
function btpw_make_user(string $role): int
{
    static $n = 0;
    $n++;

    return (int) wp_insert_user([
        'user_login' => 'btpw_'.$role.'_'.$n.'_'.wp_rand(1000, 9999),
        'user_email' => 'btpw_'.$role.'_'.$n.'_'.wp_rand(1000, 9999).'@example.test',
        'user_pass' => wp_generate_password(),
        'role' => $role,
    ]);
}

function btpw_post_funding(array $body): WP_REST_Response|WP_Error
{
    $request = new WP_REST_Request('POST', BTPW_ROUTE);
    foreach ($body as $key => $value) {
        $request->set_param($key, $value);
    }

    return rest_get_server()->dispatch($request);
}

describe('POST /funding-instructions', function (): void {
    it('refuses a logged-out request', function (): void {
        expect(btpw_post_funding(['user_id' => 1])->get_status())->toBe(401);
    });

    it('refuses a subscriber asking about their own profile', function (): void {
        // The exact case the old permission_callback let through: a customer is
        // allowed to edit their own profile, so edit_user passed and the route
        // went on to call Stripe with whatever customer id was supplied.
        $subscriber = btpw_make_user('subscriber');
        wp_set_current_user($subscriber);

        expect(btpw_post_funding(['user_id' => $subscriber])->get_status())
            ->toBeIn([401, 403]);
    });

    it('refuses a shop manager acting on an unknown user', function (): void {
        $manager = btpw_make_user('administrator');
        wp_set_current_user($manager);

        expect(btpw_post_funding(['user_id' => 999999])->get_status())
            ->toBeIn([401, 403, 404]);
    });

    it('no longer accepts a customer id from the request', function (): void {
        // The id is resolved from the user now. Passing someone else's must not
        // reach Stripe, which the 404 proves: the target user has no customer.
        $manager = btpw_make_user('administrator');
        $victim = btpw_make_user('customer');
        update_user_meta($victim, '_stripe_customer_id', 'cus_victim');
        wp_set_current_user($manager);

        $target = btpw_make_user('customer');
        $response = btpw_post_funding(['user_id' => $target, 'customer_id' => 'cus_victim']);

        expect($response->get_status())->toBe(404)
            ->and($response->get_data()['message'])->not->toContain('cus_victim');
    });
});
