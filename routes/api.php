<?php

use Illuminate\Support\Facades\Route;
use App\Http\Controllers\EscrowController;

/*
|--------------------------------------------------------------------------
| ESCROW SERVICE API ROUTES
| Microservice: Smart Escrow, Saga Distributed Transaction & VietQR Webhook
|--------------------------------------------------------------------------
*/

Route::prefix('v1/escrow')->group(function () {
    // Khởi tạo giao dịch ký quỹ (Sinh mã VietQR thanh toán)
    Route::post('/create-order', [EscrowController::class, 'createOrder']);
    
    // Webhook nhận thông báo chuyển khoản tự động từ cổng ngân hàng / VietQR (SePAY / PayOS)
    Route::post('/webhook/vietqr', [EscrowController::class, 'handleVietQRWebhook']);
    
    // Truy vấn trạng thái đơn ký quỹ (7 trạng thái)
    Route::get('/orders/{orderCode}', [EscrowController::class, 'getOrderStatus']);
    
    // Giải ngân cho người bán (sau khi người mua kiểm tra hàng tại Hub và xác nhận hài lòng)
    Route::post('/orders/{orderCode}/release', [EscrowController::class, 'releasePayment']);
    
    // Khiếu nại / Từ chối nhận đồ tại Hub (Mở quy trình hoàn tiền)
    Route::post('/orders/{orderCode}/dispute', [EscrowController::class, 'raiseDispute']);
});