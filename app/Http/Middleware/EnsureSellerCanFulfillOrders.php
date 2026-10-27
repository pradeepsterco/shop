<?php

namespace App\Http\Middleware;

use App\Enums\SellerStatus;
use App\Models\User;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Symfony\Component\HttpFoundation\Response;

class EnsureSellerCanFulfillOrders
{
    /**
     * Handle an incoming request.
     *
     * Allows approved sellers and suspended sellers (to fulfill existing orders and view settlements).
     *
     * @param  Closure(Request): (Response)  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        if (! Auth::guard('web')->check()) {
            return redirect()->route('login');
        }

        /** @var User $user */
        $user = Auth::guard('web')->user();
        $status = $user->sellerProfile?->status;

        if ($status === SellerStatus::Approved || $status === SellerStatus::Suspended) {
            return $next($request);
        }

        return redirect()->route('seller.apply');
    }
}
