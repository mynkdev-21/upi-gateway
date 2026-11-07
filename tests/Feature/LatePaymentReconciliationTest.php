<?php

namespace Tests\Feature;

use App\Jobs\SendWebhook;
use App\Models\Order;
use App\Models\PaymentAccount;
use App\Models\User;
use App\Services\OrderService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

class LatePaymentReconciliationTest extends TestCase
{
    use RefreshDatabase;

    private User $merchant;

    private OrderService $orders;

    protected function setUp(): void
    {
        parent::setUp();
        Queue::fake();
        Carbon::setTestNow(Carbon::parse('2026-11-07 10:00:00'));

        $this->merchant = User::create([
            'name' => 'Ravi', 'business_name' => 'Ravi Store', 'email' => 'ravi@example.com', 'password' => 'password123',
        ]);
        $this->orders = app(OrderService::class);
    }

    private function account(string $provider, array $credentials = []): PaymentAccount
    {
        return $this->merchant->paymentAccounts()->create([
            'provider' => $provider, 'label' => $provider, 'upi_id' => $provider === 'phonepe_pg' ? null : 'shop@paytm',
            'payee_name' => 'Ravi Store', 'credentials' => $credentials, 'is_default' => true,
        ]);
    }

    private function paytmBusinessAccount(): PaymentAccount
    {
        return $this->account('paytm_business', ['mid' => 'TESTMID123']);
    }

    /** An order that expired `$minutesAgo` minutes ago after a final unpaid check. */
    private function expiredOrder(int $minutesAgo = 10, string $clientOrderId = 'INV1'): Order
    {
        $order = $this->orders->create($this->merchant, ['amount' => 199.50, 'client_order_id' => $clientOrderId]);
        $order->forceFill([
            'status' => Order::EXPIRED,
            'expires_at' => now()->subMinutes($minutesAgo),
            'last_checked_at' => now()->subMinutes($minutesAgo),
        ])->save();

        return $order->fresh();
    }

    private function fakePaytmPaid(Order $order): void
    {
        Http::fake(['securegw.paytm.in/*' => Http::response([
            'STATUS' => 'TXN_SUCCESS', 'TXNAMOUNT' => '199.50', 'BANKTXNID' => '412345678901',
            'TXNID' => 'T1', 'ORDERID' => $order->order_no,
        ])]);
    }

    public function test_scheduled_reconciliation_records_payment_made_after_expiry(): void
    {
        $this->paytmBusinessAccount();
        $order = $this->expiredOrder();
        $this->fakePaytmPaid($order);

        $this->artisan('orders:reconcile-expired')->expectsOutput('Recovered 1 late payments.')->assertSuccessful();

        $order->refresh();
        $this->assertSame(Order::PAID, $order->status);
        $this->assertSame('412345678901', $order->utr);
        $this->assertSame('provider', $order->verified_by);
        Queue::assertPushed(SendWebhook::class, fn (SendWebhook $job) => $job->event === 'order.paid' && $job->order->is($order));
    }

    public function test_unpaid_expired_order_stays_expired_and_backs_off(): void
    {
        $this->paytmBusinessAccount();
        $order = $this->expiredOrder();
        Http::fake(['securegw.paytm.in/*' => Http::response(['STATUS' => 'TXN_FAILURE'])]);

        $this->artisan('orders:reconcile-expired')->assertSuccessful();
        $this->assertSame(Order::EXPIRED, $order->fresh()->status);
        Http::assertSentCount(1);

        // Within an hour of expiry it is re-checked every 5 minutes, not sooner.
        Carbon::setTestNow(now()->addMinutes(2));
        $this->artisan('orders:reconcile-expired')->assertSuccessful();
        Http::assertSentCount(1);

        Carbon::setTestNow(now()->addMinutes(4));
        $this->artisan('orders:reconcile-expired')->assertSuccessful();
        Http::assertSentCount(2);
        Queue::assertNotPushed(SendWebhook::class);
    }

    public function test_older_expired_orders_are_checked_less_often(): void
    {
        $this->paytmBusinessAccount();
        $order = $this->expiredOrder(minutesAgo: 120);
        $order->forceFill(['last_checked_at' => now()->subMinutes(20)])->save();
        Http::fake(['securegw.paytm.in/*' => Http::response(['STATUS' => 'TXN_FAILURE'])]);

        // Two hours after expiry the interval is 30 minutes.
        $this->artisan('orders:reconcile-expired')->assertSuccessful();
        Http::assertNothingSent();

        Carbon::setTestNow(now()->addMinutes(11));
        $this->artisan('orders:reconcile-expired')->assertSuccessful();
        Http::assertSentCount(1);
    }

