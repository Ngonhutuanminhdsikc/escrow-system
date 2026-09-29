<?php

namespace App\Services;

use Illuminate\Support\Facades\DB;
use App\Models\EscrowOrder;
use App\Models\EscrowLedger;
use App\Models\Product;
use Illuminate\Support\Str;

/**
 * ESCROW HUB SERVICE
 * Dịch vụ mở rộng hỗ trợ vận hành Trạm Hub IoT & Xử lý bồi hoạn (Compensation Rollback)
 */
class EscrowHubService
{
    /**
     * Xác nhận người bán nhập kho Trạm Hub (Locker)
     */
    public function depositToHubLocker(string $orderCode, int $lockerId): array
    {
        return DB::transaction(function () use ($orderCode, $lockerId) {
            $order = DB::table('escrow_orders')->where('order_code', $orderCode)->lockForUpdate()->first();
            if (!$order) {
                throw new \Exception("Không tìm thấy đơn hàng.");
            }

            if ($order->status !== EscrowSagaOrchestrator::STATE_ESCROW_LOCKED) {
                throw new \Exception("Đơn hàng chưa ở trạng thái đóng băng tiền (ESCROW_LOCKED).");
            }

            DB::table('escrow_orders')->where('id', $order->id)->update([
                'locker_id'  => $lockerId,
                'status'     => EscrowSagaOrchestrator::STATE_STORED_AT_HUB,
                'updated_at' => now()
            ]);

            return [
                'status'     => 'SUCCESS',
                'order_code' => $orderCode,
                'new_state'  => EscrowSagaOrchestrator::STATE_STORED_AT_HUB,
                'message'    => "Hàng đã được cất giữ an toàn tại Tủ Locker Trạm Hub."
            ];
        });
    }

    /**
     * Mở kiểm tra hàng tại Hub
     */
    public function startInspection(string $orderCode): array
    {
        return DB::transaction(function () use ($orderCode) {
            $order = DB::table('escrow_orders')->where('order_code', $orderCode)->lockForUpdate()->first();
            if (!$order) {
                throw new \Exception("Không tìm thấy đơn hàng.");
            }

            if ($order->status !== EscrowSagaOrchestrator::STATE_STORED_AT_HUB) {
                throw new \Exception("Hàng chưa lưu kho tại Trạm Hub.");
            }

            DB::table('escrow_orders')->where('id', $order->id)->update([
                'status'     => EscrowSagaOrchestrator::STATE_INSPECTING_AT_HUB,
                'updated_at' => now()
            ]);

            return [
                'status'     => 'SUCCESS',
                'order_code' => $orderCode,
                'new_state'  => EscrowSagaOrchestrator::STATE_INSPECTING_AT_HUB,
                'message'    => "Đã mở niêm phong cho sinh viên kiểm tra hàng."
            ];
        });
    }

    /**
     * Saga Rollback Compensation: Xử lý hoàn tiền 100% cho người mua khi khiếu nại được chấp nhận
     */
    public function processDisputeRefund(string $orderCode, string $refundNote = 'Hoàn tiền khiếu nại thành công'): array
    {
        return DB::transaction(function () use ($orderCode, $refundNote) {
            $order = DB::table('escrow_orders')->where('order_code', $orderCode)->lockForUpdate()->first();
            if (!$order) {
                throw new \Exception("Không tìm thấy đơn hàng.");
            }

            if ($order->status !== EscrowSagaOrchestrator::STATE_DISPUTED) {
                throw new \Exception("Đơn hàng không ở trạng thái khiếu nại (DISPUTED).");
            }

            // Hoàn tiền vào ví người mua
            DB::table('users')->where('id', $order->buyer_id)->increment('wallet_balance', $order->escrow_amount);
            $buyerBalance = DB::table('users')->where('id', $order->buyer_id)->value('wallet_balance') ?? 0;

            // Ghi sổ cái kế toán
            DB::table('escrow_ledger')->insert([
                'order_id'         => $order->id,
                'user_id'          => $order->buyer_id,
                'transaction_type' => 'REFUND_TO_BUYER',
                'amount'           => $order->escrow_amount,
                'balance_after'    => $buyerBalance,
                'idempotency_key'  => (string) Str::uuid(),
                'note'             => $refundNote,
                'created_at'       => now()
            ]);

            // Cập nhật đơn sang REFUNDED
            DB::table('escrow_orders')->where('id', $order->id)->update([
                'status'       => EscrowSagaOrchestrator::STATE_REFUNDED,
                'completed_at' => now(),
                'updated_at'   => now()
            ]);

            // Trả sản phẩm về trạng thái ACTIVE
            DB::table('products')->where('id', $order->product_id)->update(['status' => 'ACTIVE']);

            return [
                'status'     => 'SUCCESS',
                'order_code' => $orderCode,
                'new_state'  => EscrowSagaOrchestrator::STATE_REFUNDED,
                'message'    => "Hoàn tiền 100% cho người mua và giải phóng sản phẩm bán lại."
            ];
        });
    }
}
