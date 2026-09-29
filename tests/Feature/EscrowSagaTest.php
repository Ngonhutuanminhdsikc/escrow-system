<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;
use Illuminate\Support\Facades\DB;
use App\Services\EscrowSagaOrchestrator;

class EscrowSagaTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed();
    }

    public function test_full_escrow_saga_successful_flow(): void
    {
        $buyer = DB::table('users')->where('email', 'buyer@hunre.edu.vn')->first();
        $hub = DB::table('hubs')->first();
        $product = DB::table('products')->where('status', 'ACTIVE')->first();

        // 1. Khởi tạo đơn hàng ký quỹ mới (INITIATED)
        $response = $this->postJson('/api/v1/escrow/create-order', [
            'buyer_id'   => $buyer->id,
            'product_id' => $product->id,
            'hub_id'     => $hub->id,
        ]);

        $response->assertStatus(201)
            ->assertJson(['success' => true]);

        $orderCode = $response->json('order_code');
        $this->assertNotEmpty($orderCode);

        // Kiểm tra sản phẩm chuyển trạng thái RESERVED_FOR_HUB
        $this->assertDatabaseHas('products', [
            'id'     => $product->id,
            'status' => 'RESERVED_FOR_HUB'
        ]);

        // 2. Ngân hàng phát sinh VietQR Webhook tự động (ESCROW_LOCKED)
        $webhookResp = $this->postJson('/api/v1/escrow/webhook/vietqr', [
            'content'        => "Thanh toan don hang HUNRE {$orderCode}",
            'transferAmount' => $product->current_price,
            'referenceCode'  => 'TX-10029384'
        ]);

        $webhookResp->assertStatus(200)
            ->assertJsonPath('data.new_state', EscrowSagaOrchestrator::STATE_ESCROW_LOCKED);

        $this->assertDatabaseHas('escrow_orders', [
            'order_code' => $orderCode,
            'status'     => EscrowSagaOrchestrator::STATE_ESCROW_LOCKED
        ]);

        $this->assertDatabaseHas('escrow_ledger', [
            'idempotency_key'  => 'TX-10029384',
            'transaction_type' => 'HOLD_DEPOSIT'
        ]);

        // 3. Giả lập Trạm Hub tiếp nhận hàng & lưu kho locker (STORED_AT_HUB)
        $locker = DB::table('lockers')->where('hub_id', $hub->id)->first();
        DB::table('escrow_orders')->where('order_code', $orderCode)->update([
            'locker_id'  => $locker->id,
            'status'     => EscrowSagaOrchestrator::STATE_STORED_AT_HUB,
            'updated_at' => now()
        ]);

        // 4. Truy vấn thông tin chi tiết đơn ký quỹ từ API
        $statusResp = $this->getJson("/api/v1/escrow/orders/{$orderCode}");
        $statusResp->assertStatus(200)
            ->assertJsonPath('order.status', EscrowSagaOrchestrator::STATE_STORED_AT_HUB)
            ->assertJsonPath('order.product_title', $product->title);

        // 5. Người mua kiểm tra hàng và bấm Giải ngân (RELEASED)
        $releaseResp = $this->postJson("/api/v1/escrow/orders/{$orderCode}/release");
        $releaseResp->assertStatus(200)
            ->assertJsonPath('data.new_state', EscrowSagaOrchestrator::STATE_RELEASED);

        // Kiểm tra tiền đã được cộng vào ví người bán
        $seller = DB::table('users')->where('id', $product->seller_id)->first();
        $this->assertEquals((float)$product->current_price, (float) $seller->wallet_balance);

        // Kiểm tra sản phẩm đã chuyển sang trạng thái SOLD
        $this->assertDatabaseHas('products', [
            'id'     => $product->id,
            'status' => 'SOLD'
        ]);
    }

    public function test_escrow_saga_dispute_flow(): void
    {
        $buyer = DB::table('users')->where('email', 'buyer@hunre.edu.vn')->first();
        $hub = DB::table('hubs')->first();
        $product = DB::table('products')->where('status', 'ACTIVE')->orderBy('id', 'desc')->first();

        // 1. Tạo đơn hàng ký quỹ
        $createResp = $this->postJson('/api/v1/escrow/create-order', [
            'buyer_id'   => $buyer->id,
            'product_id' => $product->id,
            'hub_id'     => $hub->id,
        ]);
        $orderCode = $createResp->json('order_code');

        // 2. Webhook khóa tiền
        $this->postJson('/api/v1/escrow/webhook/vietqr', [
            'content'        => "Chuyen tien {$orderCode}",
            'transferAmount' => $product->current_price,
            'referenceCode'  => 'TX-REFUND-999'
        ]);

        // 3. Người mua tạo khiếu nại (DISPUTED)
        $disputeResp = $this->postJson("/api/v1/escrow/orders/{$orderCode}/dispute", [
            'reason' => 'Hàng không đúng như mô tả ban đầu'
        ]);
        $disputeResp->assertStatus(200)
            ->assertJson(['success' => true]);

        $this->assertDatabaseHas('escrow_orders', [
            'order_code' => $orderCode,
            'status'     => EscrowSagaOrchestrator::STATE_DISPUTED
        ]);

        $this->assertDatabaseHas('disputes', [
            'raised_by_id' => $buyer->id,
            'reason'       => 'Hàng không đúng như mô tả ban đầu'
        ]);
    }
}
