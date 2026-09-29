<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use App\Services\EscrowSagaOrchestrator;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * ESCROW CONTROLLER
 * Xử lý chu trình ký quỹ Smart Escrow, đối soát VietQR tự động & giải ngân
 */
class EscrowController
{
    protected EscrowSagaOrchestrator $orchestrator;

    public function __construct(EscrowSagaOrchestrator $orchestrator)
    {
        $this->orchestrator = $orchestrator;
    }

    /**
     * Khởi tạo đơn hàng ký quỹ mới & tạo mã VietQR chuyển khoản
     */
    public function createOrder(Request $request)
    {
        $validated = $request->validate([
            'buyer_id'      => 'required|integer',
            'product_id'    => 'required|integer',
            'hub_id'        => 'required|integer'
        ]);

        $product = DB::table('products')->where('id', $validated['product_id'])->first();
        if (!$product || $product->status !== 'ACTIVE') {
            return response()->json(['success' => false, 'message' => 'Sản phẩm không khả dụng để đặt mua.'], 400);
        }

        $orderCode = 'ORD-HUNRE-' . strtoupper(Str::random(6));

        $orderId = DB::table('escrow_orders')->insertGetId([
            'order_code'    => $orderCode,
            'buyer_id'      => $validated['buyer_id'],
            'seller_id'     => $product->seller_id,
            'product_id'    => $product->id,
            'hub_id'        => $validated['hub_id'],
            'order_type'    => 'DIRECT_BUY',
            'escrow_amount' => $product->current_price,
            'status'        => EscrowSagaOrchestrator::STATE_INITIATED,
            'created_at'    => now(),
            'updated_at'    => now()
        ]);

        // Cập nhật trạng thái sản phẩm sang Đã đặt trước để tránh người khác mua trùng
        DB::table('products')->where('id', $product->id)->update(['status' => 'RESERVED_FOR_HUB']);

        // Tạo mã VietQR động thanh toán
        $bankAccount = '1028746392'; // STK ngân hàng quỹ ký quỹ HUNRE
        $bankCode = 'MBBANK';
        $amount = (int) $product->current_price;
        $description = "HUNRE {$orderCode}";
        $vietqrUrl = "https://img.vietqr.io/image/{$bankCode}-{$bankAccount}-compact2.png?amount={$amount}&addInfo=" . urlencode($description);

        return response()->json([
            'success'     => true,
            'message'     => 'Khởi tạo đơn ký quỹ thành công! Quét mã VietQR để đóng băng tiền cọc an toàn.',
            'order_code'  => $orderCode,
            'amount'      => $amount,
            'vietqr_url'  => $vietqrUrl,
            'payment_memo'=> $description
        ], 201);
    }

    /**
     * Webhook nhận thanh toán từ ngân hàng (VietQR / SePAY / PayOS)
     */
    public function handleVietQRWebhook(Request $request)
    {
        $content = $request->input('content', ''); // Nội dung chuyển khoản: "HUNRE ORD-HUNRE-XXXXXX"
        $amount = (float) $request->input('transferAmount', 0);
        $transactionId = $request->input('referenceCode', (string) Str::uuid());

        // Tìm mã đơn hàng từ nội dung chuyển khoản
        if (preg_match('/(ORD-HUNRE-[A-Z0-9]+)/', $content, $matches)) {
            $orderCode = $matches[1];
            try {
                $result = $this->orchestrator->lockEscrowFunds($orderCode, $amount, $transactionId);
                return response()->json(['success' => true, 'data' => $result]);
            } catch (\Exception $e) {
                return response()->json(['success' => false, 'message' => $e->getMessage()], 400);
            }
        }

        return response()->json(['success' => false, 'message' => 'Không tìm thấy mã đơn ký quỹ trong nội dung chuyển khoản.'], 404);
    }

    /**
     * Truy vấn trạng thái đơn ký quỹ (7 bước)
     */
    public function getOrderStatus(string $orderCode)
    {
        $order = DB::table('escrow_orders')
            ->join('products', 'escrow_orders.product_id', '=', 'products.id')
            ->join('users as buyers', 'escrow_orders.buyer_id', '=', 'buyers.id')
            ->join('users as sellers', 'escrow_orders.seller_id', '=', 'sellers.id')
            ->join('hubs', 'escrow_orders.hub_id', '=', 'hubs.id')
            ->leftJoin('lockers', 'escrow_orders.locker_id', '=', 'lockers.id')
            ->select(
                'escrow_orders.*',
                'products.title as product_title',
                'buyers.full_name as buyer_name',
                'sellers.full_name as seller_name',
                'hubs.name as hub_name',
                'hubs.location_detail as hub_location',
                'lockers.locker_code'
            )
            ->where('escrow_orders.order_code', $orderCode)
            ->first();

        if (!$order) {
            return response()->json(['success' => false, 'message' => 'Không tìm thấy đơn hàng.'], 404);
        }

        return response()->json(['success' => true, 'order' => $order]);
    }

    /**
     * Người mua xác nhận hài lòng -> Giải ngân cho người bán
     */
    public function releasePayment(string $orderCode)
    {
        try {
            $res = $this->orchestrator->releaseToSeller($orderCode);
            return response()->json(['success' => true, 'data' => $res]);
        } catch (\Exception $e) {
            return response()->json(['success' => false, 'message' => $e->getMessage()], 400);
        }
    }

    /**
     * Khiếu nại từ chối nhận đồ
     */
    public function raiseDispute(Request $request, string $orderCode)
    {
        $validated = $request->validate([
            'reason' => 'required|string'
        ]);

        $order = DB::table('escrow_orders')->where('order_code', $orderCode)->first();
        if (!$order) {
            return response()->json(['success' => false, 'message' => 'Không tìm thấy đơn hàng.'], 404);
        }

        DB::table('disputes')->insert([
            'order_id'          => $order->id,
            'raised_by_id'      => $order->buyer_id,
            'reason'            => $validated['reason'],
            'status'            => 'PENDING',
            'created_at'        => now()
        ]);

        DB::table('escrow_orders')->where('id', $order->id)->update([
            'status'     => EscrowSagaOrchestrator::STATE_DISPUTED,
            'updated_at' => now()
        ]);

        return response()->json([
            'success' => true,
            'message' => 'Đã tiếp nhận khiếu nại! Trạm Hub sẽ lập biên bản và hoàn tiền 100% cho bạn.'
        ]);
    }
}