    public function test_orders_outside_the_window_are_not_checked(): void
    {
        $this->paytmBusinessAccount();
        $this->expiredOrder(minutesAgo: 25 * 60);
        Http::fake();

        $this->artisan('orders:reconcile-expired')->assertSuccessful();

        Http::assertNothingSent();
    }

    public function test_reconciliation_can_be_disabled(): void
    {
        config(['gateway.late_payment_window_hours' => 0]);
        $this->paytmBusinessAccount();
        $order = $this->expiredOrder();
        Http::fake();

        $this->artisan('orders:reconcile-expired')->expectsOutput('Late-payment reconciliation is disabled.')->assertSuccessful();

        $this->assertFalse($this->orders->acceptsLatePayment($order));
        Http::assertNothingSent();
    }

    public function test_manual_and_sandbox_orders_are_skipped(): void
    {
        $this->account('manual');
        $manual = $this->expiredOrder(clientOrderId: 'MANUAL1');

        $sandbox = $this->orders->create($this->merchant, ['amount' => 50, 'client_order_id' => 'TEST1'], Order::SANDBOX);
        $sandbox->forceFill(['status' => Order::EXPIRED, 'expires_at' => now()->subMinutes(10)])->save();
        Http::fake();

        $this->artisan('orders:reconcile-expired')->assertSuccessful();

        $this->assertFalse($this->orders->acceptsLatePayment($manual));
        $this->assertFalse($this->orders->acceptsLatePayment($sandbox->fresh()));
        $this->assertSame(Order::EXPIRED, $manual->fresh()->status);
        Http::assertNothingSent();
    }

    public function test_pg_orders_are_checked_only_if_the_checkout_was_started(): void
    {
        $this->account('phonepe_pg', [
            'environment' => 'sandbox', 'client_id' => 'CLIENT1', 'client_secret' => 'SECRET1', 'client_version' => '1',
        ]);
        $neverOpened = $this->expiredOrder(clientOrderId: 'PG1');
        $opened = $this->expiredOrder(clientOrderId: 'PG2');
        $opened->forceFill(['provider_checkout' => ['type' => 'redirect', 'url' => 'https://mercury-uat.phonepe.com/x', 'expires_at' => now()->timestamp]])->save();

        Http::fake([
            '*/v1/oauth/token' => Http::response(['access_token' => 'TOKEN1', 'expires_at' => time() + 3600]),
            "*/checkout/v2/order/{$opened->order_no}/status*" => Http::response([
                'state' => 'COMPLETED', 'merchantOrderId' => $opened->order_no, 'amount' => 19950,
                'paymentDetails' => [['state' => 'COMPLETED', 'transactionId' => 'TX1', 'rail' => ['utr' => '512345678901']]],
            ]),
        ]);

        $this->artisan('orders:reconcile-expired')->expectsOutput('Recovered 1 late payments.')->assertSuccessful();

        $this->assertSame(Order::EXPIRED, $neverOpened->fresh()->status);
        $this->assertSame(Order::PAID, $opened->fresh()->status);
        $this->assertSame('512345678901', $opened->fresh()->utr);
    }

    public function test_customer_returning_from_pg_after_expiry_sees_late_payment(): void
    {
        $this->paytmBusinessAccount();
        $order = $this->expiredOrder(minutesAgo: 1);
        $order->forceFill(['last_checked_at' => now()])->save();
        $this->fakePaytmPaid($order);

        Carbon::setTestNow(now()->addSeconds(5));
        $this->get("/pay/{$order->order_no}/return")->assertRedirect(route('pay.show', $order));

        $this->assertSame(Order::PAID, $order->fresh()->status);
    }

    public function test_merchant_recheck_records_late_payment(): void
    {
        $this->paytmBusinessAccount();
        $order = $this->expiredOrder();
        $this->fakePaytmPaid($order);

        $this->actingAs($this->merchant)
            ->post(route('orders.recheck', $order))
            ->assertSessionHas('status', 'Status: paid');

        $this->assertSame(Order::PAID, $order->fresh()->status);
    }

    public function test_utr_already_used_keeps_order_expired_and_continues(): void
    {
        $this->paytmBusinessAccount();
        $first = $this->expiredOrder(clientOrderId: 'INV1');
        $second = $this->expiredOrder(clientOrderId: 'INV2');
        $first->forceFill(['status' => Order::PAID, 'utr' => '412345678901'])->save();

        // Both responses carry the UTR already recorded on the first order.
        Http::fake(['securegw.paytm.in/*' => Http::response([
            'STATUS' => 'TXN_SUCCESS', 'TXNAMOUNT' => '199.50', 'BANKTXNID' => '412345678901',
            'TXNID' => 'T2', 'ORDERID' => $second->order_no,
        ])]);

        $this->artisan('orders:reconcile-expired')->expectsOutput('Recovered 0 late payments.')->assertSuccessful();

        $this->assertSame(Order::EXPIRED, $second->fresh()->status);
    }
}
