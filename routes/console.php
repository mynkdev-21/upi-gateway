<?php

use App\Models\Order;
use App\Models\User;
use App\Services\OrderService;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schedule;
use Illuminate\Validation\ValidationException;

/**
 * Catches payments whose customer closed the checkout page before it
 * confirmed, and expires unpaid orders (after one last status check).
 */
Artisan::command('orders:check-pending', function (OrderService $orders) {
    $checked = 0;

    Order::with('paymentAccount')
        ->where('status', Order::PENDING)
        ->orderBy('id')
        ->chunkById(100, function ($chunk) use ($orders, &$checked) {
            foreach ($chunk as $order) {
                $orders->check($order);
                $checked++;
            }
        });

    $this->info("Checked {$checked} pending orders.");
})->purpose('Verify pending orders with providers and expire stale ones');

/**
 * Records payments that arrive after the order expired (a UPI QR has no
 * expiry, a PG checkout can outlive the order). OrderService decides how
 * often each order is due, so running this often is cheap.
 */
Artisan::command('orders:reconcile-expired', function (OrderService $orders) {
    $window = (int) config('gateway.late_payment_window_hours', 24);

    if ($window <= 0) {
        $this->info('Late-payment reconciliation is disabled.');

        return;
    }

    $recovered = 0;

    Order::with('paymentAccount')
        ->where('status', Order::EXPIRED)
        ->where('environment', Order::LIVE)
        ->where('expires_at', '>=', now()->subHours($window))
        ->whereHas('paymentAccount', fn ($q) => $q->where('provider', '!=', 'manual'))
        ->orderBy('id')
        ->chunkById(100, function ($chunk) use ($orders, &$recovered) {
            foreach ($chunk as $order) {
                try {
                    if ($orders->reconcileExpired($order)->status === Order::PAID) {
                        $recovered++;
                    }
                } catch (ValidationException $e) {
                    // e.g. the provider's UTR is already on another order: leave
                    // this one expired for the merchant to look at, keep going.
                    Log::warning('Late payment could not be recorded', [
                        'order_id' => $order->order_no,
                        'errors' => $e->errors(),
                    ]);
                }
            }
        });

    $this->info("Recovered {$recovered} late payments.");
})->purpose('Re-check recently expired orders for payments made after expiry');

Artisan::command('app:create-admin {email} {--name=Admin}', function (string $email) {
    $password = $this->secret('Password (min 10 chars)');

    if (strlen((string) $password) < 10) {
        return $this->error('Password must be at least 10 characters.');
    }

    $user = User::updateOrCreate(['email' => $email], [
        'name' => $this->option('name'),
        'password' => $password,
        'role' => 'admin',
        'is_active' => true,
    ]);

    if (! $user->api_key) {
        $user->regenerateApiCredentials();
    }

    $this->info("Admin ready: {$email}");
})->purpose('Create or promote an admin user');

Schedule::command('orders:check-pending')->everyMinute()->withoutOverlapping();
Schedule::command('orders:reconcile-expired')->everyFiveMinutes()->withoutOverlapping();
