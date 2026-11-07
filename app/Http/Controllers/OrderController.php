<?php

namespace App\Http\Controllers;

use App\Models\Order;
use App\Services\OrderService;
use Illuminate\Http\Request;

class OrderController extends Controller
{
    public function __construct(private OrderService $orders) {}

    public function index(Request $request)
    {
        $environment = $request->query('environment') === Order::SANDBOX ? Order::SANDBOX : Order::LIVE;
        $query = $request->user()->orders()->where('environment', $environment)->with('paymentAccount')->latest();

        if ($status = $request->query('status')) {
            $query->where('status', $status);
        }

        if ($search = $request->query('q')) {
            $query->where(fn ($q) => $q->where('order_no', $search)
                ->orWhere('client_order_id', $search)
                ->orWhere('utr', $search));
        }

        $counts = $request->user()->orders()
            ->where('environment', $environment)
            ->selectRaw('status, count(*) as total')->groupBy('status')->pluck('total', 'status');

        return view('orders.index', [
            'orders' => $query->paginate(20)->withQueryString(),
            'counts' => $counts,
            'environment' => $environment,
        ]);
    }

    public function create(Request $request)
    {
        return view('orders.create', [
            'accounts' => $request->user()->paymentAccounts()->where('is_active', true)->get(),
        ]);
    }

    /**
     * Create a payment link by hand from the dashboard (no API needed).
     */
    public function store(Request $request)
    {
        $data = $request->validate([
            'amount' => ['required', 'numeric', 'min:1', 'max:100000'],
            'payment_account_id' => ['nullable', 'integer'],
            'customer_name' => ['nullable', 'string', 'max:100'],
            'customer_mobile' => ['nullable', 'string', 'max:20'],
            'note' => ['nullable', 'string', 'max:50'],
        ]);

        $order = $this->orders->create($request->user(), $data);

        return redirect()->route('orders.show', $order)
            ->with('status', 'Payment link created. Share it with your customer.');
    }

    public function show(Request $request, Order $order)
    {
        // Admin can view any order (read-only); merchants see only their own.
        $this->authorizeView($request, $order);

        return view('orders.show', [
            'order' => $order->load('paymentAccount', 'webhookLogs'),
            'readOnly' => ($request->user()->isAdmin() && $order->user_id !== $request->user()->id)
                || $order->environment === Order::SANDBOX,
        ]);
    }

    public function recheck(Request $request, Order $order)
    {
        $this->authorizeOwnerOnly($request, $order);
        $order = $this->orders->check($order);

        if ($order->status === Order::EXPIRED) {
            $order = $this->orders->reconcileExpired($order, force: true);
        }

        return back()->with('status', "Status: {$order->status}");
    }

    /**
     * Manual confirmation after the merchant finds the UTR in their bank/UPI app.
     */
    public function markPaid(Request $request, Order $order)
    {
        $this->authorizeOwnerOnly($request, $order);

        $data = $request->validate([
            'utr' => ['required', 'string', 'regex:/^[A-Za-z0-9]{6,40}$/'],
        ], ['utr.regex' => 'Enter the UTR / reference number from the payment.']);

        if (in_array($order->status, [Order::PAID, Order::REVIEW])) {
            return back()->withErrors(['utr' => $order->status === Order::PAID
                ? 'Order is already paid.'
                : 'The customer submitted a UTR. Approve or reject it instead.']);
        }

        $utr = $this->orders->normalizeUtr($data['utr']);
        $this->orders->ensureUtrUnused($order, $utr);
        $this->orders->markPaid($order, $utr, null, [], 'manual');

        return back()->with('status', 'Order marked as paid.');
    }

    public function approveUtr(Request $request, Order $order)
    {
        $this->authorizeOwnerOnly($request, $order);

        $data = $request->validate(['bank_utr' => ['required', 'string', 'max:40']], [
            'bank_utr.required' => 'Type the UTR you see in your bank/UPI app to confirm.',
        ]);

        $this->orders->approveCustomerUtr($order, $data['bank_utr']);

        return back()->with('status', 'UTR matched. Payment approved and order marked as paid.');
    }

    public function rejectUtr(Request $request, Order $order)
    {
        $this->authorizeOwnerOnly($request, $order);

        $data = $request->validate(['reason' => ['nullable', 'string', 'max:150']]);

        $order = $this->orders->rejectCustomerUtr($order, $data['reason'] ?? null);

        return back()->with('status', $order->status === Order::PENDING
            ? 'UTR rejected. The customer can check and submit again.'
            : 'UTR rejected. No attempts left, so the order is now expired.');
    }

    // ── Authorization helpers ─────────────────────────────────────────────────

    /**
     * View (read-only) access: merchant owner OR any admin.
     */
    private function authorizeView(Request $request, Order $order): void
    {
        abort_unless(
            $order->user_id === $request->user()->id || $request->user()->isAdmin(),
            404
        );
    }

    /**
     * Write access: merchant owner ONLY. Admins cannot mutate orders.
     */
    private function authorizeOwnerOnly(Request $request, Order $order): void
    {
        abort_unless($order->user_id === $request->user()->id, 404);
    }
}
