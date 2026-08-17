<?php

namespace App\Http\Controllers\V1\Guest;

use App\Http\Controllers\Controller;
use App\Models\Order;
use App\Services\OrderService;
use App\Services\PaymentService;
use App\Services\TelegramService;
use App\Services\UserService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class PaymentController extends Controller
{
    public function notify($method, $uuid, Request $request)
    {
        try {
            $paymentService = new PaymentService($method, null, $uuid);
            $verify = $paymentService->notify($request->input());
            if (!$verify) abort(500, 'verify error');
            if (!$this->handle($verify['trade_no'], $verify['callback_no'])) {
                abort(500, 'handle error');
            }
            return(isset($verify['custom_result']) ? $verify['custom_result'] : 'success');
        } catch (\Exception $e) {
            abort(500, 'fail');
        }
    }

    private function handle($tradeNo, $callbackNo)
    {
        $order = Order::where('trade_no', $tradeNo)->first();
        if (!$order) {
            abort(500, 'order is not found');
        }
        // Besnow 定制：处理订单取消后仍在支付平台完成付款的延迟回调。
        // 先扣回取消时返还的账户余额，再将订单恢复为待支付状态。
        if ((int)$order->status === 2) {
            try {
                DB::beginTransaction();

                $order = Order::where('trade_no', $tradeNo)
                    ->lockForUpdate()
                    ->first();

                if (!$order) {
                    DB::rollBack();
                    return false;
                }

                if ((int)$order->status === 2) {
                    if ((int)$order->balance_amount > 0) {
                        $userService = new UserService();
                        if (!$userService->addBalance(
                            (int)$order->user_id,
                            -(int)$order->balance_amount
                        )) {
                            DB::rollBack();
                            return false;
                        }
                    }

                    $order->status = 0;
                    if (!$order->save()) {
                        DB::rollBack();
                        return false;
                    }
                }

                DB::commit();
            } catch (\Throwable $e) {
                if (DB::transactionLevel() > 0) {
                    DB::rollBack();
                }
                return false;
            }
        }
        if ((int)$order->status !== 0) return true;
        $orderService = new OrderService($order);
        if (!$orderService->paid($callbackNo)) {
            return false;
        }
        $telegramService = new TelegramService();
        $message = sprintf(
            "💰成功收款%s元\n———————————————\n订单号：%s",
            $order->total_amount / 100,
            $order->trade_no
        );
        $telegramService->sendMessageWithAdmin($message);
        return true;
    }
}
