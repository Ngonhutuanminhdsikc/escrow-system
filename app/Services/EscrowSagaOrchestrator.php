<?php

namespace App\Services;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * SMART ESCROW SAGA ORCHESTRATOR
 * Điều phối chu trình giao dịch phân tán 7 trạng thái an toàn tuyệt đối
 */
class EscrowSagaOrchestrator
{
    public const STATE_INITIATED          = 'INITIATED';           // Khởi tạo đơn, chờ chuyển khoản
    public const STATE_ESCROW_LOCKED      = 'ESCROW_LOCKED';       // Tiền đã đóng băng trong quỹ
    public const STATE_STORED_AT_HUB      = 'STORED_AT_HUB';       // Người bán đã mang đồ đến Hub
    public const STATE_INSPECTING_AT_HUB  = 'INSPECTING_AT_HUB';   // Người mua đang kiểm tra tại Hub
    public const STATE_RELEASED           = 'RELEASED';            // Giải ngân thành công cho người bán
    public const STATE_DISPUTED           = 'DISPUTED';            // Khiếu nại do hàng lỗi / sai mô tả
    public const STATE_REFUNDED           = 'REFUNDED';            // Hoàn 100% tiền cho người mua

    /**
     * Khóa tiền ký quỹ (Hold) khi người mua nạp tiền thành công
     */
    public function lockEscrowFunds(string $orderCode, float $amount, string $idempotencyKey): array
    {
        return DB::transaction(function () use ($orderCode, $amount, $idempotencyKey) {
            $order = DB::table('escrow_orders')->where('order_code', $orderCode)->lockForUpdate()->first();
            if (!$order || $order->status !== self::STATE_INITIATED) {
                throw new \Exception("Đơn hàng không ở trạng thái hợp lệ để khóa tiền.");
            }

            // Ghi sổ cái kế toán kép (Escrow Ledger)
            DB::table('escrow_ledger')->insert([
                'order_id'         => $order->id,
                'user_id'          => $order->buyer_id,
                'transaction_type' => 'HOLD_DEPOSIT',
                'amount'           => $amount,
                'balance_after'    => $amount,
                'idempotency_key'  => $idempotencyKey,
                'note'             => "Đóng băng tiền mua hàng đơn {$orderCode}",
                'created_at'       => now()
            ]);

            // Cập nhật trạng thái đơn sang ESCROW_LOCKED
            DB::table('escrow_orders')->where('id', $order->id)->update([
                'status'     => self::STATE_ESCROW_LOCKED,
                'updated_at' => now()
            ]);

            return [
                'status'     => 'SUCCESS',
                'order_code' => $orderCode,
                'new_state'  => self::STATE_ESCROW_LOCKED,
                'message'    => "Tiền đã được bảo vệ trong ví ký quỹ trung gian. Mời người bán mang đồ đến Trạm Hub."
            ];
        });
    }

    /**
     * Giải ngân tiền cho người bán (Release) khi người mua xác nhận hài lòng tại Hub
     */
    public function releaseToSeller(string $orderCode): array
    {
        return DB::transaction(function () use ($orderCode) {
            $order = DB::table('escrow_orders')->where('order_code', $orderCode)->lockForUpdate()->first();
            if (!$order || !in_array($order->status, [self::STATE_STORED_AT_HUB, self::STATE_INSPECTING_AT_HUB])) {
                throw new \Exception("Không thể giải ngân khi hàng chưa được kiểm tra tại Hub.");
            }

            // Cộng tiền vào ví người bán
            DB::table('users')->where('id', $order->seller_id)->increment('wallet_balance', $order->escrow_amount);

            // Ghi sổ cái
            DB::table('escrow_ledger')->insert([
                'order_id'         => $order->id,
                'user_id'          => $order->seller_id,
                'transaction_type' => 'RELEASE_TO_SELLER',
                'amount'           => $order->escrow_amount,
                'balance_after'    => $order->escrow_amount,
                'idempotency_key'  => (string) Str::uuid(),
                'note'             => "Giải ngân hoàn tất giao dịch O2O tại Hub",
                'created_at'       => now()
            ]);

            DB::table('escrow_orders')->where('id', $order->id)->update([
                'status'       => self::STATE_RELEASED,
                'completed_at' => now(),
                'updated_at'   => now()
            ]);

            // Đánh dấu sản phẩm là ĐÃ BÁN
            DB::table('products')->where('id', $order->product_id)->update(['status' => 'SOLD']);

            return [
                'status'     => 'SUCCESS',
                'order_code' => $orderCode,
                'new_state'  => self::STATE_RELEASED,
                'message'    => "Giao dịch thành công! Tiền đã về tài khoản người bán."
            ];
        });
    }
}