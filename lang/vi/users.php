<?php

declare(strict_types=1);

return [
    'status' => [
        'active' => 'Đang hoạt động',
        'inactive' => 'Vô hiệu hoá',
        'locked' => 'Bị khoá',
        'pending_invite' => 'Đang chờ xác nhận',
    ],
    'errors' => [
        'no_permission' => 'Bạn không có quyền thực hiện thao tác này.',
        'cannot_self_modify' => 'Không thể tự thao tác trên chính mình.',
        'target_higher_role' => 'Vai trò của người dùng đích cao hơn hoặc bằng bạn.',
        'no_reset_channel' => 'Người dùng không có email/SĐT để gửi link đặt lại mật khẩu.',
        'invalid_verification_channel' => 'Kênh xác minh không hợp lệ (chỉ chấp nhận email hoặc phone).',
        'no_verification_identifier' => 'Người dùng không có :channel để gửi xác minh.',
        'must_soft_delete_first' => 'Phải vô hiệu hoá tài khoản trước khi xoá vĩnh viễn.',
        'not_trashed' => 'Tài khoản chưa bị xoá tạm — không thể khôi phục.',
    ],
    'success' => [
        'locked' => 'Đã khoá tài khoản.',
        'unlocked' => 'Đã mở khoá tài khoản.',
        'deactivated' => 'Đã vô hiệu hoá tài khoản.',
        'activated' => 'Đã kích hoạt lại tài khoản.',
        'password_reset_sent' => 'Đã gửi link đặt lại mật khẩu.',
        'verification_resent' => 'Đã gửi lại link xác minh.',
        'sessions_revoked' => 'Đã đăng xuất :count phiên.',
        'role_changed' => 'Đã đổi vai trò.',
        'permanently_deleted' => 'Đã xoá vĩnh viễn.',
        'restored' => 'Đã khôi phục tài khoản.',
        'bulk_done' => 'Hoàn tất.',
    ],
    'validation' => [
        'email_or_phone_required' => 'Cần ít nhất email hoặc số điện thoại.',
    ],
];
